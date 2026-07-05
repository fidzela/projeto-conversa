<?php
/**
 * Dados — leitura do CCT de mensagens pela API NATIVA do JetEngine.
 *
 * Nada de SQL manual e nada de fetch HTTP interno. Todo acesso passa pela
 * Factory do CCT e seu objeto DB:
 *
 *  - Factory:  \Jet_Engine\Modules\Custom_Content_Types\Module::instance()
 *              ->manager->get_content_types( $slug )
 *  - Args:     $factory->prepare_query_args( [ [ field / operator / value ] ] )
 *              (validados contra o schema do CCT — factory.php:135)
 *  - Query:    $factory->db->query( $args, $limit, $offset, $order, $rel )
 *              (aceita operador '>', anexa cct_slug em cada item — db.php:716,805,823)
 *  - Count:    $factory->db->count( $args, $rel )  (db.php:661; mesmo padrão
 *              usado pelo próprio Query Builder — query-builder/query.php:242-243)
 *
 * "Mensagens após o _ID X" — o coração do incremental — é uma query nativa:
 *   [ 'field' => '_ID', 'operator' => '>', 'value' => $after_id ]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Data {

	const CACHE_GROUP = 'conversa_chat';

	/**
	 * Factory do CCT de mensagens (API nativa do JetEngine).
	 *
	 * @return \Jet_Engine\Modules\Custom_Content_Types\Factory|false
	 */
	public static function factory() {

		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
			return false;
		}

		$module = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();

		if ( empty( $module->manager ) ) {
			return false;
		}

		$factory = $module->manager->get_content_types( conversa_chat()->setting( 'cct_slug' ) );

		return $factory ? $factory : false;
	}

	/**
	 * Args base de toda consulta: mensagens desta conversa, publicadas.
	 *
	 * Formato field/operator/value — o mesmo que o Query Builder do JetEngine
	 * monta internamente antes de prepare_query_args().
	 */
	private static function base_args( $conversa_id ) {
		return array(
			array(
				'field'    => conversa_chat()->setting( 'conversa_field' ),
				'operator' => '=',
				'value'    => (int) $conversa_id,
			),
			array(
				'field'    => 'cct_status',
				'operator' => '=',
				'value'    => 'publish',
			),
		);
	}

	/**
	 * Estado da conversa — BARATO, alvo do polling.
	 *
	 * Duas consultas nativas mínimas (count + última linha) com cache curto
	 * no object cache para absorver rajadas (várias abas no mesmo segundo).
	 *
	 * O hash usa APENAS last_id + count (decisão herdada e validada da versão
	 * anterior: timestamps fora do hash evitam refresh fantasma por edição).
	 *
	 * @return array|WP_Error
	 */
	public static function get_status( $conversa_id ) {

		$cache_key = 'status_' . (int) $conversa_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$factory = self::factory();

		if ( ! $factory ) {
			return new WP_Error( 'cct_missing', 'CCT de mensagens não encontrado.' );
		}

		$args = $factory->prepare_query_args( self::base_args( $conversa_id ) );

		$count = (int) $factory->db->count( $args );

		$factory->db->set_format_flag( \ARRAY_A );

		$last = $factory->db->query(
			$args,
			1,
			0,
			array(
				array(
					'orderby' => '_ID',
					'order'   => 'desc',
				),
			)
		);

		$last_id      = ! empty( $last[0]['_ID'] ) ? (int) $last[0]['_ID'] : 0;
		$last_changed = ! empty( $last[0]['cct_created'] ) ? (string) $last[0]['cct_created'] : '';

		$payload = array(
			'last_id'      => $last_id,
			'count'        => $count,
			'last_changed' => $last_changed,
			'hash'         => md5( $last_id . '|' . $count ),
		);

		wp_cache_set(
			$cache_key,
			$payload,
			self::CACHE_GROUP,
			(int) conversa_chat()->setting( 'status_cache_ttl', 2 )
		);

		return $payload;
	}

	/**
	 * Mensagens com _ID maior que $after_id — como OBJETOS (formato que o
	 * pipeline de render do listing consome; cada item sai com cct_slug
	 * anexado pelo próprio JetEngine — db.php:823).
	 *
	 * @return array|WP_Error
	 */
	public static function get_after( $conversa_id, $after_id, $limit ) {

		$factory = self::factory();

		if ( ! $factory ) {
			return new WP_Error( 'cct_missing', 'CCT de mensagens não encontrado.' );
		}

		$raw_args   = self::base_args( $conversa_id );
		$raw_args[] = array(
			'field'    => '_ID',
			'operator' => '>',
			'value'    => (int) $after_id,
		);

		$args = $factory->prepare_query_args( $raw_args );

		// Barra invertida obrigatória: "( OBJECT )" sozinho é tokenizado como
		// cast (object). O próprio JetEngine usa \OBJECT (cct query.php:52).
		$factory->db->set_format_flag( \OBJECT );

		$items = $factory->db->query(
			$args,
			max( 1, (int) $limit ),
			0,
			array(
				array(
					'orderby' => '_ID',
					'order'   => 'asc',
				),
			)
		);

		return is_array( $items ) ? $items : array();
	}
}
