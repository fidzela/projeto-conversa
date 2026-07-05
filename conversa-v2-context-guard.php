<?php

/**
 * [Conversa v2] Context Guard
 *
 * Responsabilidade ÚNICA:
 *  - Decidir se estamos num contexto onde o sistema de chat deve atuar.
 *  - Fornecer dados de contexto (conversa_id, user_id, ids dos participantes)
 *    pros demais arquivos.
 *
 * NÃO faz:
 *  - Validação de permissão pesada (JetEngine + page rules cuidam disso).
 *  - Render de HTML/CSS/JS.
 *  - Acesso a CCT/banco de mensagens.
 *
 * Estratégia em duas camadas:
 *  - chat_conversa_can_register_hooks(): gate LEVE, roda antes da query
 *    principal estar pronta. Usado pra decidir se anexamos hooks
 *    em wp_head/wp_footer/enqueue. Bloqueia admin, REST, AJAX, cron,
 *    editores de page builder.
 *  - chat_conversa_context(): gate COMPLETO, exige que a query principal
 *    esteja resolvida. Retorna false ou array com dados da conversa.
 *    Usado pelas callbacks que de fato imprimem conteúdo.
 *
 * Todos os outros arquivos do projeto consomem essas duas funções.
 * Não duplicar lógica de gate em lugar nenhum.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 * CONSTANTES GLOBAIS DO PROJETO
 * ============================================================================
 * Centralizadas aqui porque vários arquivos precisam delas.
 * Defines protegidos com if(!defined()) pra ninguém pisar caso queira
 * sobrescrever em wp-config.php.
 */

if ( ! defined( 'CHAT_CONVERSA_CPT' ) ) {
    define( 'CHAT_CONVERSA_CPT', 'conversa' );
}

if ( ! defined( 'CHAT_CONVERSA_CCT_TABLE' ) ) {
    define( 'CHAT_CONVERSA_CCT_TABLE', 'jet_cct_mensagens_' );
}

if ( ! defined( 'CHAT_CONVERSA_LISTING_ID' ) ) {
    define( 'CHAT_CONVERSA_LISTING_ID', 56326 );
}

if ( ! defined( 'CHAT_CONVERSA_FORM_IDS' ) ) {
    // IDs dos forms JetFormBuilder que o composer transforma.
    define( 'CHAT_CONVERSA_FORM_IDS', '56386' );
}

if ( ! defined( 'CHAT_CONVERSA_LAST_MESSAGE_META' ) ) {
    define( 'CHAT_CONVERSA_LAST_MESSAGE_META', 'last_message_at' );
}

/**
 * ============================================================================
 * GATE LEVE - antes da query principal
 * ============================================================================
 *
 * Use em add_action() condicional:
 *
 *   if ( chat_conversa_can_register_hooks() ) {
 *       add_action( 'wp_head', 'meu_callback' );
 *   }
 *
 * Esta função NÃO chama is_singular() porque ela é chamada cedo demais.
 * Ela só descarta contextos onde nem faz sentido registrar nada.
 */
if ( ! function_exists( 'chat_conversa_can_register_hooks' ) ) {

    function chat_conversa_can_register_hooks() {

        if ( is_admin() ) {
            return false;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return false;
        }

        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return false;
        }

        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return false;
        }

        // Editor / preview do Elementor identificáveis via $_GET
        // (não dá pra chamar Elementor\Plugin::$instance aqui ainda).
        if ( ! empty( $_GET['elementor-preview'] ) ) {
            return false;
        }

        if ( ! empty( $_GET['elementor_library'] ) ) {
            return false;
        }

        if (
            isset( $_GET['action'] )
            && in_array(
                wp_unslash( $_GET['action'] ),
                [ 'elementor', 'elementor_ajax' ],
                true
            )
        ) {
            return false;
        }

        // Block editor (Gutenberg) preview
        if ( isset( $_GET['context'] ) && $_GET['context'] === 'edit' ) {
            return false;
        }

        // Outros page builders (rede de segurança)
        if ( ! empty( $_GET['fl_builder'] ) ) {
            return false;
        }

        if ( ! empty( $_GET['et_fb'] ) ) {
            return false;
        }

        if ( ! empty( $_GET['vc_editable'] ) ) {
            return false;
        }

        return true;
    }
}

/**
 * ============================================================================
 * GATE COMPLETO - após query principal
 * ============================================================================
 *
 * Use dentro de callbacks de wp_head/wp_footer/enqueue:
 *
 *   function meu_callback() {
 *       $ctx = chat_conversa_context();
 *       if ( ! $ctx ) return;
 *       // usa $ctx['conversa_id'], $ctx['user_id'], etc.
 *   }
 *
 * Retorno (quando válido):
 *   [
 *       'conversa_id'   => int,
 *       'user_id'       => int,
 *       'is_artista'    => int,
 *       'is_convidado'  => int,
 *       'role'          => 'artista'|'convidado'|'other',
 *   ]
 *
 * O 'role' = 'other' só ocorre se o usuário não bate com nenhum dos dois
 * IDs. Como a validação de acesso à página é feita upstream pelo JetEngine
 * /page rules, esse caso normalmente nem chega aqui — mas o role 'other'
 * é informativo, não bloqueante. Quem quiser bloquear, checa role.
 *
 * Cache estático por request: a função é chamada várias vezes (uma vez
 * por arquivo que precisar do contexto). O cache evita reexecutar tudo.
 * O cache só é preenchido APÓS o action 'wp' rodar (quando is_singular
 * é confiável). Antes disso, retorna sem cachear.
 */
if ( ! function_exists( 'chat_conversa_context' ) ) {

    function chat_conversa_context() {
        static $cached = null;

        if ( $cached !== null ) {
            return $cached;
        }

        $result = chat_conversa_compute_context();

        if ( did_action( 'wp' ) ) {
            $cached = $result;
        }

        return $result;
    }

    function chat_conversa_compute_context() {

        // Gate leve primeiro - se nem o leve passa, não tem como passar o pesado.
        if ( ! chat_conversa_can_register_hooks() ) {
            return false;
        }

        // Editores do Elementor que só conseguimos detectar via API depois do init.
        if ( class_exists( '\Elementor\Plugin' ) ) {
            $elementor = \Elementor\Plugin::$instance;

            if ( $elementor ) {
                if (
                    ! empty( $elementor->editor )
                    && method_exists( $elementor->editor, 'is_edit_mode' )
                    && $elementor->editor->is_edit_mode()
                ) {
                    return false;
                }

                if (
                    ! empty( $elementor->preview )
                    && method_exists( $elementor->preview, 'is_preview_mode' )
                    && $elementor->preview->is_preview_mode()
                ) {
                    return false;
                }
            }
        }

        // Customizer
        if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
            return false;
        }

        // Precisa estar numa página single do CPT 'conversa'.
        if ( ! function_exists( 'is_singular' ) || ! is_singular( CHAT_CONVERSA_CPT ) ) {
            return false;
        }

        // Precisa estar logado. (Permissão fina = upstream.)
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $conversa_id = (int) get_queried_object_id();
        $user_id     = (int) get_current_user_id();

        if ( ! $conversa_id || ! $user_id ) {
            return false;
        }

        $is_artista   = (int) get_post_meta( $conversa_id, 'is_artista', true );
        $is_convidado = (int) get_post_meta( $conversa_id, 'is_convidado', true );

        $role = 'other';
        if ( $user_id === $is_artista ) {
            $role = 'artista';
        } elseif ( $user_id === $is_convidado ) {
            $role = 'convidado';
        }

        return [
            'conversa_id'  => $conversa_id,
            'user_id'      => $user_id,
            'is_artista'   => $is_artista,
            'is_convidado' => $is_convidado,
            'role'         => $role,
        ];
    }
}

/**
 * ============================================================================
 * HELPER PRÁTICO - reset de cache
 * ============================================================================
 *
 * Em circunstâncias raríssimas (ex: testes, hot-reload de meta) pode ser
 * útil invalidar o cache estático. Não é necessário no fluxo normal.
 */
if ( ! function_exists( 'chat_conversa_context_reset' ) ) {

    function chat_conversa_context_reset() {
        // Invalida cache estático criando uma nova closure de contexto.
        // Implementação simples: força recomputação na próxima chamada
        // via um wrapper. Como cache é static dentro da função, a única
        // forma "limpa" é não cachear neste request — não há necessidade
        // real. Função existe pra documentação/extensibilidade futura.
    }
}