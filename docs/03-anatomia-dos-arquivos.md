# 03 — Anatomia dos Arquivos (o que roda hoje via WPCode)

São **6 snippets PHP** + **1 template Elementor**. A numeração (v2, v2.1, v3, v4.1)
reflete a evolução histórica: cada versão resolveu um problema da anterior sem
remover a base. Hoje **todos convivem** — é por isso que há APIs "legadas" mantidas
para compatibilidade entre versões.

Ordem lógica de dependência:

```
context-guard  ─┬─►  side-resolver
 (base: gate +  ├─►  backend-api
  constantes)   ├─►  form-composer (v2.1)
                ├─►  layout (v3)
                └─►  incremental-runtime (v4.1)  ── usa backend-api como fallback
```

> Nada garante essa ordem em runtime além da ordem de ativação no WPCode. Todos os
> arquivos se protegem com `if ( ! defined('ABSPATH') ) exit;` e checam
> `function_exists('chat_conversa_...')` antes de chamar dependências.

---

## 3.1 `conversa-v2-context-guard.php` — base do projeto

**Responsabilidade única:** decidir se estamos num contexto onde o chat deve atuar e
fornecer os dados de contexto para os demais arquivos. Não renderiza nada.

- **Constantes globais** (centralizadas aqui): `CHAT_CONVERSA_CPT` (`:43`),
  `CHAT_CONVERSA_CCT_TABLE` (`:47`), `CHAT_CONVERSA_LISTING_ID = 56326` (`:51`),
  `CHAT_CONVERSA_FORM_IDS = '56386'` (`:56`), `CHAT_CONVERSA_LAST_MESSAGE_META` (`:60`).
- **`chat_conversa_can_register_hooks()`** (`:79`) — gate leve (pré-query).
- **`chat_conversa_context()`** (`:178`) — gate completo (pós-query), com cache
  estático; devolve `conversa_id`, `user_id`, `is_artista`, `is_convidado`, `role`.

Ver detalhes do gate em [doc 01, §1.5](01-arquitetura.md).

---

## 3.2 `conversa-v2-side-resolver.php` — de quem é o card

**Responsabilidade:** decidir se cada card é do artista ou do convidado. Estratégia
**CSS-first** (servidor já conhece os IDs → gera CSS específico), com **JS de fallback**.

- **CSS** (`wp_head`, `:35`): esconde todos os cards e reexibe só o correto casando
  `#section-msgs-conversa [data-from-user="<is_artista>"] .chat-msg-card--artist`
  (e o equivalente para convidado). Tudo escopado em `#section-msgs-conversa` para
  não vazar. Fallback: linha sem match conhecido mostra ambos (`is-chat-msg-unmatched`).
- **JS** (`wp_footer`, `:36`): só age quando uma linha **não** tem `data-from-user`
  direto — lê de um `.chat-msg-from-user` interno e replica no item. Escuta
  `ChatConversa:messages-replaced` (`:239`) para reprocessar itens novos.

> Depende do template do Listing 56326 expor `data-from-user` e as classes
> `.chat-msg-card--artist/--guest`. Isso é um **contrato implícito** entre este
> arquivo e o layout do listing.

---

## 3.3 `conversa-v2-backend-api.php` — endpoints de leitura (base v2)

**Responsabilidade:** expor 2 endpoints AJAX para o runtime.

- **`chat_conversa_status`** (`:261`) — barato, para polling. Faz **1 SELECT** direto
  na tabela do CCT: `MAX(_ID)`, `COUNT(*)`, `MAX(data_envio)` (`:337-348`). Devolve
  `hash = md5(last_id.'|'.count)` (`:371`) — `data_envio` fica **fora** do hash de
  propósito, para evitar "scroll fantasma" por edições. Cache de 2s no object cache.
- **`chat_conversa_messages`** (`:394`) — caro, sob demanda. Renderiza o Listing e
  devolve HTML. Duas estratégias em `chat_conversa_render_listing()` (`:437`):
  1. **Direta:** `do_shortcode('[jet_engine_listing listing_id="56326"]')` (`:499`),
     com sanity check de `data-listing-id` no HTML (`:520`);
  2. **Fallback HTTP:** `wp_safe_remote_get()` no próprio permalink + `DOMDocument`/
     `XPath` para extrair o nó `[data-listing-id="56326"]` (`:531-629`).
- **Segurança:** valida POST + nonce + login + o `conversa_id` existir e ser
  `publish` (`:194-228`); rate limit por transient (`:236`).
- **Hook `last_message_at`** (`:116`): tenta atualizar o meta da conversa a cada
  inserção no CCT — **mas registrado num hook que não existe nesta versão do
  JetEngine**. Ver [doc 05, item 5.1](05-achados-e-inconsistencias.md).

> Na arquitetura atual, o `v4.1` é o runtime ativo; este arquivo v2 sobrevive
> principalmente como **fallback "full"** do v4.1 (`chat_conversa_render_listing`
> é chamado pelo endpoint `chat_conversa_v4_full`).

---

## 3.4 `conversa-v2.1-form-composer.php` — transformar o form em caixa de chat

**Responsabilidade:** estilizar visualmente o Form 56386 como um composer de chat e
gerenciar o comportamento do textarea. **Puramente cosmético + UX** — não envia nada
(o envio é do JetFormBuilder).

- **Gate por classe** (lição da v2): o CSS não depende mais da lista de IDs de form
  (que causava bug de precedência de vírgula em seletores). O CSS aplica só sob
  `form.chat-conversa-composer`, e **o JS é o filtro** — só adiciona essa classe aos
  forms que casam com `form_selector` (`:584`).
- **Regras de ouro preservadas** (`:18-23`): nunca forçar `display` no `<form>`;
  nunca colapsar wrapper que contenha `textarea/input/select`; nunca mexer em
  `disabled` (isso é do runtime, via tab-lock); observer escopado a `#footer-conversa`.
- **Auto-size do textarea**, expand/collapse, botão de enviar estilizado (ícone SVG),
  estados `is-empty`/`is-expanded`/`is-focused`.
- **Limpeza pós-envio:** no `jet-form-builder/ajax/on-success` (`:653`), limpa
  **apenas o textarea** com múltiplos ticks (0/50/200ms) porque o JFB às vezes
  restaura o valor durante o pós-processo. Não toca em hidden fields.
- Escuta `ChatConversa:messages-replaced` **e** `ChatConversa:messages-appended`
  (`:676-679`) para reprocessar.

---

## 3.5 `conversa-v3-layout.php` — o layout de chat e o scroll

**Responsabilidade:** estruturar a single como chat (header/msgs/footer em flex de
altura de viewport) e controlar o scroll para o fim.

- **CSS** (`:78`): `#parent-section-conversa` vira flex column ocupando `100dvh`
  (com ajuste para `admin-bar`); `#section-msgs-conversa` é a área rolável
  (`flex:1 1 auto; overflow-y:auto`); mensagens ancoradas ao fim via
  `justify-content:flex-end` + `margin-top:auto`.
- **Mudança central em relação à v2:** a v2 tinha **uma** função `scrollToBottom(reason)`
  que decidia forçar/respeitar por regex no nome do motivo, e vários observers
  disparavam scroll por inferência de DOM → gerava o bug do **"segundo scroll
  fantasma" 10-15s após o boot**. A v3 troca isso por **uma função por contexto**
  (`:21-38`): `scrollOnBoot`, `scrollOnSubmit`, `scrollOnNewMessage` (respeita
  sticky), `scrollOnPageshow`, `scrollOnTakeover`, `scrollOnComposerExpand`.
- **Fonte da verdade do scroll:** `state.stickToBottom`. Só há **duas** funções que
  tocam `scrollTop` (`_scrollNow`/`_scrollNextFrame`, `:297-310`).
- **API legada** `scrollToBottom(reason)` (`:530`) mantida para não quebrar um Runtime
  v2 eventualmente instalado — roteia motivos "fortes" para as funções específicas e
  todo o resto para `scrollOnNewMessage` (que respeita sticky).
- Escuta `ChatConversa:messages-replaced` (`:450`) e o evento **nativo**
  `jet-engine/listing-grid/after-load-more` (`:458`) — marca interação humana para
  não forçar re-stick quando o usuário carrega histórico.

---

## 3.6 `conversa-v4.1-incremental-runtime-backend-custom-renderer.php` — o runtime

O maior arquivo (~1926 linhas) e o coração do real-time. **É aqui que mora o
"custom renderer" / mirror renderer** que o autor identifica como o ponto que quebra
o princípio fundamental. Detalhes e crítica em [doc 04](04-realtime-e-mirror-renderer.md).

**Backend — 3 endpoints AJAX** (nonce próprio `chat_conversa_v4_nonce`):

| Endpoint | Papel |
|----------|-------|
| `chat_conversa_v4_status` (`:81`) | Estado da conversa (barato). Devolve totais e por-`publish`: `last_id_*`, `count_*`, `last_changed_*`, `hash` (`:281-333`). |
| `chat_conversa_v4_after` (`:96`) | **Dados estruturados** das mensagens com `_ID > after_id`, `cct_status='publish'`, `ORDER BY _ID ASC` (`:336-367`). Devolve JSON por mensagem (`prepare_message_item`, `:383`), **não HTML**. |
| `chat_conversa_v4_full` (`:138`) | Fallback: delega ao `chat_conversa_render_listing()` do v2 (HTML completo do listing). |

- `prepare_message_item()` (`:383`) monta `{ id, from_user, side, status, created_at,
  time_label, message, display_name, avatar_url }`.
- **O texto da mensagem é adivinhado** por `extract_message_text()` (`:409`): tenta uma
  lista de nomes de campo candidatos (`mensagem`, `message`, `texto`, ...). Ver
  [doc 05, item 5.2](05-achados-e-inconsistencias.md).
- Validação: nonce + login + `conversa` publish + **é participante** (`:207-214`) +
  rate limit.

**Frontend — o runtime (`:644` em diante):**

- **Prototypes:** `collectPrototypes()` (`:883`) clona um `.jet-listing-grid__item`
  já presente que contenha `.chat-msg-card--artist` (e outro `--guest`) para usar
  como molde.
- **Mirror render:** `renderItemFromPrototype()` (`:1011`) clona o molde,
  `updateRootAttrs()` troca `data-message-id`/`data-post-id`/classes (`:967`), e
  `updateDynamicFields()` (`:928`) injeta nome/mensagem/hora em **seletores de
  elemento Elementor hard-coded** (`:931-942`).
- **Append:** `appendItems()` (`:1026`) insere no `.jet-listing-grid__items`,
  de-duplica por `data-message-id`, e se faltar prototype cai para o full refresh.
- **Sincronização:** `checkStatus()` (`:1484`) compara `hash`; `decideSyncFromStatus()`
  (`:1508`) escolhe entre `fetchAfter` (append incremental) e `requestFullRefresh`.
- **Polling adaptativo:** modos BOOT/ACTIVE/IDLE/DORMANT/HIDDEN/SECONDARY com
  intervalos configuráveis (`active_poll_ms=4000`, `idle_poll_ms=30000`, `:621-622`).
- **Tab-lock:** via `localStorage` (`:1209-1365`) — só uma aba é "primária" (pode
  enviar/polling); as outras entram em modo leitura com aviso "Assumir".
- **Pós-submit:** no submit e no `on-success` do JFB, agenda `checkStatus` em
  0/900/1800/3200ms (`:1691-1702`) para capturar a mensagem recém-gravada.
- Emite `ChatConversa:messages-appended` (append) e `ChatConversa:messages-replaced`
  (full) — os eventos que side-resolver, composer e layout escutam.

---

## 3.7 `layout-elementor-single-post-conversa.json` — o template single

Export do template Elementor da single `conversa`. Fornece os contêineres-âncora
(`#parent-section-conversa`, `#header-conversa`, `#section-msgs-conversa`,
`#footer-conversa`), o widget do Listing 56326 e o widget do Form 56386. Ver
[doc 01, §1.3](01-arquitetura.md) para a árvore completa e a ressalva sobre o Listing
56326 ser um documento **separado**.
