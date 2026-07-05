# 02 — Correlação entre Plugins (evidências)

Este documento cumpre a **Regra Nº 3** do `core-plugins/README.md`: *correlação entre
plugins deve ser comprovada no código*. Cada integração abaixo está ancorada em
`arquivo:linha` do repositório `core-plugins` (JetEngine 3.8.10.1, JetFormBuilder
3.6.1.1) ou do `projeto-conversa`.

Cadeia completa do projeto:

```
Elementor (widget booking-form)
   │  renderiza
   ▼
JetFormBuilder (Form 56386)  ──submit──►  Action 'insert_custom_content_type'
   │                                          │  (a Action é do JetEngine, plugada no JFB)
   │                                          ▼
   │                                 JetEngine CCT: Item_Handler::update_item()
   │                                          │
   │                                          ▼
   │                                 tabela wp_jet_cct_mensagens_  (+ hooks de created-item)
   │
Elementor (template single) ──contém──► JetEngine Listing Grid 56326
                                            │  renderiza
                                            ▼
                                   query no CCT → 1 card por mensagem
```

---

## 2.1 Elementor → JetFormBuilder (o composer)

**Evidência:** o widget do rodapé é `jet-engine-booking-form` com
`form_provider = 'jet-form-builder'` e `form_id = '56386'`
(`layout-elementor-single-post-conversa.json`, widget `id 4fb02bde`).

- `jet-engine-booking-form` é o widget Elementor que a JetEngine expõe para
  **incorporar um formulário JetFormBuilder** dentro de um layout Elementor.
- Isto casa com a constante `CHAT_CONVERSA_FORM_IDS = '56386'`
  (`conversa-v2-context-guard.php:56`), usada pelo composer e pelo runtime para
  localizar o `<form>` alvo (`#footer-conversa form#jet-form-56386`).

## 2.2 JetFormBuilder → JetEngine CCT (a gravação)

**Evidência:** `core-plugins/jet-engine-v3.8.10.1/includes/modules/custom-content-types/inc/forms/action.php`.

- A classe `Action extends Jet_Form_Builder\Actions\Types\Base` (`action.php:10`)
  registra a **ação de pós-envio** `insert_custom_content_type` (`get_id()`, `:16`).
  É código do **JetEngine** que se **pluga no JetFormBuilder** — a integração é real
  e explícita (usa `use Jet_Form_Builder\Actions\...`, `:6-8`).
- No submit, `do_action()` (`:85`):
  1. mapeia campos do form → campos do CCT via `fields_map` (`:106-110`);
  2. define `cct_status` (`:120`);
  3. chama `$type_object->get_item_handler()->update_item( $item )` (`:161-162`).

Ou seja: **enviar o Form 56386 grava uma linha no CCT `mensagens_`**. O `conversa_id`,
`from_user`, `to_user`, `message`, `data_envio` chegam como campos mapeados do
formulário (alguns provavelmente via campos ocultos / macros do JetEngine).

## 2.3 JetEngine CCT: a gravação e os hooks disparados

**Evidência:** `.../custom-content-types/inc/item-handler.php::update_item()` (`:325`).

Fluxo de criação de uma **nova** mensagem (sem `_ID` no payload):

```
update_item()
  ├─ do_action('jet-engine/custom-content-types/create-item/mensagens_',  $item, $this)          :448
  ├─ $item_id = $this->factory->db->insert($item)                                                  :450  → db.php:212
  ├─ do_action('jet-engine/custom-content-types/created-item/mensagens_', $item, $item_id, $this) :453
  └─ do_action('jet-engine/custom-content-types/updated-item/mensagens_', $item, [], $this)       :462
```

- `cct_status` é forçado para um status válido (default `publish`) em `:361-369`.
- `cct_author_id`, `cct_created`, `cct_modified` são preenchidos em `:444-446`.

> **Os nomes dos hooks são sufixados pelo slug do CCT** (`.../created-item/mensagens_`).
> Esse é o gancho **correto** para reagir a "chegou mensagem nova" no servidor.
> O projeto hoje escuta um hook **diferente e inexistente** nesta versão — ver
> [doc 05, item 5.1](05-achados-e-inconsistencias.md).

**Estrutura da tabela** (`.../custom-content-types/inc/db.php`):

- Prefixo fixo `jet_cct_` (`:29`).
- Sempre cria `_ID bigint AUTO_INCREMENT` (PK) e `cct_status text` (`:280-282`),
  mais as colunas do schema do projeto.
- Insert real via `wpdb->insert()` (`:212`).

## 2.4 Elementor → JetEngine Listing Grid (a exibição)

**Evidência:** `.../includes/components/listings/render/listing-grid.php`.

O Listing Grid 56326 é renderizado assim:

1. **Contêiner** (`:1518-1529`):
   ```
   <div class="jet-listing-grid__items ..." data-listing-id="56326" data-query-id="...">
   ```
2. **Loop de itens** `posts_loop()` (`:1592`) — um item por resultado da query.
3. **Cada item** (`:1685`, `:1757`):
   ```
   <div class="jet-listing-grid__item jet-listing-dynamic-post-{_ID} ..."
        data-post-id="{_ID}" data-render-type="jet-engine" ...>
       {conteúdo}
   </div>
   ```
   O `{_ID}` é o ID do item do CCT (`get_current_object_id()`, `:1681`).
4. **O conteúdo de cada item** vem de `get_listing_item($post_obj)`
   (`:1677` → `includes/components/listings/frontend.php:314`), que renderiza o
   **template Elementor do Listing 56326** para aquela mensagem. **É aqui que os
   campos dinâmicos (nome, mensagem, hora) e as classes `.chat-msg-card--*` são
   produzidos** — no template do listing, não no template single.

Conclusão: **o Listing 56326 é a fonte visual da verdade dos cards.** Qualquer
alteração de layout de card é feita editando o Listing 56326 no Elementor.

## 2.5 O mecanismo NATIVO de append incremental do JetEngine

Este é o achado mais importante para a nova versão. **O JetEngine já sabe renderizar
e anexar itens novos ao grid, pelo template real, via AJAX** — o mesmo caminho do
"load more" / "lazy load".

**Evidência (servidor):** `.../includes/components/listings/ajax-handlers.php`.

- Action AJAX: `jet_engine_ajax` (`:28-29`).
- Handlers permitidos: **`listing_load_more`** e **`get_listing`** (`:108-115`).
- `listing_load_more()` (`:328`):
  - valida a **assinatura** da query (HMAC com `AUTH_KEY`/`NONCE_KEY`/`AUTH_SALT`/
    `NONCE_SALT`, `:248-288`) — proteção contra query forjada;
  - roda `$render_instance->posts_loop(...)` (`:459`) — **o mesmo `posts_loop` do
    grid**, ou seja, renderiza pelo template real;
  - devolve `response['html']` (`:467`) com os itens novos.

**Evidência (frontend):** `.../assets/js/frontend/src/main.js`.

- `ajaxGetListing({ handler: 'listing_load_more', append: true, ... })` (`:790-802`).
- No retorno, faz `container.append($html)` (`:582-586`) e, crucialmente,
  `JetEngine.initElementsHandlers($html)` (`:599`) — **reinicializa os handlers dos
  widgets Elementor nos itens recém-inseridos**.
- Dispara o evento `jet-engine/listing-grid/after-load-more` (`:811`).

> **Implicação:** existe um caminho oficial — renderizar itens pelo `posts_loop`
> no servidor e anexá-los pelo pipeline nativo — que preserva o template do Listing
> como fonte da verdade. A v4.1 **não** usa esse caminho para o append; ela clona no
> cliente (mirror renderer). Ver [doc 04](04-realtime-e-mirror-renderer.md) e
> [doc 06](06-diretrizes-nova-versao.md).

## 2.6 Eventos JS do JetFormBuilder (o gatilho do envio no cliente)

**Evidência:** `core-plugins/jetformbuilder-v3.6.1.1`.

- `jet-form-builder/ajax/on-success` — disparado com `(event, response, form)`
  após submit AJAX bem-sucedido (`modules/user-journey/module.php:594`).
- `jet-form-builder/ajax/on-fail` (`:602`) e
  `jet-form-builder/ajax/processing-error` (`:598`) — falhas.
- Ambos documentados no changelog: `readme.txt:403,430`.

O projeto usa esses três eventos:

- `conversa-v2.1-form-composer.php:653-671` — no `on-success`, **limpa apenas o
  textarea** (replicando a técnica nativa de "Clear Data on Submit"), sem tocar em
  campos ocultos.
- `conversa-v4.1...php:1768-1780` — no `on-success`, dispara `checkStatus()` para
  buscar a mensagem recém-gravada; no `on-fail`/`processing-error`, cancela os
  timers de refresh pós-submit.

> **Requisito de configuração:** para o real-time funcionar sem reload, o Form 56386
> **precisa** estar em modo de submit **AJAX** no JetFormBuilder (caso contrário a
> página recarrega e o evento `on-success` não existe no ciclo). Este é um acoplamento
> de configuração — não de código — que a nova versão deve documentar/garantir.

---

## 2.7 Mapa-resumo de integração

| De → Para | Tipo de evidência | Onde |
|-----------|-------------------|------|
| Elementor → JFB | Widget `jet-engine-booking-form` (`form_id=56386`) | `layout-...json` (id 4fb02bde) |
| JFB → JetEngine CCT | Action `insert_custom_content_type` (`extends JFB Base`) | `custom-content-types/inc/forms/action.php:10,16,161` |
| CCT insert → hooks | `created-item/{slug}`, `updated-item/{slug}` | `custom-content-types/inc/item-handler.php:448,453,462` |
| CCT → tabela | `wpdb->insert` em `wp_jet_cct_mensagens_` | `custom-content-types/inc/db.php:29,212,280` |
| Elementor → Listing | `jet-listing-grid` (`listing_id=56326`) | `layout-...json` (id 1fac565c) |
| Listing → card | `posts_loop` + `get_listing_item` | `render/listing-grid.php:1677,1757` |
| Append nativo | `jet_engine_ajax` / `listing_load_more` | `listings/ajax-handlers.php:28,328,459` |
| Append nativo (front) | `ajaxGetListing` + `initElementsHandlers` | `assets/js/frontend/src/main.js:790,599` |
| Envio no cliente | eventos `jet-form-builder/ajax/*` | `jetformbuilder/modules/user-journey/module.php:594` |
