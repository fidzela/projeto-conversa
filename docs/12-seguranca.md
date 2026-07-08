# 12 — Segurança: modelo de ameaças e os hardenings da 1.2.0

> **Contexto.** A 1.2.0 nasce de uma auditoria de segurança completa do plugin
> e do fluxo nativo de envio (JetFormBuilder → Action "Insert CCT" do
> JetEngine). Este documento registra **o que foi encontrado**, **o que o
> plugin passou a garantir** e **o que continua sendo responsabilidade da
> autoria** (configuração dos plugins nativos). Complementa o docs/11 (o
> roteiro do JetFormBuilder), que já mapeou validação, nonce, captcha etc.

---

## 12.1 O achado central: o INSERT nativo do CCT não autoriza nada

A Action nativa **Insert/Update Custom Content Type Item**
(`jet-engine/.../custom-content-types/inc/forms/action.php → do_action()`)
só valida autoria no **UPDATE** (quando `_ID` vem no request). No **INSERT**
ela grava o que o `fields_map` mapear do POST. Consequência para o chat, sem
guard:

| Ataque | Como | Efeito |
|---|---|---|
| **Cross-conversation** | adulterar `conversa_id` no POST | mensagem injetada em QUALQUER conversa |
| **Spoof de autor** | adulterar `from_user` no POST | mensagem assinada como QUALQUER usuário (o card renderiza o lado errado) |
| **IDOR de mídia** | adulterar `message_image` com um attachment ID alheio | exibe qualquer mídia da biblioteca dentro do chat |
| **Flood** | repetir o POST | conversa inundada (sem limite nativo por usuário) |
| **CSRF** | form auto-submetido em site terceiro | mensagem enviada com a sessão da vítima (o WP Nonce do JFB é opt-in e vinha desligado) |

O envio é o "segundo coração" do projeto — e era o ponto sem defesa
server-side. Os endpoints de LEITURA do plugin (status/after/before) já
validavam nonce + login + participante + rate limit desde a 1.0.

## 12.2 A resposta: `Conversa_Chat_Guard` (só hooks nativos)

Novo módulo `includes/class-conversa-chat-guard.php`. Fiel à regra de ouro,
ele não substitui nada do JFB/JetEngine — se pluga em dois hooks nativos:

1. **`jet-form-builder/form-handler/before-send`** (form-handler.php:311) —
   marca o request atual como "submissão de form JFB". Fora desse caminho
   (admin do JetEngine, REST, código), o guard **não interfere**: cada um
   desses caminhos tem suas regras nativas de capability.
2. **`jet-engine/custom-content-types/item-to-update`** (item-handler.php:405)
   — o ponto de estrangulamento: roda ANTES do INSERT e do UPDATE, para todo
   caminho de escrita do CCT, com o item final montado.

Para submissões JFB que escrevem no CCT de mensagens, o guard garante:

| # | Garantia | Mecanismo |
|---|---|---|
| 1 | Login obrigatório | rejeição |
| 2 | `conversa_id` = post **publish** do CPT `conversa` | rejeição |
| 3 | Usuário logado é **participante** | mesma regra (e mesmo filtro `conversa-chat/is-participant`) dos endpoints de leitura |
| 4 | `from_user` = usuário logado, **sempre** | anti-spoof por SOBRESCRITA (o valor do POST é irrelevante) |
| 5 | `cct_author_id` = usuário logado | coerência com o autor nativo do CCT |
| 6 | Anexo numérico = attachment de **imagem** do **próprio remetente** | anti-IDOR; formatos "Media ID" e "Both" cobertos |
| 7 | Anti-flood de envio | `rate_send` (padrão 20/min) por usuário+conversa; tentativas rejeitadas também contam |
| 8 | Referer cross-origin explícito rejeitado | anti-CSRF secundário; referer AUSENTE é tolerado (proxies de privacidade) |

A rejeição usa o mecanismo **nativo** do JFB
(`Action_Exception->dynamic_error()` — o mesmo da Action do CCT): a mensagem
volta pelo pipeline de status do form, e o composer já exibe status apenas
quando é erro (docs/10).

### Filtros (nada engessa)

| Filtro | Uso |
|---|---|
| `conversa-chat/guard-send` | desligar/escopar o guard para um form específico (ex.: form administrativo legítimo que escreve no CCT com outras regras) |
| `conversa-chat/media-allowed` | flexibilizar a posse da mídia (ex.: biblioteca compartilhada do site) |
| `conversa-chat/check-send-referer` | desligar a checagem de referer |
| `conversa-chat/is-participant` | ampliar quem participa (ex.: moderadores) — já existia; agora vale para LEITURA e ENVIO |

## 12.3 Hardenings nos endpoints de leitura

- **Nonce vinculado à conversa** — o token agora é
  `conversa_chat_{conversa_id}`: um nonce emitido na conversa A não autoriza
  chamadas sobre a B, mesmo para quem participa das duas. Zero mudança no
  cliente (o runtime já enviava `nonce` + `conversa_id` juntos —
  runtime.js:194-195).
- **`widget_settings` com sanitização estrutural** — o payload de paridade
  com o load-more nativo agora é whitelist de FORMA: profundidade ≤ 3, ≤ 60
  entradas/nível, chaves `[a-zA-Z0-9_-]`, valores escalares sem tags com ≤
  500 chars, objetos descartados, cap de 20 KB no JSON bruto. Qualquer
  setting legítimo do grid passa; estruturas arbitrárias, não. O
  `listing_id` continua sendo SEMPRE reimposto pelo servidor.
- **Integridade do `last_message_at`** — `on_message_created` só grava o meta
  se o alvo é mesmo um post do CPT da conversa (um item com `conversa_id`
  apontando para outro post qualquer não toca metas alheias).
- **Config inline sem `</script>` breakout** — o JSON do
  `ConversaChatConfig` sai com `JSON_HEX_TAG` (e barras escapadas): nenhum
  valor, nem vindo de filtro de terceiros, fecha a tag `<script>`.
- **`index.php` de silêncio** em todos os diretórios do plugin.

## 12.4 O que continua do lado da AUTORIA (recomendações)

O plugin cobre o que o código deve cobrir. Estas opções são **configuração
nativa** (ver docs/11 para o passo-a-passo):

| Onde | O quê | Por quê |
|---|---|---|
| JFB → form → Security | **WP Nonce: ligar** | anti-CSRF primário (o referer-check do guard é secundário) |
| JFB → form → Security | Honeypot: ligar | anti-bot de custo zero |
| JFB → form → Validation | **Advanced** | erros inline (inclusive tamanho/tipo do Media Field) — o plugin já domestica o layout |
| JFB → Media Field | Value Format = **Media ID** | é o formato que o guard consegue validar por posse (URL não é verificável) |
| JFB → Media Field | Max size / Allowed types | limite no upload em si (o guard valida a REFERÊNCIA, não o upload) |
| JetEngine → CCT `mensagens_` | REST API de escrita: **manter OFF** | o guard cobre o caminho JFB; escrita via REST teria só as regras do próprio CCT |
| JetEngine → CCT | capability de admin do CCT | quem pode editar mensagens no wp-admin |
| Página da conversa | Page rules do JetEngine (login + acesso) | o guard protege o DADO; o acesso à PÁGINA é upstream, como sempre foi |
| **NÃO usar** | "Limit Form Responses" | limita o TOTAL de respostas do form — mataria a conversa (docs/11 §11.4) |

## 12.5 Verificação

Harness de testes (stubs de WP/JFB/JetEngine sobre as classes REAIS do
plugin) exercitou as regras de ponta a ponta — **29/29 PASS**:

- passthrough fora de submissão JFB e para outros CCTs;
- rejeições: deslogado, não-participante, conversa inválida/rascunho/ausente;
- anti-spoof: `from_user` e `cct_author_id` sobrescritos;
- anti-IDOR: mídia de terceiro, não-imagem, inexistente, formato Both;
- anti-flood: barrado no limite exato (`rate_send`);
- referer: cross-origin rejeitado, same-origin ok, ausente tolerado;
- sanitização do `widget_settings`: 7 propriedades da whitelist de forma;
- integridade do meta `last_message_at`.

`php -l` limpo em todos os arquivos.

## 12.6 Configurações novas

```php
'rate_send' => 20, // envios/min por usuário+conversa (anti-flood; 0 = off)
```

Tudo sobrescrevível por `conversa-chat/settings`, como sempre.
