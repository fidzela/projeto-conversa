/**
 * Conversa Chat — Layout
 *
 * Estrutura de chat + scroll por CONTEXTO EXPLÍCITO (desenho herdado da v3,
 * que eliminou o "scroll fantasma"):
 *  - state.stickToBottom é a única fonte da verdade;
 *  - apenas _scrollNow()/_scrollNextFrame() tocam em scrollTop;
 *  - cada situação chama sua própria função (boot, submit, nova mensagem,
 *    pageshow, takeover) — sem observers de DOM disparando scroll por inferência.
 *
 * API pública: window.ConversaChatLayout
 */
(function () {
	"use strict";

	var cfg = window.ConversaChatConfig || {};

	if ( ! cfg.selectors || ( window.ConversaChatLayout && window.ConversaChatLayout.booted ) ) {
		return;
	}

	var STICK_THRESHOLD_PX = 80;
	var RE_STICK_AFTER_MS = 60000;
	var HUMAN_SCROLL_WINDOW_MS = 500;
	var BOOT_RETRY_DELAY_MS = 250;

	var state = {
		booted: false,
		bootDone: false,
		stickToBottom: true,
		lastHumanInteractionAt: 0,
		reStickTimer: null,
		scrollRaf: null
	};

	function getMessages() {
		return document.querySelector( cfg.selectors.messages );
	}

	function setViewportHeight() {
		var h = window.innerHeight || document.documentElement.clientHeight;
		document.documentElement.style.setProperty( '--conversa-chat-vh', h + 'px' );
	}

	function distanceFromBottom() {
		var m = getMessages();
		if ( ! m ) return 0;
		return m.scrollHeight - m.scrollTop - m.clientHeight;
	}

	function isPhysicallyAtBottom() {
		return distanceFromBottom() <= STICK_THRESHOLD_PX;
	}

	// ------------------------------------------------------------------
	// Sticky
	// ------------------------------------------------------------------

	function setSticky( value ) {
		value = Boolean( value );
		if ( state.stickToBottom === value ) return;
		state.stickToBottom = value;
		if ( value ) {
			stopReStickTimer();
		} else {
			startReStickTimer();
		}
	}

	function startReStickTimer() {
		stopReStickTimer();
		state.reStickTimer = window.setTimeout( function () {
			state.reStickTimer = null;
			setSticky( true );
			_scrollNow();
		}, RE_STICK_AFTER_MS );
	}

	function stopReStickTimer() {
		if ( state.reStickTimer ) {
			window.clearTimeout( state.reStickTimer );
			state.reStickTimer = null;
		}
	}

	// ------------------------------------------------------------------
	// Núcleo: as únicas funções que tocam em scrollTop
	// ------------------------------------------------------------------

	function _scrollNow() {
		var m = getMessages();
		if ( ! m ) return;
		m.scrollTop = m.scrollHeight;
	}

	function _scrollNextFrame() {
		window.requestAnimationFrame( _scrollNow );
	}

	/**
	 * Ancora a viewport ao PREPENDAR conteúdo no topo (carregar antigas).
	 * Registra a altura antes, executa a mutação (inserção dos itens no topo)
	 * e reposiciona o scrollTop pela diferença de altura — a mensagem que o
	 * usuário estava lendo continua exatamente no mesmo lugar, sem "pulo".
	 * É o comportamento do WhatsApp/Messenger ao rolar pra cima.
	 */
	function anchorForPrepend( mutate ) {
		var m = getMessages();

		if ( ! m ) {
			if ( typeof mutate === 'function' ) mutate();
			return;
		}

		var prevHeight = m.scrollHeight;
		var prevTop    = m.scrollTop;

		if ( typeof mutate === 'function' ) mutate();

		// Reancoragem imediata (síncrona).
		m.scrollTop = prevTop + ( m.scrollHeight - prevHeight );

		// Reancoragem no próximo frame: se um asset (CSS/imagem) do card mudar
		// a altura logo após o prepend, mantém a viewport parada mesmo assim.
		window.requestAnimationFrame( function () {
			var delta = m.scrollHeight - prevHeight;
			if ( delta > 0 ) {
				m.scrollTop = prevTop + delta;
			}
		} );
	}

	// ------------------------------------------------------------------
	// API por contexto
	// ------------------------------------------------------------------

	function scrollOnBoot() {
		if ( state.bootDone ) return;
		setSticky( true );
		_scrollNow();
		_scrollNextFrame();
		window.setTimeout( function () {
			if ( state.stickToBottom ) _scrollNow();
			state.bootDone = true;
		}, BOOT_RETRY_DELAY_MS );
	}

	function scrollOnSubmit() {
		setSticky( true );
		_scrollNow();
		_scrollNextFrame();
		window.setTimeout( function () {
			if ( state.stickToBottom ) _scrollNow();
		}, 150 );
	}

	/** Mensagem nova confirmada: RESPEITA a leitura do usuário. */
	function scrollOnNewMessage() {
		if ( ! state.stickToBottom ) return;
		_scrollNextFrame();
	}

	function scrollOnPageshow() {
		setSticky( true );
		_scrollNow();
		_scrollNextFrame();
	}

	function scrollOnTakeover() {
		setSticky( true );
		_scrollNow();
		_scrollNextFrame();
	}

	function scrollOnComposerExpand() {
		if ( ! state.stickToBottom ) return;
		_scrollNextFrame();
	}

	// ------------------------------------------------------------------
	// Interação humana (decide quando o sticky desliga)
	// ------------------------------------------------------------------

	function markHumanInteraction() {
		state.lastHumanInteractionAt = Date.now();
	}

	function wasRecentHumanInteraction() {
		return ( Date.now() - state.lastHumanInteractionAt ) < HUMAN_SCROLL_WINDOW_MS;
	}

	function bindHumanInteraction() {
		var m = getMessages();
		if ( ! m ) return;

		[ 'wheel', 'touchstart', 'touchmove', 'keydown' ].forEach( function ( evt ) {
			m.addEventListener( evt, markHumanInteraction, { passive: true } );
		} );

		m.addEventListener( 'scroll', function () {
			if ( state.scrollRaf ) return;
			state.scrollRaf = window.requestAnimationFrame( function () {
				state.scrollRaf = null;

				var atBottom = isPhysicallyAtBottom();

				if ( atBottom && ! state.stickToBottom ) {
					setSticky( true );
				} else if ( ! atBottom && state.stickToBottom ) {
					// Scroll programático não dispara wheel/touch:
					// só desliga o sticky se foi ação humana recente.
					if ( wasRecentHumanInteraction() ) setSticky( false );
				}
			} );
		}, { passive: true } );
	}

	// ------------------------------------------------------------------
	// Eventos externos
	// ------------------------------------------------------------------

	function bindSubmitScroll() {
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( ! form || ! form.matches || ! form.matches( cfg.selectors.form ) ) return;
			scrollOnSubmit();
		}, true );
	}

	function bindRuntimeEvents() {
		window.addEventListener( 'conversa-chat:messages-appended', scrollOnNewMessage );
		window.addEventListener( 'conversa-chat:messages-replaced', scrollOnNewMessage );

		// Load-more NATIVO do JetEngine = usuário lendo histórico.
		// Marca interação humana pra não forçar re-stick no meio da leitura.
		if ( window.jQuery ) {
			window.jQuery( document ).on(
				'jet-engine/listing-grid/after-load-more',
				markHumanInteraction
			);
		}
	}

	function bindViewportEvents() {
		var resizeRaf = null;

		window.addEventListener( 'resize', function () {
			if ( resizeRaf ) return;
			resizeRaf = window.requestAnimationFrame( function () {
				resizeRaf = null;
				setViewportHeight();
				if ( state.stickToBottom ) _scrollNow();
			} );
		} );

		window.addEventListener( 'orientationchange', function () {
			window.setTimeout( function () {
				setViewportHeight();
				if ( state.stickToBottom ) _scrollNow();
			}, 250 );
		} );

		window.addEventListener( 'pageshow', function () {
			setViewportHeight();
			scrollOnPageshow();
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState !== 'visible' ) return;
			setViewportHeight();
			if ( state.stickToBottom ) _scrollNow();
		} );
	}

	// ------------------------------------------------------------------
	// Boot
	// ------------------------------------------------------------------

	function boot() {
		if ( state.booted ) return;

		var parent = document.querySelector( cfg.selectors.parent );
		var messages = getMessages();

		if ( ! parent || ! messages ) return;

		state.booted = true;

		setViewportHeight();
		bindHumanInteraction();
		bindSubmitScroll();
		bindRuntimeEvents();
		bindViewportEvents();
		scrollOnBoot();

		window.ConversaChatLayout = {
			booted: true,
			scrollOnBoot: scrollOnBoot,
			scrollOnSubmit: scrollOnSubmit,
			scrollOnNewMessage: scrollOnNewMessage,
			scrollOnPageshow: scrollOnPageshow,
			scrollOnTakeover: scrollOnTakeover,
			scrollOnComposerExpand: scrollOnComposerExpand,
			anchorForPrepend: anchorForPrepend,
			isSticky: function () { return state.stickToBottom; },
			setSticky: function ( value ) {
				setSticky( value );
				if ( value ) _scrollNow();
			}
		};
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
