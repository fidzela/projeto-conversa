# 07 — Plugin `conversa-chat` (a nova versão)

A nova versão do projeto, construída sob a **REGRA DE OURO**:

> Reaproveitar e integrar o que os plugins já proporcionam nativamente.
> O código começa e termina onde as funcionalidades nativas não chegam.

Substitui **por completo** os 6 snippets do WPCode. Código em [`conversa-chat/`](../conversa-chat/),
instalação e checklist em [`conversa-chat/README.md`](../conversa-chat/README.md).

---

## 7.1 O que mudou de paradigma

A versão anterior lutava **contra** os plugins (mirror renderer clonando HTML,
side-resolver escondendo cards por CSS/JS, fetch HTTP interno para renderizar).
A nova versão **direciona** capacidades que já existem na raiz:

| Necessidade | Versão WPCode (v2–v4.1) | Plugin novo (fonte nativa validada) |
|---|---|---|
| Renderizar mensagem nova | Mirror renderer: clona card no cliente e troca textos em seletores hard-coded (`elementor-element-ebc134e`…) | **`posts_loop()` no servidor** — o mesmo pipeline do grid/load-more (`listings/ajax-handlers.php:459`); o template do Listing é a única fonte visual |
| Card artista vs convidado | side-resolver: CSS gerado + JS de fallback escondendo o card errado | **Dynamic Visibility** (módulo nativo do JetEngine), configurado **na UI do card** — condição `equal`: `current_field(from_user)` × meta `is_artista`/`is_convidado`. *Confirmado já em uso no export do Listing 56326* |
| Anexar itens no DOM | `appendChild` de clones + eventos próprios | append + **`JetEngine.initElementsHandlers`** (a mesma API do load-more nativo, `main.js:599`) |
| Refresh completo | Endpoint próprio com shortcode ou **fetch HTTP de si mesmo** + DOMDocument | **Endpoint nativo `jet_engine_ajax`/`get_listing`** (`ajax-handlers.php:479`), chamado com os dados que o grid publica no DOM (`data-nav`, `data-queried-id`); o filtro nativo `add-query-data` (`listing-grid.php:1327`) garante a query assinada |
| "Mensagens após o ID X" | SQL manual na tabela | **API nativa do CCT**: `factory->prepare_query_args()` + `db->query()` com `['field'=>'_ID','operator'=>'>',...]` (`db.php:716`) |
| Campo do texto | Heurística com 11 candidatos | `message_field = 'message'` explícito na configuração |
| `last_message_at` | Hook **inexistente** (`data/inserted-item`) → nunca rodava | Hook real **`created-item/mensagens_`** (`item-handler.php:453`), assinatura correta |
| Limpar textarea após envio | JS próprio em múltiplos ticks lutando contra o JFB | **"Clear data on submit" nativo** do JFB (`data-clear`, `form-builder.php:146`); o plugin só re-mede o auto-size |
| Localizar o form | `form#jet-form-56386` (dependia de anchor manual) | Markup nativo: `form.jet-form-builder[data-form-id="56386"]` (`form-builder.php:139,142`) |
| Gate/contexto | Dois guards paralelos (v2 + v4.1) | Um único `Conversa_Chat_Context` |
| Endpoints | 5 endpoints, 3 nonces (v2 + v4.1) | **2 endpoints, 1 nonce** (`status`, `after`) — o "full" é o endpoint nativo do JetEngine |
| Configuração | Constantes espalhadas em 6 snippets | `Conversa_Chat::settings()` + filtro `conversa-chat/settings` |

## 7.2 Arquitetura

```
conversa-chat/
├─ conversa-chat.php                     bootstrap + declaração do princípio
├─ includes/
│  ├─ class-conversa-chat.php            settings (filterable) + loader
│  ├─ class-conversa-chat-context.php    gate único (leve + completo) + participantes
│  ├─ class-conversa-chat-data.php       leitura do CCT pela API nativa (status/after)
│  ├─ class-conversa-chat-renderer.php   posts_loop → HTML real do template
│  ├─ class-conversa-chat-ajax.php       2 endpoints + validação + rate limit
│  ├─ class-conversa-chat-integrations.php  created-item→last_message_at; add-query-data
│  └─ class-conversa-chat-assets.php     enqueue condicional + config do front
└─ assets/
   ├─ css/chat.css                       layout de chat + composer + aviso de aba
   └─ js/
      ├─ layout.js                       scroll por contexto explícito (herda o design v3)
      ├─ composer.js                     auto-size do textarea (form é 100% JFB)
      └─ runtime.js                      polling, tab-lock, append nativo, full nativo
```

### Fluxo do tempo real

```
usuário envia (JFB AJAX)
  └─ Action nativa insert_custom_content_type grava no CCT
       └─ hook created-item/mensagens_ → last_message_at + action conversa-chat/message-created
  └─ evento JS jet-form-builder/ajax/on-success → runtime checa status

runtime (aba primária)
  └─ POST conversa_chat_status  → { last_id, count, hash }      [polling adaptativo]
       hash mudou e last_id cresceu?
       └─ POST conversa_chat_after (after_id = último _ID conhecido)
            └─ servidor: CCT query nativa (_ID > X) → posts_loop() → HTML dos itens
            └─ cliente: dedup por data-post-id (nativo) → append → initElementsHandlers
                 → evento conversa-chat:messages-appended → Layout.scrollOnNewMessage()
       mudança retroativa (remoção/despublicação)?
       └─ POST NATIVO jet_engine_ajax/get_listing (dados do data-nav do próprio grid)
            └─ substitui o grid inteiro pelo render nativo
```

## 7.3 Por que os critérios de "não engessar" agora passam

1. **Editar o card no Elementor** → o incremental renderiza pelo template real;
   o que o editor salvar é o que aparece — no load e no tempo real. Nenhum
   seletor de elemento Elementor existe no plugin.
2. **Metafield novo (ex.: imagem)** → adiciona o campo no CCT, o widget no card
   do Listing e (se enviável) o campo no form JFB. O plugin não muda: os itens
   incrementais saem do mesmo template.
3. **Layout/listing alternativo** → `listing_id` na configuração; o card decide
   sua própria aparência (inclusive condicional, via Dynamic Visibility).
4. **Ligar/desligar o real-time** → `realtime` global ou filtro por conversa
   (`conversa-chat/realtime-enabled`). Desligado, o chat degrada para o
   comportamento nativo (mensagem aparece no reload) sem quebrar nada.
5. **Portabilidade** → plugin versionado; todo o "estado de UI" necessário está
   descrito no checklist do README do plugin (IDs de seção, AJAX + clear no
   form, Dynamic Visibility no card — os três já configurados no site).

## 7.4 O que foi preservado da versão anterior (decisões boas)

- Detecção barata por **hash de `last_id|count`** (timestamps fora do hash — evita refresh fantasma).
- **Gate em duas camadas** (leve pré-query / completo pós-query).
- **Scroll por contexto explícito** com `stickToBottom` como fonte única (o design da v3 que matou o scroll fantasma).
- **Regras de ouro do composer** (não forçar display no form, não colapsar wrapper com campo, não tocar em disabled).
- **Tab-lock** (uma aba primária por usuário+conversa) — reescrito compacto.
- **Rate limit + validação de participante** nos endpoints.

## 7.5 Pontos de extensão

Servidor: `conversa-chat/settings`, `conversa-chat/realtime-enabled`,
`conversa-chat/is-participant`, `conversa-chat/front-config`,
`conversa-chat/rendered-items` (filtros); `conversa-chat/message-created` (action —
gatilho para push/SSE/notificações futuras, disparado para qualquer origem de
mensagem: form, REST, admin).

Cliente: eventos `conversa-chat:messages-appended` / `messages-replaced` /
`tabstate`; APIs `ConversaChatLayout`, `ConversaChatRuntime`, `ConversaChatComposer`.

## 7.6 Migração (WPCode → plugin)

1. Instalar e ativar o plugin (`conversa-chat/` → `wp-content/plugins/`).
2. No form 56386 (UI do JFB): confirmar **Submit Type = AJAX** e ativar
   **Clear data on submit**.
3. Desativar os 6 snippets no WPCode (tudo é substituído; manter os dois
   ativos duplica polling/estilos).
4. Validar na single: enviar mensagem nas duas pontas, editar o card no
   Elementor e confirmar que o tempo real reflete a mudança.

Nenhuma migração de dados: CPT, CCT e metas continuam exatamente como estão.
