# Changelog — Conversa Chat

Formato: cada versão finalizada gera `dist/conversa-chat-{versao}.zip`.

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
