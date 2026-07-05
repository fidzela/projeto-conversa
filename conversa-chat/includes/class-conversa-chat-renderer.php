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
 *
 * ESTE É O CORAÇÃO DO PROJETO (lado servidor). O ciclo completo e as invariantes
 * estão em docs/09-o-coracao-interface-com-o-listing.md.
 *
 * DIRETRIZ DE AUTORAÇÃO (aprendida na marra — bug do "primeiro item pelado"):
 * NÃO use um Listing ANINHADO dentro do card de mensagem. Um sub-Listing tem seu
 * próprio ciclo de query/assets/hidratação e não sobrevive bem ao primeiro append
 * incremental (o item nasce "pelado" e só o reload conserta). Para dados do autor
 * use imagem/campo com contexto "CCT Item Author", dynamic tags ou Dynamic
 * Visibility, que resolvem no render do próprio card. Ver docs/09 §9.6.
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
	public static function render_items( $items, $conversa_id, $widget_settings = array() ) {

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

		// Settings do widget. Preferimos os REAIS do grid (publicados pelo
		// próprio JetEngine no data-nav e enviados pelo cliente) para o render
		// incremental ficar byte-a-byte igual ao load-more nativo — inclusive
		// no enfileiramento de assets dos widgets do card. Sem eles, caímos no
		// mesmo subconjunto mínimo que o load-more nativo aceita como fallback.
		// O typo 'lisitng_id' é o nome real do setting no JetEngine
		// (ajax-handlers.php:369) — mantido por contrato, não por descuido.
		$settings = is_array( $widget_settings ) ? $widget_settings : array();

		$settings = array_merge(
			array(
				'columns'        => 1,
				'columns_tablet' => 1,
				'columns_mobile' => 1,
			),
			$settings
		);

		// O listing_id é SEMPRE o do servidor (nunca confia no valor do cliente).
		$settings['lisitng_id'] = $listing_id;

		// Paridade com o load-more nativo (ajax-handlers.php:338-357): cria a
		// instância do widget e dispara os mesmos do_action ANTES do render, o
		// que dá a extensões/Elementor a chance de registrar/enfileirar os
		// assets do card exatamente como no load-more. Isso mantém o render
		// incremental fiel a QUALQUER layout de card (princípio de não engessar):
		// se o autor colocar um widget novo no card, seus assets vêm junto.
		//
		// NÃO é o remédio do bug do "primeiro item pelado" — esse bug era de
		// AUTORAÇÃO (um Listing ANINHADO no card não re-hidratava no 1º append)
		// e foi resolvido no layout, não aqui (ver docs/09). Mantemos esta etapa
		// só pela paridade legítima de assets, não como correção daquele bug.
		if ( jet_engine()->has_elementor() && class_exists( '\Elementor\Plugin' ) ) {

			$widget = \Elementor\Plugin::$instance->elements_manager->create_element_instance( array(
				'id'         => 'jet-listing-grid',
				'elType'     => 'widget',
				'settings'   => $settings,
				'elements'   => array(),
				'widgetType' => 'jet-listing-grid',
			) );

			if ( $widget ) {
				do_action( 'jet-engine/elementor-views/ajax/load-more', $widget );
			}
		}

		do_action( 'jet-engine/listings/ajax/load-more' );

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
