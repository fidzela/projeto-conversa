/**
 * Conversa Chat — Runtime (real-time)
 *
 * Responsabilidade: detectar mensagens novas e colocá-las no grid SEM
 * construir HTML de card no cliente.
 *
 *  - Detecção:   polling adaptativo no endpoint próprio `status` (barato).
 *  - Incremento: endpoint próprio `after` devolve os .jet-listing-grid__item
 *                renderizados pelo TEMPLATE REAL no servidor; aqui só
 *                anexamos, deduplicamos por data-post-id (atributo NATIVO
 *                do grid) e religamos os widgets com a API NATIVA
 *                JetEngine.initElementsHandlers (assets/js/frontend/src/main.js:599).
 *  - Full:       refresh completo usa o endpoint NATIVO do JetEngine
 *                (action jet_engine_ajax / handler get_listing —
 *                listings/ajax-handlers.php:479), com os dados que o próprio
 *                grid publica no DOM (data-nav, data-queried-id, data-id do
 *                widget Elementor). Nenhum endpoint "full" próprio.
 *
 * Eventos emitidos (para layout/composer/extensões):
 *  - conversa-chat:messages-appended
 *  - conversa-chat:messages-replaced
 *  - conversa-chat:tabstate
 */
(function () {
	"use strict";

	var cfg = window.ConversaChatConfig || {};

	if ( ! cfg.realtime || ! cfg.ajaxurl || ! cfg.nonce || ! cfg.conversa_id ) {
		return;
	}
	if ( window.ConversaChatRuntime && window.ConversaChatRuntime.booted ) {
		return;
	}

	var LOCK_TTL_MS = 9000;
	var LOCK_HEARTBEAT_MS = 3000;
	var PAUSE_ON_429_MS = 8000;
	var POST_SUBMIT_BURSTS = [ 0, 900, 1800, 3200 ];

	var state = {
		booted: false,
		isPrimary: false,
		mode: 'active',            // active | idle | hidden
		activeUntil: 0,
		pauseUntil: 0,
		pollTimer: null,
		heartbeatTimer: null,
		monitorTimer: null,
		burstTimers: [],
		statusInFlight: false,
		afterInFlight: false,
		beforeInFlight: false,
		fullInFlight: false,
		lastId: 0,
		oldestId: 0,
		olderExhausted: false,
		firstAppendDone: false,
		knownCount: 0,
		knownHash: ''
	};

	var tabId = makeTabId();
	var lockKey = 'conversa_chat_lock_' + cfg.user_id + '_' + cfg.conversa_id;

	function log() {
		if ( ! cfg.debug || ! window.console ) return;
		var args = Array.prototype.slice.call( arguments );
		args.unshift( '[ConversaChat]' );
		console.log.apply( console, args );
	}

	function emit( name, detail ) {
		try {
			window.dispatchEvent( new CustomEvent( name, { detail: detail || {} } ) );
		} catch ( e ) {}
	}

	function makeTabId() {
		try {
			var key = 'conversa_chat_tab_id';
			var id = window.sessionStorage.getItem( key );
			if ( ! id ) {
				id = ( window.crypto && window.crypto.randomUUID )
					? window.crypto.randomUUID()
					: 'tab_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2 );
				window.sessionStorage.setItem( key, id );
			}
			return id;
		} catch ( e ) {
			return 'tab_' + Date.now() + '_' + Math.random().toString( 36 ).slice( 2 );
		}
	}

	// ------------------------------------------------------------------
	// DOM nativo do grid
	// ------------------------------------------------------------------

	function getItemsContainer() {
		return document.querySelector( cfg.selectors.items );
	}

	function getGridRoot() {
		var container = getItemsContainer();
		if ( ! container ) return null;
		return container.closest( '.jet-listing-grid.jet-listing' ) || container;
	}

	function containerIsNotFound() {
		var container = getItemsContainer();
		return Boolean( container && container.classList.contains( 'jet-listing-not-found' ) );
	}

	/**
	 * Maior _ID presente no DOM — via data-post-id, atributo que o próprio
	 * JetEngine imprime em cada item (render/listing-grid.php:1694).
	 */
	function getMaxIdInDom() {
		var container = getItemsContainer();
		if ( ! container ) return 0;

		var max = 0;
		container.querySelectorAll( ':scope > .jet-listing-grid__item[data-post-id]' ).forEach( function ( el ) {
			var id = parseInt( el.getAttribute( 'data-post-id' ), 10 ) || 0;
			if ( id > max ) max = id;
		} );
		return max;
	}

	/**
	 * Menor _ID presente no DOM — o "topo" atual, ponto de partida para
	 * carregar as mensagens mais antigas.
	 */
	function getMinIdInDom() {
		var container = getItemsContainer();
		if ( ! container ) return 0;

		var min = 0;
		container.querySelectorAll( ':scope > .jet-listing-grid__item[data-post-id]' ).forEach( function ( el ) {
			var id = parseInt( el.getAttribute( 'data-post-id' ), 10 ) || 0;
			if ( id && ( min === 0 || id < min ) ) min = id;
		} );
		return min;
	}

	function hasItemInDom( id ) {
		var container = getItemsContainer();
		if ( ! container ) return false;
		return Boolean( container.querySelector( ':scope > .jet-listing-grid__item[data-post-id="' + id + '"]' ) );
	}

	/**
	 * data-nav que o próprio grid publica no DOM (garantido pelo filtro
	 * add-query-data — ver Integrations). Fonte da query assinada e dos
	 * widget_settings reais do grid.
	 */
	function getNav() {
		var container = getItemsContainer();
		if ( ! container ) return {};
		try {
			return JSON.parse( container.getAttribute( 'data-nav' ) || '{}' ) || {};
		} catch ( e ) {
			return {};
		}
	}

	/**
	 * widget_settings REAIS do grid, enviados ao servidor no render incremental
	 * para paridade com o load-more nativo (mesmo enfileiramento de assets do
	 * card). O servidor reimpõe o listing_id — o cliente não escolhe o listing.
	 */
	function widgetSettingsParam() {
		var nav = getNav();
		try {
			return JSON.stringify( nav.widget_settings || {} );
		} catch ( e ) {
			return '{}';
		}
	}

	function logAssets( label, data ) {
		if ( ! cfg.debug ) return;
		log( label, {
			scripts: data && data.scripts ? Object.keys( data.scripts ) : null,
			styles: data && data.styles ? Object.keys( data.styles ) : null,
			pending: ( window.JetEngine && window.JetEngine.assetsPromises || [] ).length
		} );
	}

	// ------------------------------------------------------------------
	// AJAX (endpoints próprios)
	// ------------------------------------------------------------------

	function postAjax( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'conversa_id', String( cfg.conversa_id ) );

		Object.keys( extra || {} ).forEach( function ( key ) {
			body.set( key, String( extra[ key ] ) );
		} );

		return window.fetch( cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'Cache-Control': 'no-cache'
			},
			body: body.toString()
		} ).then( function ( resp ) {
			return resp.json().then( function ( json ) {
				if ( ! resp.ok || ! json || json.success === false ) {
					var err = new Error( ( json && json.data && json.data.message ) || 'Erro AJAX' );
					err.status = resp.status;
					throw err;
				}
				return json.data || {};
			} );
		} );
	}

	function pauseIf429( err ) {
		if ( err && Number( err.status ) === 429 ) {
			state.pauseUntil = Date.now() + PAUSE_ON_429_MS;
		}
	}

	// ------------------------------------------------------------------
	// Status / sincronização
	// ------------------------------------------------------------------

	function applyStatus( status ) {
		if ( ! status ) return;
		state.knownHash = String( status.hash || state.knownHash || '' );
		state.knownCount = parseInt( status.count, 10 ) || 0;
		var lastId = parseInt( status.last_id, 10 ) || 0;
		if ( lastId > state.lastId ) state.lastId = lastId;
	}

	function checkStatus( reason ) {
		if ( ! state.isPrimary || state.statusInFlight ) return Promise.resolve( false );
		if ( state.pauseUntil && Date.now() < state.pauseUntil ) return Promise.resolve( false );

		state.statusInFlight = true;

		return postAjax( cfg.actions.status, {} )
			.then( function ( data ) {
				return decideSync( data.status || {}, reason || 'status' );
			} )
			.catch( function ( err ) {
				pauseIf429( err );
				log( 'status fail:', err );
				return false;
			} )
			.finally( function () {
				state.statusInFlight = false;
			} );
	}

	function decideSync( status, reason ) {
		var newHash = String( status.hash || '' );
		var newLastId = parseInt( status.last_id, 10 ) || 0;
		var newCount = parseInt( status.count, 10 ) || 0;

		if ( ! newHash || newHash === state.knownHash ) {
			return Promise.resolve( false );
		}

		// Caso normal de chat: chegou mensagem com _ID maior → incremental.
		if ( newLastId > state.lastId && ! containerIsNotFound() ) {
			return fetchAfter( state.lastId, reason );
		}

		// Mudança retroativa (remoção/despublicação) ou grid vazio:
		// re-render completo pelo pipeline nativo.
		if ( newCount !== state.knownCount || newLastId !== state.lastId || containerIsNotFound() ) {
			return fullRefresh( 'retroactive:' + reason );
		}

		applyStatus( status );
		return Promise.resolve( false );
	}

	// ------------------------------------------------------------------
	// Incremental: itens renderizados pelo template real, só anexar
	// ------------------------------------------------------------------

	function fetchAfter( afterId, reason ) {
		if ( state.afterInFlight ) return Promise.resolve( false );
		if ( state.pauseUntil && Date.now() < state.pauseUntil ) return Promise.resolve( false );

		state.afterInFlight = true;

		return postAjax( cfg.actions.after, { after_id: afterId, widget_settings: widgetSettingsParam() } )
			.then( function ( data ) {

				// 1. Assets ANTES do append: enqueueAssetsFromResponse injeta o
				//    CSS dos widgets do card no <head> (síncrono) e agenda os
				//    scripts (assíncrono, em JetEngine.assetsPromises). É o que
				//    o load-more nativo faz primeiro (main.js:551).
				enqueueAssets( data );
				logAssets( 'after assets:', data );

				// 2. Append + dedup por data-post-id (atributo nativo do grid).
				var nodes = appendItemsHtml( data.html || '' );

				if ( data.status ) applyStatus( data.status );

				if ( nodes.length ) {
					// 3. Religa os widgets SÓ depois que os scripts assíncronos
					//    carregarem (Promise.all(assetsPromises) — main.js:598).
					//    No PRIMEIRO append da sessão, um settle extra de 2 frames
					//    dá tempo do Elementor/CSS assentarem (sem dupla init) —
					//    reforço contra o "primeiro item pelado".
					initHandlersAfterAssets( nodes, ! state.firstAppendDone );
					state.firstAppendDone = true;

					markActive( 'messages-appended' );
					emit( 'conversa-chat:messages-appended', {
						reason: reason || 'after',
						afterId: afterId,
						appended: nodes.length
					} );
					if ( window.ConversaChatLayout ) {
						window.ConversaChatLayout.scrollOnNewMessage();
					}
					return true;
				}

				if ( data.appended > 0 ) {
					// O servidor tinha itens mas nada entrou no DOM
					// (container ausente/estado inesperado) → full nativo.
					return fullRefresh( 'append-miss:' + reason );
				}

				return false;
			} )
			.catch( function ( err ) {
				pauseIf429( err );
				log( 'after fail:', err );
				return false;
			} )
			.finally( function () {
				state.afterInFlight = false;
			} );
	}

	function appendItemsHtml( html ) {
		var container = getItemsContainer();

		html = String( html || '' ).trim();

		if ( ! container || ! html ) return [];

		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html;

		var items = tpl.content.querySelectorAll( '.jet-listing-grid__item' );
		var appendedNodes = [];

		items.forEach( function ( item ) {
			var id = parseInt( item.getAttribute( 'data-post-id' ), 10 ) || 0;

			if ( id && hasItemInDom( id ) ) return; // dedup pelo atributo nativo

			container.appendChild( item );
			appendedNodes.push( item );

			if ( id > state.lastId ) state.lastId = id;
		} );

		return appendedNodes; // NÃO religa aqui — quem chama decide (após assets).
	}

	/**
	 * Insere itens no TOPO do grid (mensagens antigas), preservando a ordem
	 * cronológica (o lote já vem ASC do servidor). Dedup por data-post-id.
	 * Não toca no scroll — a ancoragem é responsabilidade de quem chama
	 * (ConversaChatLayout.anchorForPrepend), que mantém a viewport parada.
	 */
	function prependItemsHtml( html ) {
		var container = getItemsContainer();

		html = String( html || '' ).trim();

		if ( ! container || ! html ) return [];

		var tpl = document.createElement( 'template' );
		tpl.innerHTML = html;

		var items = tpl.content.querySelectorAll( '.jet-listing-grid__item' );
		var prependedNodes = [];

		// Âncora fixa: todos entram ANTES do primeiro item atual, na ordem do
		// lote (ASC) → resultado: [lote antigo] + [itens já visíveis].
		var firstItem = container.querySelector( ':scope > .jet-listing-grid__item' );

		items.forEach( function ( item ) {
			var id = parseInt( item.getAttribute( 'data-post-id' ), 10 ) || 0;

			if ( id && hasItemInDom( id ) ) return; // dedup pelo atributo nativo

			container.insertBefore( item, firstItem );
			prependedNodes.push( item );

			if ( id && ( state.oldestId === 0 || id < state.oldestId ) ) {
				state.oldestId = id;
			}
		} );

		return prependedNodes;
	}

	// ------------------------------------------------------------------
	// Carregar mensagens ANTIGAS (rolar pra cima / botão "ver anteriores")
	// ------------------------------------------------------------------

	function fetchBefore( reason ) {
		if ( ! cfg.load_older || state.beforeInFlight || state.olderExhausted ) {
			return Promise.resolve( false );
		}
		if ( state.pauseUntil && Date.now() < state.pauseUntil ) return Promise.resolve( false );

		var beforeId = state.oldestId || getMinIdInDom();

		if ( ! beforeId || beforeId <= 1 ) {
			state.olderExhausted = true;
			updateOlderUI();
			return Promise.resolve( false );
		}

		state.beforeInFlight = true;
		setOlderLoading( true );

		return postAjax( cfg.actions.before, { before_id: beforeId, widget_settings: widgetSettingsParam() } )
			.then( function ( data ) {

				enqueueAssets( data );
				logAssets( 'before assets:', data );

				// Prepend ANCORADO: a mensagem que o usuário lia não se move.
				var nodes = [];
				var doPrepend = function () { nodes = prependItemsHtml( data.html || '' ); };

				if ( window.ConversaChatLayout && typeof window.ConversaChatLayout.anchorForPrepend === 'function' ) {
					window.ConversaChatLayout.anchorForPrepend( doPrepend );
				} else {
					doPrepend();
				}

				// Novo topo + fim do histórico.
				if ( data.oldest_id ) {
					state.oldestId = parseInt( data.oldest_id, 10 ) || state.oldestId;
				} else {
					state.oldestId = getMinIdInDom();
				}
				state.olderExhausted = ! data.has_more;

				if ( nodes.length ) {
					initHandlersAfterAssets( nodes, ! state.firstAppendDone );
					state.firstAppendDone = true;
					emit( 'conversa-chat:messages-prepended', {
						reason: reason || 'before',
						prepended: nodes.length,
						hasMore: ! state.olderExhausted
					} );
				}

				updateOlderUI();
				return true;
			} )
			.catch( function ( err ) {
				pauseIf429( err );
				log( 'before fail:', err );
				return false;
			} )
			.finally( function () {
				state.beforeInFlight = false;
				setOlderLoading( false );
			} );
	}

	/**
	 * Injeta os assets (CSS/JS de widget) que o servidor devolveu no response,
	 * usando a MESMA API do load-more nativo: CSS vai pro <head> na hora,
	 * scripts entram assíncronos e ficam em JetEngine.assetsPromises. Dedup
	 * por handle é do próprio JetEngine (main.js:1456-1481).
	 */
	function enqueueAssets( data ) {
		if ( window.JetEngine && typeof window.JetEngine.enqueueAssetsFromResponse === 'function' ) {
			try {
				window.JetEngine.enqueueAssetsFromResponse( { data: data } );
			} catch ( e ) {
				log( 'enqueueAssets fail:', e );
			}
		}
	}

	/**
	 * Religa os handlers dos widgets nos nós novos (mesma API do load-more,
	 * main.js:599). Sem ela, sliders/popups/etc. dentro do card não iniciam.
	 */
	function initHandlers( nodes ) {
		if ( ! window.JetEngine || ! window.jQuery ) return;

		try {
			if ( typeof window.JetEngine.initElementsHandlers === 'function' ) {
				window.JetEngine.initElementsHandlers( window.jQuery( nodes ) );
			}
		} catch ( e ) {
			log( 'initElementsHandlers fail:', e );
		}
	}

	/**
	 * Espera os scripts assíncronos carregarem ANTES de religar os handlers —
	 * exatamente a sequência do load-more nativo (main.js:598-601). Esta espera
	 * é o que corrige o "primeiro item pelado": no primeiro append um script do
	 * widget ainda não terminou de carregar quando o init roda; a partir do
	 * segundo ele já está na página, por isso "os próximos montam certo".
	 */
	function initHandlersAfterAssets( nodes, settle ) {
		if ( ! window.JetEngine || ! window.jQuery ) {
			initHandlers( nodes );
			return;
		}

		var promises = window.JetEngine.assetsPromises || [];

		Promise.resolve( Promise.all( promises ) ).then( function () {
			if ( window.JetEngine ) {
				window.JetEngine.assetsPromises = [];
			}

			if ( settle ) {
				// Espera 2 frames antes de religar (UMA única vez, sem dupla
				// init): dá tempo do Elementor/CSS assentarem no primeiro
				// append da sessão — reforço contra o "primeiro item pelado".
				window.requestAnimationFrame( function () {
					window.requestAnimationFrame( function () {
						initHandlers( nodes );
					} );
				} );
			} else {
				initHandlers( nodes );
			}
		} );
	}

	// ------------------------------------------------------------------
	// Full refresh — endpoint NATIVO do JetEngine (get_listing)
	// ------------------------------------------------------------------

	function fullRefresh( reason ) {
		if ( state.fullInFlight ) return Promise.resolve( false );

		var container = getItemsContainer();
		var gridRoot = getGridRoot();

		if ( ! container || ! gridRoot ) return Promise.resolve( false );

		var widgetEl = container.closest( '.elementor-widget' );
		var elementId = widgetEl ? widgetEl.getAttribute( 'data-id' ) : '';
		var queriedId = container.getAttribute( 'data-queried-id' ) || String( cfg.conversa_id );

		var nav = {};
		try {
			nav = JSON.parse( container.getAttribute( 'data-nav' ) || '{}' ) || {};
		} catch ( e ) {}

		var body = new URLSearchParams();
		body.set( 'action', 'jet_engine_ajax' );
		body.set( 'handler', 'get_listing' );
		body.set( 'page_settings[post_id]', String( cfg.conversa_id ) );
		body.set( 'page_settings[queried_id]', queriedId );
		body.set( 'page_settings[element_id]', elementId );
		body.set( 'page_settings[page]', '1' );
		body.set( 'listing_type', 'false' );
		body.set( 'isEditMode', 'false' );

		// Query assinada publicada pelo próprio grid no data-nav
		// (garantida pelo filtro add-query-data — ver Integrations).
		flattenInto( body, 'query', nav.query || {} );
		flattenInto( body, 'widget_settings', nav.widget_settings || {} );

		state.fullInFlight = true;

		return window.fetch( cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'Cache-Control': 'no-cache'
			},
			body: body.toString()
		} )
			.then( function ( resp ) { return resp.json(); } )
			.then( function ( json ) {
				if ( ! json || ! json.success || ! json.data || ! json.data.html ) {
					return false;
				}

				var tpl = document.createElement( 'template' );
				tpl.innerHTML = String( json.data.html ).trim();

				var next = tpl.content.querySelector( '.jet-listing-grid.jet-listing' ) || tpl.content.firstElementChild;

				if ( ! next ) return false;

				gridRoot.replaceWith( next );

				if ( window.JetEngine && typeof window.JetEngine.enqueueAssetsFromResponse === 'function' ) {
					try { window.JetEngine.enqueueAssetsFromResponse( json ); } catch ( e ) {}
				}

				initHandlersAfterAssets( [ next ] );

				state.lastId = Math.max( state.lastId, getMaxIdInDom() );

				emit( 'conversa-chat:messages-replaced', { reason: reason || 'full' } );

				if ( window.ConversaChatLayout ) {
					window.ConversaChatLayout.scrollOnNewMessage();
				}

				return true;
			} )
			.catch( function ( err ) {
				log( 'full fail:', err );
				return false;
			} )
			.finally( function () {
				state.fullInFlight = false;
			} );
	}

	function flattenInto( params, prefix, value ) {
		if ( value === null || value === undefined ) return;

		if ( typeof value === 'object' ) {
			var isArray = Array.isArray( value );
			var keys = isArray ? value.map( function ( v, i ) { return i; } ) : Object.keys( value );
			keys.forEach( function ( key ) {
				flattenInto( params, prefix + '[' + key + ']', value[ key ] );
			} );
			return;
		}

		params.set( prefix, String( value ) );
	}

	// ------------------------------------------------------------------
	// Polling adaptativo
	// ------------------------------------------------------------------

	function markActive( reason ) {
		state.activeUntil = Date.now() + ( cfg.active_ttl_ms || 90000 );
		if ( state.mode !== 'hidden' ) state.mode = 'active';
		if ( state.isPrimary ) scheduleNextPoll( reason || 'active' );
	}

	function currentDelay() {
		if ( state.mode === 'active' && Date.now() >= state.activeUntil ) {
			state.mode = 'idle';
		}
		return state.mode === 'active'
			? ( cfg.active_poll_ms || 4000 )
			: ( cfg.idle_poll_ms || 30000 );
	}

	function clearPollTimer() {
		if ( state.pollTimer ) {
			window.clearTimeout( state.pollTimer );
			state.pollTimer = null;
		}
	}

	function scheduleNextPoll( reason ) {
		clearPollTimer();

		if ( ! state.isPrimary || state.mode === 'hidden' ) return;

		state.pollTimer = window.setTimeout( function () {
			state.pollTimer = null;
			checkStatus( 'poll:' + ( reason || '' ) ).finally( function () {
				scheduleNextPoll( 'next' );
			} );
		}, currentDelay() );
	}

	function cancelBursts() {
		state.burstTimers.forEach( window.clearTimeout );
		state.burstTimers = [];
	}

	/** Após enviar: rajada curta de checks pra capturar a própria mensagem. */
	function scheduleBursts() {
		cancelBursts();
		POST_SUBMIT_BURSTS.forEach( function ( delay ) {
			state.burstTimers.push( window.setTimeout( function () {
				if ( state.isPrimary ) checkStatus( 'post-submit-' + delay );
			}, delay ) );
		} );
	}

	// ------------------------------------------------------------------
	// Tab lock (uma aba primária por usuário+conversa)
	// ------------------------------------------------------------------

	function canUseStorage() {
		try {
			window.localStorage.setItem( '__cchat__', '1' );
			window.localStorage.removeItem( '__cchat__' );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function readLock() {
		try {
			var raw = window.localStorage.getItem( lockKey );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function writeLock() {
		try {
			window.localStorage.setItem( lockKey, JSON.stringify( { tabId: tabId, t: Date.now() } ) );
		} catch ( e ) {}
	}

	function clearOwnLock() {
		var lock = readLock();
		if ( lock && lock.tabId === tabId ) {
			try { window.localStorage.removeItem( lockKey ); } catch ( e ) {}
		}
	}

	function lockExpired( lock ) {
		return ! lock || ! lock.t || ( Date.now() - Number( lock.t ) ) > LOCK_TTL_MS;
	}

	function checkOwnership( reason ) {
		if ( ! cfg.tab_lock || ! canUseStorage() ) {
			becomePrimary( 'lock-off' );
			return;
		}

		var lock = readLock();

		if ( ! lock || lock.tabId === tabId || lockExpired( lock ) ) {
			becomePrimary( reason );
		} else {
			becomeSecondary( reason );
		}
	}

	function becomePrimary( reason ) {
		writeLock();

		var wasPrimary = state.isPrimary;
		state.isPrimary = true;

		document.documentElement.classList.add( 'conversa-chat-primary-tab' );
		document.documentElement.classList.remove( 'conversa-chat-secondary-tab' );

		hideNotice();
		startHeartbeat();

		if ( ! wasPrimary ) {
			markActive( 'primary:' + ( reason || '' ) );
			checkStatus( 'become-primary' ).finally( function () {
				scheduleNextPoll( 'become-primary' );
			} );
			emit( 'conversa-chat:tabstate', { isPrimary: true, tabId: tabId } );
		}
	}

	function becomeSecondary( reason ) {
		state.isPrimary = false;

		document.documentElement.classList.remove( 'conversa-chat-primary-tab' );
		document.documentElement.classList.add( 'conversa-chat-secondary-tab' );

		stopHeartbeat();
		clearPollTimer();
		cancelBursts();
		showNotice();

		emit( 'conversa-chat:tabstate', { isPrimary: false, tabId: tabId, reason: reason } );
	}

	function takeOver() {
		writeLock();
		state.isPrimary = false;
		becomePrimary( 'takeover' );
		if ( window.ConversaChatLayout ) window.ConversaChatLayout.scrollOnTakeover();
	}

	function startHeartbeat() {
		stopHeartbeat();
		if ( ! cfg.tab_lock ) return;
		state.heartbeatTimer = window.setInterval( function () {
			if ( state.isPrimary ) writeLock();
		}, LOCK_HEARTBEAT_MS );
	}

	function stopHeartbeat() {
		if ( state.heartbeatTimer ) {
			window.clearInterval( state.heartbeatTimer );
			state.heartbeatTimer = null;
		}
	}

	function startLockMonitor() {
		if ( ! cfg.tab_lock ) return;
		state.monitorTimer = window.setInterval( function () {
			if ( state.isPrimary ) return;
			if ( lockExpired( readLock() ) ) checkOwnership( 'monitor' );
		}, 1500 );
	}

	// Aviso de aba secundária ------------------------------------------------

	function ensureNotice() {
		var n = document.querySelector( '.conversa-chat-tab-notice' );
		if ( n ) return n;

		n = document.createElement( 'div' );
		n.className = 'conversa-chat-tab-notice';
		n.hidden = true;
		n.setAttribute( 'role', 'status' );
		n.innerHTML =
			'<div class="conversa-chat-tab-notice__box">' +
				'<div class="conversa-chat-tab-notice__content">' +
					'<strong>' + ( cfg.i18n.other_tab_title || '' ) + '</strong>' +
					'<span>' + ( cfg.i18n.other_tab_text || '' ) + '</span>' +
				'</div>' +
				'<button type="button" class="conversa-chat-tab-notice__button">' + ( cfg.i18n.take_over || 'OK' ) + '</button>' +
			'</div>';

		document.body.appendChild( n );

		n.querySelector( '.conversa-chat-tab-notice__button' ).addEventListener( 'click', takeOver );

		return n;
	}

	function showNotice() {
		var n = ensureNotice();
		n.hidden = false;
		window.requestAnimationFrame( function () { n.classList.add( 'is-visible' ); } );
	}

	function hideNotice() {
		var n = document.querySelector( '.conversa-chat-tab-notice' );
		if ( ! n ) return;
		n.classList.remove( 'is-visible' );
		window.setTimeout( function () {
			if ( state.isPrimary ) n.hidden = true;
		}, 240 );
	}

	// ------------------------------------------------------------------
	// UI de "carregar antigas" (botão + scroll-to-top) e neutralização do
	// load-more NATIVO (que anexa no FIM — errado para chat).
	// ------------------------------------------------------------------

	var olderControl = null;
	var olderButton = null;

	function wantsButton() {
		return cfg.load_older && ( cfg.older_trigger === 'button' || cfg.older_trigger === 'both' );
	}

	function wantsScroll() {
		return cfg.load_older && ( cfg.older_trigger === 'scroll' || cfg.older_trigger === 'both' );
	}

	function ensureOlderControl() {
		if ( ! wantsButton() || olderControl ) return olderControl;

		var messages = document.querySelector( cfg.selectors.messages );
		if ( ! messages ) return null;

		olderControl = document.createElement( 'div' );
		olderControl.className = 'conversa-chat-older';

		olderButton = document.createElement( 'button' );
		olderButton.type = 'button';
		olderButton.className = 'conversa-chat-older__button';
		olderButton.textContent = ( cfg.i18n && cfg.i18n.load_older ) || 'Ver mensagens anteriores';
		olderButton.addEventListener( 'click', function () { fetchBefore( 'button' ); } );

		olderControl.appendChild( olderButton );

		// Acima do grid, dentro da área rolável das mensagens.
		messages.insertBefore( olderControl, messages.firstChild );

		return olderControl;
	}

	function updateOlderUI() {
		if ( ! olderControl ) return;
		olderControl.hidden = Boolean( state.olderExhausted );
	}

	function setOlderLoading( loading ) {
		if ( ! olderButton ) return;
		olderButton.disabled = Boolean( loading );
		olderButton.textContent = loading
			? ( ( cfg.i18n && cfg.i18n.loading_older ) || 'Carregando…' )
			: ( ( cfg.i18n && cfg.i18n.load_older ) || 'Ver mensagens anteriores' );
	}

	function bindOlderScroll() {
		if ( ! wantsScroll() ) return;

		var messages = document.querySelector( cfg.selectors.messages );
		if ( ! messages ) return;

		var TOP_THRESHOLD_PX = 60;

		messages.addEventListener( 'scroll', function () {
			if ( state.beforeInFlight || state.olderExhausted ) return;
			if ( messages.scrollTop <= TOP_THRESHOLD_PX ) {
				fetchBefore( 'scroll-top' );
			}
		}, { passive: true } );
	}

	/**
	 * Esconde o load-more NATIVO do grid: ele anexa a próxima página no FIM
	 * (feed que cresce pra baixo), o oposto do que um chat quer. Aqui o
	 * histórico é carregado pra cima (prepend). Inofensivo se o load-more
	 * nativo estiver desligado no widget.
	 */
	function neutralizeNativeLoadMore() {
		if ( ! cfg.load_older ) return;

		var gridRoot = getGridRoot();
		if ( ! gridRoot ) return;

		gridRoot.querySelectorAll( '.jet-listing-grid__loadmore, .jet-listing-load-more' ).forEach( function ( el ) {
			el.style.display = 'none';
		} );
	}

	function setupOlder() {
		if ( ! cfg.load_older ) return;

		state.oldestId = getMinIdInDom();
		// has_older vem do servidor no boot (count total > initial_limit).
		state.olderExhausted = ! cfg.has_older;

		neutralizeNativeLoadMore();
		ensureOlderControl();
		bindOlderScroll();
		updateOlderUI();
	}

	// ------------------------------------------------------------------
	// Eventos de página/form
	// ------------------------------------------------------------------

	function isChatForm( el ) {
		if ( ! el || ! el.matches ) return false;
		try { return el.matches( cfg.selectors.form ); } catch ( e ) { return false; }
	}

	function bindEvents() {

		// Digitação no composer mantém o modo ativo (polling rápido).
		document.addEventListener( 'input', function ( e ) {
			if ( e.target && e.target.tagName === 'TEXTAREA' && isChatForm( e.target.form ) && state.isPrimary ) {
				markActive( 'typing' );
			}
		}, true );

		// Submit: aba secundária bloqueia (evita duas abas enviando);
		// primária agenda a rajada de sincronização.
		document.addEventListener( 'submit', function ( e ) {
			if ( ! isChatForm( e.target ) ) return;

			if ( ! state.isPrimary ) {
				e.preventDefault();
				e.stopPropagation();
				showNotice();
				return;
			}

			markActive( 'submit' );
			scheduleBursts();
		}, true );

		// Confirmação nativa do JetFormBuilder: mensagem gravada no CCT.
		if ( window.jQuery ) {
			window.jQuery( document ).on( 'jet-form-builder/ajax/on-success', function ( event, response, $form ) {
				var form = $form && $form[0] ? $form[0] : null;
				if ( ! isChatForm( form ) || ! state.isPrimary ) return;
				markActive( 'jfb-success' );
				checkStatus( 'jfb-success' );
			} );

			window.jQuery( document ).on( 'jet-form-builder/ajax/on-fail jet-form-builder/ajax/processing-error', function ( event, response, $form ) {
				var form = $form && $form[0] ? $form[0] : null;
				if ( ! isChatForm( form ) ) return;
				cancelBursts();
			} );
		}

		// Tab lock entre abas.
		window.addEventListener( 'storage', function ( e ) {
			if ( ! cfg.tab_lock || e.key !== lockKey ) return;
			var lock = readLock();
			if ( ! lock || lockExpired( lock ) ) {
				checkOwnership( 'storage-empty' );
			} else if ( lock.tabId === tabId ) {
				becomePrimary( 'storage-own' );
			} else {
				becomeSecondary( 'storage-other' );
			}
		} );

		window.addEventListener( 'beforeunload', clearOwnLock );
		window.addEventListener( 'pagehide', clearOwnLock );

		window.addEventListener( 'pageshow', function () {
			if ( ! state.isPrimary ) return;
			markActive( 'pageshow' );
			checkStatus( 'pageshow' );
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'hidden' ) {
				if ( state.isPrimary ) {
					state.mode = 'hidden';
					clearPollTimer();
				}
				return;
			}

			state.mode = 'active';

			if ( state.isPrimary ) writeLock();
			checkOwnership( 'visible' );

			if ( state.isPrimary ) {
				markActive( 'visible' );
				checkStatus( 'visible' );
			}
		} );
	}

	// ------------------------------------------------------------------
	// Boot
	// ------------------------------------------------------------------

	function boot() {
		if ( state.booted ) return;
		state.booted = true;

		var initial = cfg.initial_status || {};

		state.knownHash = String( initial.hash || '' );
		state.knownCount = parseInt( initial.count, 10 ) || 0;
		state.lastId = Math.max( parseInt( initial.last_id, 10 ) || 0, getMaxIdInDom() );

		bindEvents();
		setupOlder();
		checkOwnership( 'boot' );
		startLockMonitor();

		window.ConversaChatRuntime = {
			booted: true,
			version: '1.0.3',
			checkStatus: checkStatus,
			refreshFull: function () { return fullRefresh( 'manual' ); },
			loadOlder: function () { return fetchBefore( 'manual' ); },
			isPrimary: function () { return state.isPrimary; },
			takeOver: takeOver,
			getState: function () {
				return {
					mode: state.mode,
					isPrimary: state.isPrimary,
					lastId: state.lastId,
					oldestId: state.oldestId,
					olderExhausted: state.olderExhausted,
					knownCount: state.knownCount,
					knownHash: state.knownHash
				};
			}
		};

		log( 'runtime ativo', window.ConversaChatRuntime.getState() );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
})();
