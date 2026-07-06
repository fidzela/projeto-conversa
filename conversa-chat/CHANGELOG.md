# Changelog — Conversa Chat

Formato: cada versão finalizada gera `dist/conversa-chat-{versao}.zip`.

## 1.1.3

**A causa raiz REAL do preview sumido** (achada pelo diagnóstico no myttooz).

### Corrigido — ancestral do campo de mídia oculto pelo tema (unhideUpTo)
O diagnóstico no site mostrou o quadro definitivo: o `<img>` do preview **existia e
era válido** (`blob:…`, opacity 1), mas o `.__files` tinha **`offsetParent` nulo e
tamanho 0×0** — a assinatura de **`display:none` num ANCESTRAL**. Não era opacity
(1.1.1), nem o `<img>` (1.1.2): o **tema/Elementor escondia um wrapper acima** do
campo de mídia, colapsando tudo. Verifiquei que nem o nosso CSS nem o do JFB
escondem esses wrappers.

Correção determinística: `composer.js::unhideUpTo` caminha do `.__files` até o
`<form>` e **força visível qualquer ancestral que esteja `display:none`** (inline
`!important`, só nos ocultos) — sem depender da classe do wrapper. Com isso o
galho destrava e o preview (miniatura + X) aparece. As defesas anteriores
seguem: `paintPreviews` (thumb via data-file) e `unpositionUpTo` (âncora no form).
Verificado ponta a ponta com o composer.js real sobre um campo com ancestral
`display:none`.

## 1.1.2

Correção real do **preview da mídia** (o fix de opacity da 1.1.1 mirou o alvo
errado) e esclarecimento do erro de validação.

### Corrigido — miniatura do anexo agora aparece (paintPreviews)
Diagnóstico: como o `+` posiciona certo e o espaço reserva certo, o CSS aplica e
o `.__file` entra na régua — logo o problema era o **`<img>` do preview do JFB
não renderizar** nessa montagem (blob/CSP/tema), e não a `opacity` (fix da 1.1.1
mirou o alvo errado). Agora o `composer.js::paintPreviews` **pinta a miniatura
como `background-image` do próprio `.__file`, a partir do `data-file`** que o JFB
já grava ali (`image-preview.php` → `media.field.js`). Assim o thumb aparece
independentemente do `<img>` do JFB, sem recriar uploader/preview/exclusão. Se o
`<img>` do JFB existir, ele fica por cima (object-fit cover), sem conflito.
Verificado ponta a ponta com o composer.js real sobre um `.__file` sem `<img>`.

### Esclarecido — a "mensagem de erro" depende de validação AVANÇADA (config)
O check de tamanho/tipo de arquivo **só é carregado em validação avançada**
(`media-field.php:205`). No modo **Browser** (padrão), o JFB **não gera** o erro
— não há o que o plugin reposicionar. Para o aviso aparecer, mude a validação do
form para **Advanced** (o plugin já o torna layout-safe). Ver docs/11 §11.3.

## 1.1.1

Ajustes do composer de mídia (pós-teste real) + **roteiro do segundo coração**
(análise profunda do JetFormBuilder). Detalhes em
[`docs/11-segundo-coracao-jetformbuilder-fluxo-de-envio.md`](../docs/11-segundo-coracao-jetformbuilder-fluxo-de-envio.md).

### Corrigido — preview 50×50 não aparecia (espaço reservado, miniatura oculta)
Duas causas, ambas de conflito com o JFB (não de config do Value Format):
- **CSS-base do JFB**: `.jet-form-builder-file-upload__file` vem com `opacity:.5`
  + `background-image` (ícone placeholder), e o script que devolveria `opacity:1`
  só entra em validação avançada. Agora forçamos `opacity:1` e sem placeholder.
- **Posição**: o `.__files` (previews) resolvia em absoluto sob um wrapper
  `position:relative` do bloco. Agora `unpositionUpTo` (composer.js) zera a
  position dos ancestrais até o `<form>`, ancorando o preview na moldura certa.

### Corrigido — mídia não limpava após o envio (espaço pendurado)
`clearMedia` dispara, no `on-success`, o **excluir nativo** de cada preview
(`.__file-remove` → `removeFile` do JFB). O campo reseta pelo caminho do JFB e o
`has-previews` desliga → o espaço reservado recolhe.

### Corrigido — validação layout-safe (erro de arquivo grande agora pode aparecer)
O check de tamanho/tipo de arquivo **só carrega em validação avançada**
(`media-field.php:205`). Ao ligar o modo avançado, os erros inline
(`jet-form-builder__field-error`) e os do Media Field (`.__errors`) quebravam a
pílula — agora são **reposicionados como aviso discreto acima do composer**
(mesma pegada da mensagem de status). Ativar validação avançada não quebra o layout.

### Botão de excluir sempre visível
O `.__file-remove` nativo fica escondido (`opacity:0` até hover) e esticado;
revestimos como um **X** pequeno no canto, sempre visível (melhor no touch).

### Documentado — o SEGUNDO CORAÇÃO (roteiro do JFB)
Novo [`docs/11`](../docs/11-segundo-coracao-jetformbuilder-fluxo-de-envio.md): o
pipeline de envio do JFB, o que já "passamos por cima", os **dois modos de
validação** e como ativar sem quebrar, por que **Limit Form Responses NÃO** serve
ao composer, quais camadas de **segurança/captcha** ligar, e um roteiro de
ativação segura. Responde à dúvida de config do Media Field (Value Format não
afeta o preview).

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
