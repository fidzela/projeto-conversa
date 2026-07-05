<?php

/**
 * [Conversa v4.1] Incremental Mirror Renderer
 * ============================================================================
 *
 * O QUE MUDA EM RELAÇÃO À v4.0:
 * - O backend incremental não tenta mais devolver HTML visual próprio.
 * - O backend devolve dados estruturados da CCT.
 * - O frontend clona um item real do Elementor/JetEngine já renderizado.
 * - O clone preserva a árvore do Listing 56326 e só troca mensagem/data/attrs.
 *
 * REGRA:
 * Elementor/JetEngine continuam sendo a fonte visual da verdade.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 * CONSTANTES
 * ============================================================================
 */

if ( ! defined( 'CHAT_CONVERSA_V4_NONCE_ACTION' ) ) {
    define( 'CHAT_CONVERSA_V4_NONCE_ACTION', 'chat_conversa_v4_nonce' );
}

if ( ! defined( 'CHAT_CONVERSA_V4_AJAX_STATUS' ) ) {
    define( 'CHAT_CONVERSA_V4_AJAX_STATUS', 'chat_conversa_v4_status' );
}

if ( ! defined( 'CHAT_CONVERSA_V4_AJAX_AFTER' ) ) {
    define( 'CHAT_CONVERSA_V4_AJAX_AFTER', 'chat_conversa_v4_after' );
}

if ( ! defined( 'CHAT_CONVERSA_V4_AJAX_FULL' ) ) {
    define( 'CHAT_CONVERSA_V4_AJAX_FULL', 'chat_conversa_v4_full' );
}

if ( ! defined( 'CHAT_CONVERSA_V4_RATE_WINDOW' ) ) {
    define( 'CHAT_CONVERSA_V4_RATE_WINDOW', 60 );
}

if ( ! defined( 'CHAT_CONVERSA_V4_RATE_STATUS_LIMIT' ) ) {
    define( 'CHAT_CONVERSA_V4_RATE_STATUS_LIMIT', 80 );
}

if ( ! defined( 'CHAT_CONVERSA_V4_RATE_AFTER_LIMIT' ) ) {
    define( 'CHAT_CONVERSA_V4_RATE_AFTER_LIMIT', 40 );
}

if ( ! defined( 'CHAT_CONVERSA_V4_AFTER_LIMIT_DEFAULT' ) ) {
    define( 'CHAT_CONVERSA_V4_AFTER_LIMIT_DEFAULT', 20 );
}

if ( ! defined( 'CHAT_CONVERSA_V4_AFTER_LIMIT_MAX' ) ) {
    define( 'CHAT_CONVERSA_V4_AFTER_LIMIT_MAX', 50 );
}

/**
 * Se souber o nome exato do campo de texto da CCT, defina antes:
 * define( 'CHAT_CONVERSA_V4_MESSAGE_FIELD', 'mensagem' );
 */
if ( ! defined( 'CHAT_CONVERSA_V4_MESSAGE_FIELD' ) ) {
    define( 'CHAT_CONVERSA_V4_MESSAGE_FIELD', '' );
}

/**
 * ============================================================================
 * ENDPOINTS AJAX
 * ============================================================================
 */

add_action( 'wp_ajax_' . CHAT_CONVERSA_V4_AJAX_STATUS, 'chat_conversa_v4_ajax_status' );
add_action( 'wp_ajax_' . CHAT_CONVERSA_V4_AJAX_AFTER,  'chat_conversa_v4_ajax_after' );
add_action( 'wp_ajax_' . CHAT_CONVERSA_V4_AJAX_FULL,   'chat_conversa_v4_ajax_full' );

function chat_conversa_v4_ajax_status() {
    $req    = chat_conversa_v4_validate_ajax_request( 'status', CHAT_CONVERSA_V4_RATE_STATUS_LIMIT );
    $status = chat_conversa_v4_get_status_payload( $req['conversa_id'] );

    if ( is_wp_error( $status ) ) {
        chat_conversa_v4_send_error( $status->get_error_message(), $status->get_error_code(), 500 );
    }

    wp_send_json_success( [
        'conversa_id' => $req['conversa_id'],
        'status'     => $status,
        'request_id' => $req['request_id'],
    ] );
}

function chat_conversa_v4_ajax_after() {
    $req = chat_conversa_v4_validate_ajax_request( 'after', CHAT_CONVERSA_V4_RATE_AFTER_LIMIT );

    $after_id = isset( $_POST['after_id'] )
        ? absint( wp_unslash( $_POST['after_id'] ) )
        : 0;

    $limit = isset( $_POST['limit'] )
        ? absint( wp_unslash( $_POST['limit'] ) )
        : CHAT_CONVERSA_V4_AFTER_LIMIT_DEFAULT;

    $limit = max( 1, min( CHAT_CONVERSA_V4_AFTER_LIMIT_MAX, $limit ) );

    $rows = chat_conversa_v4_get_messages_after( $req['conversa_id'], $after_id, $limit );

    if ( is_wp_error( $rows ) ) {
        chat_conversa_v4_send_error( $rows->get_error_message(), $rows->get_error_code(), 500 );
    }

    $ctx   = chat_conversa_v4_get_conversa_participants( $req['conversa_id'] );
    $items = [];

    foreach ( $rows as $row ) {
        $items[] = chat_conversa_v4_prepare_message_item( $row, $ctx );
    }

    $status = chat_conversa_v4_get_status_payload( $req['conversa_id'] );

    wp_send_json_success( [
        'conversa_id' => $req['conversa_id'],
        'after_id'    => $after_id,
        'limit'       => $limit,
        'items'       => $items,
        'status'      => is_wp_error( $status ) ? null : $status,
        'request_id'  => $req['request_id'],
        'mode'        => 'incremental_mirror',
    ] );
}

/**
 * Fallback full: usa o Backend API antigo quando disponível.
 */
function chat_conversa_v4_ajax_full() {
    $req = chat_conversa_v4_validate_ajax_request( 'full', CHAT_CONVERSA_V4_RATE_AFTER_LIMIT );

    if ( ! function_exists( 'chat_conversa_render_listing' ) ) {
        chat_conversa_v4_send_error(
            'Fallback full indisponível: chat_conversa_render_listing() não existe.',
            'full_fallback_unavailable',
            500
        );
    }

    $html = chat_conversa_render_listing( $req['conversa_id'] );

    if ( is_wp_error( $html ) ) {
        chat_conversa_v4_send_error( $html->get_error_message(), $html->get_error_code(), 500 );
    }

    $status = chat_conversa_v4_get_status_payload( $req['conversa_id'] );

    wp_send_json_success( [
        'conversa_id' => $req['conversa_id'],
        'html'        => $html,
        'status'      => is_wp_error( $status ) ? null : $status,
        'request_id'  => $req['request_id'],
        'mode'        => 'full_fallback',
    ] );
}

/**
 * ============================================================================
 * VALIDAÇÃO / SEGURANÇA
 * ============================================================================
 */

function chat_conversa_v4_validate_ajax_request( $kind, $limit ) {
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
        chat_conversa_v4_send_error( 'Método inválido.', 'invalid_method', 405 );
    }

    nocache_headers();

    $nonce = isset( $_POST['nonce'] )
        ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
        : '';

    if ( ! wp_verify_nonce( $nonce, CHAT_CONVERSA_V4_NONCE_ACTION ) ) {
        chat_conversa_v4_send_error( 'Nonce inválido ou expirado.', 'invalid_nonce', 403 );
    }

    $user_id = (int) get_current_user_id();

    if ( ! $user_id ) {
        chat_conversa_v4_send_error( 'Login obrigatório.', 'not_logged_in', 401 );
    }

    $conversa_id = isset( $_POST['conversa_id'] )
        ? absint( wp_unslash( $_POST['conversa_id'] ) )
        : 0;

    if ( ! $conversa_id ) {
        chat_conversa_v4_send_error( 'conversa_id inválido.', 'invalid_conversa_id', 400 );
    }

    $post = get_post( $conversa_id );

    if ( ! $post || $post->post_type !== 'conversa' || $post->post_status !== 'publish' ) {
        chat_conversa_v4_send_error( 'Conversa inválida.', 'invalid_conversa', 404 );
    }

    $participants = chat_conversa_v4_get_conversa_participants( $conversa_id );

    if (
        $user_id !== $participants['is_artista']
        && $user_id !== $participants['is_convidado']
    ) {
        chat_conversa_v4_send_error( 'Você não é participante desta conversa.', 'not_participant', 403 );
    }

    chat_conversa_v4_check_rate( $user_id, $conversa_id, $kind, $limit );

    return [
        'user_id'     => $user_id,
        'conversa_id' => $conversa_id,
        'request_id'  => isset( $_POST['request_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['request_id'] ) )
            : '',
    ];
}

function chat_conversa_v4_send_error( $message, $code = 'error', $status = 400, $extra = [] ) {
    wp_send_json_error(
        array_merge(
            [
                'message' => $message,
                'code'    => $code,
            ],
            $extra
        ),
        $status
    );
}

function chat_conversa_v4_check_rate( $user_id, $conversa_id, $kind, $limit ) {
    $key   = 'chat_v4_rl_' . $kind . '_' . (int) $user_id . '_' . (int) $conversa_id;
    $count = (int) get_transient( $key );

    if ( $count >= $limit ) {
        chat_conversa_v4_send_error(
            'Muitas requisições. Aguarde alguns segundos.',
            'rate_limited',
            429
        );
    }

    set_transient( $key, $count + 1, CHAT_CONVERSA_V4_RATE_WINDOW );
}

/**
 * ============================================================================
 * CCT / STATUS
 * ============================================================================
 */

function chat_conversa_v4_get_cct_table() {
    global $wpdb;

    $suffix = defined( 'CHAT_CONVERSA_CCT_TABLE' )
        ? CHAT_CONVERSA_CCT_TABLE
        : 'jet_cct_mensagens_';

    return $wpdb->prefix . $suffix;
}

function chat_conversa_v4_table_exists() {
    global $wpdb;

    $table = chat_conversa_v4_get_cct_table();

    return $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
    ) === $table;
}

function chat_conversa_v4_get_status_payload( $conversa_id ) {
    if ( ! chat_conversa_v4_table_exists() ) {
        return new WP_Error( 'cct_table_missing', 'Tabela de mensagens não encontrada.' );
    }

    global $wpdb;

    $table = chat_conversa_v4_get_cct_table();

    $total = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                MAX(_ID)        AS last_id_total,
                COUNT(*)        AS count_total,
                MAX(data_envio) AS last_changed_total
             FROM `{$table}`
             WHERE conversa_id = %d",
            $conversa_id
        ),
        ARRAY_A
    );

    $published = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                MAX(_ID)        AS last_id_published,
                COUNT(*)        AS count_published,
                MAX(data_envio) AS last_changed_published
             FROM `{$table}`
             WHERE conversa_id = %d
               AND cct_status = %s",
            $conversa_id,
            'publish'
        ),
        ARRAY_A
    );

    if ( $wpdb->last_error ) {
        return new WP_Error( 'db_error', 'Erro ao consultar status da conversa.' );
    }

    $payload = [
        'last_id_total'          => (int) ( $total['last_id_total'] ?? 0 ),
        'count_total'            => (int) ( $total['count_total'] ?? 0 ),
        'last_changed_total'     => (string) ( $total['last_changed_total'] ?? '' ),
        'last_id_published'      => (int) ( $published['last_id_published'] ?? 0 ),
        'count_published'        => (int) ( $published['count_published'] ?? 0 ),
        'last_changed_published' => (string) ( $published['last_changed_published'] ?? '' ),
    ];

    $payload['hash'] = md5( implode( '|', $payload ) );

    return $payload;
}

function chat_conversa_v4_get_messages_after( $conversa_id, $after_id, $limit ) {
    if ( ! chat_conversa_v4_table_exists() ) {
        return new WP_Error( 'cct_table_missing', 'Tabela de mensagens não encontrada.' );
    }

    global $wpdb;

    $table = chat_conversa_v4_get_cct_table();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM `{$table}`
             WHERE conversa_id = %d
               AND cct_status = %s
               AND _ID > %d
             ORDER BY _ID ASC
             LIMIT %d",
            $conversa_id,
            'publish',
            $after_id,
            $limit
        ),
        ARRAY_A
    );

    if ( $wpdb->last_error ) {
        return new WP_Error( 'db_error', 'Erro ao consultar mensagens novas.' );
    }

    return is_array( $rows ) ? $rows : [];
}

function chat_conversa_v4_get_conversa_participants( $conversa_id ) {
    return [
        'conversa_id'  => (int) $conversa_id,
        'is_artista'   => (int) get_post_meta( $conversa_id, 'is_artista', true ),
        'is_convidado' => (int) get_post_meta( $conversa_id, 'is_convidado', true ),
    ];
}

/**
 * ============================================================================
 * PREPARAÇÃO DOS DADOS PARA O MIRROR RENDERER
 * ============================================================================
 */

function chat_conversa_v4_prepare_message_item( $row, $ctx ) {
    $message_id = isset( $row['_ID'] ) ? (int) $row['_ID'] : 0;
    $from_user  = isset( $row['from_user'] ) ? (int) $row['from_user'] : 0;
    $created_at = isset( $row['data_envio'] ) ? (string) $row['data_envio'] : '';
    $status     = isset( $row['cct_status'] ) ? (string) $row['cct_status'] : '';

    $side = 'unknown';
    if ( $from_user && $from_user === (int) $ctx['is_artista'] ) {
        $side = 'artist';
    } elseif ( $from_user && $from_user === (int) $ctx['is_convidado'] ) {
        $side = 'guest';
    }

    return [
        'id'           => $message_id,
        'from_user'    => $from_user,
        'side'         => $side,
        'status'       => $status,
        'created_at'   => $created_at,
        'time_label'   => chat_conversa_v4_format_message_time( $created_at ),
        'message'      => chat_conversa_v4_extract_message_text( $row ),
        'display_name' => chat_conversa_v4_get_user_display_name( $from_user ),
        'avatar_url'   => $from_user ? get_avatar_url( $from_user, [ 'size' => 300 ] ) : '',
    ];
}

function chat_conversa_v4_extract_message_text( $row ) {
    if ( ! is_array( $row ) ) {
        return '';
    }

    $explicit = CHAT_CONVERSA_V4_MESSAGE_FIELD;

    if ( $explicit && isset( $row[ $explicit ] ) && is_scalar( $row[ $explicit ] ) ) {
        return trim( (string) $row[ $explicit ] );
    }

    $candidates = [
        'mensagem',
        'message',
        'mensagem_texto',
        'texto',
        'conteudo',
        'content',
        'chat_message',
        'mensagem_chat',
        'mensagem_conversa',
        'descricao',
        'description',
    ];

    foreach ( $candidates as $field ) {
        if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ) {
            $value = trim( (string) $row[ $field ] );
            if ( $value !== '' ) {
                return $value;
            }
        }
    }

    $skip = [
        '_ID',
        'ID',
        'conversa_id',
        'from_user',
        'to_user',
        'cct_status',
        'cct_author_id',
        'cct_created',
        'cct_modified',
        'data_envio',
        'created_at',
        'updated_at',
        'status',
    ];

    foreach ( $row as $key => $value ) {
        if ( in_array( (string) $key, $skip, true ) ) {
            continue;
        }

        if ( ! is_scalar( $value ) ) {
            continue;
        }

        $value = trim( (string) $value );

        if ( $value === '' || preg_match( '/^\d+$/', $value ) ) {
            continue;
        }

        return $value;
    }

    return '';
}

function chat_conversa_v4_format_message_time( $created_at ) {
    $created_at = trim( (string) $created_at );

    if ( $created_at === '' ) {
        return '';
    }

    $timestamp = strtotime( $created_at );

    if ( ! $timestamp ) {
        return '';
    }

    return date_i18n( 'l, d, G:i', $timestamp );
}

function chat_conversa_v4_get_user_display_name( $user_id ) {
    $user_id = (int) $user_id;

    if ( ! $user_id ) {
        return '';
    }

    $user = get_userdata( $user_id );

    if ( ! $user ) {
        return '';
    }

    return $user->display_name ?: $user->user_login;
}

/**
 * ============================================================================
 * FRONT CONFIG / RUNTIME
 * ============================================================================
 */

add_action( 'wp_head', 'chat_conversa_v4_print_config', 2 );
add_action( 'wp_footer', 'chat_conversa_v4_print_runtime', 90 );

function chat_conversa_v4_get_front_context() {
    if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
        return false;
    }

    if ( ! is_user_logged_in() || ! is_singular( 'conversa' ) ) {
        return false;
    }

    $conversa_id = (int) get_queried_object_id();
    $user_id     = (int) get_current_user_id();

    if ( ! $conversa_id || ! $user_id ) {
        return false;
    }

    $participants = chat_conversa_v4_get_conversa_participants( $conversa_id );

    $role = 'other';
    if ( $user_id === $participants['is_artista'] ) {
        $role = 'artista';
    } elseif ( $user_id === $participants['is_convidado'] ) {
        $role = 'convidado';
    }

    if ( $role === 'other' ) {
        return false;
    }

    return [
        'conversa_id'  => $conversa_id,
        'user_id'      => $user_id,
        'role'         => $role,
        'is_artista'   => $participants['is_artista'],
        'is_convidado' => $participants['is_convidado'],
    ];
}

function chat_conversa_v4_get_form_selector() {
    $ids_raw = defined( 'CHAT_CONVERSA_FORM_IDS' ) ? CHAT_CONVERSA_FORM_IDS : '';
    $ids     = array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) );

    $fallback = [
        '#footer-conversa form.jet-form-builder',
        '#footer-conversa form.chat-conversa-form',
        '#footer-conversa form[data-chat-conversa-form="1"]',
    ];

    if ( empty( $ids ) ) {
        return implode( ', ', $fallback );
    }

    $parts = [];
    foreach ( $ids as $id ) {
        $parts[] = '#footer-conversa form#jet-form-' . $id;
    }

    return implode( ', ', array_merge( $parts, $fallback ) );
}

function chat_conversa_v4_print_config() {
    $ctx = chat_conversa_v4_get_front_context();

    if ( ! $ctx ) {
        return;
    }

    $status = chat_conversa_v4_get_status_payload( $ctx['conversa_id'] );

    if ( is_wp_error( $status ) ) {
        $status = [
            'last_id_total'          => 0,
            'count_total'            => 0,
            'last_changed_total'     => '',
            'last_id_published'      => 0,
            'count_published'        => 0,
            'last_changed_published' => '',
            'hash'                   => '',
        ];
    }

    $listing_id = defined( 'CHAT_CONVERSA_LISTING_ID' ) ? CHAT_CONVERSA_LISTING_ID : 56326;

    $config = [
        'ajaxurl'           => admin_url( 'admin-ajax.php' ),
        'nonce'             => wp_create_nonce( CHAT_CONVERSA_V4_NONCE_ACTION ),
        'action_status'     => CHAT_CONVERSA_V4_AJAX_STATUS,
        'action_after'      => CHAT_CONVERSA_V4_AJAX_AFTER,
        'action_full'       => CHAT_CONVERSA_V4_AJAX_FULL,
        'conversa_id'       => $ctx['conversa_id'],
        'user_id'           => $ctx['user_id'],
        'role'              => $ctx['role'],
        'is_artista'        => $ctx['is_artista'],
        'is_convidado'      => $ctx['is_convidado'],
        'listing_id'        => $listing_id,
        'listing_selector'  => defined( 'CHAT_CONVERSA_LISTING_SELECTOR' )
            ? CHAT_CONVERSA_LISTING_SELECTOR
            : '[data-listing-id="' . $listing_id . '"]',
        'form_selector'     => chat_conversa_v4_get_form_selector(),
        'initial_status'    => $status,
        'active_poll_ms'    => 4000,
        'idle_poll_ms'      => 30000,
        'active_ttl_ms'     => 90000,
        'boot_active_ms'    => 30000,
        'dormant_after_ms'  => 600000,
        'lock_ttl_ms'       => defined( 'CHAT_CONVERSA_TAB_LOCK_TTL_MS' ) ? CHAT_CONVERSA_TAB_LOCK_TTL_MS : 9000,
        'lock_heartbeat_ms' => defined( 'CHAT_CONVERSA_TAB_HEARTBEAT_MS' ) ? CHAT_CONVERSA_TAB_HEARTBEAT_MS : 3000,
        'after_limit'       => CHAT_CONVERSA_V4_AFTER_LIMIT_DEFAULT,
        'enable_tab_lock'   => true,
        'enable_polling'    => true,
        'debug'             => false,
    ];
    ?>
    <script id="chat-conversa-v4-config">
        window.ChatConversaV4 = Object.assign(
            {},
            window.ChatConversaV4 || {},
            <?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>
        );
    </script>
    <?php
}

function chat_conversa_v4_print_runtime() {
    $ctx = chat_conversa_v4_get_front_context();

    if ( ! $ctx ) {
        return;
    }
    ?>
    <script id="chat-conversa-v4-runtime-js">
    (function () {
        "use strict";

        const cfg = window.ChatConversaV4 || {};
        if (!cfg.ajaxurl || !cfg.nonce || !cfg.conversa_id) return;
        if (window.ChatConversaRuntime && window.ChatConversaRuntime.booted) return;

        const docEl = document.documentElement;

        const MODE = {
            BOOT: "boot",
            ACTIVE: "active",
            IDLE: "idle",
            DORMANT: "dormant",
            HIDDEN: "hidden",
            SECONDARY: "secondary"
        };

        const state = {
            booted: false,
            isPrimary: false,
            mode: MODE.BOOT,
            activeUntil: 0,
            lastIdleEnteredAt: 0,
            statusInFlight: false,
            afterInFlight: false,
            fullInFlight: false,
            pollTimer: null,
            heartbeatTimer: null,
            monitorTimer: null,
            postSubmitTimers: [],
            pauseUntil: 0,
            knownHash: "",
            knownLastIdPublished: 0,
            knownCountPublished: 0,
            knownLastIdTotal: 0,
            knownCountTotal: 0,
            requestSeq: 0,
            lastAppliedSeq: 0,
            initializedFromStatus: false,
            bootCatchupAfterId: 0,
            prototypes: {
                artist: null,
                guest: null
            }
        };

        const tabId = getOrCreateTabId();
        const lockKey = "chat_conversa_lock_" + String(cfg.user_id) + "_" + String(cfg.conversa_id);

        function log() {
            if (!cfg.debug || !window.console) return;
            const args = Array.prototype.slice.call(arguments);
            args.unshift("[ChatConversaV4.1]");
            console.log.apply(console, args);
        }

        function now() {
            return Date.now();
        }

        function parseMs(value, fallback) {
            const n = parseInt(value, 10);
            return Number.isFinite(n) && n > 0 ? n : fallback;
        }

        function emit(name, detail) {
            try {
                window.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
            } catch (e) {}
        }

        function makeId() {
            if (window.crypto && typeof window.crypto.randomUUID === "function") {
                return window.crypto.randomUUID();
            }
            return "tab_" + now() + "_" + Math.random().toString(36).slice(2);
        }

        function getOrCreateTabId() {
            const key = "chat_conversa_tab_id";
            try {
                let id = window.sessionStorage.getItem(key);
                if (!id) {
                    id = makeId();
                    window.sessionStorage.setItem(key, id);
                }
                return id;
            } catch (e) {
                return makeId();
            }
        }

        function canUseLocalStorage() {
            try {
                const k = "__chat_conversa_v4_test__";
                window.localStorage.setItem(k, "1");
                window.localStorage.removeItem(k);
                return true;
            } catch (e) {
                return false;
            }
        }

        function cssEscape(value) {
            value = String(value || "");
            if (window.CSS && typeof window.CSS.escape === "function") {
                return window.CSS.escape(value);
            }
            return value.replace(/"/g, '\\"');
        }

        function queryAll(selector, root) {
            try {
                return Array.prototype.slice.call((root || document).querySelectorAll(selector));
            } catch (e) {
                return [];
            }
        }

        function matchesSelector(el, selector) {
            if (!el || !selector) return false;
            try {
                return el.matches(selector);
            } catch (e) {
                return false;
            }
        }

        function getFormElement(formLike) {
            if (!formLike) return null;
            if (formLike.nodeType === 1) return formLike;
            if (formLike[0] && formLike[0].nodeType === 1) return formLike[0];
            if (typeof formLike.get === "function") {
                const g = formLike.get(0);
                if (g && g.nodeType === 1) return g;
            }
            return null;
        }

        function buildBody(extra) {
            const p = new URLSearchParams();
            p.set("nonce", cfg.nonce);
            p.set("conversa_id", String(cfg.conversa_id));

            Object.keys(extra || {}).forEach(function (key) {
                p.set(key, String(extra[key]));
            });

            return p;
        }

        function postAjax(action, extra) {
            const body = buildBody(Object.assign({
                action: action,
                request_id: tabId + "_" + action + "_" + now()
            }, extra || {}));

            return window.fetch(cfg.ajaxurl, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                    "Cache-Control": "no-cache"
                },
                body: body.toString()
            })
                .then(function (resp) {
                    return resp.text().then(function (text) {
                        let json = null;

                        try {
                            json = JSON.parse(text);
                        } catch (e) {
                            const err = new Error("Resposta inválida");
                            err.status = resp.status;
                            err.body = text;
                            throw err;
                        }

                        if (!resp.ok || !json || json.success === false) {
                            const msg =
                                json && json.data && json.data.message
                                    ? json.data.message
                                    : "Erro AJAX";

                            const err = new Error(msg);
                            err.status = resp.status;
                            err.payload = json;
                            throw err;
                        }

                        return json.data || {};
                    });
                });
        }

        // ====================================================================
        // MAIN LISTING / PROTOTYPES
        // ====================================================================

        function getItemsContainer() {
            const byId = '#section-msgs-conversa [data-listing-id="' + String(cfg.listing_id) + '"]';

            let el = document.querySelector(byId + ".jet-listing-grid__items");
            if (el) return el;

            el = document.querySelector(byId + " .jet-listing-grid__items");
            if (el) return el;

            try {
                const listing = document.querySelector(cfg.listing_selector);
                if (listing && listing.classList.contains("jet-listing-grid__items")) return listing;
                if (listing) {
                    const items = listing.querySelector(".jet-listing-grid__items");
                    if (items) return items;
                }
            } catch (e) {}

            return document.querySelector("#section-msgs-conversa .jet-listing-grid__items");
        }

        function getTopLevelMessageRows() {
            const container = getItemsContainer();
            if (!container) return [];

            return Array.prototype.slice.call(container.children).filter(function (child) {
                return child && child.nodeType === 1 && child.classList.contains("jet-listing-grid__item");
            });
        }

        function collectPrototypes() {
            const rows = getTopLevelMessageRows();

            rows.forEach(function (row) {
                if (!state.prototypes.artist && row.querySelector(".chat-msg-card--artist")) {
                    state.prototypes.artist = row.cloneNode(true);
                }

                if (!state.prototypes.guest && row.querySelector(".chat-msg-card--guest")) {
                    state.prototypes.guest = row.cloneNode(true);
                }
            });

            return state.prototypes;
        }

        function getPrototypeForItem(item) {
            collectPrototypes();

            if (item.side === "artist") return state.prototypes.artist;
            if (item.side === "guest") return state.prototypes.guest;

            return null;
        }

        function getMaxMessageIdInDom() {
            let maxId = 0;

            getTopLevelMessageRows().forEach(function (row) {
                const id =
                    parseInt(row.getAttribute("data-message-id"), 10) ||
                    parseInt(row.getAttribute("data-post-id"), 10) ||
                    0;

                if (id > maxId) maxId = id;
            });

            return maxId;
        }

        function setText(el, value) {
            if (!el) return;
            el.textContent = String(value || "");
        }

        function updateDynamicFields(clone, item) {
            const side = item.side === "artist" ? "artist" : "guest";

            const slotSelectors = {
                guest: {
                    name: ".elementor-element-ebc134e .jet-listing-dynamic-field__content",
                    message: ".elementor-element-9a10ed6 .jet-listing-dynamic-field__content",
                    time: ".elementor-element-6fc61e3 .jet-listing-dynamic-field__content"
                },
                artist: {
                    name: ".elementor-element-1178325 .jet-listing-dynamic-field__content",
                    message: ".elementor-element-066944a .jet-listing-dynamic-field__content",
                    time: ".elementor-element-e02293e .jet-listing-dynamic-field__content"
                }
            };

            const slots = slotSelectors[side];

            let nameEl = clone.querySelector(slots.name);
            let msgEl  = clone.querySelector(slots.message);
            let timeEl = clone.querySelector(slots.time);

            const card = clone.querySelector(
                side === "artist" ? ".chat-msg-card--artist" : ".chat-msg-card--guest"
            );

            const contents = card
                ? queryAll(".elementor-widget-jet-listing-dynamic-field .jet-listing-dynamic-field__content", card)
                : [];

            if (!nameEl && contents[0]) nameEl = contents[0];
            if (!msgEl && contents[1]) msgEl = contents[1];
            if (!timeEl && contents[2]) timeEl = contents[2];

            setText(nameEl, item.display_name || "");
            setText(msgEl, item.message || "");
            setText(timeEl, item.time_label || "");
        }

        function updateRootAttrs(clone, item) {
            const oldId =
                clone.getAttribute("data-message-id") ||
                clone.getAttribute("data-post-id") ||
                "";

            const id = String(item.id || "");

            clone.setAttribute("data-message-id", id);
            clone.setAttribute("data-post-id", id);
            clone.setAttribute("data-from-user", String(item.from_user || ""));
            clone.setAttribute("data-status", String(item.status || ""));
            clone.setAttribute("data-created-at", String(item.created_at || ""));
            clone.setAttribute("data-chat-side-resolved", "1");

            Array.prototype.slice.call(clone.classList).forEach(function (cls) {
                if (cls.indexOf("jet-listing-dynamic-post-") === 0) {
                    clone.classList.remove(cls);
                }
            });

            clone.classList.add("jet-listing-dynamic-post-" + id);
            clone.classList.remove("is-chat-msg-unmatched", "is-from-artist", "is-from-guest");

            if (item.side === "artist") {
                clone.classList.add("is-from-artist");
            } else if (item.side === "guest") {
                clone.classList.add("is-from-guest");
            } else {
                clone.classList.add("is-chat-msg-unmatched");
            }

            if (oldId) {
                queryAll("[data-queried-id]", clone).forEach(function (node) {
                    const current = node.getAttribute("data-queried-id") || "";
                    const expectedPrefix = String(oldId) + "|cct:mensagens_";

                    if (current.indexOf(expectedPrefix) === 0) {
                        node.setAttribute("data-queried-id", id + "|cct:mensagens_");
                    }
                });
            }
        }

        function renderItemFromPrototype(item) {
            const proto = getPrototypeForItem(item);

            if (!proto) {
                return null;
            }

            const clone = proto.cloneNode(true);

            updateRootAttrs(clone, item);
            updateDynamicFields(clone, item);

            return clone;
        }

        function appendItems(items) {
            const container = getItemsContainer();

            if (!container || !items || !items.length) {
                return false;
            }

            let appended = 0;
            let missingPrototype = false;

            items.forEach(function (item) {
                const id = String(item.id || "");
                if (!id) return;

                const exists = document.querySelector(
                    '#section-msgs-conversa [data-message-id="' + cssEscape(id) + '"], ' +
                    '#section-msgs-conversa [data-post-id="' + cssEscape(id) + '"]'
                );

                if (exists) return;

                const node = renderItemFromPrototype(item);

                if (!node) {
                    missingPrototype = true;
                    return;
                }

                container.appendChild(node);
                appended++;
            });

            if (missingPrototype && appended === 0) {
                return "missing_prototype";
            }

            return appended > 0;
        }

        function replaceListingHtml(html) {
            const current = document.querySelector(cfg.listing_selector);
            if (!current) return false;

            const tpl = document.createElement("template");
            tpl.innerHTML = String(html || "").trim();

            let next = null;

            try {
                next = tpl.content.querySelector(cfg.listing_selector);
            } catch (e) {}

            if (!next) {
                next = tpl.content.firstElementChild;
            }

            if (!next) return false;

            current.replaceWith(next);

            state.prototypes.artist = null;
            state.prototypes.guest = null;
            collectPrototypes();

            return true;
        }

        // ====================================================================
        // STATUS LOCAL
        // ====================================================================

        function applyKnownStatus(status) {
            if (!status) return;

            state.knownHash = String(status.hash || state.knownHash || "");
            state.knownLastIdPublished = parseInt(status.last_id_published, 10) || 0;
            state.knownCountPublished = parseInt(status.count_published, 10) || 0;
            state.knownLastIdTotal = parseInt(status.last_id_total, 10) || 0;
            state.knownCountTotal = parseInt(status.count_total, 10) || 0;
            state.initializedFromStatus = true;
        }

        function initKnownStatus() {
            const initial = cfg.initial_status || {};
            const domMax = getMaxMessageIdInDom();
            const serverLast = parseInt(initial.last_id_published, 10) || 0;

            state.knownHash = String(initial.hash || "");
            state.knownLastIdPublished = domMax || serverLast || 0;
            state.knownCountPublished = parseInt(initial.count_published, 10) || 0;
            state.knownLastIdTotal = parseInt(initial.last_id_total, 10) || 0;
            state.knownCountTotal = parseInt(initial.count_total, 10) || 0;
            state.initializedFromStatus = Boolean(state.knownHash || state.knownLastIdPublished);

            if (domMax && serverLast && serverLast > domMax) {
                state.bootCatchupAfterId = domMax;
            }
        }

        // ====================================================================
        // MODE / SCHEDULER
        // ====================================================================

        function setMode(mode, reason) {
            if (state.mode === mode) return;
            state.mode = mode;
            if (mode === MODE.IDLE) state.lastIdleEnteredAt = now();
            log("mode:", mode, reason || "");
        }

        function markActive(reason, customTtl) {
            const ttl = parseMs(customTtl, parseMs(cfg.active_ttl_ms, 90000));
            state.activeUntil = now() + ttl;

            if (state.mode !== MODE.ACTIVE) {
                setMode(MODE.ACTIVE, reason || "active");
            }

            if (state.isPrimary) {
                scheduleNextPoll("active:" + (reason || ""));
            }
        }

        function maybeDowngradeMode() {
            if (!state.isPrimary) return;
            if (state.mode === MODE.HIDDEN || state.mode === MODE.SECONDARY) return;

            const t = now();

            if (state.mode === MODE.ACTIVE && t >= state.activeUntil) {
                setMode(MODE.IDLE, "active-expired");
            }

            if (state.mode === MODE.IDLE) {
                const dormantAfter = parseMs(cfg.dormant_after_ms, 600000);
                if (state.lastIdleEnteredAt > 0 && (t - state.lastIdleEnteredAt) >= dormantAfter) {
                    setMode(MODE.DORMANT, "idle-timeout");
                }
            }
        }

        function clearPollTimer() {
            if (state.pollTimer) {
                window.clearTimeout(state.pollTimer);
                state.pollTimer = null;
            }
        }

        function canPollNow() {
            if (!cfg.enable_polling) return false;
            if (!state.isPrimary) return false;
            if (state.mode === MODE.HIDDEN) return false;
            if (state.mode === MODE.SECONDARY) return false;
            if (state.mode === MODE.DORMANT) return false;
            if (state.pauseUntil && now() < state.pauseUntil) return false;
            return true;
        }

        function getCurrentPollDelay() {
            if (state.mode === MODE.ACTIVE) return parseMs(cfg.active_poll_ms, 4000);
            if (state.mode === MODE.IDLE) return parseMs(cfg.idle_poll_ms, 30000);
            return parseMs(cfg.active_poll_ms, 4000);
        }

        function scheduleNextPoll(reason) {
            clearPollTimer();

            if (!canPollNow()) return;

            state.pollTimer = window.setTimeout(function () {
                state.pollTimer = null;

                checkStatus("scheduled:" + reason).finally(function () {
                    maybeDowngradeMode();
                    scheduleNextPoll("after-check");
                });
            }, getCurrentPollDelay());
        }

        // ====================================================================
        // TAB LOCK
        // ====================================================================

        function readLock() {
            try {
                const raw = window.localStorage.getItem(lockKey);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        }

        function writeLock() {
            try {
                window.localStorage.setItem(lockKey, JSON.stringify({
                    tabId: tabId,
                    conversaId: cfg.conversa_id,
                    userId: cfg.user_id,
                    updatedAt: now(),
                    url: window.location.href,
                    visibility: document.visibilityState || "unknown",
                    runtime: "v4.1"
                }));
            } catch (e) {}
        }

        function clearOwnLock() {
            const lock = readLock();
            if (lock && lock.tabId === tabId) {
                try {
                    window.localStorage.removeItem(lockKey);
                } catch (e) {}
            }
        }

        function isLockExpired(lock) {
            const ttl = parseMs(cfg.lock_ttl_ms, 9000);
            if (!lock || !lock.updatedAt) return true;
            return now() - Number(lock.updatedAt) > ttl;
        }

        function canOwnLock() {
            const lock = readLock();
            if (!lock) return true;
            if (lock.tabId === tabId) return true;
            if (isLockExpired(lock)) return true;
            return false;
        }

        function checkOwnership(reason) {
            if (!cfg.enable_tab_lock) {
                becomePrimary("tab-lock-disabled");
                return;
            }

            if (!canUseLocalStorage()) {
                becomePrimary("no-localStorage");
                return;
            }

            if (canOwnLock()) {
                becomePrimary(reason || "can-own");
            } else {
                becomeSecondary(reason || "other-tab");
            }
        }

        function becomePrimary(reason) {
            writeLock();

            if (state.isPrimary) {
                enableChatUi();
                hideSecondaryNotice();
                startHeartbeat();
                return;
            }

            state.isPrimary = true;

            docEl.classList.add("chat-conversa-primary-tab");
            docEl.classList.remove("chat-conversa-secondary-tab");

            enableChatUi();
            hideSecondaryNotice();
            startHeartbeat();

            collectPrototypes();
            markActive(reason || "primary", parseMs(cfg.boot_active_ms, 30000));

            if (state.bootCatchupAfterId > 0) {
                fetchAfter(state.bootCatchupAfterId, "boot-catchup").finally(function () {
                    checkStatus("become-primary").finally(function () {
                        scheduleNextPoll("become-primary");
                    });
                });
            } else {
                checkStatus("become-primary").finally(function () {
                    scheduleNextPoll("become-primary");
                });
            }

            emitTabState();
        }

        function becomeSecondary(reason) {
            state.isPrimary = false;
            setMode(MODE.SECONDARY, reason || "secondary");

            docEl.classList.remove("chat-conversa-primary-tab");
            docEl.classList.add("chat-conversa-secondary-tab");

            stopHeartbeat();
            clearPollTimer();
            cancelPostSubmitTimers();

            disableChatUi();
            showSecondaryNotice();

            emitTabState();
        }

        function forceTakeOver() {
            writeLock();
            if (state.isPrimary) state.isPrimary = false;
            becomePrimary("manual-takeover");
            requestFullRefresh("manual-takeover");
        }

        function startHeartbeat() {
            stopHeartbeat();
            if (!state.isPrimary || !cfg.enable_tab_lock) return;

            state.heartbeatTimer = window.setInterval(function () {
                if (state.isPrimary) writeLock();
            }, parseMs(cfg.lock_heartbeat_ms, 3000));
        }

        function stopHeartbeat() {
            if (state.heartbeatTimer) {
                window.clearInterval(state.heartbeatTimer);
                state.heartbeatTimer = null;
            }
        }

        function startLockMonitor() {
            if (!cfg.enable_tab_lock) return;

            if (state.monitorTimer) {
                window.clearInterval(state.monitorTimer);
            }

            state.monitorTimer = window.setInterval(function () {
                if (state.isPrimary) return;

                const lock = readLock();
                if (!lock || isLockExpired(lock)) {
                    checkOwnership("monitor-expired");
                }
            }, 1500);
        }

        function emitTabState() {
            emit("ChatConversa:tabstate", {
                isPrimary: state.isPrimary,
                tabId: tabId,
                conversaId: cfg.conversa_id,
                runtime: "v4.1"
            });
        }

        // ====================================================================
        // FORM LOCK
        // ====================================================================

        function getChatForms() {
            return queryAll(cfg.form_selector);
        }

        function isChatForm(formLike) {
            const f = getFormElement(formLike);
            return matchesSelector(f, cfg.form_selector);
        }

        function disableChatUi() {
            getChatForms().forEach(function (form) {
                form.classList.add("chat-conversa-form-locked");
                form.setAttribute("aria-disabled", "true");

                queryAll("textarea", form).forEach(function (ta) {
                    if (!ta.readOnly) {
                        ta.readOnly = true;
                        ta.dataset.chatConversaReadonlyLocked = "1";
                    }
                });
            });
        }

        function enableChatUi() {
            getChatForms().forEach(function (form) {
                form.classList.remove("chat-conversa-form-locked");
                form.removeAttribute("aria-disabled");

                queryAll("[data-chat-conversa-readonly-locked='1']", form).forEach(function (el) {
                    el.readOnly = false;
                    delete el.dataset.chatConversaReadonlyLocked;
                });
            });
        }

        function blockSecondaryInteraction(event) {
            if (state.isPrimary) return false;

            event.preventDefault();
            event.stopPropagation();

            if (typeof event.stopImmediatePropagation === "function") {
                event.stopImmediatePropagation();
            }

            showSecondaryNotice();
            return true;
        }

        // ====================================================================
        // NOTICE
        // ====================================================================

        function ensureSecondaryNotice() {
            let n = document.querySelector(".chat-conversa-tab-notice");
            if (n) return n;

            n = document.createElement("div");
            n.className = "chat-conversa-tab-notice";
            n.hidden = true;
            n.setAttribute("role", "status");
            n.setAttribute("aria-live", "polite");
            n.innerHTML = [
                '<div class="chat-conversa-tab-notice__box">',
                    '<div class="chat-conversa-tab-notice__icon" aria-hidden="true"></div>',
                    '<div class="chat-conversa-tab-notice__content">',
                        '<div class="chat-conversa-tab-notice__title">Conversa aberta em outra aba</div>',
                        '<div class="chat-conversa-tab-notice__text">Esta aba está em modo leitura por segurança.</div>',
                    '</div>',
                    '<button type="button" class="chat-conversa-tab-notice__button">Assumir</button>',
                '</div>'
            ].join("");

            document.body.appendChild(n);

            const btn = n.querySelector(".chat-conversa-tab-notice__button");
            if (btn) btn.addEventListener("click", forceTakeOver);

            return n;
        }

        function showSecondaryNotice() {
            const n = ensureSecondaryNotice();
            n.hidden = false;
            window.requestAnimationFrame(function () {
                n.classList.add("is-visible");
            });
        }

        function hideSecondaryNotice() {
            const n = document.querySelector(".chat-conversa-tab-notice");
            if (!n) return;

            n.classList.remove("is-visible");

            window.setTimeout(function () {
                if (state.isPrimary) n.hidden = true;
            }, 240);
        }

        // ====================================================================
        // STATUS / SYNC
        // ====================================================================

        function checkStatus(reason) {
            if (!state.isPrimary) return Promise.resolve(false);
            if (state.pauseUntil && now() < state.pauseUntil) return Promise.resolve(false);
            if (state.statusInFlight) return Promise.resolve(false);

            state.statusInFlight = true;

            return postAjax(cfg.action_status, {})
                .then(function (data) {
                    const status = data.status || {};
                    return decideSyncFromStatus(status, reason || "status");
                })
                .catch(function (err) {
                    if (err && Number(err.status) === 429) {
                        state.pauseUntil = now() + 8000;
                    }
                    log("status fail:", err);
                    return false;
                })
                .finally(function () {
                    state.statusInFlight = false;
                });
        }

        function decideSyncFromStatus(status, reason) {
            const newHash = String(status.hash || "");
            const newLastPublished = parseInt(status.last_id_published, 10) || 0;
            const newCountPublished = parseInt(status.count_published, 10) || 0;

            if (!state.initializedFromStatus) {
                applyKnownStatus(status);
                return Promise.resolve(false);
            }

            if (!newHash || newHash === state.knownHash) {
                return Promise.resolve(false);
            }

            if (newLastPublished > state.knownLastIdPublished) {
                return fetchAfter(state.knownLastIdPublished, reason).then(function (didAppend) {
                    if (!didAppend) {
                        return requestFullRefresh("after-empty:" + reason);
                    }
                    return true;
                });
            }

            if (newCountPublished !== state.knownCountPublished || newHash !== state.knownHash) {
                return requestFullRefresh("retroactive:" + reason);
            }

            applyKnownStatus(status);
            return Promise.resolve(false);
        }

        function fetchAfter(afterId, reason) {
            if (state.afterInFlight) return Promise.resolve(false);
            if (state.pauseUntil && now() < state.pauseUntil) return Promise.resolve(false);

            state.afterInFlight = true;

            const seq = ++state.requestSeq;

            return postAjax(cfg.action_after, {
                after_id: afterId,
                limit: parseInt(cfg.after_limit, 10) || 20
            })
                .then(function (data) {
                    if (seq < state.lastAppliedSeq) return false;
                    state.lastAppliedSeq = seq;

                    const items = Array.isArray(data.items) ? data.items : [];
                    const appendResult = appendItems(items);

                    if (appendResult === "missing_prototype") {
                        return requestFullRefresh("missing-prototype:" + reason);
                    }

                    if (data.status) {
                        applyKnownStatus(data.status);
                    }

                    if (appendResult) {
                        markActive("messages-appended");

                        emit("ChatConversa:messages-appended", {
                            reason: reason || "after",
                            afterId: afterId,
                            runtime: "v4.1"
                        });

                        callLayoutNewMessage();
                        resolveSides();
                    }

                    return Boolean(appendResult);
                })
                .catch(function (err) {
                    if (err && Number(err.status) === 429) {
                        state.pauseUntil = now() + 8000;
                    }
                    log("after fail:", err);
                    return false;
                })
                .finally(function () {
                    state.afterInFlight = false;
                });
        }

        function requestFullRefresh(reason) {
            if (state.fullInFlight) return Promise.resolve(false);
            if (state.pauseUntil && now() < state.pauseUntil) return Promise.resolve(false);

            state.fullInFlight = true;

            return postAjax(cfg.action_full, {
                reason: reason || "full"
            })
                .then(function (data) {
                    if (data.status) {
                        applyKnownStatus(data.status);
                    }

                    if (!data.html) {
                        return false;
                    }

                    const didReplace = replaceListingHtml(data.html);

                    if (didReplace) {
                        emit("ChatConversa:messages-replaced", {
                            reason: reason || "full",
                            runtime: "v4.1"
                        });

                        resolveSides();
                        callLayoutNewMessage();
                    }

                    return didReplace;
                })
                .catch(function (err) {
                    if (err && Number(err.status) === 429) {
                        state.pauseUntil = now() + 8000;
                    }
                    log("full fail:", err);
                    return false;
                })
                .finally(function () {
                    state.fullInFlight = false;
                });
        }

        function resolveSides() {
            if (
                window.ChatConversaSideResolver &&
                typeof window.ChatConversaSideResolver.resolve === "function"
            ) {
                try {
                    window.ChatConversaSideResolver.resolve();
                } catch (e) {}
            }
        }

        function callLayoutNewMessage() {
            const L = window.ChatConversaLayout;

            if (L && typeof L.scrollOnNewMessage === "function") {
                L.scrollOnNewMessage();
                return;
            }

            if (L && typeof L.scrollToBottom === "function") {
                L.scrollToBottom("messages-appended");
                return;
            }

            const messages = document.querySelector("#section-msgs-conversa");
            if (messages) {
                messages.scrollTop = messages.scrollHeight;
            }
        }

        function callLayoutSubmit() {
            const L = window.ChatConversaLayout;

            if (L && typeof L.scrollOnSubmit === "function") {
                L.scrollOnSubmit();
                return;
            }

            if (L && typeof L.scrollToBottom === "function") {
                L.scrollToBottom("submit");
            }
        }

        // ====================================================================
        // POST SUBMIT
        // ====================================================================

        function cancelPostSubmitTimers() {
            state.postSubmitTimers.forEach(function (t) {
                window.clearTimeout(t);
            });
            state.postSubmitTimers = [];
        }

        function schedulePostSubmitRefresh() {
            cancelPostSubmitTimers();

            [0, 900, 1800, 3200].forEach(function (delay) {
                const t = window.setTimeout(function () {
                    if (!state.isPrimary) return;
                    checkStatus("post-submit-" + delay);
                }, delay);

                state.postSubmitTimers.push(t);
            });
        }

        // ====================================================================
        // EVENTS
        // ====================================================================

        function bindComposerIntentEvents() {
            document.addEventListener("focusin", function (e) {
                const target = e.target;
                if (!target || target.tagName !== "TEXTAREA") return;

                const form = target.form || (target.closest && target.closest("form"));
                if (!isChatForm(form)) return;
                if (!state.isPrimary) return;

                markActive("textarea-focus");
            }, true);

            document.addEventListener("input", function (e) {
                const target = e.target;
                if (!target || target.tagName !== "TEXTAREA") return;

                const form = target.form || (target.closest && target.closest("form"));
                if (!isChatForm(form)) return;
                if (!state.isPrimary) return;

                markActive("textarea-input");
            }, true);
        }

        function bindFormEvents() {
            document.addEventListener("submit", function (e) {
                const form = e.target;
                if (!isChatForm(form)) return;

                if (!state.isPrimary) {
                    blockSecondaryInteraction(e);
                    return;
                }

                markActive("submit");
                callLayoutSubmit();
                schedulePostSubmitRefresh();
            }, true);

            document.addEventListener("click", function (e) {
                const clicked = e.target && e.target.closest
                    ? e.target.closest("button, input[type='submit'], input[type='button']")
                    : null;

                if (!clicked) return;

                const form = clicked.form || clicked.closest("form");
                if (!isChatForm(form)) return;

                if (!state.isPrimary) {
                    blockSecondaryInteraction(e);
                }
            }, true);
        }

        function bindJetFormBuilderEvents() {
            if (!window.jQuery) return;

            const $doc = window.jQuery(document);

            $doc.on("jet-form-builder/ajax/on-success", function (event, response, $form) {
                if (!state.isPrimary || !isChatForm($form)) return;

                markActive("jetform-success");
                checkStatus("jetform-success");
            });

            $doc.on("jet-form-builder/ajax/on-fail jet-form-builder/ajax/processing-error",
                function (event, response, $form) {
                    if (!isChatForm($form)) return;
                    cancelPostSubmitTimers();
                }
            );
        }

        function bindWindowEvents() {
            window.addEventListener("storage", function (e) {
                if (!cfg.enable_tab_lock || e.key !== lockKey) return;

                const lock = readLock();

                if (!lock || isLockExpired(lock)) {
                    checkOwnership("storage-empty");
                    return;
                }

                if (lock.tabId === tabId) {
                    becomePrimary("storage-own");
                    return;
                }

                becomeSecondary("storage-other");
            });

            window.addEventListener("beforeunload", clearOwnLock);
            window.addEventListener("pagehide", clearOwnLock);

            window.addEventListener("pageshow", function () {
                if (!state.isPrimary) return;

                collectPrototypes();
                markActive("pageshow", parseMs(cfg.boot_active_ms, 30000));
                checkStatus("pageshow");
            });

            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === "hidden") {
                    if (state.isPrimary && state.mode !== MODE.SECONDARY) {
                        setMode(MODE.HIDDEN, "hidden");
                        clearPollTimer();
                    }
                    return;
                }

                if (state.isPrimary) {
                    writeLock();
                }

                collectPrototypes();
                checkOwnership("visible");

                if (state.isPrimary) {
                    markActive("visible", parseMs(cfg.boot_active_ms, 30000));
                    checkStatus("visible");
                }
            });
        }

        // ====================================================================
        // PUBLIC API
        // ====================================================================

        function getStateSnapshot() {
            return {
                runtime: "v4.1-mirror-renderer",
                mode: state.mode,
                isPrimary: state.isPrimary,
                knownHash: state.knownHash,
                knownLastIdPublished: state.knownLastIdPublished,
                knownCountPublished: state.knownCountPublished,
                statusInFlight: state.statusInFlight,
                afterInFlight: state.afterInFlight,
                fullInFlight: state.fullInFlight,
                hasArtistPrototype: Boolean(state.prototypes.artist),
                hasGuestPrototype: Boolean(state.prototypes.guest),
                bootCatchupAfterId: state.bootCatchupAfterId,
                tabId: tabId
            };
        }

        // ====================================================================
        // BOOT
        // ====================================================================

        function boot() {
            if (state.booted) return;
            state.booted = true;

            collectPrototypes();
            initKnownStatus();

            window.ChatConversaRuntime = {
                booted: true,
                version: "v4.1-mirror-renderer",

                checkStatus: checkStatus,
                refresh: function () {
                    return checkStatus("manual-refresh");
                },
                refreshFull: function () {
                    return requestFullRefresh("manual-full");
                },
                fetchAfter: function (afterId) {
                    return fetchAfter(parseInt(afterId, 10) || state.knownLastIdPublished, "manual-after");
                },
                recollectPrototypes: collectPrototypes,

                scrollToBottom: callLayoutNewMessage,

                isPrimary: function () {
                    return state.isPrimary;
                },

                takeOver: forceTakeOver,
                release: clearOwnLock,
                getTabId: function () {
                    return tabId;
                },
                getKnownHash: function () {
                    return state.knownHash;
                },
                getMode: function () {
                    return state.mode;
                },
                getStateSnapshot: getStateSnapshot,
                markActive: function (reason) {
                    markActive(reason || "manual-api");
                }
            };

            bindComposerIntentEvents();
            bindFormEvents();
            bindJetFormBuilderEvents();
            bindWindowEvents();

            checkOwnership("boot");
            startLockMonitor();

            console.info("[ChatConversaV4.1] Runtime incremental mirror ativo.");
        }

        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", boot);
        } else {
            boot();
        }
    })();
    </script>
    <?php
}