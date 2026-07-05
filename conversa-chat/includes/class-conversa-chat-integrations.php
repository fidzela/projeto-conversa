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
 *
 * 3. "Últimas N mensagens" no carregamento inicial (performance):
 *    A CCT Query da UI só faz query LINEAR — não dá pra montar a subquery
 *    "ORDER BY DESC LIMIT N reordenada ASC" pelo Query Builder (e o Listing
 *    só funciona com CCT Query; SQL Query não renderiza itens aqui). Então a
 *    reordenação vive no CÓDIGO, em dois hooks NATIVOS da Query:
 *      - jet-engine/query-builder/query/after-query-setup (base.php:397):
 *        roda DENTRO do setup_query, com final_query já montado (macro do
 *        conversa_id resolvido) e ANTES do fetch e do hash de cache. Forçamos
 *        number = N e order = {order_field} DESC → o DB devolve as N mais
 *        recentes de forma barata.
 *      - jet-engine/query-builder/query/items (base.php:591; README:1139):
 *        array_reverse dos itens → exibe do mais antigo → mais novo (mesma
 *        ordem visual de sempre).
 *    Não toca o real-time: o endpoint `after` lê o CCT direto pela Factory
 *    (data.php), fora do Query Builder — esses hooks não o afetam.
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

		// "Últimas N mensagens" no carregamento inicial — só se ligado.
		if ( (int) conversa_chat()->setting( 'initial_limit' ) > 0 ) {

			add_action(
				'jet-engine/query-builder/query/after-query-setup',
				array( __CLASS__, 'limit_messages_query' )
			);

			add_filter(
				'jet-engine/query-builder/query/items',
				array( __CLASS__, 'reverse_messages_items' ),
				10,
				2
			);
		}
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

	/**
	 * É a CCT Query das mensagens?
	 *
	 * Escopo sem hardcode: por padrão casa qualquer CCT Query sobre o CCT de
	 * mensagens (cct_slug). Se 'messages_query_id' for > 0, casa SÓ aquela
	 * query (precisão cirúrgica quando há mais de uma listagem do mesmo CCT).
	 *
	 * @param  object $query Instância de \Jet_Engine\Query_Builder\Queries\Base_Query.
	 * @return bool
	 */
	protected static function is_messages_query( $query ) {

		if ( ! is_object( $query ) || empty( $query->query_type ) ) {
			return false;
		}

		// Só CCT Query (query-builder/manager.php:25 → slug 'custom-content-type').
		if ( 'custom-content-type' !== $query->query_type ) {
			return false;
		}

		$query_id = (int) conversa_chat()->setting( 'messages_query_id' );

		if ( $query_id > 0 ) {
			return (int) $query->id === $query_id;
		}

		// Auto: pelo CCT alvo. final_query já está montado no momento em que
		// os dois hooks rodam (after-query-setup e items são pós-setup_query).
		$final = is_array( $query->final_query ) ? $query->final_query : array();
		$ct    = ! empty( $final['content_type'] ) ? $final['content_type'] : '';

		return $ct === conversa_chat()->setting( 'cct_slug' );
	}

	/**
	 * Força "as N mais recentes" na CCT Query das mensagens.
	 *
	 * Roda no after-query-setup (base.php:397), DENTRO do setup_query: o
	 * final_query já tem o conversa_id resolvido (macro) e a mutação entra no
	 * hash de cache (get_query_hash chama setup_query antes de hashear —
	 * base.php:111). Sobrescreve number e order — independentemente do que a
	 * CCT Query tem na UI: pegamos as N mais recentes (DESC) e o reverse
	 * (items) reexibe do mais antigo → mais novo.
	 *
	 * O CCT_Query::_get_items() passa final_query['order'] direto para
	 * db->query(); o formato aceito é lista de { orderby, order }
	 * (base-db.php:914-968; db.php:707-709).
	 *
	 * @param object $query Instância da Query.
	 */
	public static function limit_messages_query( $query ) {

		if ( ! self::is_messages_query( $query ) ) {
			return;
		}

		$limit = (int) conversa_chat()->setting( 'initial_limit' );
		$field = (string) conversa_chat()->setting( 'messages_order_field' );

		if ( $limit < 1 || '' === $field ) {
			return;
		}

		$query->final_query['number'] = $limit;
		$query->final_query['order']  = array(
			array(
				'orderby' => $field,
				'order'   => 'DESC',
			),
		);
	}

	/**
	 * Reexibe as mensagens do mais antigo → mais novo.
	 *
	 * As N linhas vieram DESC (mais recentes primeiro) por limit_messages_query;
	 * aqui invertemos para a ordem visual do chat. Hook nativo
	 * jet-engine/query-builder/query/items (base.php:591; README:1139).
	 *
	 * @param  array  $items Itens retornados pela query.
	 * @param  object $query Instância da Query.
	 * @return array
	 */
	public static function reverse_messages_items( $items, $query ) {

		if ( ! is_array( $items ) || count( $items ) < 2 ) {
			return $items;
		}

		if ( ! self::is_messages_query( $query ) ) {
			return $items;
		}

		return array_reverse( $items );
	}
}
