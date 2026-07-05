# 08 — Render incremental, limpeza do campo e performance (1.0.1 → 1.0.4)

Documenta os achados e correções da **1.0.1** (a partir do teste real da 1.0.0,
com o boot corrigido), da **1.0.2** (carregamento inicial "últimas N mensagens",
resolvido no código — seção 8.3), da **1.0.3** (carregar antigas / prepend no
topo — seção 8.4) e da **1.0.4** (o desfecho real do bug do "primeiro item
pelado" — seção 8.1 — e o reforço de consistência do carregar-antigas).

> **O coração do projeto tem doc próprio:** a interface do render incremental com
> o **Listing** (o ponto onde o usuário interage e onde este bug morava) está
> detalhada em [`09-o-coracao-interface-com-o-listing.md`](09-o-coracao-interface-com-o-listing.md).
> Comece por lá se o objetivo é entender/alterar o render das mensagens.

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

### Tentativa (1.0.3) — a hipótese de assets/timing, que NÃO resolveu
Como a 1.0.1 não bastou no cenário real, a 1.0.3 apostou (erradamente) que ainda
era assets/timing e foi por dois caminhos:

1. **Render fiel (servidor).** `render_items` passou a usar os **widget_settings
   reais** do grid e a disparar os `do_action` pré-render do nativo antes do
   `posts_loop` (`ajax-handlers.php:338-357`).
2. **Settle + diagnóstico (cliente).** No primeiro append, `initHandlersAfterAssets`
   esperava 2 frames antes de religar, e um log de debug reportava os
   `scripts`/`styles` do response.

**Resultado do teste real: comportamento idêntico.** O primeiro item continuou
"pelado" e os seguintes certos — exatamente como antes. Ou seja, **assets/timing
nunca foram a causa**. O item **1** (render fiel) foi mantido por ser paridade
legítima com o load-more nativo (bom para qualquer layout de card — não engessar),
mas com o comentário corrigido: **não é** a correção do bug. O item **2** (settle
de frames + log de assets) era **lixo especulativo** e foi **removido na 1.0.4**.

### Causa REAL e desfecho (1.0.4) — era autoração do card, não código
O autor isolou o bug por eliminação, alterando o **layout do card no Elementor**:

> Removi o **Listing** de dentro do card e, no lugar, inseri uma **imagem** com o
> contexto **"CCT Item Author"**. O layout passou a funcionar. O bug ocorria em
> **1 único elemento** do card — justamente um **Listing ANINHADO** (a imagem
> vinha de dentro dele). O problema estava em **como esse Listing interno se
> comporta na re-renderização** após o envio (o primeiro append da sessão).

Ou seja: o card de mensagem continha um **Listing dentro do Listing**. Um Listing
aninhado tem seu próprio ciclo de inicialização (query + assets + hidratação) que
**não sobrevive bem ao primeiro `initElementsHandlers`** sobre o nó recém
anexado — por isso "o primeiro quebra, o resto monta" e o reload conserta. Trocar
o elemento por um widget de **imagem com contexto CCT Item Author** (que resolve o
autor no próprio render, sem um sub-Listing) **elimina** a causa.

**Duas conclusões importantes:**
- O bug **nunca foi do plugin** — foi de **autoração** do template do card. Nenhum
  código de assets/timing poderia corrigi-lo; só o layout.
- **Alterar o layout do card NÃO quebrou o real-time** — o card novo apareceu
  correto nas mensagens incrementais sem tocar em uma linha do plugin. É a prova
  viva do princípio de **não engessar**: o render incremental usa o template real,
  então o layout é livre. (Nas versões antigas, com o "mirror renderer", uma
  mudança dessas quebraria tudo.)

> **Diretriz de autoração (registrada):** evite **Listing dentro do card do
> Listing** de mensagens. Para dados do autor/relacionados, prefira **dynamic
> tags** / contexto **CCT Item Author** / campos dinâmicos, que resolvem no render
> do próprio card e sobrevivem ao append incremental. Ver [`docs/09`](09-o-coracao-interface-com-o-listing.md).

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

---

## 8.4 Carregar mensagens ANTIGAS — rolar pra cima (1.0.3)

### Por que o load-more nativo NÃO serve aqui
O load-more nativo do JetEngine **anexa a próxima página no fim** do grid (feed
que cresce pra baixo). Num chat, "carregar mais" = mensagens **antigas**, que
precisam entrar **no topo**, com a rolagem ancorada (a mensagem que você lia não
pode pular). Além disso, ele pagina pelo mesmo `number` da página (ex.: 6), então
não dá pra ter "início 6, +3 por vez". Em teste real, o load-more nativo
inseriu o lote **embaixo**, como se fossem mensagens novas — o oposto do
esperado. Logo, "carregar antigas" é uma operação **própria** do chat: é onde o
nativo não alcança, e o código começa aí (Regra de Ouro).

### O fluxo `before` — simétrico ao `after`
**Servidor** (`Conversa_Chat_Data::get_before` + endpoint `conversa_chat_before`):

```
_ID < before_id   ORDER BY _ID DESC   LIMIT N+1     →  as N mais recentes ABAIXO do topo
array_slice(0, N) + has_more = (retornou N+1)         →  o +1 sonda "há mais antigas?" sem count
array_reverse                                         →  lote em ordem cronológica (ASC) p/ prepend
```

Render pelo **mesmo** `posts_loop` (template real). O `_ID < X` lê o CCT direto
pela Factory — **fora** do Query Builder, então os hooks de limite/ordem da 8.3
não o afetam (o lote antigo não é re-limitado nem reinvertido).

**Cliente** (`runtime.js`):
- `prependItemsHtml` insere o lote **antes** do primeiro item atual (dedup por
  `data-post-id`), preservando a ordem cronológica.
- `ConversaChatLayout.anchorForPrepend(mutate)` registra a altura, executa o
  prepend e reposiciona o `scrollTop` pela diferença — **a viewport fica parada**
  na mesma mensagem (comportamento WhatsApp/Messenger). Reancoragem também no
  próximo frame, caso um asset do card mude a altura logo depois.
- Gatilho: **rolar ao topo** (`scroll` ≤ 60px) e/ou **botão** "Ver mensagens
  anteriores" (`older_trigger`). O load-more nativo é neutralizado
  (`neutralizeNativeLoadMore`); recomenda-se **desligar** "Load more" no widget
  do Listing para não haver dois mecanismos.
- Fim do histórico: `has_more` do servidor desliga o gatilho; no boot,
  `has_older` (count total > `initial_limit`) evita a primeira carga inútil
  quando não há nada mais antigo.

### O que NÃO muda
- `state.lastId` (tempo real) continua vindo do `_ID` máximo do CCT via
  `get_status` — independente do que o Listing exibe.
- O `after` (mensagem nova) segue anexando no fim + scroll pro fim.
- Nenhuma dependência da fonte da query do Listing: `before` é uma leitura
  direta do CCT, como o `after`.

### Reforço de consistência (1.0.4)
Com o carregar-antigas **funcionando** (ordem correta, itens no lugar certo), a
1.0.4 blindou os pontos onde ele poderia se desalinhar — sem engessar, só
fechando arestas:

- **Scroll só dispara "para cima".** O listener guarda o `scrollTop` anterior e
  só chama `fetchBefore` quando o gesto é **ascendente** e perto do topo. Como a
  reancoragem do prepend empurra o `scrollTop` **para baixo**, um prepend nunca se
  auto-dispara como se fosse rolagem do usuário (evita loop de carga).
- **Cooldown entre cargas por scroll** (`OLDER_SCROLL_COOLDOWN_MS`, 500ms). A
  requisição em voo já é barrada por `beforeInFlight`; o cooldown evita que o
  **mesmo gesto** contínuo enfileire a carga seguinte no instante em que a
  anterior termina. O botão e cargas espaçadas seguem livres.
- **Frontier à prova de duplicata.** O `oldestId` (topo do histórico) só anda para
  **baixo** (`Math.min`). Se um lote vier **todo deduplicado** (nada novo entrou),
  o frontier é recuado para **abaixo do `before_id` pedido**, garantindo que a
  próxima carga **progrida** em vez de reconsultar a mesma janela.
- **Esgotamento robusto.** `olderExhausted` liga por `has_more` do servidor **ou**
  quando o topo alcança o começo absoluto (`_ID <= 1`).
- **Reset após full refresh.** Quando o grid é **trocado inteiro**
  (`fullRefresh`, ex.: mudança retroativa), `resetOlderState()` recalcula o
  `oldestId` a partir do novo DOM e reavalia o esgotamento — o carregar-antigas
  não fica com um frontier obsoleto do grid anterior. Também re-neutraliza o
  load-more nativo do grid recém-inserido.

Esses mecanismos vivem **só no cliente** (`runtime.js`): o servidor (`get_before`)
continua uma leitura pura e sem estado do CCT. Detalhe do ciclo em
[`docs/09`](09-o-coracao-interface-com-o-listing.md).

### Configuração (settings)
`load_older` (on/off), `older_batch` (N por carga — TESTE: 3, produção ~15),
`older_trigger` (`scroll` | `button` | `both`), `rate_before` (rate limit).
API JS: `ConversaChatRuntime.loadOlder()`.
