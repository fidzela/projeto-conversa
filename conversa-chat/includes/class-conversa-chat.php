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
			'form_ids'        => array( 56386 ),  // forms JetFormBuilder do composer (legado/compat)

			// --- Mídia nas mensagens (metafield message_image no CCT) ------------
			// Coluna do CCT que guarda o anexo de imagem da mensagem. O envio é
			// feito por um Media Field NATIVO do JetFormBuilder mapeado para esta
			// coluna na Action "Insert/Update CCT"; a exibição vem do próprio card
			// do Listing (imagem dinâmica da coluna). O plugin não cria uploader
			// nem coluna: só reconhece este nome. '' = sem suporte a mídia.
			'message_image_field' => 'message_image',

			// --- Layouts de composer (ARMAZENADOS; base para o futuro) -----------
			// O plugin guarda "layouts" de formulário do composer. Cada layout
			// aponta para um form JetFormBuilder (form_id) e declara suas features.
			// Isso cumpre o pedido de separar layouts SEM engessar: trocar/duplicar
			// o composer é configuração (novo form_id + features), não código.
			//
			//   - 'text'  → o composer atual (só texto). NÃO é alterado.
			//   - 'media' → cópia do módulo de envio + Media Field (imagem). É o
			//               padrão ('default_layout' => 'media'). O autor duplica
			//               o form no JFB, adiciona um Media Field mapeado para
			//               'message_image' e informa o form_id aqui.
			//
			// 'form_id' => 0 significa "ainda não informado": o layout fica
			// registrado (visível para o futuro) mas inerte até receber um ID > 0.
			// O layout de mídia é feature-detectado no cliente pela presença do
			// campo nativo .jet-form-builder-file-upload — então o revestimento
			// visual (+ / previews) funciona assim que o form com Media Field
			// entra na página, independente do ID.
			'composer_layouts' => array(
				'text'  => array(
					'label'   => 'Texto',
					'form_id' => 56386,
					'media'   => false,
				),
				'media' => array(
					'label'       => 'Texto + mídia',
					'form_id'     => 0,
					'media'       => true,
					'media_field' => 'message_image',
				),
			),
			// Layout recomendado/ativo por padrão. Informação de intenção (o autor
			// escolhe no Elementor qual form renderiza); o runtime reconhece todos
			// os forms dos layouts configurados.
			'default_layout' => 'media',

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
			//   6  = valor de TESTE atual (produção: ~30).
			'initial_limit'   => 6,
			// ID da CCT Query que alimenta o Listing das mensagens.
			//   0 = auto: casa qualquer CCT Query sobre o 'cct_slug' (mensagens_).
			//   >0 = cirúrgico: limita/reordena SÓ essa query (use se houver mais
			//        de uma listagem do mesmo CCT e você quiser tratar só uma).
			'messages_query_id' => 0,
			// Coluna de data do CCT usada para ordenar "as mais recentes".
			// 'cct_created' é a coluna nativa de criação do CCT.
			'messages_order_field' => 'cct_created',

			// --- Carregar mensagens ANTIGAS (rolar pra cima, estilo WhatsApp) -----
			// O load-more NATIVO do JetEngine anexa no FIM (feed que cresce pra
			// baixo) — errado para um chat. Aqui o runtime carrega as antigas e
			// as insere no TOPO, com a rolagem ancorada (a viewport não pula).
			//   load_older   → liga/desliga o recurso.
			//   older_batch  → quantas mensagens por carga (TESTE: 3; produção: ~15).
			//   older_trigger→ 'scroll' (rolar ao topo) | 'button' | 'both'.
			'load_older'      => true,
			'older_batch'     => 3,
			'older_trigger'   => 'both',

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
			'rate_before'      => 40,     // carregar antigas (rolar pra cima)
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
	 * Mapa dos forms do composer com suas features.
	 *
	 * Une, por form_id, o registro de layouts ('composer_layouts', fonte de
	 * verdade das features) com a lista legada 'form_ids' (tratada como texto,
	 * para compat). Chave = form_id (int > 0). Valor:
	 *   [ 'layout' => slug, 'media' => bool, 'media_field' => string ].
	 *
	 * É o que o runtime usa para (a) reconhecer QUAIS forms são o composer do
	 * chat e (b) saber quais têm mídia. Filtro: conversa-chat/composer-forms.
	 *
	 * @return array<int, array>
	 */
	public function composer_forms() {

		$forms = array();

		foreach ( (array) $this->setting( 'composer_layouts', array() ) as $key => $layout ) {

			$fid = isset( $layout['form_id'] ) ? (int) $layout['form_id'] : 0;

			if ( $fid < 1 ) {
				continue; // layout registrado mas ainda sem form (inerte).
			}

			$forms[ $fid ] = array(
				'layout'      => (string) $key,
				'media'       => ! empty( $layout['media'] ),
				'media_field' => isset( $layout['media_field'] ) ? (string) $layout['media_field'] : '',
			);
		}

		// Compat: form_ids explícitos ainda são reconhecidos (como texto) se
		// nenhum layout já os cobrir.
		foreach ( (array) $this->setting( 'form_ids', array() ) as $fid ) {

			$fid = (int) $fid;

			if ( $fid > 0 && ! isset( $forms[ $fid ] ) ) {
				$forms[ $fid ] = array(
					'layout'      => 'text',
					'media'       => false,
					'media_field' => '',
				);
			}
		}

		return apply_filters( 'conversa-chat/composer-forms', $forms );
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
