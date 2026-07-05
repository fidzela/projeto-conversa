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
		add_action( 'wp_ajax_conversa_chat_before', array( __CLASS__, 'before' ) );

		// Visitante deslogado nunca acessa (o chat exige login upstream).
		add_action( 'wp_ajax_nopriv_conversa_chat_status', array( __CLASS__, 'block_nopriv' ) );
		add_action( 'wp_ajax_nopriv_conversa_chat_after', array( __CLASS__, 'block_nopriv' ) );
		add_action( 'wp_ajax_nopriv_conversa_chat_before', array( __CLASS__, 'block_nopriv' ) );
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
	 * widget_settings REAIS do grid, enviados pelo cliente a partir do data-nav
	 * que o próprio JetEngine publica no DOM. Usados no render incremental para
	 * paridade com o load-more nativo (mesmo enfileiramento de assets). O
	 * listing_id é sempre reimposto pelo servidor no renderer — o cliente não
	 * escolhe QUAL listing renderiza.
	 */
	private static function read_widget_settings() {

		if ( empty( $_POST['widget_settings'] ) ) {
			return array();
		}

		$raw = wp_unslash( $_POST['widget_settings'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Anexa os assets dos widgets do card ao response (CSS/JS enfileirados
	 * DURANTE o posts_loop de render_items). É o mesmo método público do
	 * load-more nativo (ajax-handlers.php:577): lê wp_styles()/wp_scripts()->queue
	 * do MESMO request e preenche $response['styles'] / $response['scripts']. O
	 * cliente injeta e deduplica por handle (main.js:1456-1481).
	 *
	 * Paridade legítima com o load-more nativo: garante que um card com QUALQUER
	 * widget (o autor pode mudar o layout — princípio de não engessar) traga seus
	 * assets no primeiro paint. NÃO é a correção do bug do "primeiro item pelado":
	 * esse bug era de autoração do card (Listing aninhado) e foi resolvido no
	 * layout (ver docs/09).
	 */
	private static function attach_assets( &$response ) {
		if ( class_exists( 'Jet_Engine_Listings_Ajax_Handlers' ) ) {
			Jet_Engine_Listings_Ajax_Handlers::maybe_add_enqueue_assets_data( $response );
		}
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

		$html = Conversa_Chat_Renderer::render_items( $items, $req['conversa_id'], self::read_widget_settings() );

		$status = Conversa_Chat_Data::get_status( $req['conversa_id'] );

		$response = array(
			'conversa_id' => $req['conversa_id'],
			'after_id'    => $after_id,
			'appended'    => count( $items ),
			'html'        => $html,
			'status'      => is_wp_error( $status ) ? null : $status,
		);

		self::attach_assets( $response );

		wp_send_json_success( $response );
	}

	/**
	 * ENDPOINT 3: mensagens ANTIGAS (rolar pra cima / "ver anteriores"),
	 * já renderizadas pelo template real, em ordem cronológica para PREPEND.
	 *
	 * Simétrico ao `after`. Devolve também:
	 *  - has_more   → ainda há mensagens além deste lote (controla o botão/scroll);
	 *  - oldest_id  → menor _ID deste lote (o novo topo para o próximo before).
	 */
	public static function before() {

		$req = self::validate( 'before', conversa_chat()->setting( 'rate_before', 40 ) );

		$before_id = isset( $_POST['before_id'] ) ? absint( wp_unslash( $_POST['before_id'] ) ) : 0;

		$limit = (int) conversa_chat()->setting( 'older_batch', 15 );

		$result = Conversa_Chat_Data::get_before( $req['conversa_id'], $before_id, $limit );

		if ( is_wp_error( $result ) ) {
			self::send_error( $result->get_error_message(), $result->get_error_code(), 500 );
		}

		$items = $result['items'];

		$html = Conversa_Chat_Renderer::render_items( $items, $req['conversa_id'], self::read_widget_settings() );

		// Menor _ID do lote = novo topo para a próxima carga.
		$oldest_id = 0;
		foreach ( $items as $item ) {
			$id = is_object( $item ) && isset( $item->_ID ) ? (int) $item->_ID : 0;
			if ( $id && ( 0 === $oldest_id || $id < $oldest_id ) ) {
				$oldest_id = $id;
			}
		}

		$response = array(
			'conversa_id' => $req['conversa_id'],
			'before_id'   => $before_id,
			'prepended'   => count( $items ),
			'has_more'    => (bool) $result['has_more'],
			'oldest_id'   => $oldest_id,
			'html'        => $html,
		);

		self::attach_assets( $response );

		wp_send_json_success( $response );
	}
}
