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

			// --- Carregamento inicial (performance) -------------------------------
			// Quantas mensagens o Listing renderiza no primeiro paint. O plugin
			// pega as N MAIS RECENTES (order DESC + LIMIT no código) e reexibe do
			// mais antigo → mais novo, via os hooks nativos da Query do JetEngine
			// (after-query-setup + query/items). Isso resolve a lentidão com
			// 40–60 mensagens SEM subquery na UI (a CCT Query só faz query linear)
			// e SEM tocar na sua CCT Query: ela pode continuar como está.
			//   0  = desligado (mostra todas, comportamento antigo).
			//   30 = últimas 30 (padrão).
			'initial_limit'   => 30,
			// ID da CCT Query que alimenta o Listing das mensagens.
			//   0 = auto: casa qualquer CCT Query sobre o 'cct_slug' (mensagens_).
			//   >0 = cirúrgico: limita/reordena SÓ essa query (use se houver mais
			//        de uma listagem do mesmo CCT e você quiser tratar só uma).
			'messages_query_id' => 0,
			// Coluna de data do CCT usada para ordenar "as mais recentes".
			// 'cct_created' é a coluna nativa de criação do CCT.
			'messages_order_field' => 'cct_created',

			// --- Real-time --------------------------------------------------------
			'realtime'        => true,
			'active_poll_ms'  => 4000,    // polling com usuário ativo
			'idle_poll_ms'    => 30000,   // polling ocioso
			'active_ttl_ms'   => 90000,   // quanto tempo "ativo" dura após interação
			'after_limit'     => 20,      // máx. de mensagens por fetch incremental
			'tab_lock'        => true,    // só uma aba envia/faz polling

			// Limpa o textarea após envio OK (fallback ao "Clear form after
			// submit" nativo do JFB, que vem desligado). Desligue se preferir
			// usar só o clear nativo do JFB.
			'clear_composer_on_success' => true,

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

		// TIMING CRÍTICO: o JetEngine carrega os módulos (incluindo o CCT) só
		// no hook `init`, prioridade -999 (jet-engine.php:164 → init() →
		// require modules-manager). Portanto a classe
		// \Jet_Engine\Modules\Custom_Content_Types\Module NÃO existe no
		// plugins_loaded. Se checássemos dependência aqui, has_dependencies()
		// falharia sempre e o plugin não faria nada no front (bug real da v1.0.0).
		// O wiring roda no `init` (prioridade 20 > -999), quando o CCT já existe.
		add_action( 'init', array( $this, 'init_modules' ), 20 );
	}

	/**
	 * Wiring dos módulos. Roda no `init` (prio 20), depois do JetEngine (-999).
	 */
	public function init_modules() {

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
