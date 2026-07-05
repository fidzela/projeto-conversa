# 01 — Arquitetura e Modelo de Dados

> Ver também: [02 — Correlação entre Plugins](02-correlacao-plugins.md) para as evidências
> `arquivo:linha` de cada integração citada aqui.

## 1.1 Visão de alto nível

```
                       ┌─────────────────────────────────────────────┐
                       │  Single do CPT 'conversa'  (template Elementor)│
                       │                                               │
  Usuário digita  ───► │  #footer-conversa                             │
   e envia            │    └─ jet-engine-booking-form (Form 56386)     │
                       │                                               │
                       │  #section-msgs-conversa                       │
   Mensagens exibidas ◄│    └─ jet-listing-grid (Listing 56326)        │
                       │         └─ 1 card por linha do CCT 'mensagens_'│
                       │                                               │
                       │  #header-conversa                             │
                       │    └─ jet-button + jet-listing-grid (68686)   │
                       └─────────────────────────────────────────────┘
```

O fluxo de dados canônico (entrada → processamento → saída):

```
Envio (JetFormBuilder)                    Exibição (JetEngine + Elementor)
─────────────────────                     ────────────────────────────────
Form 56386 submit                         Listing 56326 (query CCT mensagens_)
  ↓  (AJAX)                                 ↓  por item
Action 'insert_custom_content_type'       get_listing_item()  → 1 card Elementor
  ↓                                         ↓
Item_Handler::update_item()               #section-msgs-conversa
  ↓
DB::insert()  →  wp_jet_cct_mensagens_
  ↓ (dispara hooks JetEngine)
[gatilho de real-time — ver doc 04]
```

O elo **crítico** do projeto está no espaço entre "gravou no CCT" e "apareceu no
Listing". Nativamente, o Listing só re-renderiza no **reload da página**. Todo o
esforço de real-time (docs 03 e 04) existe para preencher esse vão sem reload.

---

## 1.2 Modelo de dados

### CPT `conversa` (o "quarto")

- Post type: **`conversa`** (constante `CHAT_CONVERSA_CPT`, `conversa-v2-context-guard.php:43`).
- Custom Meta Storage ativo (armazenamento em meta próprio do JetEngine).
- Post ID de exemplo citado pelo autor: **670**.

**Metafields:**

| Meta | Uso |
|------|-----|
| `is_artista` | User ID do participante "artista" |
| `is_convidado` | User ID do participante "convidado" |
| `last_message_at` | Timestamp da última mensagem (constante `CHAT_CONVERSA_LAST_MESSAGE_META`, `context-guard.php:60`) |

O par (`is_artista`, `is_convidado`) define **quem** são os dois lados da conversa.
Todo o resto do sistema (resolução de lado do card, validação de participante nos
endpoints AJAX) deriva desses dois metas — ver `context-guard.php:246-254`.

### CCT `mensagens_` (as mensagens)

- Custom Content Type do JetEngine, **slug `mensagens_`** (com underscore final).
- Tabela: **`wp_jet_cct_mensagens_`** — prefixo do WP + `jet_cct_` (prefixo fixo do
  JetEngine, `core-plugins/.../custom-content-types/inc/db.php:29`) + slug.
- Constante do projeto: `CHAT_CONVERSA_CCT_TABLE = 'jet_cct_mensagens_'`
  (`context-guard.php:47`).

**Colunas definidas pelo projeto (metafields do CCT):**

| Coluna | Uso |
|--------|-----|
| `conversa_id` | FK para o post `conversa` |
| `from_user` | User ID de quem enviou |
| `to_user` | User ID do destinatário |
| `message` | Texto da mensagem |
| `data_envio` | Timestamp de envio |

**Colunas de serviço, injetadas automaticamente pelo JetEngine** (não são do
projeto, mas existem em toda linha — `db.php:280-282` e `db.php:564-571`):

| Coluna | Origem |
|--------|--------|
| `_ID` | `bigint AUTO_INCREMENT`, primary key. É o identificador incremental de cada mensagem. |
| `cct_status` | `text`, default **`publish`** (`db.php:26`). O projeto filtra por `cct_status = 'publish'`. |
| `cct_author_id` | Preenchido com `get_current_user_id()` na criação (`item-handler.php:444`). |
| `cct_created` | `current_time('mysql')` na criação (`item-handler.php:445`). |
| `cct_modified` | idem, atualizado em cada update (`item-handler.php:446,409`). |

> **Observação de arquitetura (não é erro, é oportunidade):** o CCT já entrega
> `_ID` (incremental) e `cct_created`/`cct_modified` (timestamps) de graça. O projeto
> criou `data_envio` manualmente para cumprir o papel de "timestamp da mensagem",
> quando `cct_created` já existiria para isso. Ver [doc 05](05-achados-e-inconsistencias.md).

---

## 1.3 O template Elementor da single (`layout-elementor-single-post-conversa.json`)

O arquivo exportado é o **shell da página single** do CPT `conversa`. Sua árvore
efetiva de contêineres:

```
container #1229c5f
└─ container #745d0f19   → #parent-section-conversa
   ├─ container #11953521 → #header-conversa
   │   ├─ widget jet-button              [id 75f639ee]
   │   └─ widget jet-listing-grid        [id 776da9be]  listing_id = 68686
   ├─ container #4fd8fa8   → #section-msgs-conversa
   │   └─ widget jet-listing-grid        [id 1fac565c]  listing_id = 56326   ◄ MENSAGENS
   ├─ container #71f568d5  → #footer-conversa
   │   └─ container #4f5039bd
   │       └─ widget jet-engine-booking-form [id 4fb02bde]  form_id = 56386   ◄ COMPOSER
   └─ widget off-canvas   [id 47a3ede0]
       └─ container #59a4245a
           ├─ button #474ab1b0, button #450ad686, heading #7093d635
           └─ 2× jet-listing-grid  listing_id = 57373   (lista lateral de conversas)
```

Os **IDs de âncora de CSS/JS** que todo o projeto usa são definidos aqui, via
`_element_id` do Elementor:

- `#parent-section-conversa` — o wrapper flex de altura de viewport (layout de chat).
- `#header-conversa` — cabeçalho fixo (topo).
- `#section-msgs-conversa` — área rolável das mensagens (o "corpo" do chat).
- `#footer-conversa` — rodapé fixo com o composer.

> **Ponto crítico de acoplamento:** o Listing 56326 aparece aqui **apenas como
> referência** (`listing_id = 56326`). O **layout interno de cada card de mensagem**
> (a estrutura HTML com `.chat-msg-card--artist` / `.chat-msg-card--guest` e os
> widgets de campo dinâmico) vive num **documento Elementor separado — o Listing
> 56326 em si —, que NÃO está neste JSON nem no repositório.** Confirmado: nenhum dos
> IDs de elemento hard-coded pelo runtime (`ebc134e`, `9a10ed6`, `6fc61e3`,
> `1178325`, `066944a`, `e02293e`) nem a classe `chat-msg-card` existem neste arquivo.
> Esse é exatamente o acoplamento que o [doc 04](04-realtime-e-mirror-renderer.md) discute.

---

## 1.4 Papéis: artista vs convidado

O sistema é 1‑a‑1 e assimétrico visualmente: mensagem do artista renderiza um card
`--artist`, mensagem do convidado renderiza um card `--guest`. A decisão de qual
card mostrar é feita comparando `from_user` (da mensagem) com `is_artista` /
`is_convidado` (do post `conversa`):

- No servidor: `context-guard.php:249-254` resolve o `role` do usuário atual.
- No CSS: `conversa-v2-side-resolver.php` gera regras que casam
  `[data-from-user="<id>"]` com o card correto.
- No runtime incremental: `conversa-v4.1...php:389-394` define `side = artist|guest`
  por mensagem.

---

## 1.5 Contextos onde o sistema atua (gate)

Todo o sistema só deve atuar na **single logada do CPT `conversa`**. Isso é decidido
em duas camadas (`conversa-v2-context-guard.php`):

1. **Gate leve** `chat_conversa_can_register_hooks()` (`:79`) — roda cedo, antes da
   query principal. Descarta admin, REST, AJAX, cron, XML-RPC e editores de page
   builder (Elementor preview, Gutenberg, etc.). Decide se vale registrar hooks de
   `wp_head`/`wp_footer`.
2. **Gate completo** `chat_conversa_context()` (`:178`) — roda dentro das callbacks,
   já com a query resolvida. Exige `is_singular('conversa')` + usuário logado, e
   devolve `conversa_id`, `user_id`, `is_artista`, `is_convidado`, `role`. Tem cache
   estático por request.

Todos os outros 5 arquivos consomem essas duas funções e **não** duplicam lógica de
gate — exceto o `v4.1`, que reimplementa seu próprio gate
(`chat_conversa_v4_get_front_context()`, `:521`) — ver [doc 05](05-achados-e-inconsistencias.md).
