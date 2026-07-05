<?php
/**
 * Contexto — o ÚNICO gate do plugin.
 *
 * Unifica os dois "context guards" da versão WPCode (v2 + v4.1) num módulo só.
 * Todos os outros módulos consomem esta classe; nenhum duplica lógica de gate.
 *
 * Duas camadas (mesmo desenho da versão anterior, que era bom):
 *  - can_register_hooks(): gate LEVE, roda antes da query principal.
 *    Decide se vale registrar hooks de front (assets etc.).
 *  - get(): gate COMPLETO, exige query principal resolvida (is_singular
 *    confiável). Retorna false ou o array de contexto da conversa.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Context {

	/**
	 * Cache por request do contexto completo.
	 * Só é preenchido após o action 'wp' (quando is_singular é confiável).
	 *
	 * @var array|false|null
	 */
	private static $cached = null;

	/**
	 * Gate leve — pode rodar cedo (antes da query principal).
	 *
	 * Descarta contextos onde o chat nunca deve atuar: admin, REST, AJAX,
	 * cron, XML-RPC e editores/previews de page builder.
	 */
	public static function can_register_hooks() {

		if ( is_admin() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		// Editor/preview do Elementor detectáveis via $_GET (cedo demais pra API).
		if ( ! empty( $_GET['elementor-preview'] ) || ! empty( $_GET['elementor_library'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		if ( isset( $_GET['action'] ) && in_array( wp_unslash( $_GET['action'] ), array( 'elementor', 'elementor_ajax' ), true ) ) { // phpcs:ignore
			return false;
		}

		return true;
	}

	/**
	 * Gate completo. Retorna o contexto da conversa ou false.
	 *
	 * Retorno (quando válido):
	 * [
	 *   'conversa_id'  => int,
	 *   'user_id'      => int,
	 *   'is_artista'   => int,
	 *   'is_convidado' => int,
	 *   'role'         => 'artista'|'convidado'|'other',
	 * ]
	 *
	 * 'other' é informativo, não bloqueante — a permissão de ACESSO à página
	 * é upstream (JetEngine page rules). Quem precisa bloquear 'other'
	 * (ex.: endpoints AJAX) decide explicitamente via is_participant().
	 */
	public static function get() {

		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$result = self::compute();

		if ( did_action( 'wp' ) ) {
			self::$cached = $result;
		}

		return $result;
	}

	private static function compute() {

		if ( ! self::can_register_hooks() ) {
			return false;
		}

		// Editor/preview do Elementor via API (disponível a partir do init).
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::$instance;

			if ( $elementor && ! empty( $elementor->editor ) && $elementor->editor->is_edit_mode() ) {
				return false;
			}

			if ( $elementor && ! empty( $elementor->preview ) && $elementor->preview->is_preview_mode() ) {
				return false;
			}
		}

		if ( is_customize_preview() ) {
			return false;
		}

		$cpt = conversa_chat()->setting( 'cpt' );

		if ( ! is_singular( $cpt ) || ! is_user_logged_in() ) {
			return false;
		}

		$conversa_id = (int) get_queried_object_id();
		$user_id     = (int) get_current_user_id();

		if ( ! $conversa_id || ! $user_id ) {
			return false;
		}

		$participants = self::get_participants( $conversa_id );

		$role = 'other';
		if ( $user_id === $participants['is_artista'] ) {
			$role = 'artista';
		} elseif ( $user_id === $participants['is_convidado'] ) {
			$role = 'convidado';
		}

		return array(
			'conversa_id'  => $conversa_id,
			'user_id'      => $user_id,
			'is_artista'   => $participants['is_artista'],
			'is_convidado' => $participants['is_convidado'],
			'role'         => $role,
		);
	}

	/**
	 * Participantes da conversa — a ÚNICA leitura desses metas no plugin.
	 */
	public static function get_participants( $conversa_id ) {
		return array(
			'is_artista'   => (int) get_post_meta( $conversa_id, conversa_chat()->setting( 'meta_artista' ), true ),
			'is_convidado' => (int) get_post_meta( $conversa_id, conversa_chat()->setting( 'meta_convidado' ), true ),
		);
	}

	/**
	 * O usuário participa da conversa?
	 *
	 * Regra única usada pelos endpoints AJAX. O filtro permite ampliar o
	 * acesso (ex.: administradores/moderadores) sem tocar no plugin.
	 */
	public static function is_participant( $user_id, $conversa_id ) {

		$participants = self::get_participants( $conversa_id );

		$allowed = in_array( (int) $user_id, $participants, true );

		return (bool) apply_filters(
			'conversa-chat/is-participant',
			$allowed,
			(int) $user_id,
			(int) $conversa_id,
			$participants
		);
	}
}
