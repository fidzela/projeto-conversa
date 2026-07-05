<?php
/**
 * Integrações — os pontos onde o plugin se PLUGA nos hooks nativos.
 *
 * 1. last_message_at:
 *    Registrado no hook REAL de criação de item do CCT:
 *      jet-engine/custom-content-types/created-item/{slug}
 *    disparado em item-handler.php:453 com ( $item, $item_id, $handler ).
 *    (A versão WPCode escutava 'data/inserted-item', que NÃO existe no
 *    JetEngine 3.8.10.1 — o meta nunca era atualizado.)
 *
 * 2. data-nav com query assinada:
 *    O grid só embute a própria query (assinada por HMAC) no atributo
 *    data-nav quando load-more está ativo OU quando o filtro nativo
 *    jet-engine/listing/grid/add-query-data devolve true
 *    (render/listing-grid.php:1326-1352). Forçamos true para o listing do
 *    chat: é o que permite ao runtime chamar o endpoint NATIVO get_listing
 *    para o refresh completo, sem endpoint próprio.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Integrations {

	public static function init() {

		$slug = conversa_chat()->setting( 'cct_slug' );

		add_action(
			'jet-engine/custom-content-types/created-item/' . $slug,
			array( __CLASS__, 'on_message_created' ),
			10,
			3
		);

		add_filter(
			'jet-engine/listing/grid/add-query-data',
			array( __CLASS__, 'force_nav_query_data' ),
			10,
			2
		);
	}

	/**
	 * Mensagem criada no CCT (por QUALQUER caminho: JetFormBuilder, REST,
	 * admin, código) → atualiza last_message_at da conversa e expõe um
	 * evento próprio para extensões (push, badge de não lidas, e-mail...).
	 *
	 * @param array  $item    Dados do item inserido (inclui os campos do CCT).
	 * @param int    $item_id _ID do item na tabela.
	 * @param object $handler Item_Handler do JetEngine.
	 */
	public static function on_message_created( $item, $item_id, $handler ) {

		$conversa_field = conversa_chat()->setting( 'conversa_field' );
		$conversa_id    = ! empty( $item[ $conversa_field ] ) ? (int) $item[ $conversa_field ] : 0;

		if ( ! $conversa_id ) {
			return;
		}

		update_post_meta(
			$conversa_id,
			conversa_chat()->setting( 'meta_last_msg' ),
			current_time( 'mysql' )
		);

		/**
		 * Gatilho de servidor para "chegou mensagem nova".
		 * Base para evoluções sem polling (push/SSE) e para features como
		 * notificações — sem mexer no núcleo do plugin.
		 */
		do_action( 'conversa-chat/message-created', (int) $item_id, $item, $conversa_id );
	}

	/**
	 * Garante a query assinada no data-nav do listing do chat.
	 *
	 * Inofensivo quando a fonte do listing não expõe query (caso CCT legado);
	 * essencial se o listing migrar para uma Query do Query Builder.
	 *
	 * @param bool   $add    Valor atual (true quando load-more ligado).
	 * @param object $render Instância do render do grid.
	 */
	public static function force_nav_query_data( $add, $render ) {

		if ( ! empty( $render->listing_id )
			&& (int) $render->listing_id === (int) conversa_chat()->setting( 'listing_id' )
		) {
			return true;
		}

		return $add;
	}
}
