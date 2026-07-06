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
 * Também (ainda SÓ UX, sem tocar no envio do JFB):
 *  - suprime o balão nativo de validação do browser ("Preencha este campo") —
 *    mantém a obrigatoriedade, tira só o popup feio (suppressNativeValidation);
 *  - detecta o campo de MÍDIA nativo do JFB (.jet-form-builder-file-upload) e
 *    liga o layout de anexo (+ / previews). O upload, a miniatura e o excluir
 *    são 100% do JFB (media-field.php + media.field.js); aqui só estilizamos.
 *    No sucesso, limpa a mídia pelo caminho nativo (clearMedia dispara o
 *    excluir de cada preview) para o espaço reservado recolher;
 *  - ancora previews/"+" em absoluto no <form> (unpositionUpTo) para o preview
 *    não sumir sob um wrapper posicionado do bloco.
 *  A mensagem de status "só em erro" e os erros de validação layout-safe são
 *  puramente CSS (chat.css §2 e §2b). Fluxo completo do JFB em docs/11.
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

	/**
	 * Auto-size do textarea — SEM o "desce e sobe" (bug reportado).
	 *
	 * O bug: quando o texto da 1ª linha chegava perto do botão, ele passava
	 * "por baixo" e o campo oscilava. Causa raiz: a classe .is-expanded troca a
	 * reserva lateral do botão por reserva no rodapé — ou seja, muda a LARGURA
	 * útil do textarea. Medir o scrollHeight numa largura e renderizar noutra
	 * fazia a linha ora quebrar ora não a cada tecla → oscilação.
	 *
	 * Correção em duas frentes:
	 *  1) Expansão STICKY: uma vez expandido, permanece até o campo esvaziar.
	 *     Elimina o vai-e-volta no limiar de quebra da 1ª linha.
	 *  2) Dupla medição: decide o estado, aplica a classe (que define a largura
	 *     FINAL) e só então mede a altura — o scrollHeight passa a bater com a
	 *     largura real. O botão fica fixo e o texto "sobe" sem atropelar nada.
	 */
	function autoSize( form, cache ) {
		var ta = cache.textarea;
		if ( ! ta ) return;

		var value     = String( ta.value || '' );
		var textEmpty = value.trim().length === 0;

		// No layout de mídia, uma imagem anexada conta como conteúdo: o botão
		// não fica "cinza" (permite enviar só imagem, se o form permitir texto
		// vazio). O tamanho do textarea segue guiado só pelo texto.
		var hasPreview = form.classList.contains( 'conversa-composer-has-previews' );
		var empty      = textEmpty && ! hasPreview;

		var minH = getCssNumber( ta, '--conversa-composer-textarea-min-height', 34 );
		var maxH = getCssNumber( ta, '--conversa-composer-textarea-max-height', 168 );

		// 1) Decide o estado medindo na largura ATUAL. Expansão é sticky e é
		//    guiada pelo TEXTO (a mídia não expande o textarea).
		ta.style.height = 'auto';
		var wasExpanded = form.classList.contains( 'conversa-composer-is-expanded' );
		var needsExpand = value.indexOf( '\n' ) !== -1 || ta.scrollHeight > minH + 8;
		var expanded    = ! textEmpty && ( wasExpanded || needsExpand );

		form.classList.toggle( 'conversa-composer-is-expanded', expanded );
		form.classList.toggle( 'conversa-composer-is-empty', empty );

		// 2) Mede a ALTURA já na largura FINAL do estado escolhido (a classe
		//    acima pode ter mudado a reserva lateral → a largura útil). Sem esta
		//    segunda medição o scrollHeight referencia a largura antiga.
		ta.style.height = 'auto';
		var next = textEmpty ? minH : Math.max( minH, Math.min( ta.scrollHeight, maxH ) );
		ta.style.height = next + 'px';
		ta.style.overflowY = ( ! textEmpty && ta.scrollHeight > maxH ) ? 'auto' : 'hidden';

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

	/**
	 * Limpa o textarea após um envio bem-sucedido.
	 *
	 * O JetFormBuilder tem "Clear form after submit" nativo (atributo data-clear,
	 * form-builder.php:146) — mas ele depende de estar LIGADO nas config do form,
	 * e por padrão vem desligado. Este fallback garante o comportamento esperado
	 * de chat (campo vazio após enviar) mesmo sem o setting, sem brigar com o
	 * envio: roda SÓ no evento on-success (mensagem já gravada no CCT).
	 *
	 * Dispara 'input'/'change' para o modelo reativo do JFB e o auto-size
	 * re-sincronizarem com o valor vazio. Desligável via cfg.clear_on_success.
	 */
	function clearComposer( form ) {
		var cache = getCache( form, true );
		var ta = cache.textarea;

		if ( ! ta || ta.value === '' ) return;

		ta.value = '';

		try {
			ta.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			ta.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} catch ( e ) {}

		scheduleAutoSize( form );
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

		suppressNativeValidation( form );
		wireMedia( form );

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

	/**
	 * Suprime o balão nativo do browser ("Preencha este campo") — feio.
	 *
	 * NÃO remove a obrigatoriedade do campo: o form inválido continua sem
	 * enviar (o botão fica cinza no estado vazio), apenas sem o popup nativo.
	 * Captura na fase de captura para pegar o evento antes do default do
	 * browser (que mostraria o balão). Idempotente por form.
	 */
	function suppressNativeValidation( form ) {
		if ( form.dataset.conversaNoValidateBubble === '1' ) return;
		form.dataset.conversaNoValidateBubble = '1';

		form.addEventListener( 'invalid', function ( e ) {
			e.preventDefault();
		}, true );
	}

	/**
	 * Layout de MÍDIA — detecta o campo de upload NATIVO do JetFormBuilder
	 * (.jet-form-builder-file-upload, media-field.php) e liga o revestimento
	 * visual: o input file vira o botão "anexar" (+) e a área de previews
	 * (.jet-form-builder-file-upload__files) vira a régua de miniaturas.
	 *
	 * Regra de Ouro: o plugin NÃO cria uploader nem previews — quem faz upload,
	 * mostra miniatura e exclui (.__file-remove) é o próprio JFB. Aqui só:
	 *  - marca .conversa-composer-has-media (o CSS aplica o layout do +/toolbar);
	 *  - observa a lista de previews p/ .conversa-composer-has-previews (reserva
	 *    o espaço no topo só quando há mídia anexada) e remede a altura.
	 */
	/**
	 * Neutraliza `position` de todos os ancestrais de `node` até `stop`
	 * (exclusivo). Necessário porque o revestimento posiciona os previews e o
	 * botão "+" em ABSOLUTO relativo ao <form>: se algum wrapper intermediário
	 * do bloco do Media Field for `position: relative`, o absoluto resolveria
	 * NELE (o preview aparecia fora da moldura / atrás do textarea — o "espaço
	 * reservado no topo, mas a miniatura não aparecia"). Só toca ancestrais,
	 * nunca o próprio node nem seus filhos (o .__file-remove segue relativo ao
	 * .__file).
	 */
	function unpositionUpTo( node, stop ) {
		var el = node && node.parentElement;
		while ( el && el !== stop && el !== document.body ) {
			if ( window.getComputedStyle( el ).position !== 'static' ) {
				el.style.position = 'static';
			}
			el = el.parentElement;
		}
	}

	function wireMedia( form ) {
		var upload = form.querySelector( '.jet-form-builder-file-upload' );
		if ( ! upload ) return;

		form.classList.add( 'conversa-composer-has-media' );

		var files  = upload.querySelector( '.jet-form-builder-file-upload__files' );
		var fields = upload.querySelector( '.jet-form-builder-file-upload__fields' );

		// Ancoragem determinística no <form> (ver unpositionUpTo).
		unpositionUpTo( files, form );
		unpositionUpTo( fields, form );

		if ( ! files ) return;

		var syncPreviews = function () {
			var has = files.querySelector( '.jet-form-builder-file-upload__file' ) !== null;
			form.classList.toggle( 'conversa-composer-has-previews', has );
			scheduleAutoSize( form );
		};

		syncPreviews();

		if ( typeof MutationObserver === 'function' ) {
			new MutationObserver( syncPreviews ).observe( files, { childList: true } );
		}
	}

	/**
	 * Limpa a MÍDIA anexada após envio OK (o clear nativo do JFB e o nosso
	 * clearComposer só cuidavam do texto — a miniatura e o espaço reservado
	 * ficavam pendurados). Reset pelo caminho NATIVO do JFB: dispara o clique
	 * no botão de excluir de cada preview (.__file-remove → removeFile do JFB),
	 * que atualiza o valor interno do campo e remove o .__file do DOM. O
	 * MutationObserver do wireMedia então desliga o has-previews e recolhe o
	 * espaço. Desligável junto com clear_on_success.
	 */
	function clearMedia( form ) {
		var removes = form.querySelectorAll( '.jet-form-builder-file-upload__file-remove' );
		Array.prototype.forEach.call( removes, function ( btn ) {
			try { btn.click(); } catch ( e ) {}
		} );
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

		// Envio OK: limpa o campo (fallback robusto) e re-mede o textarea.
		$doc.on( 'jet-form-builder/ajax/on-success', function ( event, response, $form ) {
			var form = $form && $form[0] ? $form[0] : null;
			if ( ! form || ! form.matches || ! form.matches( cfg.selectors.form ) ) return;

			if ( cfg.clear_on_success !== false ) {
				clearComposer( form );
				clearMedia( form );
			}

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
