# 08 — Render incremental, limpeza do campo e performance (1.0.1)

Documenta os achados e correções da **1.0.1**, a partir do teste real da 1.0.0
(boot corrigido → plugin passou a rodar de fato).

---

## 8.1 O bug crítico: primeiro item incremental "pelado"

### Sintoma (relatado no teste)
> O **primeiro** envio após cada reload quebrava o layout do item renderizado;
> do **segundo** em diante montava certo. No reload, o item quebrado voltava ao
> normal. Acontecia para os **dois** usuários.

### Por que era 100% client-side
Cada request AJAX é um processo PHP novo — não existe "primeiro vs resto" no
servidor. Logo, algo era inicializado como **efeito colateral do primeiro
append** e persistia na sessão. O reload "consertava" porque a página inteira
carrega todos os assets.

### Causa-raiz (comprovada na raiz do JetEngine)
O meu endpoint `after` devolvia **apenas o HTML** dos itens. O **load-more
nativo** devolve muito mais: na linha `ajax-handlers.php:469` ele chama

```php
self::maybe_add_enqueue_assets_data( $response );
```

que captura **todo CSS/JS enfileirado durante o `posts_loop`** (via
`wp_styles()->queue` / `wp_scripts()->queue`) e o embute em
`$response['styles']` / `$response['scripts']`. No cliente (`main.js`):

```
enqueueAssetsFromResponse( response )   // main.js:551  → injeta CSS no <head> e agenda scripts
container.append( $html )               // main.js:583  → só então anexa
Promise.all( assetsPromises ).then( () => initElementsHandlers( $html ) )  // main.js:598-601
```

A sequência é: **CSS primeiro, append depois, e o `initElementsHandlers` SÓ
depois que os scripts assíncronos carregam**. No meu fluxo antigo eu anexava e
chamava `initElementsHandlers` na hora — no primeiro item o script do widget
ainda não tinha carregado, então o handler não montava; do segundo em diante o
script já estava presente. É exatamente o "primeiro quebra, resto monta".

### Correção (1.0.1) — nativa-fiel
- **Servidor** (`class-conversa-chat-ajax.php::after`): após `render_items`,
  chama `Jet_Engine_Listings_Ajax_Handlers::maybe_add_enqueue_assets_data(
  $response )` (método `public static` do próprio JetEngine) → o response passa
  a carregar `styles` e `scripts`.
- **Cliente** (`runtime.js`): antes do append, `enqueueAssets(data)` chama
  `JetEngine.enqueueAssetsFromResponse({ data })` (dedup por handle é do
  JetEngine); depois do append, `initHandlersAfterAssets(nodes)` espera
  `Promise.all(JetEngine.assetsPromises)` antes de `initElementsHandlers` —
  a mesma sequência do load-more nativo.

O caminho incremental passou a ser **idêntico ao load-more nativo** no que diz
respeito a assets. É o ponto onde as versões antigas empacavam (sem a raiz do
código, tentavam clonar/estilizar à mão); aqui a fronteira volta a ser o que o
JetEngine já faz.

---

## 8.2 Limpeza do textarea após envio

### Achado
O JetFormBuilder tem "Clear form after submit" **nativo** — atributo `data-clear`
no `<form>` (`form-builder.php:146`), que dispara `form.reset()` no on-success.
Mas **vem desligado** por padrão; sem ativar, o campo não limpa.

### Correção (1.0.1)
Fallback em `composer.js`: no evento `jet-form-builder/ajax/on-success` (mensagem
já gravada), limpa o textarea e dispara `input`/`change` para o modelo reativo
do JFB e o auto-size re-sincronizarem. Roda só no sucesso, então não briga com o
envio. Desligável por `clear_composer_on_success` (settings) / `clear_on_success`
(config do front) — para quem preferir usar só o clear nativo do JFB.

> **Recomendado também:** ligar "Clear form after submit" na UI do form (é o
> caminho nativo e reseta o estado reativo do JFB corretamente). Com os dois
> ligados o resultado é idempotente.

---

## 8.3 Performance do carregamento inicial (últimas N mensagens)

### Diagnóstico
A query do listing (Query 678) traz **todas** as mensagens, **sem LIMIT**:

```sql
SELECT * FROM wp_jet_cct_mensagens_
WHERE conversa_id = %ID% AND cct_status = 'publish'
ORDER BY cct_created ASC;
```

Com 40–60 mensagens já pesa; não escala. O `CCT Query` do Query Builder só
oferece "number" (limita os **primeiros** N pela ordem — com `ASC` isso são os
**mais antigos**, o oposto do que um chat quer).

### Coordenada nativa (JetEngine) — SQL Query "últimas N ascendentes"
Trocar a fonte do Listing 56326 para uma **SQL Query** do Query Builder em
**modo avançado**, com subquery:

```sql
SELECT * FROM (
    SELECT * FROM wp_jet_cct_mensagens_
    WHERE conversa_id = %conversa_id_dinamico% AND cct_status = 'publish'
    ORDER BY cct_created DESC
    LIMIT 30
) AS recent
ORDER BY recent.cct_created ASC;
```

- Traz as **30 mais recentes**, exibidas do mais antigo → mais novo (mesma
  ordem de hoje). **O append em tempo real e o scroll continuam iguais** — nada
  no plugin muda.
- **Atenção ao valor dinâmico:** o `conversa_id = 0` que aparece no preview é só
  porque o Query Builder não tem contexto de post na tela de edição. Em produção
  precisa ser o **macro dinâmico** (o mesmo binding que a Query 678 já usa hoje
  para resolver o `conversa_id`) — dentro do `WHERE` da subquery. Não deixar `0`
  fixo.

### Por que isto NÃO quebra o tempo real (o ponto que derrubava as versões antigas)
O endpoint `after` lê o CCT **direto pela API do factory** (`_ID > X`) e o
`render_items` renderiza os itens que eu passo pelo `posts_loop` — **ambos
independentes da fonte de query do Listing**. A fonte da query só afeta o
**render inicial** e o load-more. Trocar para SQL Query afeta só o carregamento
inicial; o incremental segue intacto. E o `state.lastId` do runtime vem do
`_ID` máximo real do CCT (via `get_status`), então continua correto mesmo com o
listing mostrando só as 30 últimas.

### "Ver mensagens mais antigas" (rolar pra cima) — próximo incremento
Fica faltando carregar as mensagens além das 30 ao rolar pro topo. É simétrico
ao `after`: um passo futuro (endpoint/gatilho `before` com `_ID < X`, ou o
load-more nativo em modo "prepend"). Não incluído na 1.0.1 para não acoplar sem
teste no site.
