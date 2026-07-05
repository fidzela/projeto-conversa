# Conversa Chat (plugin)

Chat em tempo real sobre **JetEngine + JetFormBuilder + Elementor**.

> **Regra de ouro do projeto:** reaproveitar e integrar o que os plugins já
> proporcionam nativamente. O código deste plugin começa e termina onde as
> funcionalidades nativas não chegam.

## O que o plugin faz (e só isso)

| Responsabilidade | Como |
|---|---|
| Detectar mensagem nova sem reload | Endpoint `status` barato + polling adaptativo |
| Buscar "mensagens após o _ID X" | API nativa do CCT (`factory->db->query` com operador `>`) |
| Renderizar as mensagens novas | **Pipeline nativo do Listing** (`posts_loop`) — o template do Elementor é a única fonte visual |
| Anexar no grid e religar widgets | API JS nativa `JetEngine.initElementsHandlers` |
| Refresh completo (fallback) | Endpoint **nativo** `jet_engine_ajax`/`get_listing` do JetEngine |
| Layout de chat + scroll | CSS nas seções ancoradas por ID + scroll por contexto explícito |
| UX do composer | Auto-size do textarea (o form continua 100% JetFormBuilder) |
| `last_message_at` | Hook nativo `jet-engine/custom-content-types/created-item/{slug}` |

O que o plugin **não** faz: layout de card (é do Listing), decisão
artista/convidado (é do Dynamic Visibility, na UI), envio/validação/limpeza
do form (é do JetFormBuilder), armazenamento (é do CCT).

## Instalação

1. Copie a pasta `conversa-chat/` para `wp-content/plugins/`.
2. Ative o plugin **Conversa Chat**.
3. Desative os 6 snippets do WPCode da versão anterior (o plugin os substitui
   por completo — manter os dois ativos duplicaria polling e estilos).

## Checklist de configuração (tudo na UI dos plugins)

### Elementor — template single da conversa
- As quatro seções precisam dos IDs (campo **CSS ID**):
  `parent-section-conversa`, `header-conversa`, `section-msgs-conversa`,
  `footer-conversa`. *(Já configurado no template atual.)*

### JetEngine — Listing das mensagens (56326)
- Fonte: CCT `mensagens_`, filtrado pela conversa atual. *(Já configurado.)*
- Card artista/convidado via **Dynamic Visibility** (condição *Equal*:
  macro `current_field: from_user` × dynamic tag meta `is_artista` /
  `is_convidado`). *(Já configurado no listing exportado.)*
- Novos campos no card (ex.: imagem): basta adicionar o widget no template
  do listing — aparecem automaticamente nas mensagens em tempo real, porque
  o incremental renderiza pelo mesmo template.

### JetFormBuilder — form do composer (56386)
- Action **Insert/Update Custom Content Type Item** no CCT `mensagens_`. *(Já configurado.)*
- **Submit Type: AJAX** — obrigatório para o tempo real (sem reload).
- **Clear data on submit: ativado** — a limpeza do campo após enviar é o
  recurso nativo do JFB (o plugin não limpa campo por código).

## Configuração via código (opcional)

Tudo é sobrescrevível pelo filtro `conversa-chat/settings` — IDs de listing e
form, nomes de campos/metas, seletores, intervalos de polling, liga/desliga:

```php
// Ex.: desligar o real-time globalmente (chat vira "aparece no reload"):
add_filter( 'conversa-chat/settings', function ( $s ) {
    $s['realtime'] = false;
    return $s;
} );

// Ex.: desligar por conversa:
add_filter( 'conversa-chat/realtime-enabled', function ( $on, $conversa_id ) {
    return $conversa_id === 670 ? false : $on;
}, 10, 2 );
```

Hooks expostos para extensões:

| Hook | Quando |
|---|---|
| `conversa-chat/settings` (filtro) | Resolução da configuração |
| `conversa-chat/realtime-enabled` (filtro) | Por conversa |
| `conversa-chat/is-participant` (filtro) | Regra de acesso dos endpoints |
| `conversa-chat/front-config` (filtro) | Config entregue ao JS |
| `conversa-chat/rendered-items` (filtro) | HTML incremental antes de enviar |
| `conversa-chat/message-created` (action) | Mensagem gravada no CCT (base p/ push, notificações) |

Eventos JS: `conversa-chat:messages-appended`, `conversa-chat:messages-replaced`,
`conversa-chat:tabstate`. APIs: `ConversaChatLayout`, `ConversaChatRuntime`,
`ConversaChatComposer`.

## Testes de "não engessar" (critérios de aceitação)

1. **Editar o card no Elementor** → reflete nas mensagens em tempo real, sem
   tocar em código. ✔ (o incremental renderiza pelo template real)
2. **Adicionar metafield novo no CCT + widget no card** → aparece no reload e
   no tempo real. ✔ (mesmo motivo)
3. **Trocar o listing** → `listing_id` no filtro de settings. ✔
4. **Ligar/desligar o real-time** → `realtime` / `conversa-chat/realtime-enabled`. ✔
5. **Portabilidade** → plugin versionado + este checklist de UI. ✔
