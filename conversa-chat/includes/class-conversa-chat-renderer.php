<?php
/**
 * Renderer — mensagens novas renderizadas pelo TEMPLATE REAL do Listing.
 *
 * Este é o módulo que substitui (e mata) o "mirror renderer" da versão
 * anterior. Nenhum HTML de card é construído aqui: os itens passam pelo
 * MESMO pipeline que o JetEngine usa no grid e no load-more nativo:
 *
 *   jet_engine()->listings->get_render_instance( 'listing-grid', $settings )
 *       ->posts_loop( $items, ... )
 *
 * Evidência (core-plugins): é exatamente o que o handler nativo
 * `listing_load_more` faz em listings/ajax-handlers.php:377,459.
 * posts_loop() produz cada item com o wrapper nativo
 * `.jet-listing-grid__item .jet-listing-dynamic-post-{_ID}` e data-post-id
 * (render/listing-grid.php:1683-1697) e renderiza o conteúdo pelo template
 * do listing via get_listing_item() (listing-grid.php:1677).
 *
 * Consequências práticas (o que resolve o problema histórico do projeto):
 *  - Editar o card no Elementor reflete AUTOMATICAMENTE nas mensagens
 *    incrementais (mesmo template, mesmo render).
 *  - Dynamic Visibility do card (artista/convidado) é avaliada no render —
 *    o lado certo sai decidido do servidor, sem CSS/JS de resolução.
 *  - Um metafield novo (ex.: imagem) colocado no card aparece nas mensagens
 *    novas sem nenhuma mudança neste plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat_Renderer {

	/**
	 * Renderiza itens do CCT como itens do Listing (HTML real do template).
	 *
	 * @param array $items       Itens OBJETO vindos de Conversa_Chat_Data::get_after().
	 * @param int   $conversa_id Post da conversa — vira o contexto global do
	 *                           render (as condições de Dynamic Visibility do
	 *                           card leem meta do post atual: is_artista /
	 *                           is_convidado via dynamic tag post-custom-field).
	 * @return string HTML concatenado dos .jet-listing-grid__item.
	 */
	public static function render_items( $items, $conversa_id ) {

		if ( empty( $items ) || ! function_exists( 'jet_engine' ) ) {
			return '';
		}

		$listing_id = (int) conversa_chat()->setting( 'listing_id' );

		// ------------------------------------------------------------------
		// Contexto do post da conversa.
		// As condições do card comparam o from_user do item com metas do post
		// ATUAL (is_artista/is_convidado) — o global $post precisa ser a conversa.
		// ------------------------------------------------------------------
		global $post;
		$original_post = $post;

		$post = get_post( $conversa_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $post ) {
			setup_postdata( $post );
		}

		// Listing atual para o pipeline do JetEngine.
		jet_engine()->listings->data->set_listing_by_id( $listing_id );

		// Settings mínimos: o mesmo subconjunto que o load-more nativo usa.
		// O typo 'lisitng_id' é o nome real do setting no JetEngine
		// (ajax-handlers.php:369) — mantido por contrato, não por descuido.
		$settings = array(
			'lisitng_id'     => $listing_id,
			'columns'        => 1,
			'columns_tablet' => 1,
			'columns_mobile' => 1,
		);

		$render = jet_engine()->listings->get_render_instance( 'listing-grid', $settings );

		if ( ! $render ) {
			wp_reset_postdata();
			$post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			return '';
		}

		// Mesmo setup que o listing_load_more faz (ajax-handlers.php:450-457).
		$render->listing_id = $listing_id;
		$render->view       = jet_engine()->listings->data->get_listing_type( $listing_id );

		if ( isset( $render->query_vars ) ) {
			$render->query_vars = array(
				'page'    => 1,
				'pages'   => 1,
				'request' => array(),
			);
		}

		// Sem isso, templates Elementor internos podem suprimir estilos
		// (mesma chamada do load-more nativo — ajax-handlers.php:446-448).
		if ( jet_engine()->has_elementor() && class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::instance()->frontend->start_excerpt_flag( null );
		}

		ob_start();

		$render->posts_loop( $items, $settings, 'jet-listing-grid', '' );

		$html = ob_get_clean();

		// Restaura contexto global.
		wp_reset_postdata();
		$post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $post ) {
			setup_postdata( $post );
		}

		/**
		 * Permite pós-processar o HTML dos itens incrementais
		 * (ex.: injetar um marcador de "mensagem nova").
		 */
		return apply_filters( 'conversa-chat/rendered-items', $html, $items, $conversa_id );
	}
}
