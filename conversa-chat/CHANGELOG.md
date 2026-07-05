# Changelog — Conversa Chat

Formato: cada versão finalizada gera `dist/conversa-chat-{versao}.zip`.

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
