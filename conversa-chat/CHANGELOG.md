# Changelog — Conversa Chat

Formato: cada versão finalizada gera `dist/conversa-chat-{versao}.zip`.

## 1.1.0

Correções de UX do composer + **módulo de mídia** (enviar imagem na mensagem)
+ registro de **layouts** de composer. Detalhes em
[`docs/10-composer-midia-e-layouts.md`](../docs/10-composer-midia-e-layouts.md).

### Corrigido — composer (o que foi pedido primeiro)
- **Auto-grow sem "desce e sobe":** o textarea passava por baixo do botão e
  oscilava até fechar a 1ª linha. Causa: a expansão trocava a reserva lateral do
  botão (mudava a largura útil) e a medição batia em larguras diferentes a cada
  tecla. Agora a expansão é **sticky** (só reverte ao esvaziar) e a altura é
  medida em **duas etapas** (na largura final). O botão fica fixo.
- **Sem o balão nativo de required** ("Preencha este campo"): suprimido via
  `invalid` + `preventDefault` — mantém a obrigatoriedade, tira só o popup feio.
- **Status só em erro:** o JetFormBuilder emite mensagem `--success`/`--error`
  (`form-messages/builder.php:73-74`). O sucesso é redundante no chat (a mensagem
  já aparece pela lista) → escondido; o **erro** aparece como aviso discreto
  acima do composer.

### Adicionado — módulo de mídia (metafield `message_image`)
- A mensagem pode levar **imagem** além do texto. A **exibição** já vinha de
  graça (o card do Listing mostra a coluna; o render incremental usa o template
  real — trocar o layout do card não quebrou o real-time).
- O **envio** usa o **Media Field NATIVO do JetFormBuilder**
  (`templates/fields/media-field.php`). O plugin só **reveste** o campo, no
  estilo da referência: input file vira o botão **`+`** (barra inferior
  esquerda, sem microfone), a lista de previews vira miniaturas **50×50
  `object-fit: cover`** e o `.__file-remove` nativo vira um **X** no canto.
  Nada de uploader/preview/exclusão recriados — tudo é do JFB
  (`image-preview.php` + `media.field.js`).
- Feature-detect: o revestimento liga sozinho quando um form com Media Field
  entra na página (`composer.js::wireMedia`).

### Adicionado — layouts de composer (armazenados; base para o futuro)
- `composer_layouts` (texto / texto+mídia) + `default_layout` (`media`) +
  `composer_forms()` unindo layouts e `form_ids`. Separar/duplicar o composer é
  **configuração**, não código (não engessa). O form atual **não foi alterado**.
- `default_layout => 'media'`. Para o form de mídia virar padrão de fato, basta
  informar o `form_id` do form duplicado (via `conversa-chat/settings`).

### Verificado
- Render do composer (CSS do plugin sobre o **DOM nativo** do Media Field) nos
  quatro estados: texto vazio/expandido e mídia sem/com anexos.

## 1.0.4

Desfecho do bug do "primeiro item pelado", remoção do lixo das tentativas que não
o resolveram, reforço de consistência do carregar-antigas e **documentação vasta
do CORAÇÃO do projeto**. Detalhes em
[`docs/09-o-coracao-interface-com-o-listing.md`](../docs/09-o-coracao-interface-com-o-listing.md)
e [`docs/08`](../docs/08-render-incremental-e-performance.md) §8.1/§8.4.

### Resolvido (autoração, não código) — "primeiro item pelado"
O primeiro item incremental de cada reload renderizava quebrado; do segundo em
diante, certo. **A causa real não era do plugin:** o card de mensagem tinha um
**Listing ANINHADO** (a imagem do autor vinha de dentro dele), e um sub-Listing
não sobrevive à re-renderização/hidratação no primeiro append. O autor resolveu
**no layout**: trocou o Listing interno por uma **imagem com contexto "CCT Item
Author"**. O bug desapareceu — e, prova viva do princípio de não engessar,
**mudar o layout do card não quebrou o real-time** (o render usa o template real).
Diretriz registrada: **não usar Listing dentro do card** de mensagens (usar
dynamic tags / CCT Item Author / campos dinâmicos).

### Removido — lixo das tentativas de mitigação (1.0.1/1.0.3) que não resolveram
As apostas em "assets/timing" não mudaram o comportamento e foram limpas:
- `initHandlersAfterAssets`: removido o *settle* de 2 frames e o estado
  `firstAppendDone` (chute especulativo, sem efeito).
- Removido o `logAssets`/log de diagnóstico de `scripts`/`styles`.
- Comentários corrigidos em `renderer.php`, `ajax.php` e `runtime.js`: o que
  ficou de "render fiel ao nativo" (widget_settings reais + `do_action`
  pré-render + `maybe_add_enqueue_assets_data`) é **paridade legítima** com o
  load-more nativo (bom para qualquer layout de card), **não** a correção daquele
  bug — e está reetiquetado como tal.

### Reforçado — consistência do "carregar antigas" (agora que funciona)
Com o prepend no topo funcionando, blindamos o ciclo (só no cliente; o servidor
segue uma leitura pura e sem estado):
- Scroll só dispara em gesto **ascendente** e perto do topo (o prepend empurra o
  scroll pra baixo e nunca se auto-dispara).
- **Cooldown** entre cargas por scroll (500ms) evita rajada no mesmo gesto.
- **Frontier à prova de duplicata:** `oldestId` só anda pra baixo; lote todo
  deduplicado recua o frontier abaixo do pedido → a próxima carga progride (sem
  loop).
- **Reset após full refresh:** ao trocar o grid inteiro, `resetOlderState()`
  recalcula `oldestId`/esgotamento do novo DOM e re-neutraliza o load-more nativo.

### Documentado — o CORAÇÃO do projeto (doc à parte)
Novo [`docs/09`](../docs/09-o-coracao-interface-com-o-listing.md): a interface do
render incremental com o Listing/composer/scroll — os três mecanismos onde o
usuário interage, os atributos nativos que são contrato (`data-post-id`,
`data-listing-id`, `data-nav`), o ciclo de mensagem nova/antiga/refresh com as
**invariantes** de cada um, o estudo de caso do bug e um **checklist** para
alterar o coração sem quebrar. Comentários no código apontam para ele.

## 1.0.3

Carregar mensagens ANTIGAS (rolar pra cima, estilo WhatsApp) e reforço no
render incremental. Detalhes em
[`docs/08-render-incremental-e-performance.md`](../docs/08-render-incremental-e-performance.md).

### Adicionado — "ver mensagens anteriores" (prepend no topo, âncora de scroll)
O load-more **nativo** do JetEngine anexa a próxima página no **fim** (feed que
cresce pra baixo) — errado para um chat, onde "carregar mais" = mensagens
**antigas**, que entram **no topo**. Novo fluxo, simétrico ao `after`:
- Servidor: endpoint `conversa_chat_before` + `Conversa_Chat_Data::get_before`
  (`_ID < before_id`, `DESC LIMIT N+1`, `array_reverse` p/ ASC; o +1 sonda
  `has_more` sem query de count). Render pelo mesmo `posts_loop`.
- Cliente: `prependItemsHtml` insere no topo com dedup; `anchorForPrepend`
  (layout) mantém a viewport parada na mesma mensagem (sem "pulo"). Gatilho por
  **rolar ao topo** e/ou **botão** ("Ver mensagens anteriores"). O load-more
  nativo é neutralizado para não conflitar (recomendado desligá-lo no widget).
- Configurável: `load_older`, `older_batch` (TESTE: 3; produção ~15),
  `older_trigger` (`scroll` | `button` | `both`). `has_older` calculado no boot
  (count total > `initial_limit`) evita gatilho quando não há mais nada.

### Alterado — `initial_limit` de 30 → 6 (valor de TESTE)
Para os testes atuais. Em produção, voltar para ~30 (setting `initial_limit`).

### Reforçado — render incremental fiel ao load-more nativo (bug do "primeiro item pelado")
O `after`/`before` agora renderizam com os **widget_settings reais** do grid
(enviados pelo cliente a partir do `data-nav`) e disparam os mesmos `do_action`
pré-render do load-more nativo (`jet-engine/listings/ajax/load-more` +
`.../elementor-views/ajax/load-more` com a instância do widget) — paridade total
no enfileiramento de assets do card. No cliente: settle de 2 frames no PRIMEIRO
append da sessão (uma única vez, sem dupla init) e log de debug dos assets do
response (`cfg.debug`) para confirmar `scripts`/`styles`. O `listing_id` é sempre
reimposto pelo servidor (o cliente nunca escolhe qual listing renderiza).

## 1.0.2

Carregamento inicial das "últimas N mensagens" — **100% no código**, mantendo a
**CCT Query** do Listing (a única fonte que renderiza itens; SQL Query não
funciona no Listing). Detalhes em
[`docs/08-render-incremental-e-performance.md`](../docs/08-render-incremental-e-performance.md).

### Corrigido — carregamento inicial pesado (todas as mensagens sem LIMIT)
Antes o Listing trazia todas as mensagens (40–60 já pesavam). A rota via SQL
Query (subquery "DESC LIMIT N reordenada ASC") **não é viável**: a CCT Query da
UI só faz query linear, e o Listing **só renderiza com CCT Query** — ao trocar
para SQL Query nenhum item aparece. Solução movida para o código, em dois hooks
**nativos** da Query do JetEngine, sem tocar na sua CCT Query:
- `jet-engine/query-builder/query/after-query-setup` (`base.php:397`): força
  `number = initial_limit` e `order = {order_field} DESC` — pega as N mais
  recentes de forma barata. Roda dentro do `setup_query`, com o `conversa_id`
  (macro) já resolvido e antes do hash de cache (`base.php:111`).
- `jet-engine/query-builder/query/items` (`base.php:591`; `README:1139`):
  `array_reverse` → exibe do mais antigo → mais novo (mesma ordem visual).

Configurável e desligável por `initial_limit` (0 = mostra todas). Escopo por
`cct_slug` (auto) ou `messages_query_id` (cirúrgico). **Não afeta o real-time:**
o endpoint `after` lê o CCT direto pela Factory (`data.php`), fora do Query
Builder — os hooks não o tocam. O `fullRefresh` (get_listing nativo) passa pela
mesma Query, então também respeita o limite — consistente com o primeiro paint.

### Revisado — recomendação de SQL Query da 1.0.1 (obsoleta)
A "coordenada" de SQL Query documentada na 1.0.1 foi **substituída** por esta
solução de código. Motivo comprovado no site: (a) a CCT Query da UI não monta
subquery; (b) o Listing não renderiza itens com SQL Query.

## 1.0.1

Correções a partir do primeiro teste real da 1.0.0 (com o boot já funcionando).
Detalhes e evidências em [`docs/08-render-incremental-e-performance.md`](../docs/08-render-incremental-e-performance.md).

### Corrigido — primeiro item incremental "pelado" (crítico)
O primeiro envio após cada reload quebrava o layout do item; do segundo em
diante montava certo. Causa: o endpoint `after` devolvia só HTML, **sem os
assets** dos widgets. O load-more nativo resolve com
`maybe_add_enqueue_assets_data` (`ajax-handlers.php:469`) + `enqueueAssetsFromResponse`
+ `Promise.all(assetsPromises)` antes do `initElementsHandlers` (`main.js:551,598`).
- Servidor: `after` agora chama
  `Jet_Engine_Listings_Ajax_Handlers::maybe_add_enqueue_assets_data($response)`
  → devolve `styles`/`scripts`.
- Cliente: injeta os assets antes do append e espera os scripts assíncronos
  carregarem antes de religar os handlers (sequência idêntica ao load-more).

### Corrigido — textarea não limpava após envio
Fallback em `composer.js`: limpa o textarea no `jet-form-builder/ajax/on-success`
e dispara `input`/`change` (sincroniza o modelo reativo do JFB e o auto-size).
Desligável por `clear_composer_on_success`. O clear nativo do JFB ("Clear form
after submit") continua recomendado e é idempotente com este.

### Documentado — performance do carregamento inicial (não-código)
> **Obsoleto na 1.0.2** — ver a seção 1.0.2 acima. A rota de SQL Query abaixo
> **não funciona** neste projeto (o Listing só renderiza com CCT Query) e foi
> substituída por uma solução de código.

O listing carrega todas as mensagens (sem LIMIT). Coordenada nativa: trocar a
fonte do Listing para uma **SQL Query** (modo avançado) "últimas N ascendentes"
(subquery `ORDER BY cct_created DESC LIMIT 30` reordenada `ASC`), mantendo o
macro dinâmico do `conversa_id`. Não quebra o tempo real (o `after` lê o CCT
direto pela API, independente da fonte do listing). Ver doc 08.

## 1.0.0

Primeira versão que **inicializa de fato** no front (a build anterior ativava
mas não fazia nada — ver "Correção crítica").

### Correção crítica — timing do boot
O wiring dos módulos (`Assets`, `Ajax`, `Integrations`) dependia de
`has_dependencies()`, que exige a classe
`\Jet_Engine\Modules\Custom_Content_Types\Module`. Essa checagem rodava no
`plugins_loaded` (prioridade 20), mas o **JetEngine só carrega os módulos —
inclusive o CCT — no hook `init`, prioridade `-999`** (`jet-engine.php:164` →
`init()` → `require modules-manager.php`). No `plugins_loaded` a classe ainda
não existe, então `has_dependencies()` retornava `false` e **nenhum módulo era
registrado**: sem CSS, sem layout, sem composer em pílula, sem real-time — o
form aparecia cru (JetFormBuilder padrão).

**Correção:** o wiring passou a acontecer no hook `init` (prioridade 20, depois
do `-999` do JetEngine), quando o módulo CCT já existe.
Ver `includes/class-conversa-chat.php::init_modules()`.

### O que a versão entrega (validado contra a raiz dos plugins)
- Layout de chat travado no viewport + área de mensagens rolável.
- Composer em pílula (revestimento do form JetFormBuilder, sem tocar no envio).
- Detecção de mensagem nova (endpoint `status` + polling adaptativo).
- Append incremental renderizado pelo **template real do Listing**
  (`posts_loop`), com dedup por `data-post-id` nativo e religação via
  `JetEngine.initElementsHandlers`.
- `last_message_at` no hook nativo correto do CCT
  (`jet-engine/custom-content-types/created-item/{slug}`).
- Refresh completo via endpoint **nativo** `jet_engine_ajax`/`get_listing`.
