<?php

/**
 * [Conversa v3] Layout
 *
 * Responsabilidades:
 *  - Estruturar a single 'conversa' como chat (header / msgs / footer).
 *  - Manter mensagens recentes no fim visualmente.
 *  - Decidir scroll por CONTEXTO EXPLÍCITO, não por inferência DOM.
 *
 * MUDANÇA CENTRAL EM RELAÇÃO À V2:
 *
 *  v2 tinha UMA função `scrollToBottom(reason)` que decidia FORCE/RESPECT
 *  por regex no nome do reason. Múltiplos observers (Mutation, Resize, img
 *  load) chamavam scroll por inferência de DOM, gerando o bug do
 *  "segundo scroll fantasma" 10-15s após o boot.
 *
 *  v3 expõe FUNÇÕES ESPECÍFICAS POR CONTEXTO. Cada situação chama a sua
 *  própria. Sem inferência. Sem observers automáticos disparando scroll.
 *
 * API PÚBLICA NOVA (cada uma com comportamento próprio):
 *
 *   Layout.scrollOnBoot()             força, 1 scroll + 1 retry em 250ms
 *   Layout.scrollOnSubmit()           força, sem retry
 *   Layout.scrollOnNewMessage()       respeita sticky (rola só se já no fim)
 *   Layout.scrollOnPageshow()         força
 *   Layout.scrollOnTakeover()         força (virou aba primária via tab lock)
 *   Layout.scrollOnComposerExpand()   só se sticky=true
 *   Layout.scrollOnReStick()          interno do timer de 60s
 *
 * API LEGADA (mantida pra compat com Runtime v2 que ainda esteja instalado):
 *
 *   Layout.scrollToBottom(reason)     redireciona pra scrollOnNewMessage
 *                                     (RESPECT). Eventos com nomes "fortes"
 *                                     (boot, submit, pageshow, takeover)
 *                                     redirecionam pras funções
 *                                     específicas equivalentes.
 *
 * O QUE SAIU DA V2:
 *  - MutationObserver de mensagens (causava scroll fantasma).
 *  - bindImageLoads (imagens lazy disparavam scroll inesperado).
 *  - ResizeObserver chamando scroll automático (idem).
 *  - Submit grace de 5s acoplado a messages-replaced.
 *  - Lógica de decisão por regex no reason.
 *
 * O QUE FICOU:
 *  - state.stickToBottom como fonte da verdade.
 *  - bindHumanInteraction (wheel/touch/keydown/scroll humano).
 *  - Timer de re-stick de 60s.
 *  - CSS intacto.
 *  - Viewport events (resize/orientation/pageshow/visibilitychange).
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
    add_action( 'wp_head',   'chat_conversa_layout_print_styles', 40 );
    add_action( 'wp_footer', 'chat_conversa_layout_print_script', 110 );
}

/**
 * ============================================================================
 * CSS — idêntico à v2 (estrutura de chat, sticky-bottom via flex)
 * ============================================================================
 */

function chat_conversa_layout_print_styles() {
    if ( ! function_exists( 'chat_conversa_context' ) || ! chat_conversa_context() ) {
        return;
    }
    ?>
    <style id="chat-conversa-layout-css">
        body.single-conversa:not(.elementor-editor-active) {
            overflow: hidden;
        }

        #parent-section-conversa {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            height: var(--chat-conversa-vh, 100dvh) !important;
            min-height: var(--chat-conversa-vh, 100dvh) !important;
            max-height: var(--chat-conversa-vh, 100dvh) !important;
            overflow: hidden !important;
        }

        body.admin-bar #parent-section-conversa {
            height: calc(var(--chat-conversa-vh, 100dvh) - 32px) !important;
            min-height: calc(var(--chat-conversa-vh, 100dvh) - 32px) !important;
            max-height: calc(var(--chat-conversa-vh, 100dvh) - 32px) !important;
        }

        #header-conversa {
            flex: 0 0 auto !important;
            width: 100% !important;
            z-index: 30;
 
        }

        #section-msgs-conversa {
            position: relative;
            flex: 1 1 auto !important;
            width: 100% !important;
            min-height: 0 !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
            overscroll-behavior: contain;
            scroll-behavior: smooth;
            scrollbar-gutter: stable;
        }

        #footer-conversa {
            flex: 0 0 auto !important;
            width: 100% !important;
            z-index: 40;
        }

        #section-msgs-conversa > .e-con-inner,
        #section-msgs-conversa > .elementor-container,
        #section-msgs-conversa > .elementor-widget-wrap,
        #section-msgs-conversa > .elementor-widget-container {
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            width: 100% !important;
            min-height: 100% !important;
        }

        #section-msgs-conversa > * {
            min-width: 0;
        }

        #section-msgs-conversa [data-listing-id],
        #section-msgs-conversa .jet-listing-grid,
        #section-msgs-conversa .jet-listing-grid__items {
            width: 100% !important;
        }

        #section-msgs-conversa [data-listing-id] {
            margin-top: auto !important;
        }

        #section-msgs-conversa .jet-listing-grid__items {
            display: flex !important;
            flex-direction: column !important;
            justify-content: flex-end !important;
            min-height: 100% !important;
        }

        #section-msgs-conversa .jet-listing-grid__item {
            flex: 0 0 auto !important;
        }

        #section-msgs-conversa .jet-listing-grid__loadmore {
            order: -1;
            margin: 8px auto 12px;
        }

        #section-msgs-conversa::-webkit-scrollbar { width: 8px; }
        #section-msgs-conversa::-webkit-scrollbar-track { background: transparent; }
        #section-msgs-conversa::-webkit-scrollbar-thumb {
            background: #e5e7eb;
            border-radius: 100px;
        }
        #section-msgs-conversa::-webkit-scrollbar-thumb:hover { background: #d1d5db; }

        @media (max-width: 782px) {
            body.admin-bar #parent-section-conversa {
                height: calc(var(--chat-conversa-vh, 100dvh) - 46px) !important;
                min-height: calc(var(--chat-conversa-vh, 100dvh) - 46px) !important;
                max-height: calc(var(--chat-conversa-vh, 100dvh) - 46px) !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            #section-msgs-conversa { scroll-behavior: auto; }
        }
    </style>
    <?php
}

/**
 * ============================================================================
 * JS — refatoração completa
 * ============================================================================
 */

function chat_conversa_layout_print_script() {
    if ( ! function_exists( 'chat_conversa_context' ) || ! chat_conversa_context() ) {
        return;
    }
    ?>
    <script id="chat-conversa-layout-js">
    (function () {
        "use strict";

        if (window.ChatConversaLayout && window.ChatConversaLayout.booted) return;

        // ====================================================================
        // CONSTANTES
        // ====================================================================

        const SEL_PARENT   = "#parent-section-conversa";
        const SEL_MSGS     = "#section-msgs-conversa";
        const SEL_FORM     = "#footer-conversa form.jet-form-builder, " +
                             "#footer-conversa form.chat-conversa-form, " +
                             "#footer-conversa form[data-chat-conversa-form='1']";

        const STICK_THRESHOLD_PX = 80;
        const RE_STICK_AFTER_MS = 60000;
        const HUMAN_SCROLL_WINDOW_MS = 500;
        const BOOT_RETRY_DELAY_MS = 250;

        // ====================================================================
        // STATE
        // ====================================================================

        const state = {
            booted: false,
            stickToBottom: true,
            lastHumanInteractionAt: 0,
            reStickTimer: null,
            scrollHandlerRaf: null,
            visible: document.visibilityState !== "hidden",
            bootDone: false
        };

        // ====================================================================
        // DOM HELPERS
        // ====================================================================

        function getMessages() { return document.querySelector(SEL_MSGS); }

        function setViewportHeight() {
            const h = window.innerHeight || document.documentElement.clientHeight;
            document.documentElement.style.setProperty("--chat-conversa-vh", h + "px");
        }

        function distanceFromBottom() {
            const m = getMessages();
            if (!m) return 0;
            return m.scrollHeight - m.scrollTop - m.clientHeight;
        }

        function isPhysicallyAtBottom() {
            return distanceFromBottom() <= STICK_THRESHOLD_PX;
        }

        // ====================================================================
        // STICKY STATE
        // ====================================================================

        function setSticky(value) {
            value = Boolean(value);
            if (state.stickToBottom === value) return;
            state.stickToBottom = value;
            if (value) stopReStickTimer();
            else       startReStickTimer();
        }

        function startReStickTimer() {
            stopReStickTimer();
            state.reStickTimer = window.setTimeout(function () {
                state.reStickTimer = null;
                setSticky(true);
                _scrollNow();
            }, RE_STICK_AFTER_MS);
        }

        function stopReStickTimer() {
            if (state.reStickTimer) {
                window.clearTimeout(state.reStickTimer);
                state.reStickTimer = null;
            }
        }

        // ====================================================================
        // CORE: AS ÚNICAS DUAS FUNÇÕES QUE TOCAM EM scrollTop
        // ====================================================================

        /**
         * Aplica scrollTop = scrollHeight imediatamente.
         * Não modifica state.stickToBottom.
         * Esta é a função de mais baixo nível. Tudo abaixo passa por aqui.
         */
        function _scrollNow() {
            const m = getMessages();
            if (!m) return;
            m.scrollTop = m.scrollHeight;
        }

        /**
         * Aplica _scrollNow() no próximo frame.
         * Útil quando o caller acabou de mexer no DOM e quer esperar
         * o layout estabilizar.
         */
        function _scrollNextFrame() {
            window.requestAnimationFrame(_scrollNow);
        }

        // ====================================================================
        // API PÚBLICA: UMA FUNÇÃO POR CONTEXTO
        // ====================================================================

        /**
         * Chamada UMA vez no boot da página. Força scroll com 1 retry
         * pra cobrir listing/imagens com render assíncrono.
         *
         * Após o retry, NÃO faz mais nada de scroll por conta de boot.
         * Marca state.bootDone = true.
         */
        function scrollOnBoot() {
            if (state.bootDone) return;

            setSticky(true);
            _scrollNow();
            _scrollNextFrame();

            window.setTimeout(function () {
                if (state.stickToBottom) _scrollNow();
                state.bootDone = true;
            }, BOOT_RETRY_DELAY_MS);
        }

        /**
         * Usuário enviou mensagem. Sempre força.
         */
        function scrollOnSubmit() {
            setSticky(true);
            _scrollNow();
            _scrollNextFrame();
            window.setTimeout(function () {
                if (state.stickToBottom) _scrollNow();
            }, 150);
        }

        /**
         * Mensagem nova confirmada pelo Runtime (hash mudou + HTML diferente).
         * RESPEITA sticky: só rola se o usuário já estava no fim.
         * Se o usuário rolou pra cima, NÃO interrompe a leitura.
         */
        function scrollOnNewMessage() {
            if (!state.stickToBottom) return;
            _scrollNextFrame();
        }

        /**
         * Volta pra aba via back/forward cache do navegador.
         */
        function scrollOnPageshow() {
            setSticky(true);
            _scrollNow();
            _scrollNextFrame();
        }

        /**
         * Esta aba virou primária via tab lock (NÃO no boot — boot tem
         * sua própria função). Acontece em takeover de outra aba.
         */
        function scrollOnTakeover() {
            setSticky(true);
            _scrollNow();
            _scrollNextFrame();
        }

        /**
         * Composer (textarea) cresceu. O viewport útil das mensagens
         * diminuiu. Se sticky=true, mantém o fim visível.
         * Se o usuário tá lendo histórico (sticky=false), não interfere.
         */
        function scrollOnComposerExpand() {
            if (!state.stickToBottom) return;
            _scrollNextFrame();
        }

        // ====================================================================
        // INTERAÇÃO HUMANA
        // ====================================================================

        function markHumanInteraction() {
            state.lastHumanInteractionAt = Date.now();
        }

        function wasRecentHumanInteraction() {
            return (Date.now() - state.lastHumanInteractionAt) < HUMAN_SCROLL_WINDOW_MS;
        }

        function bindHumanInteraction() {
            const m = getMessages();
            if (!m) return;

            const evts = ["wheel", "touchstart", "touchmove", "keydown"];
            evts.forEach(function (evt) {
                m.addEventListener(evt, markHumanInteraction, { passive: true });
            });

            // Scroll handler: detecta sticky por POSIÇÃO + INTERAÇÃO HUMANA.
            // Scroll programático (nosso _scrollNow) não dispara wheel/touch
            // e portanto NÃO é confundido com ação do usuário.
            m.addEventListener("scroll", function () {
                if (state.scrollHandlerRaf) return;
                state.scrollHandlerRaf = window.requestAnimationFrame(function () {
                    state.scrollHandlerRaf = null;

                    const atBottom = isPhysicallyAtBottom();

                    if (atBottom && !state.stickToBottom) {
                        // Voltou pro fim (manualmente ou via scroll automático).
                        setSticky(true);
                    } else if (!atBottom && state.stickToBottom) {
                        // Saiu do fim. Só desliga sticky se humano interagiu
                        // recentemente. Scroll programático não dispara.
                        if (wasRecentHumanInteraction()) setSticky(false);
                    }
                });
            }, { passive: true });
        }

        // ====================================================================
        // SUBMIT INTERCEPT
        // ====================================================================

        function bindSubmitScroll() {
            document.addEventListener("submit", function (event) {
                const form = event.target;
                if (!form || !form.matches || !form.matches(SEL_FORM)) return;
                scrollOnSubmit();
            }, true);
        }

        // ====================================================================
        // RUNTIME EVENTS (legacy + novos)
        // ====================================================================

        function bindRuntimeEvents() {
            // Mensagens substituídas pelo Runtime (após hash novo confirmado).
            // O Runtime v3 vai chamar scrollOnNewMessage diretamente, mas
            // mantemos esse listener pra compat com Runtime v2 instalado.
            window.addEventListener("ChatConversa:messages-replaced", function () {
                scrollOnNewMessage();
            });

            // Load-more do JetEngine. Usuário está lendo histórico —
            // marca interação humana pra impedir re-stick acidental.
            // Nenhum scroll forçado.
            if (window.jQuery) {
                window.jQuery(document).on(
                    "jet-engine/listing-grid/after-load-more " +
                    "jet-engine/listing/load-more/loaded",
                    markHumanInteraction
                );
            }
        }

        // ====================================================================
        // VIEWPORT EVENTS
        // ====================================================================

        function bindViewportEvents() {
            let resizeRaf = null;

            window.addEventListener("resize", function () {
                if (resizeRaf) return;
                resizeRaf = window.requestAnimationFrame(function () {
                    resizeRaf = null;
                    setViewportHeight();
                    if (state.stickToBottom) _scrollNow();
                });
            });

            window.addEventListener("orientationchange", function () {
                window.setTimeout(function () {
                    setViewportHeight();
                    if (state.stickToBottom) _scrollNow();
                }, 250);
            });

            window.addEventListener("pageshow", function () {
                setViewportHeight();
                scrollOnPageshow();
            });

            document.addEventListener("visibilitychange", function () {
                state.visible = document.visibilityState === "visible";
                if (!state.visible) return;
                setViewportHeight();
                // Não força scroll só por voltar a aba visível —
                // Runtime decide via hash se há mensagem nova.
                if (state.stickToBottom) _scrollNow();
            });
        }

        // ====================================================================
        // ROTEAMENTO DA API LEGADA
        // ====================================================================

        /**
         * scrollToBottom(reason) — API antiga do Layout v2. Mantida só
         * pra não quebrar Runtime v2 que esteja instalado em paralelo
         * durante a transição.
         *
         * Mapeamento conservador:
         *   reason começa com "boot"                  → scrollOnBoot
         *   reason começa com "submit"                → scrollOnSubmit
         *   reason começa com "pageshow"              → scrollOnPageshow
         *   reason começa com "become-primary"        → scrollOnTakeover
         *   reason começa com "primary-existing"      → scrollOnTakeover
         *   reason começa com "orientation"           → scrollOnPageshow
         *   reason começa com "manual-api"            → scrollOnTakeover
         *   tudo o mais                               → scrollOnNewMessage
         *                                                (respeita sticky)
         *
         * O ponto-chave: nomes que ANTES caíam em FORCE indiscriminado
         * (refresh-start, refresh-end, listing-replaced, listing-not-found,
         * after-submit, visible, etc) agora caem em scrollOnNewMessage,
         * que respeita sticky. Isso elimina o "segundo scroll fantasma"
         * mesmo se o Runtime v2 não for atualizado ainda.
         */
        function legacyScrollToBottom(reason) {
            const r = String(reason || "");

            if (r.indexOf("boot") === 0) {
                scrollOnBoot();
                return;
            }
            if (r.indexOf("submit") === 0) {
                scrollOnSubmit();
                return;
            }
            if (r.indexOf("pageshow") === 0 || r.indexOf("orientation") === 0) {
                scrollOnPageshow();
                return;
            }
            if (
                r.indexOf("become-primary")    === 0 ||
                r.indexOf("primary-existing")  === 0 ||
                r.indexOf("manual-api")        === 0
            ) {
                scrollOnTakeover();
                return;
            }

            // Tudo o resto: RESPECT sticky. Inclui:
            //  - refresh-start-*  (não deveria rolar — ainda nem chegou HTML)
            //  - refresh-end-*    (rola só se já estava no fim)
            //  - listing-replaced (idem)
            //  - listing-not-found / invalid-listing-html (nada)
            //  - after-submit / visible / become-secondary
            scrollOnNewMessage();
        }

        // ====================================================================
        // BOOT
        // ====================================================================

        function boot() {
            if (state.booted) return;
            const parent = document.querySelector(SEL_PARENT);
            const messages = getMessages();
            if (!parent || !messages) return;

            state.booted = true;

            setViewportHeight();
            bindHumanInteraction();
            bindSubmitScroll();
            bindRuntimeEvents();
            bindViewportEvents();

            // Scroll inicial. Único momento que o Layout rola sozinho
            // sem ser pedido por evento externo ou ação humana.
            scrollOnBoot();

            window.ChatConversaLayout = {
                booted: true,

                // ----- API NOVA (preferida pelo Runtime v3) -----
                scrollOnBoot:           scrollOnBoot,
                scrollOnSubmit:         scrollOnSubmit,
                scrollOnNewMessage:     scrollOnNewMessage,
                scrollOnPageshow:       scrollOnPageshow,
                scrollOnTakeover:       scrollOnTakeover,
                scrollOnComposerExpand: scrollOnComposerExpand,

                // ----- API LEGADA (Runtime v2 / Composer atual) -----
                scrollToBottom:       legacyScrollToBottom,
                scrollToBottomForced: function () { scrollOnTakeover(); },
                scrollIfSticky:       function () { scrollOnNewMessage(); },

                // ----- Sticky state -----
                isSticky:       function () { return state.stickToBottom; },
                isStickyBottom: function () { return state.stickToBottom; },
                setSticky: function (value) {
                    setSticky(value);
                    if (value) _scrollNow();
                },

                // ----- Debug -----
                getStateSnapshot: function () {
                    return {
                        stickToBottom: state.stickToBottom,
                        lastHumanInteractionAt: state.lastHumanInteractionAt,
                        distanceFromBottom: distanceFromBottom(),
                        reStickTimerActive: Boolean(state.reStickTimer),
                        bootDone: state.bootDone,
                        visible: state.visible
                    };
                }
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