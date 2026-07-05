# Changelog — Conversa Chat

Formato: cada versão finalizada gera `dist/conversa-chat-{versao}.zip`.

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
