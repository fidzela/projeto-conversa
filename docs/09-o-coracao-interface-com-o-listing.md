# 09 — O CORAÇÃO do projeto: a interface com os mecanismos onde o usuário interage

> Este documento descreve **o ponto mais sensível do projeto**: a fronteira entre
> o código do `conversa-chat` e os mecanismos nativos com os quais o usuário
> **interage de fato** — o **Listing Grid** (onde as mensagens aparecem), o
> **composer JetFormBuilder** (onde ele digita e envia) e o **scroll** da área de
> mensagens. É aqui que moram todos os bugs históricos do projeto e é aqui que o
> princípio de **não engessar** é testado a cada versão.
>
> Se você vai **alterar o render das mensagens, o layout do card, ou o fluxo de
> mensagem nova/antiga**, leia este documento **antes** de tocar no código.

Versões cobertas: 1.0.0 → 1.0.4. Toda referência `arquivo:linha` aponta para o
repositório `core-plugins` (JetEngine 3.8.10.1, JetFormBuilder 3.6.1.1,
Elementor 4.1.4 / Pro 4.1.2), a fonte da verdade sobre o comportamento nativo.

---

## 9.1 O que é "o coração", em uma frase

> **O coração é o ciclo que pega uma mensagem (nova ou antiga), a renderiza pelo
> TEMPLATE REAL do Listing no servidor, injeta os assets do card, encaixa o HTML
> no grid nativo e religa os widgets — sem nunca construir HTML de card à mão.**

Tudo o que o usuário vê de mensagem passa por esse ciclo. Ele existe porque o
JetEngine renderiza o grid **inteiro** (primeiro paint) e o **load-more** (páginas
seguintes, sempre no fim), mas **não** tem um caminho nativo para "aparecer a
mensagem nova assim que enviada" nem para "carregar as antigas no topo". O código
do plugin começa e termina **exatamente** nessa lacuna (Regra de Ouro).

O erro de todas as versões antigas (o "mirror renderer") foi **clonar um card e
trocar campos no cliente** — o que engessava o layout (qualquer mudança no card
quebrava o clone). A regra inviolável desta arquitetura é o oposto:

> **O HTML do card é SEMPRE produzido pelo `posts_loop` do Listing, no servidor.
> O cliente só posiciona o nó e religa os widgets. Nunca monta card.**

---

## 9.2 Os três mecanismos e onde o código se pluga

| Mecanismo (nativo) | O que o usuário faz | Onde o código encosta | Arquivo |
|---|---|---|---|
| **Listing Grid 56326** | lê as mensagens | render incremental (`posts_loop`) + append/prepend + religar | `renderer.php`, `runtime.js` |
| **Composer (Form 56386)** | digita e envia | detecta o submit/sucesso e dispara a sincronização; auto-size do textarea | `runtime.js`, `composer.js` |
| **Scroll (#section-msgs-conversa)** | rola a conversa | sticky-to-bottom por contexto explícito + âncora no prepend | `layout.js` |

Nenhum desses IDs/seletores é hard-coded no JS: todos chegam via
`ConversaChatConfig` (montado em `class-conversa-chat-assets.php::front_config`).
Trocar o Listing, o form ou as seções = **configuração** (`conversa-chat/settings`
e `conversa-chat/front-config`), não código.

### Os atributos NATIVOS que o código usa como contrato

O código **não inventa** marcação; ele lê o que o JetEngine já imprime:

- **`data-post-id`** em cada `.jet-listing-grid__item` (`render/listing-grid.php:1694`).
  É a **identidade** da mensagem (o `_ID` do CCT). Base de **toda** deduplicação e
  do cálculo dos frontiers (maior/menor `_ID` no DOM).
- **`data-listing-id`** no wrapper `.jet-listing-grid__items`
  (`render/listing-grid.php:1518-1529`). Localiza o **container** dos itens.
- **`data-nav`** no mesmo wrapper: a **query assinada** (HMAC) + os
  **`widget_settings`** reais do grid. Só é impresso quando load-more está ligado
  **ou** quando o filtro `jet-engine/listing/grid/add-query-data` devolve `true` —
  por isso o `Integrations::force_nav_query_data` o força para o listing do chat.
  É a fonte para o `fullRefresh` nativo e para o render fiel.

Esses três atributos são o **contrato** entre o grid e o runtime. Se um dia o
JetEngine mudar esses nomes, é aqui (e só aqui) que o código quebra — está tudo
centralizado e comentado.

---

## 9.3 O ciclo de uma MENSAGEM NOVA (o caminho quente)

Este é o fluxo que o usuário exercita a cada envio. Cada seta é um ponto onde algo
pode desalinhar; por isso cada etapa tem uma invariante explícita.

```
[usuário envia no composer]
   │  JetFormBuilder faz o submit AJAX e insere no CCT (Action nativa)
   ▼
[CCT: item criado]  ── hook nativo created-item/{slug} ──► atualiza last_message_at
   │
   ▼
[runtime: polling status]  conversa_chat_status → { last_id, count, hash }
   │  o hash (last_id|count) mudou?
   ▼  sim, e last_id subiu
[runtime: fetchAfter(lastId)]  conversa_chat_after → { html, appended, status, styles, scripts }
   │      (html = .jet-listing-grid__item renderizados pelo posts_loop REAL)
   ▼
[1] enqueueAssets(data)      injeta CSS no <head>, agenda scripts (assetsPromises)
[2] appendItemsHtml(html)    insere no FIM do container, dedup por data-post-id
[3] initHandlersAfterAssets  espera Promise.all(assetsPromises) → initElementsHandlers
[4] scrollOnNewMessage       rola pro fim SE o usuário estiver "colado embaixo"
```

### Invariantes do caminho quente (o que NÃO pode ser violado)

1. **Detecção é barata.** O polling bate só no `status` (2 consultas mínimas com
   cache curto). O `after` (render, caro) só roda quando o **hash muda**. Nunca
   inverta essa ordem.
2. **Idempotência por `data-post-id`.** `appendItemsHtml` ignora qualquer item
   cujo `_ID` já esteja no DOM. Duas abas, um burst duplicado, um `after` que
   cruza com um `fullRefresh` — nada gera item repetido. **A dedup é o que segura
   a consistência.**
3. **Assets antes de religar.** `initElementsHandlers` só roda depois de
   `Promise.all(JetEngine.assetsPromises)` — a mesma sequência do load-more nativo
   (`main.js:598-601`). Sem isso, um widget cujo JS ainda não chegou não inicia.
4. **Scroll respeita a leitura.** `scrollOnNewMessage` só desce se
   `state.stickToBottom` for `true`. Se o usuário rolou para cima para ler, a
   mensagem nova entra embaixo **sem** arrastar a viewport.
5. **`state.lastId` é a verdade do servidor**, não do DOM: vem do `_ID` máximo do
   CCT (`get_status`), então continua correto mesmo que o Listing mostre só as N
   últimas (ver 8.3).
6. **Uma aba escreve.** O tab-lock (localStorage) elege **uma** aba primária por
   usuário+conversa; só ela faz polling e envia. As demais entram em modo leitura.
   Isso evita duas abas disparando `after`/burst ao mesmo tempo.

### Por que `render_items` reproduz o load-more nativo (e não "só renderiza")

`render_items` (`renderer.php`) faz, na ordem, **o mesmo** que o handler nativo
`listing_load_more` (`ajax-handlers.php:338-459`):

- `set_listing_by_id` + `get_render_instance('listing-grid', $settings)`;
- usa os **`widget_settings` reais** do grid (enviados pelo cliente via `data-nav`)
  — assim o card sai idêntico ao que o load-more produziria, para **qualquer**
  layout que o autor montar (não engessar);
- dispara `do_action('jet-engine/elementor-views/ajax/load-more', $widget)` e
  `do_action('jet-engine/listings/ajax/load-more')` **antes** do render, dando a
  Elementor/extensões a chance de registrar os assets do card;
- `start_excerpt_flag`, `posts_loop($items, ...)`, e restaura o `$post` global.

E o `conversa_id` vira o **`$post` global** durante o render: é isso que faz a
**Dynamic Visibility** do card (artista × convidado) decidir o lado certo **no
servidor**, sem nenhum resolver em JS/CSS.

> **Segurança de contexto:** o `listing_id` é **sempre** reimposto pelo servidor
> (`renderer.php`). O cliente envia `widget_settings` por fidelidade visual, mas
> **nunca** escolhe *qual* listing é renderizado — não há como um cliente pedir o
> render de outro Listing.

---

## 9.4 O ciclo de uma MENSAGEM ANTIGA (carregar histórico / rolar pra cima)

Simétrico ao caminho quente, mas o nó entra **no topo** e a viewport fica parada.

```
[usuário rola ao topo  OU  clica "Ver mensagens anteriores"]
   │  (scroll ascendente ≤ 60px, com cooldown)  |  (botão)
   ▼
[runtime: fetchBefore]  conversa_chat_before(before_id = oldestId) → { html, has_more, oldest_id }
   │      servidor: _ID < before_id  DESC  LIMIT N+1  → array_reverse (ASC)
   ▼
[1] enqueueAssets(data)
[2] anchorForPrepend( prependItemsHtml )   insere ANTES do 1º item, dedup; âncora de scroll
[3] avança o frontier oldestId (só p/ baixo) e olderExhausted (has_more)
[4] initHandlersAfterAssets                religa os widgets do lote
```

### Invariantes do histórico

1. **Prepend ancorado.** `anchorForPrepend` (`layout.js`) mede a altura, executa o
   prepend e corrige o `scrollTop` pela diferença — **a mensagem que o usuário lia
   não se move** (WhatsApp/Messenger). Reancoragem também no frame seguinte, caso
   um asset do card mude a altura.
2. **`oldestId` só anda para baixo** (`Math.min`). É o "topo" do histórico já
   carregado e o `before_id` da próxima carga.
3. **Progresso garantido.** Se um lote vier **todo deduplicado**, o frontier recua
   para abaixo do `before_id` pedido — a próxima carga **avança**, nunca reconsulta
   a mesma janela (sem loop).
4. **Fim do histórico.** `olderExhausted` liga por `has_more == false` **ou** topo
   no começo absoluto (`_ID <= 1`). O `has_older` do boot (count total >
   `initial_limit`) evita a primeira carga inútil.
5. **Scroll só "para cima" + cooldown.** O prepend empurra o `scrollTop` para
   baixo; o listener só dispara em gesto **ascendente**, com janela mínima entre
   cargas — um prepend jamais se auto-dispara.
6. **Leitura direta do CCT.** `get_before` lê o CCT pela Factory (`_ID < X`),
   **fora** do Query Builder — os hooks de limite/ordem do primeiro paint (8.3)
   **não** afetam o lote antigo.

### Por que o load-more NATIVO não serve (e é neutralizado)

O load-more nativo anexa a próxima página **no fim** (feed que cresce pra baixo).
Num chat "carregar mais" = **antigas, no topo**. Em teste real o nativo inseriu o
lote **embaixo**, como se fossem novas. Por isso `neutralizeNativeLoadMore` esconde
o loader nativo e **recomenda-se desligar "Load more" no widget** do Listing — para
não haver dois mecanismos concorrentes sobre o mesmo container.

---

## 9.5 O REFRESH COMPLETO (rede de segurança, 100% nativo)

Quando o `status` indica mudança **retroativa** (uma mensagem removida/despublicada,
contagem inconsistente) ou o grid está vazio, o runtime não tenta remendar
incrementalmente: chama o endpoint **NATIVO** `jet_engine_ajax` / `get_listing`
(`ajax-handlers.php:479`) com a query assinada do `data-nav`, e **substitui o grid
inteiro** (`gridRoot.replaceWith`). Depois: religa os assets e **`resetOlderState`**
(recalcula `oldestId`/esgotamento do novo DOM). Não existe endpoint "full" próprio —
é o mecanismo nativo, chamado com os dados que o próprio grid publicou.

---

## 9.6 ESTUDO DE CASO: o bug do "primeiro item pelado" (1.0.0 → 1.0.4)

O bug mais teimoso do projeto viveu **exatamente** nesta interface. Vale como
manual de diagnóstico.

### Sintoma
O **primeiro** item incremental de cada reload renderizava quebrado; do **segundo**
em diante, certo. O reload consertava. Acontecia para os dois usuários.

### As hipóteses que NÃO eram a causa (e o que aprendemos)
- **1.0.1 — assets ausentes no response.** Real e legítimo (o `after` passou a
  devolver `styles`/`scripts` como o load-more), mas **não** era a causa deste bug.
- **1.0.3 — timing/settle + render fiel.** Apostou de novo em assets/timing (settle
  de 2 frames, log de debug). **Comportamento idêntico** no teste. Confirmou por
  eliminação que assets/timing **não** eram o problema. O "settle" e o log eram
  **lixo especulativo** e foram **removidos na 1.0.4**; o render fiel ficou, mas
  reetiquetado como **paridade** (bom para qualquer card), não como correção.

### A causa REAL (isolada pelo autor, alterando o layout)
O card de mensagem tinha um **Listing ANINHADO dentro do card do Listing** — dele
saía a **imagem** do autor. Um Listing interno tem **seu próprio** ciclo (query +
assets + hidratação) que **não sobrevive** ao primeiro `initElementsHandlers` sobre
o nó recém-anexado — daí "o primeiro quebra, o resto monta, o reload conserta".

**A correção foi de AUTORAÇÃO, não de código:** o autor removeu o Listing interno e
colocou uma **imagem com contexto "CCT Item Author"**, que resolve o autor no
próprio render do card, sem sub-Listing. O bug desapareceu.

### As duas lições que ficam
1. **Nem todo bug da interface é do plugin.** Este era do **template do card**.
   Nenhum código de assets/timing poderia corrigi-lo. Ao diagnosticar problemas de
   render incremental, **teste também o layout do card por eliminação** (troque o
   elemento suspeito), não só o JS/PHP.
2. **Não engessar funcionou.** Trocar o layout do card **não quebrou** o real-time —
   o card novo apareceu correto nas mensagens incrementais **sem uma linha alterada
   no plugin**, porque o render usa o **template real**. Nas versões antigas (mirror
   renderer) essa mesma troca quebraria tudo. Este episódio é a **prova viva** do
   princípio fundamental.

### Diretriz de autoração do card (regra registrada)
> **NÃO** coloque um **Listing dentro do card** do Listing de mensagens. Para dados
> do autor/relacionados, use **dynamic tags**, **CCT Item Author**, campos
> dinâmicos ou Dynamic Visibility — tudo que resolve **no render do próprio card**
> e sobrevive ao append incremental. Sub-Listings, sub-queries e widgets que fazem
> seu próprio AJAX de hidratação são frágeis no primeiro append.

---

## 9.7 O que você PODE mudar sem quebrar (mapa do "não engessar")

Esta interface foi desenhada para absorver mudança. O que é seguro, e por quê:

| Mudança desejada | Seguro? | Por quê / como |
|---|---|---|
| Redesenhar o card no Elementor | ✅ | O render usa o template real; o incremental acompanha. Só evite **sub-Listing** no card (9.6). |
| Adicionar um metafield (ex.: imagem) ao card | ✅ | Aparece nas mensagens novas sem tocar no plugin. |
| Trocar o Listing por outro layout | ✅ | `listing_id` no filtro `conversa-chat/settings`. |
| Adicionar um segundo form (ex.: enviar imagem) | ✅ | acrescente em `form_ids`. |
| Desligar o real-time | ✅ | `realtime => false`: vira "envia e aparece no reload" (nativo). |
| Ligar/desligar carregar-antigas | ✅ | `load_older`, `older_trigger`, `older_batch`. |
| Mudar os IDs de seção do Elementor | ✅ | `selectors` no filtro. |
| Trocar a CCT Query do Listing | ✅ | `is_messages_query` casa pelo `cct_slug` (ou `messages_query_id`). |
| Pós-processar o HTML incremental | ✅ | filtro `conversa-chat/rendered-items`. |
| Reagir a "mensagem criada" (push, badge…) | ✅ | action `conversa-chat/message-created`. |

Pontos de extensão (todos em código nativo/próprio, sem hardcode):
`conversa-chat/settings`, `conversa-chat/front-config`,
`conversa-chat/realtime-enabled`, `conversa-chat/rendered-items`,
`conversa-chat/message-created`, e os hooks nativos que o plugin apenas **usa**
(`created-item/{slug}`, `add-query-data`, `query/after-query-setup`,
`query/items`).

---

## 9.8 Checklist para alterar o coração com segurança

Antes de commitar qualquer mudança nesta interface:

1. **O HTML do card ainda vem do `posts_loop`?** Se você começou a montar/clonar
   card no cliente, **pare** — é o retorno do mirror renderer.
2. **A dedup por `data-post-id` continua intacta** nos dois caminhos (append e
   prepend)?
3. **`initElementsHandlers` roda depois dos assets** (`Promise.all(assetsPromises)`)?
4. **O `listing_id` é reimposto no servidor** (o cliente não escolhe o listing)?
5. **Os frontiers** (`lastId` sobe, `oldestId` desce) e o **sticky** continuam com
   fonte única da verdade?
6. **O card não tem sub-Listing** (9.6)?
7. **Testou o PRIMEIRO envio pós-reload** especificamente (é onde os bugs de
   interface aparecem)?
8. **Desligável?** A mudança respeita os toggles (`realtime`, `load_older`,
   `initial_limit`) ou introduz um caminho que ignora a configuração?

Se os oito passarem, a mudança está alinhada ao princípio fundamental.
