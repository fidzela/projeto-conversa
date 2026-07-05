# 08 — Render incremental, limpeza do campo e performance (1.0.1 / 1.0.2)

Documenta os achados e correções da **1.0.1** (a partir do teste real da 1.0.0,
com o boot corrigido) e da **1.0.2** (carregamento inicial "últimas N
mensagens", resolvido no código — seção 8.3).

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

## 8.3 Performance do carregamento inicial — últimas N mensagens (1.0.2)

### Diagnóstico
A CCT Query do listing traz **todas** as mensagens, **sem LIMIT**:

```sql
SELECT * FROM wp_jet_cct_mensagens_
WHERE conversa_id = %ID% AND cct_status = 'publish'
ORDER BY cct_created ASC;
```

Com 40–60 mensagens já pesa; não escala. Na UI, o `CCT Query` do Query Builder
só oferece um `Order/Order By` e um `number` — query **linear**. Para um chat, o
que se quer são as **N mais recentes**, exibidas do mais antigo → mais novo:
isso exige "pegar as N do fim e reordenar", que em SQL puro é uma **subquery**.

### Por que NÃO dá para resolver na UI / com SQL Query (comprovado no site)
Duas barreiras, ambas verificadas em produção:

1. **A CCT Query da UI não monta subquery.** Dá para escrever
   `... ORDER BY cct_created DESC LIMIT 30`, mas não o invólucro
   `SELECT * FROM ( ... ) AS recent ORDER BY recent.cct_created ASC`. Sem o
   invólucro, ou você mostra os 30 mais **novos** em ordem invertida, ou os 30
   mais **antigos** — nunca "os 30 novos em ordem cronológica".
2. **O Listing só renderiza com CCT Query.** Ao trocar a fonte do Listing 56326
   para uma **SQL Query** (onde a subquery seria possível), o grid **não exibe
   nenhum item**. É um sintoma reincidente (já aparecia nas primeiras versões).
   Logo, mudar o *tipo* da query não é uma opção: a fonte precisa continuar
   sendo CCT Query.

**Conclusão:** a reordenação "DESC LIMIT N → ASC" tem de viver no **código**,
por cima da CCT Query que já funciona — sem trocar o tipo da query e sem tocar
na configuração dela na UI.

### Solução (1.0.2) — dois hooks nativos da Query
A CCT Query passa por `Base_Query::get_items()`
(`query-builder/queries/base.php`). Dois pontos de extensão nativos resolvem tudo:

**a) Forçar "as N mais recentes" — `after-query-setup` (`base.php:397`)**

```php
add_action( 'jet-engine/query-builder/query/after-query-setup', function ( $query ) {
    if ( ! is_messages_query( $query ) ) return;
    $query->final_query['number'] = 30;
    $query->final_query['order']  = array(
        array( 'orderby' => 'cct_created', 'order' => 'DESC' ),
    );
} );
```

Roda **dentro** do `setup_query` (`base.php:392-397`), então:
- o `final_query` já está montado com o **`conversa_id` resolvido** (macro
  aplicado em `base.php:354`) — nada de `conversa_id = 0`;
- é **antes** de `_get_items()` ler `number`/`order` (`cct query.php:38-90` →
  `db->query( $args, $limit, $offset, $order )`), então o DB devolve as 30 mais
  recentes de forma barata (`DESC LIMIT 30`);
- é **antes** do hash de cache: `get_query_hash()` chama `setup_query()` e só
  então hasheia o `final_query` (`base.php:111,119-122`) — a mutação entra no
  hash, o cache fica consistente (não grava "30 itens" sob a chave da query
  "sem limite").

O formato do `order` é o que o CCT espera: lista de `{ orderby, order }`
(`db.php:707-709` → `base-db.php:914-968`). `cct_created` é a coluna nativa de
criação do CCT.

**b) Reexibir cronologicamente — `query/items` (`base.php:591`; `README:1139`)**

```php
add_filter( 'jet-engine/query-builder/query/items', function ( $items, $query ) {
    if ( ! is_messages_query( $query ) ) return $items;
    return array_reverse( $items );  // DESC (novos primeiro) → ASC (cronológico)
}, 10, 2 );
```

As 30 vieram "mais novas primeiro"; o `array_reverse` devolve a ordem visual do
chat (mais antiga no topo, mais nova embaixo). É exatamente o efeito da subquery
`( ... DESC LIMIT 30 ) ORDER BY ASC`, só que a metade interna (`DESC LIMIT 30`)
é feita no hook **(a)** e a externa (`ORDER BY ASC`) no hook **(b)**.

### Identificação da query (sem hardcode, sem engessar)
`is_messages_query()` casa **CCT Query** (`query_type === 'custom-content-type'`,
`manager.php:25`) cujo `final_query['content_type']` é o `cct_slug`
(`mensagens_`). Assim o mecanismo **não depende do ID do Listing nem da Query**:
trocar o layout do card, o Listing ou a Query — desde que continue uma CCT Query
sobre `mensagens_` — mantém o comportamento. Para cenários com mais de uma
listagem do mesmo CCT, `messages_query_id` (setting) limita ao ID exato.
Tudo desligável por `initial_limit = 0` (volta a mostrar todas).

### Por que isto NÃO quebra o tempo real (o ponto que derrubava as versões antigas)
O endpoint `after` lê o CCT **direto pela Factory** (`data.php:164` →
`$factory->db->query()` com `_ID > X`, ordem `_ID ASC`) — **fora** do
`Base_Query`, então os hooks `query/*` **não o tocam**. O `render_items`
renderiza pelo `posts_loop` os itens que eu passo. A mutação só afeta:
- o **render inicial** do Listing (o que queríamos limitar), e
- o **`fullRefresh`** (endpoint nativo `get_listing`, que passa pela mesma
  Query) — que assim fica **consistente** com o primeiro paint (também as N
  últimas). 

O `state.lastId` do runtime vem do `_ID` máximo real do CCT (via `get_status`,
`data.php:104-116`), independente do que o Listing mostra — continua correto
mesmo exibindo só as 30 últimas.

### "Ver mensagens mais antigas" (rolar pra cima) — próximo incremento
Fica faltando carregar as mensagens além das N ao rolar pro topo. É simétrico ao
`after`: um passo futuro (gatilho `before` com `_ID < X` em modo "prepend", ou o
load-more nativo — a CCT Query com `number = N` já pagina o restante em DESC).
Não incluído na 1.0.2 para não acoplar sem teste no site.
