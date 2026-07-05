<?php
/**
 * Núcleo do plugin: configuração central + carregamento dos módulos.
 *
 * A configuração é o ÚNICO lugar onde IDs de instância do site
 * (listing, form) e nomes de campos são declarados — e tudo é
 * sobrescrevível pelo filtro `conversa-chat/settings`, cumprindo o
 * requisito de não engessar (trocar de listing, adicionar forms,
 * desligar o real-time = configuração, não código).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Conversa_Chat {

	/**
	 * @var Conversa_Chat
	 */
	private static $instance = null;

	/**
	 * @var array Configuração resolvida (defaults + filtro).
	 */
	private $settings = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load();
	}

	/**
	 * Configuração do plugin.
	 *
	 * Cada chave existe para manter o projeto flexível:
	 *  - trocar o Listing (layout novo de card) = trocar 'listing_id';
	 *  - adicionar um segundo form (ex.: form de imagem) = acrescentar em 'form_ids';
	 *  - desligar o real-time = 'realtime' => false (o chat vira "envia e
	 *    aparece no reload", que é o comportamento nativo);
	 *  - mudar os IDs de seção do Elementor = 'selectors'.
	 *
	 * @return array
	 */
	public function settings() {

		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$defaults = array(

			// --- Modelo de dados -------------------------------------------------
			'cpt'             => 'conversa',      // CPT da conversa (o "quarto")
			'cct_slug'        => 'mensagens_',    // slug do CCT de mensagens
			'conversa_field'  => 'conversa_id',   // campo do CCT que aponta pro post
			'from_user_field' => 'from_user',     // campo do CCT com o autor da mensagem
			'message_field'   => 'message',       // campo do CCT com o texto (explícito, nunca adivinhado)
			'meta_artista'    => 'is_artista',    // meta do CPT: user ID do artista
			'meta_convidado'  => 'is_convidado',  // meta do CPT: user ID do convidado
			'meta_last_msg'   => 'last_message_at',

			// --- Instâncias do site (Elementor/JetEngine/JFB) --------------------
			'listing_id'      => 56326,           // Listing Grid das mensagens
			'form_ids'        => array( 56386 ),  // forms JetFormBuilder do composer

			// --- Âncoras de UI (definidas NO ELEMENTOR, via _element_id) ---------
			// O plugin nunca cria essas seções: ele apenas as reconhece.
			'selectors'       => array(
				'parent'   => '#parent-section-conversa',
				'header'   => '#header-conversa',
				'messages' => '#section-msgs-conversa',
				'footer'   => '#footer-conversa',
			),

			// --- Real-time --------------------------------------------------------
			'realtime'        => true,
			'active_poll_ms'  => 4000,    // polling com usuário ativo
			'idle_poll_ms'    => 30000,   // polling ocioso
			'active_ttl_ms'   => 90000,   // quanto tempo "ativo" dura após interação
			'after_limit'     => 20,      // máx. de mensagens por fetch incremental
			'tab_lock'        => true,    // só uma aba envia/faz polling

			// --- Endpoint / proteção ----------------------------------------------
			'status_cache_ttl' => 2,      // segundos de cache do status (object cache)
			'rate_status'      => 80,     // req/min por usuário+conversa
			'rate_after'       => 40,
			'rate_window'      => 60,

			'debug'            => false,
		);

		/**
		 * Sobrescreva qualquer configuração sem tocar no plugin.
		 *
		 * Ex.: mudar o listing num child theme:
		 *   add_filter( 'conversa-chat/settings', fn( $s ) => array_merge( $s, [ 'listing_id' => 99999 ] ) );
		 */
		$this->settings = apply_filters( 'conversa-chat/settings', $defaults );

		return $this->settings;
	}

	/**
	 * Atalho para uma chave da configuração.
	 */
	public function setting( $key, $default = null ) {
		$settings = $this->settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Real-time ligado para esta conversa?
	 *
	 * O filtro recebe o conversa_id para permitir ligar/desligar por conversa
	 * (ex.: conversas arquivadas sem polling).
	 */
	public function realtime_enabled( $conversa_id = 0 ) {
		return (bool) apply_filters(
			'conversa-chat/realtime-enabled',
			(bool) $this->setting( 'realtime', true ),
			$conversa_id
		);
	}

	/**
	 * Dependências mínimas presentes?
	 *
	 * O plugin degrada em silêncio no front (nada quebra) e avisa no admin.
	 */
	public function has_dependencies() {
		return function_exists( 'jet_engine' )
			&& class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' );
	}

	private function load() {

		require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat-context.php';
		require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat-data.php';
		require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat-renderer.php';
		require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat-ajax.php';
		require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat-integrations.php';
		require_once CONVERSA_CHAT_PATH . 'includes/class-conversa-chat-assets.php';

		if ( ! $this->has_dependencies() ) {
			add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
			return;
		}

		Conversa_Chat_Ajax::init();
		Conversa_Chat_Integrations::init();
		Conversa_Chat_Assets::init();
	}

	public function dependency_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Conversa Chat: JetEngine (com o módulo Custom Content Types ativo) é obrigatório. O chat está inativo até a dependência ser resolvida.', 'conversa-chat' )
		);
	}
}
