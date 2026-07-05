/**
 * Conversa Chat — Composer
 *
 * SOMENTE UX visual do campo de mensagem (auto-size, estados vazio/expandido/
 * focado). O comportamento do formulário é 100% do JetFormBuilder:
 *
 *  - envio:   Submit Type AJAX (configurado no form, na UI);
 *  - limpeza: "Clear data on submit" NATIVO do JFB (atributo data-clear no
 *             <form> — form-builder.php:146). Este arquivo NÃO limpa campo;
 *  - eventos: jet-form-builder/ajax/on-success|on-fail|processing-error
 *             (user-journey/module.php:594-602) usados apenas pra re-medir
 *             o textarea depois que o JFB fez o trabalho dele.
 *
 * Regras de ouro preservadas da versão anterior (lições reais de produção):
 *  - NUNCA forçar display no <form>;
 *  - NUNCA colapsar wrapper que contenha textarea/input/select;
 *  - NUNCA mexer em disabled/readonly (isso é do runtime, via tab lock).
 */
(function () {
	"use strict";

	var cfg = window.ConversaChatConfig || {};

	if ( ! cfg.selectors || ! cfg.selectors.form ) {
		return;
	}
	if ( window.ConversaChatComposer && window.ConversaChatComposer.booted ) {
		return;
	}

	var formCache = new WeakMap();
	var state = { booted: false, scanRaf: null };

	function queryForms() {
		try {
			return Array.prototype.slice.call( document.querySelectorAll( cfg.selectors.form ) );
		} catch ( e ) {
			return [];
		}
	}

	function getCache( form, force ) {
		var c = formCache.get( form );

		var needsRefresh = ! c || force || ! c.textarea || ! c.submit ||
			! form.contains( c.textarea ) || ! form.contains( c.submit );

		if ( needsRefresh ) {
			c = {
				textarea: form.querySelector( 'textarea' ),
				submit: form.querySelector( "button[type='submit'], input[type='submit'], .jet-form-builder__action-button" ),
				rafScheduled: false
			};
			formCache.set( form, c );
		}

		return c;
	}

	/**
	 * Wrapper do submit só é colapsado se comprovadamente não contiver
	 * nenhum campo (validação anti-bug herdada da v2.1).
	 */
	function getSubmitHost( submit, form ) {
		if ( ! submit || ! submit.closest ) return null;

		var host = submit.closest( [
			'.jet-form-builder-row',
			'.field-type-submit',
			'.wp-block-jet-forms-submit-field',
			'.jet-form-builder__submit-wrap',
			'.jet-form-builder__action-button-wrapper'
		].join( ', ' ) );

		if ( ! host || host === form ) return null;
		if ( host.querySelector( 'textarea' ) ) return null;
		if ( host.querySelector( "input:not([type='submit']):not([type='hidden']):not([type='button'])" ) ) return null;
		if ( host.querySelector( 'select' ) ) return null;

		return host;
	}

	// ------------------------------------------------------------------
	// Auto-size
	// ------------------------------------------------------------------

	function getCssNumber( el, prop, fallback ) {
		if ( ! el || ! window.getComputedStyle ) return fallback;
		var v = parseFloat( window.getComputedStyle( el ).getPropertyValue( prop ) );
		return Number.isFinite( v ) && v > 0 ? v : fallback;
	}

	function autoSize( form, cache ) {
		var ta = cache.textarea;
		if ( ! ta ) return;

		var value = String( ta.value || '' );
		var empty = value.trim().length === 0;

		ta.style.height = 'auto';

		var minH = getCssNumber( ta, '--conversa-composer-textarea-min-height', 34 );
		var maxH = getCssNumber( ta, '--conversa-composer-textarea-max-height', 168 );

		var next = minH;
		if ( ! empty ) {
			next = Math.max( minH, Math.min( ta.scrollHeight, maxH ) );
		}

		ta.style.height = next + 'px';
		ta.style.overflowY = ( ! empty && ta.scrollHeight > maxH ) ? 'auto' : 'hidden';

		var expanded = ! empty && ( value.indexOf( '\n' ) !== -1 || ta.scrollHeight > minH + 8 );

		form.classList.toggle( 'conversa-composer-is-expanded', expanded );
		form.classList.toggle( 'conversa-composer-is-empty', empty );

		if ( window.ConversaChatLayout && window.ConversaChatLayout.scrollOnComposerExpand ) {
			window.ConversaChatLayout.scrollOnComposerExpand();
		}
	}

	function scheduleAutoSize( form ) {
		var c = getCache( form );
		if ( c.rafScheduled ) return;
		c.rafScheduled = true;
		window.requestAnimationFrame( function () {
			c.rafScheduled = false;
			autoSize( form, c );
		} );
	}

	// ------------------------------------------------------------------
	// Boot por form
	// ------------------------------------------------------------------

	function bootForm( form ) {
		if ( ! form ) return;

		var cache = getCache( form, true );

		// Sem textarea/submit ainda (conditional logic do JFB pode renderizar
		// depois): não marca como booted, permite nova tentativa no rescan.
		if ( ! cache.textarea || ! cache.submit ) return;

		if ( form.dataset.conversaComposerBooted === '1' ) {
			autoSize( form, cache );
			return;
		}

		form.dataset.conversaComposerBooted = '1';
		form.classList.add( 'conversa-chat-composer' );

		cache.textarea.setAttribute( 'rows', '1' );
		if ( ! cache.textarea.getAttribute( 'placeholder' ) ) {
			cache.textarea.setAttribute( 'placeholder', 'Mensagem' );
		}

		if ( ! cache.submit.getAttribute( 'aria-label' ) ) {
			cache.submit.setAttribute( 'aria-label', 'Enviar mensagem' );
		}

		var host = getSubmitHost( cache.submit, form );
		if ( host ) host.classList.add( 'conversa-composer-submit-row' );

		cache.textarea.addEventListener( 'input', function () {
			scheduleAutoSize( form );
		} );
		cache.textarea.addEventListener( 'focus', function () {
			form.classList.add( 'conversa-composer-is-focused' );
		} );
		cache.textarea.addEventListener( 'blur', function () {
			form.classList.remove( 'conversa-composer-is-focused' );
		} );

		autoSize( form, cache );
	}

	function scanForms() {
		queryForms().forEach( bootForm );
	}

	function scheduleScan() {
		if ( state.scanRaf ) return;
		state.scanRaf = window.requestAnimationFrame( function () {
			state.scanRaf = null;
			scanForms();
		} );
	}

	// ------------------------------------------------------------------
	// Eventos
	// ------------------------------------------------------------------

	function bindJetFormBuilderEvents() {
		if ( ! window.jQuery ) return;

		var $doc = window.jQuery( document );

		// O JFB (com clear nativo ligado) já limpou o campo: só re-medimos.
		$doc.on( 'jet-form-builder/ajax/on-success', function ( event, response, $form ) {
			var form = $form && $form[0] ? $form[0] : null;
			if ( ! form || ! form.matches || ! form.matches( cfg.selectors.form ) ) return;
			window.setTimeout( function () { scheduleAutoSize( form ); }, 100 );
			window.setTimeout( function () { scheduleAutoSize( form ); }, 350 );
		} );

		$doc.on( 'jet-form-builder/ajax/on-fail jet-form-builder/ajax/processing-error', function ( event, response, $form ) {
			var form = $form && $form[0] ? $form[0] : null;
			if ( ! form || ! form.matches || ! form.matches( cfg.selectors.form ) ) return;
			scheduleAutoSize( form );
		} );
	}

	function observeFooter() {
		var footer = document.querySelector( cfg.selectors.footer );
		if ( ! footer || typeof MutationObserver !== 'function' ) return;

		var obs = new MutationObserver( scheduleScan );

		obs.observe( footer, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'hidden', 'aria-hidden' ]
		} );
	}

	function bindViewportEvents() {
		window.addEventListener( 'resize', function () {
			queryForms().forEach( scheduleAutoSize );
		} );
		window.addEventListener( 'pageshow', function () {
			queryForms().forEach( scheduleAutoSize );
		} );
	}

	// ------------------------------------------------------------------
	// Boot
	// ------------------------------------------------------------------

	function boot() {
		if ( state.booted ) return;
		state.booted = true;

		scanForms();
		observeFooter();
		bindJetFormBuilderEvents();
		bindViewportEvents();

		window.ConversaChatComposer = {
			booted: true,
			refresh: scanForms
		};
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
