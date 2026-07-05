<?php
/**
 * AJAX — o ÚNICO contrato de endpoints do plugin (substitui os dois
 * conjuntos v2 + v4.1 da versão WPCode).
 *
 * Dois endpoints, um validador:
 *
 *  1. conversa_chat_status  (barato — alvo do polling)
 *     → { last_id, count, last_changed, hash }
 *
 *  2. conversa_chat_after   (sob demanda — quando o hash muda)
 *     → { html, appended, status }
 *     O html são .jet-listing-grid__item renderizados pelo template REAL
 *     (ver Conversa_Chat_Renderer).
 *
 * NÃO existe endpoint "full" próprio: o refresh completo do grid usa o
 * endpoint NATIVO do JetEngine (action jet_engine_ajax, handler get_listing
 * — listings/ajax-handlers.php:28,479), chamado direto pelo runtime JS.
 *
 * Segurança: nonce + login + conversa publish do tipo certo + participante
 * + rate limit por usuário+conversa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Ajax {

	const NONCE_ACTION = 'conversa_chat';

	public static function init() {
		add_action( 'wp_ajax_conversa_chat_status', array( __CLASS__, 'status' ) );
		add_action( 'wp_ajax_conversa_chat_after', array( __CLASS__, 'after' ) );

		// Visitante deslogado nunca acessa (o chat exige login upstream).
		add_action( 'wp_ajax_nopriv_conversa_chat_status', array( __CLASS__, 'block_nopriv' ) );
		add_action( 'wp_ajax_nopriv_conversa_chat_after', array( __CLASS__, 'block_nopriv' ) );
	}

	public static function block_nopriv() {
		self::send_error( 'Login obrigatório.', 'not_logged_in', 401 );
	}

	private static function send_error( $message, $code = 'error', $status = 400 ) {
		wp_send_json_error(
			array(
				'message' => $message,
				'code'    => $code,
			),
			$status
		);
	}

	/**
	 * Validação compartilhada dos dois endpoints.
	 */
	private static function validate( $kind, $limit ) {

		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			self::send_error( 'Método inválido.', 'invalid_method', 405 );
		}

		nocache_headers();

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$user_id = (int) get_current_user_id();

		if ( ! $user_id ) {
			self::send_error( 'Login obrigatório.', 'not_logged_in', 401 );
		}

		$conversa_id = isset( $_POST['conversa_id'] ) ? absint( wp_unslash( $_POST['conversa_id'] ) ) : 0;

		if ( ! $conversa_id ) {
			self::send_error( 'conversa_id inválido.', 'invalid_conversa_id', 400 );
		}

		$conversa = get_post( $conversa_id );
		$cpt      = conversa_chat()->setting( 'cpt' );

		if ( ! $conversa || $conversa->post_type !== $cpt || 'publish' !== $conversa->post_status ) {
			self::send_error( 'Conversa inválida.', 'invalid_conversa', 404 );
		}

		if ( ! Conversa_Chat_Context::is_participant( $user_id, $conversa_id ) ) {
			self::send_error( 'Você não participa desta conversa.', 'not_participant', 403 );
		}

		self::check_rate( $user_id, $conversa_id, $kind, $limit );

		return array(
			'user_id'     => $user_id,
			'conversa_id' => $conversa_id,
		);
	}

	/**
	 * Rate limit simples por usuário+conversa+tipo (transient).
	 */
	private static function check_rate( $user_id, $conversa_id, $kind, $limit ) {

		$key   = 'cchat_rl_' . $kind . '_' . $user_id . '_' . $conversa_id;
		$count = (int) get_transient( $key );

		if ( $count >= (int) $limit ) {
			self::send_error( 'Muitas requisições. Aguarde alguns segundos.', 'rate_limited', 429 );
		}

		set_transient( $key, $count + 1, (int) conversa_chat()->setting( 'rate_window', 60 ) );
	}

	/**
	 * ENDPOINT 1: status.
	 */
	public static function status() {

		$req = self::validate( 'status', conversa_chat()->setting( 'rate_status', 80 ) );

		$status = Conversa_Chat_Data::get_status( $req['conversa_id'] );

		if ( is_wp_error( $status ) ) {
			self::send_error( $status->get_error_message(), $status->get_error_code(), 500 );
		}

		wp_send_json_success(
			array(
				'conversa_id' => $req['conversa_id'],
				'status'      => $status,
			)
		);
	}

	/**
	 * ENDPOINT 2: mensagens após um _ID, já renderizadas pelo template real.
	 */
	public static function after() {

		$req = self::validate( 'after', conversa_chat()->setting( 'rate_after', 40 ) );

		$after_id = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;

		$limit = (int) conversa_chat()->setting( 'after_limit', 20 );

		$items = Conversa_Chat_Data::get_after( $req['conversa_id'], $after_id, $limit );

		if ( is_wp_error( $items ) ) {
			self::send_error( $items->get_error_message(), $items->get_error_code(), 500 );
		}

		$html = Conversa_Chat_Renderer::render_items( $items, $req['conversa_id'] );

		$status = Conversa_Chat_Data::get_status( $req['conversa_id'] );

		$response = array(
			'conversa_id' => $req['conversa_id'],
			'after_id'    => $after_id,
			'appended'    => count( $items ),
			'html'        => $html,
			'status'      => is_wp_error( $status ) ? null : $status,
		);

		// Assets dos widgets do card (CSS/JS enfileirados DURANTE o posts_loop
		// de render_items, acima). Sem isto o PRIMEIRO item incremental
		// renderiza "pelado" e só monta certo depois que um script assíncrono
		// carrega — exatamente o bug observado ("primeiro envio quebra o
		// layout, os próximos montam"). O load-more nativo resolve assim
		// (ajax-handlers.php:469): o método é public static e lê
		// wp_styles()/wp_scripts()->queue do MESMO request, preenchendo
		// $response['styles'] e $response['scripts']. O cliente injeta e
		// deduplica por handle (main.js:1456-1481).
		if ( class_exists( 'Jet_Engine_Listings_Ajax_Handlers' ) ) {
			Jet_Engine_Listings_Ajax_Handlers::maybe_add_enqueue_assets_data( $response );
		}

		wp_send_json_success( $response );
	}
}
