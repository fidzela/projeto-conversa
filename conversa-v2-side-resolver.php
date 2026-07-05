<?php

/**
 * [Conversa v2] Side Resolver
 *
 * Responsabilidade:
 *  - Decidir qual card de mensagem aparece (artista vs convidado).
 *  - Estratégia CSS-first: o servidor já conhece os IDs dos participantes,
 *    então geramos regras CSS específicas. JS é só fallback mínimo.
 *
 * Mudanças em relação à v1:
 *  - Gate via Context Guard (delegado, não duplicado).
 *  - Removidos os seletores genéricos .user-pro / .user-guest do CSS base
 *    (eram nomes muito comuns, podiam colidir com outros plugins).
 *    Mantidos APENAS sob escopo de #section-msgs-conversa [data-from-user]
 *    pra preservar compatibilidade com markup atual, mas sem afetar
 *    nada fora da área de mensagens.
 *  - IDs vêm do Context Guard, não de get_post_meta duplicada.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================================
 * HOOKS
 * ============================================================================
 */

if (
    function_exists( 'chat_conversa_can_register_hooks' )
    && chat_conversa_can_register_hooks()
) {
    add_action( 'wp_head',   'chat_conversa_side_resolver_print_styles', 45 );
    add_action( 'wp_footer', 'chat_conversa_side_resolver_print_script', 115 );
}

/**
 * ============================================================================
 * CSS
 * ============================================================================
 *
 * O CSS faz 95% do trabalho. JS é fallback.
 * Regras escopadas em #section-msgs-conversa — nunca vazam pra outras áreas.
 */

function chat_conversa_side_resolver_print_styles() {
    if ( ! function_exists( 'chat_conversa_context' ) ) {
        return;
    }

    $ctx = chat_conversa_context();
    if ( ! $ctx ) {
        return;
    }

    $is_artista   = (int) $ctx['is_artista'];
    $is_convidado = (int) $ctx['is_convidado'];
    ?>
    <style id="chat-conversa-side-resolver-css">
        /* Helper interno que carrega o ID — não deve aparecer. */
        #section-msgs-conversa .chat-msg-from-user {
            display: none !important;
        }

        /* Estado base: esconde todos os cards até o resolver decidir. */
        #section-msgs-conversa .chat-msg-card--artist,
        #section-msgs-conversa .chat-msg-card--guest,
        #section-msgs-conversa [data-from-user] .user-pro,
        #section-msgs-conversa [data-from-user] .user-guest {
            display: none !important;
        }

        /* ========================================================
         * RESOLUÇÃO CSS — sem JS necessário no caminho feliz.
         * Cada item do listing carrega data-from-user com o ID
         * de quem enviou. O CSS casa esse ID com is_artista ou
         * is_convidado da conversa e mostra o card correto.
         * ====================================================== */

        <?php if ( $is_artista > 0 ) : ?>
        #section-msgs-conversa [data-from-user="<?php echo esc_attr( $is_artista ); ?>"] .chat-msg-card--artist,
        #section-msgs-conversa [data-from-user="<?php echo esc_attr( $is_artista ); ?>"] .user-pro,
        #section-msgs-conversa .is-from-artist .chat-msg-card--artist,
        #section-msgs-conversa .is-from-artist .user-pro {
            display: flex !important;
        }
        <?php endif; ?>

        <?php if ( $is_convidado > 0 ) : ?>
        #section-msgs-conversa [data-from-user="<?php echo esc_attr( $is_convidado ); ?>"] .chat-msg-card--guest,
        #section-msgs-conversa [data-from-user="<?php echo esc_attr( $is_convidado ); ?>"] .user-guest,
        #section-msgs-conversa .is-from-guest .chat-msg-card--guest,
        #section-msgs-conversa .is-from-guest .user-guest {
            display: flex !important;
        }
        <?php endif; ?>

        /* Fallback de segurança: row sem match conhecido mostra ambos cards
         * (melhor exibir a mais do que esconder mensagem inteira). */
        #section-msgs-conversa .is-chat-msg-unmatched .chat-msg-card--artist,
        #section-msgs-conversa .is-chat-msg-unmatched .chat-msg-card--guest,
        #section-msgs-conversa .is-chat-msg-unmatched .user-pro,
        #section-msgs-conversa .is-chat-msg-unmatched .user-guest {
            display: flex !important;
        }
    </style>
    <?php
}

/**
 * ============================================================================
 * JS - fallback
 * ============================================================================
 *
 * Só faz trabalho real quando uma row NÃO tem data-from-user direto.
 * Nesse caso, lê do .chat-msg-from-user interno e replica no row pai.
 *
 * Em listings que já carregam data-from-user no item raiz (caminho feliz),
 * este JS só verifica e marca como resolvido — custo ~zero.
 */

function chat_conversa_side_resolver_print_script() {
    if ( ! function_exists( 'chat_conversa_context' ) ) {
        return;
    }

    $ctx = chat_conversa_context();
    if ( ! $ctx ) {
        return;
    }

    $config = [
        'is_artista'   => (int) $ctx['is_artista'],
        'is_convidado' => (int) $ctx['is_convidado'],
        'selectors'    => [
            'messages'  => '#section-msgs-conversa',
            'row'       => '.chat-msg-row, .jet-listing-grid__item',
            'from_user' => '.chat-msg-from-user, [data-from-user], [data-chat-from-user]',
        ],
    ];
    ?>
    <script id="chat-conversa-side-resolver-js">
    (function () {
        "use strict";

        const cfg = <?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
        const sel = cfg.selectors || {};
        const artistaId = parseInt(cfg.is_artista, 10) || 0;
        const convidadoId = parseInt(cfg.is_convidado, 10) || 0;

        if (window.ChatConversaSideResolver && window.ChatConversaSideResolver.booted) {
            return;
        }

        const state = { booted: false, pending: false };

        function toId(value) {
            const raw = String(value || "").trim();
            if (!raw) return 0;
            const m = raw.match(/\d+/);
            return m ? (parseInt(m[0], 10) || 0) : 0;
        }

        function queryRows(root) {
            const scope = root && root.nodeType === 1 ? root : document;
            try {
                return Array.prototype.slice.call(scope.querySelectorAll(sel.row));
            } catch (e) {
                return [];
            }
        }

        function readFromUser(row) {
            if (!row) return 0;

            const direct =
                row.getAttribute("data-from-user") ||
                row.getAttribute("data-chat-from-user");
            if (direct) return toId(direct);

            let source = null;
            try { source = row.querySelector(sel.from_user); } catch (e) {}
            if (!source) return 0;

            const data =
                source.getAttribute("data-from-user") ||
                source.getAttribute("data-chat-from-user") ||
                source.textContent;

            return toId(data);
        }

        function resolveRow(row) {
            if (row.dataset.chatSideResolved === "1") return;

            // Caminho rápido: row já tem data-from-user, CSS resolve.
            if (
                row.getAttribute("data-from-user") ||
                row.getAttribute("data-chat-from-user")
            ) {
                row.dataset.chatSideResolved = "1";
                return;
            }

            const fromUser = readFromUser(row);

            if (fromUser && artistaId && fromUser === artistaId) {
                row.classList.add("is-from-artist");
            } else if (fromUser && convidadoId && fromUser === convidadoId) {
                row.classList.add("is-from-guest");
            } else if (fromUser === 0) {
                row.classList.add("is-chat-msg-unmatched");
            }

            row.setAttribute("data-from-user", String(fromUser || ""));
            row.dataset.chatSideResolved = "1";
        }

        function resolveAll(root) {
            const rows = queryRows(root);
            for (let i = 0; i < rows.length; i++) {
                resolveRow(rows[i]);
            }
        }

        function scheduleResolve() {
            if (state.pending) return;
            state.pending = true;
            window.requestAnimationFrame(function () {
                state.pending = false;
                resolveAll(document);
            });
        }

        function bindEvents() {
            // Runtime substituiu o listing → resolve as rows novas.
            window.addEventListener("ChatConversa:messages-replaced", scheduleResolve);
        }

        function boot() {
            if (state.booted) return;
            state.booted = true;

            resolveAll(document);
            bindEvents();

            window.ChatConversaSideResolver = {
                booted: true,
                resolve: function () { resolveAll(document); }
            };
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