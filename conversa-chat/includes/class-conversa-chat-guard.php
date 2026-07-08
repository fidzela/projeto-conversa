<?php
/**
 * Guard — autorização do ENVIO de mensagem (o "segundo coração", lado servidor).
 *
 * POR QUE EXISTE (achado da auditoria de segurança):
 * A Action nativa "Insert/Update CCT" do JetEngine (custom-content-types/inc/
 * forms/action.php → do_action) só valida autoria no UPDATE (quando _ID vem no
 * request). No INSERT ela grava o que o fields_map mapear do POST — ou seja,
 * sem este guard, um request adulterado poderia:
 *  - inserir mensagem em QUALQUER conversa (trocando conversa_id);
 *  - assinar a mensagem como QUALQUER usuário (trocando from_user);
 *  - anexar QUALQUER attachment da biblioteca de mídia (trocando message_image).
 *
 * ONDE ELE SE PLUGA (regra de ouro — só hooks nativos):
 *  - jet-form-builder/form-handler/before-send (form-handler.php:311) marca que
 *    o request atual é uma SUBMISSÃO DE FORM JFB (front). Fora desse caminho
 *    (admin do JetEngine, REST, código) o guard NÃO interfere: esses caminhos
 *    têm suas próprias regras nativas de capability.
 *  - jet-engine/custom-content-types/item-to-update (item-handler.php:405) é o
 *    ponto de estrangulamento: roda ANTES do INSERT e do UPDATE, para todo
 *    caminho de escrita do CCT, com o $item final já montado.
 *
 * COMO ELE REJEITA (mecanismo nativo do JFB):
 *  throw Action_Exception->dynamic_error() — exatamente o que a própria Action
 *  do CCT usa. A exceção sobe pelo update_item() → do_action() da Action → é
 *  capturada pelo Form_Handler, que devolve o status de erro ao front (o
 *  composer já exibe status apenas quando é erro).
 *
 * O QUE ELE GARANTE (para submissões JFB que escrevem no CCT de mensagens):
 *  1. Login obrigatório.
 *  2. conversa_id aponta para um post publish do CPT certo.
 *  3. O usuário logado é participante da conversa (mesma regra dos endpoints
 *     de leitura — Conversa_Chat_Context::is_participant, com o mesmo filtro).
 *  4. from_user é SEMPRE o usuário logado (anti-spoof por sobrescrita, não por
 *     rejeição: o valor do POST é irrelevante).
 *  5. cct_author_id idem (coerência com o autor nativo do CCT).
 *  6. message_image (quando numérico) é um attachment DE IMAGEM cujo autor é o
 *     próprio remetente (anti-IDOR na biblioteca de mídia).
 *  7. Anti-flood: rate limit de envio por usuário+conversa (rate_send).
 *  8. Anti-CSRF secundário: referer cross-origin explícito é rejeitado
 *     (complementa — não substitui — o WP Nonce nativo do JFB, ver docs/11).
 *
 * NADA disso engessa: tudo é sobrescrevível pelos filtros
 * conversa-chat/guard-send e conversa-chat/is-participant, e as mensagens de
 * erro aparecem pelo pipeline nativo de status do JFB.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Guard {

	/**
	 * form_id da submissão JFB em andamento neste request (null = request que
	 * não é submissão de form JFB — admin, REST, código).
	 *
	 * @var int|null
	 */
	private static $jfb_form_id = null;

	public static function init() {

		// Marca o request como "submissão de form JFB" (form-handler.php:311
		// dispara tanto no submit AJAX quanto no submit com reload).
		add_action(
			'jet-form-builder/form-handler/before-send',
			array( __CLASS__, 'flag_jfb_submission' )
		);

		// Ponto de estrangulamento: TODO insert/update de CCT passa aqui
		// (item-handler.php:405), antes da escrita no banco.
		add_filter(
			'jet-engine/custom-content-types/item-to-update',
			array( __CLASS__, 'guard_item' ),
			10,
			3
		);
	}

	/**
	 * @param object $handler Jet_Form_Builder\Form_Handler ($form_id é público).
	 */
	public static function flag_jfb_submission( $handler ) {
		self::$jfb_form_id = ! empty( $handler->form_id ) ? (int) $handler->form_id : -1;
	}

	/**
	 * Valida (e corrige) o item ANTES da escrita no CCT de mensagens.
	 *
	 * @param  array  $item    Item final montado pelo Item_Handler.
	 * @param  array  $fields  Campos brutos recebidos.
	 * @param  object $handler Item_Handler (get_factory() é público).
	 * @return array  Item validado, com autor imposto pelo servidor.
	 */
	public static function guard_item( $item, $fields, $handler ) {

		// Só o CCT de mensagens do chat.
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'get_factory' ) ) {
			return $item;
		}

		$factory = $handler->get_factory();

		if ( ! $factory || $factory->get_arg( 'slug' ) !== conversa_chat()->setting( 'cct_slug' ) ) {
			return $item;
		}

		// Só submissões de form JFB (front). Admin/REST/código seguem as regras
		// de capability nativas de cada caminho.
		if ( null === self::$jfb_form_id ) {
			return $item;
		}

		/**
		 * Escape hatch documentado: permite desligar/estender o guard para um
		 * form específico (ex.: um form administrativo legítimo que escreve no
		 * CCT com outras regras). Default: TODO form JFB é guardado.
		 */
		if ( ! apply_filters( 'conversa-chat/guard-send', true, $item, self::$jfb_form_id ) ) {
			return $item;
		}

		self::check_referer();

		$user_id = (int) get_current_user_id();

		if ( ! $user_id ) {
			self::reject( __( 'Faça login para enviar mensagens.', 'conversa-chat' ) );
		}

		// Conversa válida: post publish do CPT configurado.
		$conversa_field = conversa_chat()->setting( 'conversa_field' );
		$conversa_id    = ! empty( $item[ $conversa_field ] ) ? absint( $item[ $conversa_field ] ) : 0;
		$conversa       = $conversa_id ? get_post( $conversa_id ) : null;

		if ( ! $conversa
			|| $conversa->post_type !== conversa_chat()->setting( 'cpt' )
			|| 'publish' !== $conversa->post_status
		) {
			self::reject( __( 'Conversa inválida.', 'conversa-chat' ) );
		}

		// Mesma regra (e mesmo filtro) dos endpoints de leitura.
		if ( ! Conversa_Chat_Context::is_participant( $user_id, $conversa_id ) ) {
			self::reject( __( 'Você não participa desta conversa.', 'conversa-chat' ) );
		}

		self::check_send_rate( $user_id, $conversa_id );

		// ANTI-SPOOF: o remetente é SEMPRE o usuário logado. O que veio no POST
		// para esses campos é ignorado por sobrescrita.
		$from_field = (string) conversa_chat()->setting( 'from_user_field' );

		if ( '' !== $from_field ) {
			$item[ $from_field ] = $user_id;
		}

		$item['cct_author_id'] = $user_id;

		// ANTI-IDOR: anexo numérico precisa ser um attachment de imagem do
		// próprio remetente (não qualquer ID da biblioteca de mídia).
		$item = self::validate_media( $item, $user_id );

		return $item;
	}

	/**
	 * Valida o campo de mídia da mensagem quando presente.
	 *
	 * Cobre os dois value formats recomendados do Media Field (docs/10):
	 * "Media ID" (escalar numérico) e "Both" (array com id). Formato URL não é
	 * verificável por ID — por isso docs/12 recomenda Media ID.
	 */
	private static function validate_media( $item, $user_id ) {

		$media_field = (string) conversa_chat()->setting( 'message_image_field' );

		if ( '' === $media_field || empty( $item[ $media_field ] ) ) {
			return $item;
		}

		$value    = $item[ $media_field ];
		$media_id = 0;

		if ( is_numeric( $value ) ) {
			$media_id = absint( $value );
		} elseif ( is_array( $value ) && isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
			$media_id = absint( $value['id'] );
		}

		if ( ! $media_id ) {
			return $item; // formato URL/string: nada verificável por ID.
		}

		$attachment = get_post( $media_id );

		$valid = $attachment
			&& 'attachment' === $attachment->post_type
			&& wp_attachment_is_image( $media_id )
			&& (int) $attachment->post_author === (int) $user_id;

		/**
		 * Permite flexibilizar a regra de posse da mídia (ex.: mídia de um
		 * repositório compartilhado do site) sem tocar no plugin.
		 */
		$valid = (bool) apply_filters(
			'conversa-chat/media-allowed',
			$valid,
			$media_id,
			(int) $user_id,
			$item
		);

		if ( ! $valid ) {
			self::reject( __( 'Anexo inválido.', 'conversa-chat' ) );
		}

		return $item;
	}

	/**
	 * Anti-flood do envio: mesmo padrão de transient dos endpoints de leitura
	 * (Conversa_Chat_Ajax::check_rate), com limite próprio (rate_send).
	 */
	private static function check_send_rate( $user_id, $conversa_id ) {

		$limit = (int) conversa_chat()->setting( 'rate_send', 20 );

		if ( $limit < 1 ) {
			return; // 0 = anti-flood do envio desligado.
		}

		$key   = 'cchat_rl_send_' . $user_id . '_' . $conversa_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			self::reject( __( 'Muitas mensagens em pouco tempo. Aguarde alguns segundos.', 'conversa-chat' ) );
		}

		set_transient( $key, $count + 1, (int) conversa_chat()->setting( 'rate_window', 60 ) );
	}

	/**
	 * Anti-CSRF secundário: rejeita referer explicitamente cross-origin.
	 *
	 * Referer AUSENTE é tolerado (proxies/extensões de privacidade o removem);
	 * o que se bloqueia é o caso clássico de CSRF por form HTML em site
	 * terceiro, em que o navegador SEMPRE envia ao menos a origin. A defesa
	 * primária continua sendo o WP Nonce nativo do JFB (ligar no form —
	 * ver docs/11 §11.5 e docs/12).
	 */
	private static function check_referer() {

		if ( ! apply_filters( 'conversa-chat/check-send-referer', true ) ) {
			return;
		}

		$referer = wp_get_raw_referer();

		if ( ! $referer ) {
			return;
		}

		$ref_host  = wp_parse_url( $referer, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( $ref_host && $home_host && strtolower( $ref_host ) !== strtolower( $home_host ) ) {
			self::reject( __( 'Origem da requisição inválida.', 'conversa-chat' ) );
		}
	}

	/**
	 * Rejeição pelo mecanismo NATIVO do JFB: Action_Exception com mensagem
	 * dinâmica — o Form_Handler captura e devolve o status de erro ao front
	 * (handler-exception.php:48; form-handler.php catch Action_Exception).
	 */
	private static function reject( $message ) {

		if ( class_exists( '\Jet_Form_Builder\Exceptions\Action_Exception' ) ) {
			throw ( new \Jet_Form_Builder\Exceptions\Action_Exception( $message ) )->dynamic_error();
		}

		// Sem JFB carregado não há submissão JFB para rejeitar — mas por
		// segurança em profundidade, interrompe do mesmo jeito.
		wp_die( esc_html( $message ), 403 );
	}
}
