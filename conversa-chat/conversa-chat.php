<?php
/**
 * Plugin Name:       Conversa Chat
 * Plugin URI:        https://github.com/fidzela/projeto-conversa
 * Description:       Chat em tempo real sobre JetEngine (CPT + CCT + Listing Grid), JetFormBuilder e Elementor. O plugin só cobre o que os plugins não fazem nativamente: detecção de mensagens novas e o append incremental renderizado pelo template real do Listing.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            fidzela
 * Text Domain:       conversa-chat
 *
 * ============================================================================
 * PRINCÍPIO FUNDAMENTAL DO PROJETO
 * ============================================================================
 * "Utilizar as ferramentas já existentes, integrar, e não fazer as coisas
 *  parecerem um remendo."
 *
 * REGRA DE OURO: reaproveitar e integrar o que os plugins já proporcionam
 * nativamente. O código deste plugin começa e termina onde as funcionalidades
 * nativas de JetEngine / JetFormBuilder / Elementor não chegam.
 *
 * O QUE OS PLUGINS FAZEM (e este plugin NÃO refaz):
 *  - Elementor: todo o layout. A single é editada no Elementor; o vínculo com
 *    o código é feito por IDs de seção definidos NA UI (#parent-section-conversa,
 *    #header-conversa, #section-msgs-conversa, #footer-conversa).
 *  - JetEngine Listing Grid: renderiza os cards de mensagem. O template do
 *    Listing é a ÚNICA fonte visual da verdade — inclusive nas mensagens
 *    incrementais (renderizadas server-side pelo mesmo pipeline do grid).
 *  - JetEngine Dynamic Visibility: decide qual card (artista/convidado)
 *    renderiza, configurado NA UI do card (condição equal: from_user == meta
 *    do post). Nenhum side-resolver em código.
 *  - JetFormBuilder: envio da mensagem (Action nativa insert_custom_content_type),
 *    limpeza do campo ("Clear data on submit" nativo), validação, eventos JS.
 *  - JetEngine CCT: armazenamento (tabela própria), hooks de criação de item.
 *
 * O QUE ESTE PLUGIN FAZ (a fronteira onde o nativo não chega):
 *  1. Detectar mensagens novas sem reload (endpoint de status barato + polling).
 *  2. Buscar "mensagens após o _ID X" pela API nativa do CCT e renderizá-las
 *     pelo pipeline nativo do Listing (posts_loop), devolvendo HTML real.
 *  3. Anexar os itens no grid do cliente e religar os widgets via API JS
 *     nativa do JetEngine (initElementsHandlers).
 *  4. Layout de chat (viewport travado, scroll por contexto explícito).
 *  5. UX do composer (auto-size do textarea) — sem tocar no comportamento do form.
 *  6. Atualizar o meta last_message_at no hook nativo correto do CCT.
 *
 * Toda referência "arquivo:linha" nos comentários aponta para o repositório
 * core-plugins (raiz dos plugins) onde o comportamento foi validado.
 * ============================================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONVERSA_CHAT_VERSION', '1.0.3' );
define( 'CONVERSA_CHAT_FILE', __FILE__ );
define( 'CONVERSA_CHAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CONVERSA_CHAT_URL', plugin_dir_url( __FILE__ ) );

require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat.php';

/**
 * Acesso global à instância do plugin.
 *
 * @return Conversa_Chat
 */
function conversa_chat() {
	return Conversa_Chat::instance();
}

/**
 * Boot no plugins_loaded para garantir que JetEngine/JetFormBuilder/Elementor
 * já registraram suas APIs quando os módulos do chat forem inicializados.
 */
add_action( 'plugins_loaded', array( 'Conversa_Chat', 'instance' ), 20 );
