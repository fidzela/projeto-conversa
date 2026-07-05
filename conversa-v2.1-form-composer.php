<?php

/**
 * [Conversa v2.1] Form Composer (FIX)
 *
 * Mudanças em relação à v2 original:
 *  - CSS deixa de depender da lista de IDs do JetForm (que causava bug de
 *    precedência de vírgula: "A, B.classe" interpretado como
 *    "A (sem .classe), B.classe"). Agora o gate é a classe
 *    .chat-conversa-composer adicionada pelo JS, que SÓ é aplicada
 *    nos forms que casam com cfg.form_selector. Resultado: visual
 *    aplica de forma estável independente da quantidade/ordem de IDs.
 *  - JS preservado integralmente (boot, cache, observer, autoSize,
 *    JetForm events, viewport events).
 *  - Adicionado listener para ChatConversa:messages-appended (v4.1).
 *    O listener original "messages-replaced" continua ativo.
 *
 * Princípios CRÍTICOS (lições da v1) — preservados:
 *  - NUNCA forçar display no <form>.
 *  - NUNCA colapsar wrapper que contenha textarea/input/select.
 *  - NUNCA mexer em disabled (responsabilidade do runtime via tab lock).
 *  - Observer escopado a #footer-conversa, attributeFilter mínimo.
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
    add_action( 'wp_head',   'chat_conversa_composer_print_styles', 55 );
    add_action( 'wp_footer', 'chat_conversa_composer_print_script', 120 );
}

/**
 * ============================================================================
 * HELPERS
 * ============================================================================
 */

function chat_conversa_composer_get_form_ids() {
    if ( ! defined( 'CHAT_CONVERSA_FORM_IDS' ) ) {
        return [];
    }
    return array_filter(
        array_map( 'absint', explode( ',', CHAT_CONVERSA_FORM_IDS ) )
    );
}

/**
 * Seletor usado APENAS pelo JS para localizar os forms alvo.
 * O CSS não usa mais esse seletor — usa só .chat-conversa-composer.
 */
function chat_conversa_composer_get_form_selector() {
    $ids = chat_conversa_composer_get_form_ids();

    if ( empty( $ids ) ) {
        return '#footer-conversa form.jet-form-builder, '
            . '#footer-conversa form.chat-conversa-form, '
            . '#footer-conversa form[data-chat-conversa-form="1"]';
    }

    $parts = [];
    foreach ( $ids as $id ) {
        $parts[] = '#footer-conversa form#jet-form-' . $id;
    }
    return implode( ', ', $parts );
}

/**
 * ============================================================================
 * CSS
 * ============================================================================
 *
 * REGRAS DE OURO (preservadas):
 *  - Nenhuma regra esconde o form ou seus campos.
 *  - Nenhuma regra força display.
 *  - Apenas estilo visual quando o JS adiciona .chat-conversa-composer.
 *
 * MUDANÇA-CHAVE:
 *  - Gate único = .chat-conversa-composer. Sem lista de IDs no CSS.
 *  - O JS continua sendo o filtro: só os forms certos recebem a classe.
 */

function chat_conversa_composer_print_styles() {
    if ( ! function_exists( 'chat_conversa_context' ) || ! chat_conversa_context() ) {
        return;
    }
    ?>
    <style id="chat-conversa-composer-css">
        /* Footer container — vale sempre na página de conversa. */
        body.single-conversa:not(.elementor-editor-active) #footer-conversa {
            padding: 10px clamp(10px, 2.4vw, 20px) calc(10px + env(safe-area-inset-bottom, 0px)) !important;
            border-top: 1px solid #f3f4f6 !important;
            background: #ffffff !important;
        }

        body.single-conversa:not(.elementor-editor-active) #footer-conversa > .e-con-inner,
        body.single-conversa:not(.elementor-editor-active) #footer-conversa > .elementor-container,
        body.single-conversa:not(.elementor-editor-active) #footer-conversa .elementor-widget-container {
            width: 100% !important;
            max-width: 100% !important;
        }

        /* ============================================================
         * Composer — gate único: o JS adiciona a classe só nos forms
         * corretos via cfg.form_selector. CSS confia na classe.
         * ============================================================ */
        form.chat-conversa-composer {
            --chat-composer-min-height: 56px;
            --chat-composer-button-size: 40px;
            --chat-composer-button-gap: 7px;
            --chat-composer-x-padding: 18px;
            --chat-composer-textarea-min-height: 34px;
            --chat-composer-textarea-max-height: 168px;
            --chat-composer-bottom-step: 56px;

            position: relative !important;
            isolation: isolate !important;
            width: min(100%, 920px) !important;
            min-height: var(--chat-composer-min-height) !important;
            margin: 0 auto !important;
            padding:
                8px
                calc(var(--chat-composer-button-size) + var(--chat-composer-button-gap) + 12px)
                8px
                var(--chat-composer-x-padding) !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 28px !important;
            background: #ffffff !important;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 8px 24px rgba(15,23,42,0.04) !important;
            box-sizing: border-box !important;
            transition: border-color 160ms ease, box-shadow 160ms ease, padding 160ms ease, border-radius 160ms ease !important;
        }

        form.chat-conversa-composer.chat-composer-is-expanded {
            padding: 12px var(--chat-composer-x-padding) var(--chat-composer-bottom-step) var(--chat-composer-x-padding) !important;
            border-radius: 30px !important;
        }

        form.chat-conversa-composer.chat-composer-is-focused {
            border-color: #d1d5db !important;
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 10px 28px rgba(15,23,42,0.055) !important;
        }

        /* Lock vem do RUNTIME. Composer só responde visualmente. */
        form.chat-conversa-composer.chat-conversa-form-locked {
            opacity: 0.72 !important;
            background: #f9fafb !important;
        }

        form.chat-conversa-composer *,
        form.chat-conversa-composer *::before,
        form.chat-conversa-composer *::after {
            box-sizing: border-box !important;
        }

        /* Reset de wrappers internos do JetForm — sem display:none. */
        form.chat-conversa-composer .jet-form-builder-row,
        form.chat-conversa-composer .jet-form-builder__field-wrap,
        form.chat-conversa-composer .jet-form-builder__fields-group {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
        }

        /* Labels: escondemos pra deixar o composer limpo. Não afeta funcionalidade. */
        form.chat-conversa-composer .jet-form-builder__label,
        form.chat-conversa-composer .jet-form-builder__desc,
        form.chat-conversa-composer .jet-form-builder__field-label,
        form.chat-conversa-composer > label,
        form.chat-conversa-composer .jet-form-builder-row > label {
            display: none !important;
        }

        /* Feedback wrap específico (não a classe genérica .jet-form-builder-message). */
        form.chat-conversa-composer .jet-form-builder-messages-wrap {
            display: none !important;
        }

        /* Submit row colapsada — só aplicada pelo JS depois de validar
         * que o wrapper NÃO contém textarea/input/select. */
        form.chat-conversa-composer .chat-conversa-composer-submit-row {
            position: static !important;
            width: 0 !important; min-width: 0 !important; max-width: 0 !important;
            height: 0 !important; min-height: 0 !important; max-height: 0 !important;
            margin: 0 !important; padding: 0 !important; overflow: visible !important;
        }

        /* Textarea */
        form.chat-conversa-composer textarea,
        form.chat-conversa-composer textarea.jet-form-builder__field {
            appearance: none !important;
            display: block !important;
            width: 100% !important;
            min-width: 0 !important;
            min-height: var(--chat-composer-textarea-min-height) !important;
            max-height: var(--chat-composer-textarea-max-height) !important;
            margin: 0 !important;
            padding: 7px 2px !important;
            border: 0 !important; border-radius: 0 !important; outline: 0 !important;
            resize: none !important;
            overflow-x: hidden !important;
            overflow-y: hidden;
            background: transparent !important;
            box-shadow: none !important;
            color: #111827 !important;
            font-family: Montserrat, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
            font-size: 14px !important;
            font-weight: 400 !important;
            line-height: 20px !important;
            letter-spacing: 0 !important;
            scrollbar-width: thin;
            scrollbar-color: #d1d5db transparent;
        }

        form.chat-conversa-composer textarea::placeholder {
            color: #9ca3af !important; opacity: 1 !important;
        }
        form.chat-conversa-composer textarea::-webkit-scrollbar { width: 6px; }
        form.chat-conversa-composer textarea::-webkit-scrollbar-track { background: transparent; }
        form.chat-conversa-composer textarea::-webkit-scrollbar-thumb {
            background: #d1d5db; border-radius: 100px;
        }

        /* Botão enviar */
        form.chat-conversa-composer button[type="submit"],
        form.chat-conversa-composer input[type="submit"],
        form.chat-conversa-composer .jet-form-builder__action-button {
            position: absolute !important;
            right: var(--chat-composer-button-gap) !important;
            bottom: var(--chat-composer-button-gap) !important;
            z-index: 5 !important;
            display: block !important;
            width: var(--chat-composer-button-size) !important;
            min-width: var(--chat-composer-button-size) !important;
            max-width: var(--chat-composer-button-size) !important;
            height: var(--chat-composer-button-size) !important;
            min-height: var(--chat-composer-button-size) !important;
            max-height: var(--chat-composer-button-size) !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 0 !important;
            border-radius: 999px !important;
            outline: 0 !important;
            background: #111827 !important;
            color: #fff !important;
            box-shadow: none !important;
            cursor: pointer !important;
            font-size: 0 !important;
            line-height: 0 !important;
            text-indent: -9999px !important;
            overflow: hidden !important;
            transform: none !important;
            transition: background-color 160ms ease, color 160ms ease, transform 160ms ease, opacity 160ms ease !important;
        }

        form.chat-conversa-composer button[type="submit"]::before,
        form.chat-conversa-composer .jet-form-builder__action-button::before {
            content: "" !important;
            position: absolute !important;
            top: 50% !important; left: 50% !important;
            display: block !important;
            width: 20px !important; height: 20px !important;
            background: currentColor !important;
            transform: translate(-50%, -50%) !important;
            -webkit-mask: url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%3E%3Cpath%20d='M12%205l7%207-1.45%201.45L13%208.9V20h-2V8.9l-4.55%204.55L5%2012l7-7z'/%3E%3C/svg%3E") center / contain no-repeat !important;
                    mask: url("data:image/svg+xml,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%3E%3Cpath%20d='M12%205l7%207-1.45%201.45L13%208.9V20h-2V8.9l-4.55%204.55L5%2012l7-7z'/%3E%3C/svg%3E") center / contain no-repeat !important;
        }

        form.chat-conversa-composer button[type="submit"]:hover,
        form.chat-conversa-composer input[type="submit"]:hover,
        form.chat-conversa-composer .jet-form-builder__action-button:hover {
            background: #030712 !important; transform: translateY(-1px) !important;
        }
        form.chat-conversa-composer button[type="submit"]:active,
        form.chat-conversa-composer input[type="submit"]:active,
        form.chat-conversa-composer .jet-form-builder__action-button:active {
            transform: translateY(0) scale(0.98) !important;
        }

        form.chat-conversa-composer.chat-composer-is-empty button[type="submit"],
        form.chat-conversa-composer.chat-composer-is-empty input[type="submit"],
        form.chat-conversa-composer.chat-composer-is-empty .jet-form-builder__action-button {
            background: #e5e7eb !important;
            color: #9ca3af !important;
            cursor: default !important;
            transform: none !important;
        }

        form.chat-conversa-composer button[disabled],
        form.chat-conversa-composer input[disabled],
        form.chat-conversa-composer textarea[disabled],
        form.chat-conversa-composer textarea[readonly],
        form.chat-conversa-composer .jet-form-builder__action-button[disabled] {
            cursor: not-allowed !important;
            opacity: 0.62 !important;
        }

        @media (max-width: 640px) {
            body.single-conversa:not(.elementor-editor-active) #footer-conversa {
                padding: 8px 10px calc(8px + env(safe-area-inset-bottom, 0px)) !important;
            }

            form.chat-conversa-composer {
                --chat-composer-min-height: 52px;
                --chat-composer-button-size: 34px;
                --chat-composer-button-gap: 8px;
                --chat-composer-x-padding: 13px;
                --chat-composer-textarea-min-height: 36px;
                --chat-composer-textarea-max-height: 132px;
                --chat-composer-bottom-step: 50px;
                border-radius: 25px !important;
            }
            form.chat-conversa-composer.chat-composer-is-expanded { border-radius: 28px !important; }
            form.chat-conversa-composer textarea,
            form.chat-conversa-composer textarea.jet-form-builder__field {
                font-size: 16px !important; line-height: 22px !important; padding: 7px 2px !important;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            form.chat-conversa-composer,
            form.chat-conversa-composer button[type="submit"],
            form.chat-conversa-composer input[type="submit"],
            form.chat-conversa-composer .jet-form-builder__action-button {
                transition: none !important;
            }
        }
    </style>
    <?php
}

/**
 * ============================================================================
 * JS
 * ============================================================================
 *
 * 100% preservado em relação à v2 original, exceto:
 *  - bindChatEvents() agora escuta TAMBÉM ChatConversa:messages-appended
 *    (evento novo emitido pela v4.1 a cada mensagem incremental).
 */

function chat_conversa_composer_print_script() {
    if ( ! function_exists( 'chat_conversa_context' ) || ! chat_conversa_context() ) {
        return;
    }

    $config = [
        'form_ids'      => chat_conversa_composer_get_form_ids(),
        'form_selector' => chat_conversa_composer_get_form_selector(),
    ];
    ?>
    <script id="chat-conversa-composer-js">
    (function () {
        "use strict";

        const cfg = <?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;

        if (window.ChatConversaComposer && window.ChatConversaComposer.booted) return;

        const formCache = new WeakMap();
        const state = { booted: false, scanRaf: null, resizeRaf: null };

        // ====================================================================
        // CACHE
        // ====================================================================

        function getCache(form, forceRefresh) {
            let c = formCache.get(form);

            const needsRefresh =
                !c ||
                forceRefresh ||
                !c.textarea ||
                !c.submit ||
                !form.contains(c.textarea) ||
                !form.contains(c.submit);

            if (needsRefresh) {
                c = {
                    textarea: form.querySelector("textarea"),
                    submit: form.querySelector(
                        "button[type='submit'], input[type='submit'], .jet-form-builder__action-button"
                    ),
                    rafScheduled: false
                };
                formCache.set(form, c);
            }

            return c;
        }

        // ====================================================================
        // SUBMIT HOST - validação anti-bug
        // ====================================================================

        function getSubmitHost(submit, form) {
            if (!submit || !submit.closest) return null;

            const host = submit.closest([
                ".jet-form-builder-row",
                ".field-type-submit",
                ".wp-block-jet-forms-submit-field",
                ".jet-form-builder__submit-wrap",
                ".jet-form-builder__action-button-wrapper"
            ].join(", "));

            if (!host || host === form) return null;

            if (host.querySelector("textarea")) return null;
            if (host.querySelector("input:not([type='submit']):not([type='hidden']):not([type='button'])")) return null;
            if (host.querySelector("select")) return null;

            return host;
        }

        // ====================================================================
        // QUERIES
        // ====================================================================

        function queryForms() {
            if (!cfg.form_selector) return [];
            try {
                return Array.prototype.slice.call(document.querySelectorAll(cfg.form_selector));
            } catch (e) {
                return [];
            }
        }

        // ====================================================================
        // MEASUREMENTS
        // ====================================================================

        function getCssNumber(el, prop, fallback) {
            if (!el || !window.getComputedStyle) return fallback;
            const v = parseFloat(window.getComputedStyle(el).getPropertyValue(prop));
            return Number.isFinite(v) && v > 0 ? v : fallback;
        }

        function getMaxHeight(ta) { return getCssNumber(ta, "--chat-composer-textarea-max-height", 168); }
        function getMinHeight(ta) { return getCssNumber(ta, "--chat-composer-textarea-min-height", 34); }

        function detectExpanded(ta) {
            if (!ta) return false;
            const v = String(ta.value || "");
            if (v.trim().length === 0) return false;
            if (v.indexOf("\n") !== -1) return true;
            return ta.scrollHeight > getMinHeight(ta) + 8;
        }

        // ====================================================================
        // AUTO SIZE
        // ====================================================================

        function autoSize(form, cache) {
            const ta = cache.textarea;
            if (!ta) return;

            const v = String(ta.value || "");
            const empty = v.trim().length === 0;

            ta.style.height = "auto";

            const minH = getMinHeight(ta);
            const maxH = getMaxHeight(ta);

            let next = minH;
            if (!empty) {
                next = Math.max(minH, Math.min(ta.scrollHeight, maxH));
            }

            ta.style.height = next + "px";
            ta.style.overflowY = (!empty && ta.scrollHeight > maxH) ? "auto" : "hidden";

            form.classList.toggle("chat-composer-is-expanded", !empty && detectExpanded(ta));
            form.classList.toggle("chat-composer-is-empty", empty);

            requestLayoutScroll();
        }

        function requestLayoutScroll() {
            if (state.resizeRaf) return;
            state.resizeRaf = window.requestAnimationFrame(function () {
                state.resizeRaf = null;
                if (window.ChatConversaLayout && typeof window.ChatConversaLayout.scrollToBottom === "function") {
                    window.ChatConversaLayout.scrollToBottom("composer-resize");
                }
            });
        }

        function scheduleAutoSize(form) {
            const c = getCache(form);
            if (c.rafScheduled) return;
            c.rafScheduled = true;
            window.requestAnimationFrame(function () {
                c.rafScheduled = false;
                autoSize(form, c);
            });
        }

        function resetAfterSubmit(form) {
            window.setTimeout(function () { scheduleAutoSize(form); }, 200);
        }

        /**
         * Limpa APENAS o textarea após sucesso do JFB.
         * NÃO toca em hidden fields (IDs de usuário/post/etc).
         *
         * Replica a técnica nativa do "Clear Data on Submit" do JetForm:
         *  - usa jQuery .val('') quando disponível (canal que o JFB escuta);
         *  - dispara change.JetFormBuilderMain (conditional logic);
         *  - dispara input/change (autoSize + frameworks).
         *
         * Roda em múltiplos ticks porque o JFB às vezes restaura
         * o valor depois do on-success durante o pós-processo interno.
         */
        function clearComposerTextarea(form) {
            const cache = getCache(form, true);
            if (!cache.textarea) return;

            const ta = cache.textarea;

            function doClear() {
                if (window.jQuery) {
                    window.jQuery(ta)
                        .val("")
                        .trigger("change.JetFormBuilderMain")
                        .trigger("input")
                        .trigger("change");
                } else {
                    ta.value = "";
                    ta.dispatchEvent(new Event("input", { bubbles: true }));
                    ta.dispatchEvent(new Event("change", { bubbles: true }));
                }

                // Defensivo: garante DOM zerado mesmo se algum framework
                // tentou restaurar via property.
                if (ta.value !== "") {
                    ta.value = "";
                }

                form.classList.remove("chat-composer-is-expanded");
                form.classList.add("chat-composer-is-empty");
                scheduleAutoSize(form);
            }

            doClear();
            window.setTimeout(doClear, 0);
            window.setTimeout(doClear, 50);
            window.setTimeout(doClear, 200);
        }

        // ====================================================================
        // BOOT POR FORM
        // ====================================================================

        function bootForm(form) {
            if (!form) return;

            const cache = getCache(form, true);

            // Sem textarea ou sem submit? Não marca como booted —
            // permite re-tentar quando conditional logic renderizar.
            if (!cache.textarea || !cache.submit) return;

            if (form.dataset.chatConversaComposerBooted === "1") {
                autoSize(form, cache);
                return;
            }

            form.dataset.chatConversaComposerBooted = "1";
            form.classList.add("chat-conversa-composer");

            cache.textarea.classList.add("chat-conversa-composer-textarea");
            cache.textarea.setAttribute("rows", "1");
            if (!cache.textarea.getAttribute("placeholder")) {
                cache.textarea.setAttribute("placeholder", "Mensagem");
            }

            cache.submit.classList.add("chat-conversa-composer-submit");
            if (!cache.submit.getAttribute("aria-label")) {
                cache.submit.setAttribute("aria-label", "Enviar mensagem");
            }
            if (cache.submit.tagName === "INPUT") cache.submit.value = "↑";

            const host = getSubmitHost(cache.submit, form);
            if (host) host.classList.add("chat-conversa-composer-submit-row");

            cache.textarea.addEventListener("input", function () { scheduleAutoSize(form); });
            cache.textarea.addEventListener("focus", function () {
                form.classList.add("chat-composer-is-focused");
            });
            cache.textarea.addEventListener("blur", function () {
                form.classList.remove("chat-composer-is-focused");
            });

            form.addEventListener("submit", function () { resetAfterSubmit(form); }, true);

            autoSize(form, cache);
        }

        function scanForms() {
            queryForms().forEach(bootForm);
        }

        function scheduleScan() {
            if (state.scanRaf) return;
            state.scanRaf = window.requestAnimationFrame(function () {
                state.scanRaf = null;
                scanForms();
            });
        }

        // ====================================================================
        // OBSERVER (escopado em #footer-conversa)
        // ====================================================================

        function observeFooter() {
            const footer = document.querySelector("#footer-conversa");
            if (!footer || typeof MutationObserver !== "function") return;

            const obs = new MutationObserver(function () { scheduleScan(); });

            obs.observe(footer, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ["hidden", "aria-hidden"]
            });
        }

        // ====================================================================
        // EVENTOS
        // ====================================================================

        function bindJetFormBuilderEvents() {
            if (!window.jQuery) return;
            const $doc = window.jQuery(document);

            // SUCCESS: limpa o textarea (e SÓ o textarea) + ajusta layout.
            $doc.on(
                "jet-form-builder/ajax/on-success",
                function (event, response, $form) {
                    const form = $form && $form[0] ? $form[0] : null;
                    if (!form || !form.matches || !form.matches(cfg.form_selector)) return;
                    clearComposerTextarea(form);
                    resetAfterSubmit(form);
                }
            );

            // FAIL/ERROR: preserva o que o usuário digitou, só reajusta layout.
            $doc.on(
                "jet-form-builder/ajax/on-fail jet-form-builder/ajax/processing-error",
                function (event, response, $form) {
                    const form = $form && $form[0] ? $form[0] : null;
                    if (!form || !form.matches || !form.matches(cfg.form_selector)) return;
                    resetAfterSubmit(form);
                }
            );
        }

        function bindChatEvents() {
            // Compat v2/v3: full replace do listing.
            window.addEventListener("ChatConversa:messages-replaced", scheduleScan);
            // v4.1: incremental append. Footer normalmente intacto, mas
            // rescan barato garante reaplicação caso o DOM tenha mudado.
            window.addEventListener("ChatConversa:messages-appended", scheduleScan);
        }

        function bindViewportEvents() {
            window.addEventListener("resize", function () {
                queryForms().forEach(scheduleAutoSize);
            });
            window.addEventListener("pageshow", function () {
                queryForms().forEach(scheduleAutoSize);
            });
        }

        // ====================================================================
        // BOOT
        // ====================================================================

        function boot() {
            if (state.booted) return;
            state.booted = true;

            scanForms();
            observeFooter();
            bindJetFormBuilderEvents();
            bindChatEvents();
            bindViewportEvents();

            window.ChatConversaComposer = {
                booted: true,
                version: "v2.1-css-classgate",
                refresh: scanForms,
                resize: function () { queryForms().forEach(scheduleAutoSize); }
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