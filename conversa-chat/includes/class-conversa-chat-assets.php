<?php
/**
 * Assets — CSS/JS do chat, enfileirados APENAS na single da conversa.
 *
 * Três scripts com responsabilidades separadas (e removíveis uma a uma):
 *  - layout.js   → estrutura de chat + scroll por contexto explícito.
 *  - composer.js → UX do textarea (auto-size). O comportamento do form é
 *                  100% do JetFormBuilder (submit AJAX, clear nativo).
 *  - runtime.js  → real-time (polling, fetch incremental, append nativo).
 *                  Só é enfileirado se o real-time estiver ligado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Assets {

	public static function init() {

		if ( ! Conversa_Chat_Context::can_register_hooks() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {

		$ctx = Conversa_Chat_Context::get();

		if ( ! $ctx ) {
			return;
		}

		$ver = CONVERSA_CHAT_VERSION;

		wp_enqueue_style(
			'conversa-chat',
			CONVERSA_CHAT_URL . 'assets/css/chat.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'conversa-chat-layout',
			CONVERSA_CHAT_URL . 'assets/js/layout.js',
			array(),
			$ver,
			true
		);

		// jQuery é dependência porque os eventos do JetFormBuilder
		// (jet-form-builder/ajax/on-success etc.) são jQuery events
		// (jetformbuilder/modules/user-journey/module.php:594).
		wp_enqueue_script(
			'conversa-chat-composer',
			CONVERSA_CHAT_URL . 'assets/js/composer.js',
			array( 'jquery', 'conversa-chat-layout' ),
			$ver,
			true
		);

		$realtime = conversa_chat()->realtime_enabled( $ctx['conversa_id'] );

		if ( $realtime ) {

			$deps = array( 'jquery', 'conversa-chat-layout' );

			// O runtime usa APIs JS nativas do JetEngine quando presentes
			// (JetEngine.initElementsHandlers / enqueueAssetsFromResponse).
			if ( wp_script_is( 'jet-engine-frontend', 'registered' ) ) {
				$deps[] = 'jet-engine-frontend';
			}

			wp_enqueue_script(
				'conversa-chat-runtime',
				CONVERSA_CHAT_URL . 'assets/js/runtime.js',
				$deps,
				$ver,
				true
			);
		}

		wp_add_inline_script(
			'conversa-chat-layout',
			'window.ConversaChatConfig = ' . wp_json_encode( self::front_config( $ctx, $realtime ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
	}

	/**
	 * Configuração entregue ao front. Tudo que o JS conhece do site passa
	 * por aqui — nenhum ID/seletor é hard-coded nos arquivos .js.
	 */
	private static function front_config( $ctx, $realtime ) {

		$settings   = conversa_chat()->settings();
		$listing_id = (int) $settings['listing_id'];

		$status = Conversa_Chat_Data::get_status( $ctx['conversa_id'] );

		if ( is_wp_error( $status ) ) {
			$status = array(
				'last_id'      => 0,
				'count'        => 0,
				'last_changed' => '',
				'hash'         => '',
			);
		}

		// Seletor NATIVO do form JetFormBuilder: o <form> carrega a classe
		// jet-form-builder e data-form-id (form-builder.php:139,142).
		$form_selectors = array();
		foreach ( (array) $settings['form_ids'] as $form_id ) {
			$form_selectors[] = sprintf(
				'%s form.jet-form-builder[data-form-id="%d"]',
				$settings['selectors']['footer'],
				(int) $form_id
			);
		}

		$config = array(
			'ajaxurl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( Conversa_Chat_Ajax::NONCE_ACTION ),
			'actions'        => array(
				'status' => 'conversa_chat_status',
				'after'  => 'conversa_chat_after',
				'before' => 'conversa_chat_before',
			),
			'conversa_id'    => $ctx['conversa_id'],
			'user_id'        => $ctx['user_id'],
			'role'           => $ctx['role'],
			'listing_id'     => $listing_id,
			'selectors'      => array(
				'parent'   => $settings['selectors']['parent'],
				'header'   => $settings['selectors']['header'],
				'messages' => $settings['selectors']['messages'],
				'footer'   => $settings['selectors']['footer'],
				// Container de itens NATIVO do grid: o próprio JetEngine
				// imprime data-listing-id no wrapper __items
				// (render/listing-grid.php:1518-1529).
				'items'    => $settings['selectors']['messages'] . ' [data-listing-id="' . $listing_id . '"]',
				'form'     => implode( ', ', $form_selectors ),
			),
			'realtime'       => (bool) $realtime,
			'clear_on_success' => (bool) $settings['clear_composer_on_success'],

			// Carregar mensagens antigas (rolar pra cima). has_older diz, já no
			// boot, se existem mensagens além das exibidas (count total >
			// initial_limit) — evita mostrar o botão/gatilho quando não há mais
			// nada para carregar.
			'load_older'     => (bool) $settings['load_older'] && (int) $settings['initial_limit'] > 0,
			'older_trigger'  => (string) $settings['older_trigger'],
			'has_older'      => ( (int) $settings['initial_limit'] > 0 )
				&& ( (int) ( is_array( $status ) ? $status['count'] : 0 ) > (int) $settings['initial_limit'] ),

			'initial_status' => $status,
			'active_poll_ms' => (int) $settings['active_poll_ms'],
			'idle_poll_ms'   => (int) $settings['idle_poll_ms'],
			'active_ttl_ms'  => (int) $settings['active_ttl_ms'],
			'tab_lock'       => (bool) $settings['tab_lock'],
			'debug'          => (bool) $settings['debug'],
			'i18n'           => array(
				'other_tab_title' => __( 'Conversa aberta em outra aba', 'conversa-chat' ),
				'other_tab_text'  => __( 'Esta aba está em modo leitura.', 'conversa-chat' ),
				'take_over'       => __( 'Usar esta aba', 'conversa-chat' ),
				'load_older'      => __( 'Ver mensagens anteriores', 'conversa-chat' ),
				'loading_older'   => __( 'Carregando…', 'conversa-chat' ),
			),
		);

		/**
		 * Ajuste fino da configuração do front sem tocar no plugin.
		 */
		return apply_filters( 'conversa-chat/front-config', $config, $ctx );
	}
}
