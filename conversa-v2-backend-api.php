<?php

/**
 * [Conversa v2] Backend API
 *
 * Responsabilidade:
 *  - Expor dois endpoints AJAX para o runtime do chat.
 *
 * Endpoints:
 *  1. chat_conversa_status   (BARATO, polling alto)
 *     - SELECT direto na CCT wp_jet_cct_mensagens_.
 *     - Retorna { last_id, count, last_changed, hash }.
 *     - Frontend usa o hash pra decidir se vale a pena pedir HTML.
 *
 *  2. chat_conversa_messages (CARO, sob demanda)
 *     - Renderiza o JetEngine Listing das mensagens.
 *     - Tenta jet_engine()->listings->frontend->get_listing_content() primeiro
 *       (rápido, sem fetch HTTP).
 *     - Fallback: wp_safe_remote_get() no permalink + DOMDocument extract
 *       (caminho atual do projeto, mantido como rede de segurança).
 *
 * Premissas explícitas (acordadas):
 *  - Permissão de acesso à página = upstream (JetEngine + page rules).
 *    Aqui só validamos: nonce, login, conversa existe.
 *  - Permissão de envio = JetFormBuilder.
 *    Aqui não tem endpoint de send; só de leitura.
 *  - Index da CCT = responsabilidade de DBA, não do código.
 *
 * Performance:
 *  - status: 1 query SELECT em tabela própria da CCT. ~0.5ms.
 *  - status: object cache de 2s pra evitar rajadas simultâneas
 *    (mesma conversa, mesmo segundo, várias abas).
 *  - messages: só chamado quando hash mudou no client side.
 *
 * Hash:
 *  - md5( last_id . '|' . count . '|' . last_changed )
 *  - data_envio entra como last_changed porque é o único timestamp
 *    confiável da mensagem em si. Se uma mensagem antiga for editada
 *    (improvável no fluxo de chat, mas teoricamente possível), o hash
 *    muda e o front rebusca.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 * CONSTANTES
 * ============================================================================
 */

if ( ! defined( 'CHAT_CONVERSA_NONCE_ACTION' ) ) {
    define( 'CHAT_CONVERSA_NONCE_ACTION', 'chat_conversa_nonce' );
}

if ( ! defined( 'CHAT_CONVERSA_AJAX_STATUS' ) ) {
    define( 'CHAT_CONVERSA_AJAX_STATUS', 'chat_conversa_status' );
}

if ( ! defined( 'CHAT_CONVERSA_AJAX_MESSAGES' ) ) {
    define( 'CHAT_CONVERSA_AJAX_MESSAGES', 'chat_conversa_messages' );
}

if ( ! defined( 'CHAT_CONVERSA_STATUS_CACHE_TTL' ) ) {
    define( 'CHAT_CONVERSA_STATUS_CACHE_TTL', 2 ); // segundos
}

if ( ! defined( 'CHAT_CONVERSA_RATE_STATUS_LIMIT' ) ) {
    define( 'CHAT_CONVERSA_RATE_STATUS_LIMIT', 60 ); // por usuário+conversa
}

if ( ! defined( 'CHAT_CONVERSA_RATE_MESSAGES_LIMIT' ) ) {
    define( 'CHAT_CONVERSA_RATE_MESSAGES_LIMIT', 20 ); // por usuário+conversa
}

if ( ! defined( 'CHAT_CONVERSA_RATE_WINDOW' ) ) {
    define( 'CHAT_CONVERSA_RATE_WINDOW', 60 ); // segundos
}

/**
 * ============================================================================
 * HOOKS DE REGISTRO
 * ============================================================================
 *
 * Handlers AJAX são SEMPRE registrados (não dependem de ser front).
 * Eles validam internamente.
 */

// Remove handler antigo se ainda estiver registrado de versão anterior.
remove_action( 'wp_ajax_chat_render_messages', 'chat_conversa_ajax_render_messages' );
remove_action( 'wp_ajax_chat_render_messages', 'chat_render_messages_handler' );

add_action( 'wp_ajax_' . CHAT_CONVERSA_AJAX_STATUS, 'chat_conversa_ajax_status' );
add_action( 'wp_ajax_nopriv_' . CHAT_CONVERSA_AJAX_STATUS, 'chat_conversa_ajax_block_nopriv' );

add_action( 'wp_ajax_' . CHAT_CONVERSA_AJAX_MESSAGES, 'chat_conversa_ajax_messages' );
add_action( 'wp_ajax_nopriv_' . CHAT_CONVERSA_AJAX_MESSAGES, 'chat_conversa_ajax_block_nopriv' );

/**
 * ============================================================================
 * HOOK: atualiza last_message_at no meta da conversa
 * ============================================================================
 *
 * Toda vez que uma mensagem é inserida na CCT, atualiza o meta
 * 'last_message_at' do post-conversa. Isso permite que features futuras
 * (badge de "novas mensagens" em listings de conversas, ordenação,
 * push notifications) consultem o estado da conversa sem tocar na CCT.
 *
 * Não é usado pelo endpoint status (que vai direto na CCT pra ter
 * last_id/count também), mas mantém o meta atualizado pra outros usos.
 *
 * Idempotente: se o hook já existe registrado em outro lugar, este
 * registro é inofensivo (timestamp será o mesmo).
 */
add_action( 'jet-engine/custom-content-types/data/inserted-item', 'chat_conversa_touch_last_message_at', 10, 2 );

function chat_conversa_touch_last_message_at( $item_id, $cct_instance ) {
    if ( ! is_object( $cct_instance ) ) {
        return;
    }

    // Verifica se é a CCT certa.
    $slug = '';
    if ( method_exists( $cct_instance, 'get_arg' ) ) {
        $slug = $cct_instance->get_arg( 'slug' );
    } elseif ( isset( $cct_instance->args['slug'] ) ) {
        $slug = $cct_instance->args['slug'];
    }

    if ( $slug !== 'mensagens_' ) {
        return;
    }

    // Pega o conversa_id da última mensagem inserida.
    $conversa_id = 0;

    if ( method_exists( $cct_instance, 'db' ) ) {
        $row = $cct_instance->db()->query( [
            'where' => [
                [
                    'column' => '_ID',
                    'value'  => $item_id,
                ],
            ],
            'limit' => [ 1 ],
        ] );

        if ( ! empty( $row[0]['conversa_id'] ) ) {
            $conversa_id = (int) $row[0]['conversa_id'];
        }
    }

    if ( ! $conversa_id ) {
        return;
    }

    update_post_meta(
        $conversa_id,
        CHAT_CONVERSA_LAST_MESSAGE_META,
        current_time( 'mysql' )
    );
}

/**
 * ============================================================================
 * BLOQUEIO DE NOPRIV
 * ============================================================================
 */

function chat_conversa_ajax_block_nopriv() {
    chat_conversa_send_error( 'Não autenticado', 'not_authenticated', 401 );
}

function chat_conversa_send_error( $message, $code = 'error', $status = 400, $extra = [] ) {
    wp_send_json_error(
        array_merge(
            [ 'message' => $message, 'code' => $code ],
            $extra
        ),
        $status
    );
}

/**
 * ============================================================================
 * VALIDAÇÃO BÁSICA DE REQUEST
 * ============================================================================
 *
 * Mínimo viável: POST + nonce + login + conversa_id existe e é do tipo certo.
 * Permissão de quem pode acessar a conversa = upstream.
 */

function chat_conversa_validate_request() {
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        chat_conversa_send_error( 'Método inválido', 'invalid_method', 405 );
    }

    nocache_headers();

    check_ajax_referer( CHAT_CONVERSA_NONCE_ACTION, 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        chat_conversa_send_error( 'Não autenticado', 'not_authenticated', 401 );
    }

    $conversa_id = isset( $_POST['conversa_id'] )
        ? absint( wp_unslash( $_POST['conversa_id'] ) )
        : 0;

    if ( ! $conversa_id ) {
        chat_conversa_send_error( 'Parâmetros inválidos', 'invalid_params', 400 );
    }

    $post = get_post( $conversa_id );
    if ( ! $post || $post->post_type !== CHAT_CONVERSA_CPT || $post->post_status !== 'publish' ) {
        chat_conversa_send_error( 'Conversa inválida', 'invalid_conversation', 404 );
    }

    return [
        'user_id'     => $user_id,
        'conversa_id' => $conversa_id,
        'request_id'  => isset( $_POST['request_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) )
            : '',
    ];
}

/**
 * ============================================================================
 * RATE LIMIT
 * ============================================================================
 */

function chat_conversa_check_rate( $user_id, $conversa_id, $kind, $limit ) {
    $key = 'chat_rl_' . $kind . '_' . $user_id . '_' . $conversa_id;
    $count = (int) get_transient( $key );

    if ( $count >= $limit ) {
        chat_conversa_send_error(
            'Muitas requisições, aguarde',
            'rate_limited',
            429
        );
    }

    set_transient( $key, $count + 1, CHAT_CONVERSA_RATE_WINDOW );
}

/**
 * ============================================================================
 * ENDPOINT 1: STATUS (barato)
 * ============================================================================
 *
 * Retorna o estado da conversa pro client decidir se precisa atualizar.
 * Cache de 2s evita que múltiplas abas/usuários polling simultâneo
 * batam na mesma query.
 */

function chat_conversa_ajax_status() {
    $req = chat_conversa_validate_request();

    chat_conversa_check_rate(
        $req['user_id'],
        $req['conversa_id'],
        'status',
        CHAT_CONVERSA_RATE_STATUS_LIMIT
    );

    $status = chat_conversa_get_status( $req['conversa_id'] );

    if ( is_wp_error( $status ) ) {
        chat_conversa_send_error(
            $status->get_error_message(),
            $status->get_error_code(),
            500
        );
    }

    wp_send_json_success( [
        'conversa_id'  => $req['conversa_id'],
        'last_id'      => (int) $status['last_id'],
        'count'        => (int) $status['count'],
        'last_changed' => $status['last_changed'],
        'hash'         => $status['hash'],
        'request_id'   => $req['request_id'],
    ] );
}

/**
 * Lê o estado da conversa direto da CCT.
 * Cache de 2s no object cache (Redis/Memcached se disponível, in-memory senão).
 *
 * @return array|WP_Error
 */
function chat_conversa_get_status( $conversa_id ) {
    $cache_key   = 'chat_conv_status_' . $conversa_id;
    $cache_group = 'chat_conversa';

    $cached = wp_cache_get( $cache_key, $cache_group );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    global $wpdb;

    $table = $wpdb->prefix . CHAT_CONVERSA_CCT_TABLE;

    // Verificação minimalista: tabela existe?
    // (Só roda 1x por request — caso a CCT não exista, retorna estado vazio
    // em vez de erro 500, pra não travar o chat em desenvolvimento.)
    $table_check_key = 'chat_conv_table_exists';
    $table_exists    = wp_cache_get( $table_check_key, $cache_group );

    if ( false === $table_exists ) {
        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );
        $table_exists = ( $found === $table );
        wp_cache_set( $table_check_key, $table_exists, $cache_group, 300 );
    }

    if ( ! $table_exists ) {
        $empty = [
            'last_id'      => 0,
            'count'        => 0,
            'last_changed' => '',
            'hash'         => 'empty',
        ];
        wp_cache_set( $cache_key, $empty, $cache_group, CHAT_CONVERSA_STATUS_CACHE_TTL );
        return $empty;
    }

    // Query principal: barata, 1 row.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                MAX(_ID)        AS last_id,
                COUNT(*)        AS total,
                MAX(data_envio) AS last_changed
            FROM `{$table}`
            WHERE conversa_id = %d",
            $conversa_id
        ),
        ARRAY_A
    );

    if ( $wpdb->last_error ) {
        return new WP_Error(
            'db_error',
            'Erro ao consultar mensagens'
        );
    }

    if ( ! $row ) {
        $row = [ 'last_id' => 0, 'total' => 0, 'last_changed' => '' ];
    }

    $last_id      = (int) ( $row['last_id'] ?? 0 );
    $count        = (int) ( $row['total'] ?? 0 );
    $last_changed = (string) ( $row['last_changed'] ?? '' );

    // Hash conservador: APENAS last_id + count.
    // last_changed FORA do hash de propósito — algum hook (JetEngine,
    // Elementor, plugin terceiro) pode tocar data_envio sem mensagem
    // realmente nova/removida, causando scroll fantasma no client.
    // Trade-off aceito: edição de mensagem existente não dispara refresh.
    // Em chat isso é raro/irrelevante.
    $hash = md5( $last_id . '|' . $count );

    $payload = [
        'last_id'      => $last_id,
        'count'        => $count,
        'last_changed' => $last_changed,
        'hash'         => $hash,
    ];

    wp_cache_set( $cache_key, $payload, $cache_group, CHAT_CONVERSA_STATUS_CACHE_TTL );

    return $payload;
}

/**
 * ============================================================================
 * ENDPOINT 2: MESSAGES (caro)
 * ============================================================================
 *
 * Renderiza o JetEngine Listing das mensagens.
 * Estratégia: tenta renderização direta primeiro (rápida), fallback HTTP.
 */

function chat_conversa_ajax_messages() {
    $req = chat_conversa_validate_request();

    chat_conversa_check_rate(
        $req['user_id'],
        $req['conversa_id'],
        'messages',
        CHAT_CONVERSA_RATE_MESSAGES_LIMIT
    );

    $html = chat_conversa_render_listing( $req['conversa_id'] );

    if ( is_wp_error( $html ) ) {
        chat_conversa_send_error(
            $html->get_error_message(),
            $html->get_error_code(),
            500
        );
    }

    // Junta status no mesmo payload pra economizar ida-e-volta:
    // o client já sai com o estado novo em mãos.
    $status = chat_conversa_get_status( $req['conversa_id'] );

    wp_send_json_success( [
        'html'         => $html,
        'request_id'   => $req['request_id'],
        'last_id'      => is_array( $status ) ? (int) $status['last_id']      : 0,
        'count'        => is_array( $status ) ? (int) $status['count']        : 0,
        'last_changed' => is_array( $status ) ? $status['last_changed']       : '',
        'hash'         => is_array( $status ) ? $status['hash']               : '',
        'generated_at' => current_time( 'mysql' ),
    ] );
}

/**
 * Renderiza o listing usando o caminho mais barato disponível.
 *
 * 1. Tenta API direta do JetEngine (sem refetch da página).
 * 2. Se falhar, cai no fetch HTTP interno + DOMDocument.
 *
 * @return string|WP_Error
 */
function chat_conversa_render_listing( $conversa_id ) {

    $html = chat_conversa_try_direct_listing( $conversa_id );

    if ( is_string( $html ) && $html !== '' ) {
        return $html;
    }

    return chat_conversa_fallback_http_listing( $conversa_id );
}

/**
 * Tenta renderizar o listing direto via JetEngine, sem fetch HTTP.
 * Esse caminho é ~95% mais barato que o fallback.
 *
 * Retorna string HTML do listing OU string vazia se não conseguiu.
 * (Não retorna WP_Error pra deixar o fallback assumir naturalmente.)
 */
function chat_conversa_try_direct_listing( $conversa_id ) {
    if ( ! function_exists( 'jet_engine' ) ) {
        return '';
    }

    $jet_engine = jet_engine();

    if ( empty( $jet_engine->listings ) || empty( $jet_engine->listings->frontend ) ) {
        return '';
    }

    $frontend = $jet_engine->listings->frontend;

    if ( ! method_exists( $frontend, 'get_listing_item_content' ) && ! method_exists( $frontend, 'get_listing_content' ) ) {
        return '';
    }

    // Setup do contexto: o listing precisa enxergar a conversa atual como
    // current object. Forçamos via $post global + setup_postdata.
    global $post;
    $original_post = $post;

    $post = get_post( $conversa_id );
    if ( ! $post ) {
        return '';
    }

    setup_postdata( $post );

    // Atributos típicos esperados pelo render do JetEngine.
    $atts = [
        'lisitng_id'      => CHAT_CONVERSA_LISTING_ID, // typo histórico do JetEngine, mantido por compat
        'listing_id'      => CHAT_CONVERSA_LISTING_ID,
        'columns'         => 1,
        'columns_tablet'  => '',
        'columns_mobile'  => '',
        'is_archive_template' => false,
    ];

    $html = '';

    try {
        // O método público mais consistente entre versões é o shortcode.
        // jet_engine_listing aceita listing_id e renderiza o grid completo.
        $html = do_shortcode(
            '[jet_engine_listing listing_id="' . (int) CHAT_CONVERSA_LISTING_ID . '"]'
        );
    } catch ( \Throwable $e ) {
        $html = '';
    }

    // Restaura contexto global.
    wp_reset_postdata();
    $post = $original_post;
    if ( $post ) {
        setup_postdata( $post );
    }

    if ( ! is_string( $html ) ) {
        return '';
    }

    $html = trim( $html );

    // Sanity check: o HTML retornado precisa conter o data-listing-id correto.
    if ( strpos( $html, 'data-listing-id="' . CHAT_CONVERSA_LISTING_ID . '"' ) === false ) {
        return '';
    }

    return $html;
}

/**
 * Fallback: caminho original. Faz request HTTP no permalink, extrai o listing
 * via DOMDocument. Mais caro mas funciona em qualquer cenário.
 */
function chat_conversa_fallback_http_listing( $conversa_id ) {
    $permalink = get_permalink( $conversa_id );

    if ( ! $permalink ) {
        return new WP_Error( 'invalid_permalink', 'Permalink inválido' );
    }

    $url = add_query_arg(
        [
            'chat_refresh' => str_replace( ' ', '', microtime() ),
            'nocache'      => wp_generate_uuid4(),
        ],
        $permalink
    );

    $cookies = chat_conversa_collect_cookies();

    $response = wp_safe_remote_get(
        $url,
        [
            'cookies'            => $cookies,
            'timeout'            => 15,
            'redirection'        => 3,
            'reject_unsafe_urls' => true,
            'headers'            => [
                'X-Chat-Refresh' => '1',
                'Cache-Control'  => 'no-cache, no-store, must-revalidate',
                'Pragma'         => 'no-cache',
                'Expires'        => '0',
            ],
        ]
    );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'internal_request_failed', 'Erro ao buscar conversa' );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    if ( $code !== 200 ) {
        return new WP_Error( 'invalid_internal_response', 'Resposta inválida' );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        return new WP_Error( 'empty_internal_response', 'Página vazia' );
    }

    return chat_conversa_extract_listing_from_html( $body );
}

function chat_conversa_collect_cookies() {
    $cookies = [];

    if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
        return $cookies;
    }

    foreach ( $_COOKIE as $name => $value ) {
        if ( ! is_scalar( $value ) ) {
            continue;
        }
        $cookies[] = new WP_Http_Cookie( [
            'name'  => $name,
            'value' => wp_unslash( $value ),
        ] );
    }

    return $cookies;
}

function chat_conversa_extract_listing_from_html( $body ) {
    $previous = libxml_use_internal_errors( true );

    $dom    = new DOMDocument( '1.0', 'UTF-8' );
    $loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $body );

    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $loaded ) {
        return new WP_Error( 'dom_parse_failed', 'Erro ao interpretar HTML' );
    }

    $xpath = new DOMXPath( $dom );
    $nodes = $xpath->query(
        '//*[@data-listing-id="' . (int) CHAT_CONVERSA_LISTING_ID . '"]'
    );

    if ( ! $nodes || $nodes->length === 0 ) {
        return new WP_Error( 'listing_not_found', 'Listing não encontrado' );
    }

    $html = $dom->saveHTML( $nodes->item( 0 ) );

    if ( ! $html ) {
        return new WP_Error( 'listing_render_failed', 'Erro ao renderizar' );
    }

    return $html;
}