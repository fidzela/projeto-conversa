# 11 — O SEGUNDO CORAÇÃO: JetFormBuilder e o fluxo de envio (roteiro)

Se o **primeiro coração** (docs/09) é a interface com o **Listing** — onde as mensagens
são **exibidas** —, o **segundo coração** é o **composer**: a interface com o
**JetFormBuilder**, onde o usuário **interage para enviar**. Este documento é o
roteiro de como o JFB lida com o nosso fluxo, o que já "passamos por cima", e
como ativar as camadas nativas (validação, segurança) **sem quebrar**.

> Evidências em `arquivo:linha` do `core-plugins` (JetFormBuilder 3.6.1.1).
> Princípio: ativar o nativo é **configuração**; o papel do plugin é tornar o
> resultado **layout-safe** e integrar — nunca bloquear a funcionalidade.

---

## 11.1 O pipeline de envio do JetFormBuilder

Ao enviar uma mensagem (submit AJAX do form), o JFB roda, nesta ordem
(`includes/form-handler.php`):

1. `process_ajax_form()` / `process_form()` (`:230` / `:240`).
2. `request_handler->set_form_data()` (`:309`) — coleta e sanitiza os campos.
3. **Validação** dos campos (módulo `validation`) e **restrições** (tamanho/tipo
   de arquivo, quando em modo avançado).
4. **Segurança**: nonce, CSRF, honeypot, captcha (módulos `security` / `captcha`).
5. `do_action( 'jet-form-builder/form-handler/before-send', $this )` (`:311`).
6. **Executa as actions** (`:313`) — entre elas a **Insert/Update CCT** que grava
   a mensagem (e dispara nosso hook `created-item/{slug}` → `on_message_created`).
7. Resposta → eventos JS: `jet-form-builder/ajax/on-success` | `on-fail` |
   `processing-error` (`modules/user-journey/module.php:594-602`).

**Consequência crucial:** o **ENVIO** passa 100% pelo JFB. Os nossos endpoints
(`conversa_chat_status` / `_after` / `_before`) são só **detecção e leitura**
(polling/fetch) — **não** o envio. Portanto:

- Os nossos **rate limits** (`rate_status`/`rate_after`/`rate_before`) protegem o
  polling/fetch, **não** o envio de mensagens.
- **Validação e anti-flood do ENVIO** são responsabilidade do **JFB** (config
  nativa) ou de um **hook no pipeline** (`before-send`), não do nosso código de
  real-time.

O real-time só **reage** ao `on-success` (a mensagem já foi gravada) para buscar
e anexar o novo item. Se a validação/segurança **falha**, nada é gravado e o
`on-fail` **cancela** nossas rajadas de sincronização (`runtime.js:1054`).

---

## 11.2 O que já "passamos por cima" — e a reconciliação

| Funcionalidade nativa | Como o JFB faz | O que o plugin faz | Reconciliação |
|---|---|---|---|
| **Clear form after submit** (`data-clear`, form-builder.php:146) | Limpa todos os campos no sucesso, **se ligado** (vem desligado) | `clearComposer` (texto) + `clearMedia` (mídia, via `.__file-remove` nativo) no `on-success` | Idempotente. Pode ligar o nativo **ou** deixar o nosso; ligar os dois não causa dano. |
| **Required** (validação browser) | Balão nativo "Preencha este campo" | Suprime o **popup** (mantém a obrigatoriedade) via `invalid`+`preventDefault` | É o modo **Browser** de validação (§11.3). |
| **Media Field** | Uploader + previews + excluir nativos | **Reveste** (+/miniaturas/X) — não recria nada | Ver docs/10. |
| **Mensagens de status** | `.jet-form-builder-message--success/--error` | Esconde sucesso, revela erro como aviso discreto | §11.3. |

O `clearMedia` (1.1.1) existe porque o clear (nativo ou o nosso) só cuidava do
**texto** — a miniatura e o **espaço reservado** ficavam pendurados após enviar.
Agora, no sucesso, disparamos o excluir nativo de cada preview → o campo reseta
pelo caminho do JFB e o espaço recolhe.

---

## 11.3 Validação — os dois modos e como ATIVAR sem quebrar

O módulo `validation` tem **dois modos** (`modules/validation/module.php:290-317`,
`get_settings()` → `type`):

- **Browser** (`FORMAT_BROWSER`, **o padrão de hoje**): usa a *constraint
  validation* nativa do navegador (os balões). **Importante:** o script de
  **restrições de mídia** (tamanho/tipo de arquivo) **só é enfileirado em modo
  avançado** (`includes/blocks/types/media-field.php:205` →
  `if ( $module->is_advanced(...) ) wp_enqueue_script( RESTRICTIONS )`).
  **É por isso que enviar uma imagem maior que o permitido não mostra erro
  hoje:** o check de tamanho nem é carregado.
- **Advanced** (`FORMAT_ADVANCED`): validação própria do JFB, com erros **inline**
  por campo (`jet-form-builder__field-error`) e o script `advanced.reporting.js`.
  **Carrega as restrições de mídia** — o check de tamanho/tipo passa a funcionar.

### Para ter validação real + check de arquivo → mude para **Advanced**

- Form inteiro: nas configurações do form (JetForm → **Validation Type → Advanced**),
  ou por campo (`validation.type` do bloco).
- O modo avançado renderiza erros **inline**, que **quebravam a pílula** do
  composer. **O plugin (1.1.1) já domou isso:** `jet-form-builder__field-error`
  e os erros do Media Field (`.__errors`) são **reposicionados como aviso
  discreto ACIMA do composer** (mesma pegada da mensagem de status). Ver
  `chat.css` §2b. Então **ativar o Advanced não quebra o layout**.

### "Ativar isso quebra tudo?" → Não

- É setting de form/campo. O **real-time é intacto**: a validação roda **antes**
  da Insert CCT; se falha, **nada é gravado** e o `on-fail` cancela as rajadas.
- O único efeito colateral visual (erros inline) já está tratado.

### Roteando os erros para o seu toast (easypanel)

O plugin, por padrão, mostra os erros como um chip discreto acima do composer.
Se você usa um **toast easypanel** para as mensagens de status, há três opções
(escolha uma; documentadas para o próximo passo):

1. **Manter o default do plugin** (chip discreto) — nada a fazer.
2. **Deixar seu toast assumir**: desligar o CSS de erro do plugin (por um filtro/
   classe) e deixar o seu estilo pegar `.jet-form-builder-message` /
   `.jet-form-builder__field-error`.
3. **Empurrar via JS**: escutar `jet-form-builder/ajax/on-fail` e
   `jet-form-builder/advanced-reporting` e injetar no seu toast. (É o caminho
   mais limpo para unificar status + validação no mesmo toast.)

> **Decisão pendente sua:** qual das três? A 1.1.1 entrega a opção 1 (não quebra
> o layout). Se preferir 2 ou 3, eu implemento o gancho.

---

## 11.4 Limit Form Responses — por que NÃO no composer

O "Limit Form Responses" é um **meta de formulário** (`_jf_limit_responses`) que
limita o **total de respostas** do form. Num chat, **cada mensagem é uma
resposta** → limitar respostas = **capar a conversa** (errado). **Não** use no
form do composer. Para conter abuso, use as camadas do §11.5.

---

## 11.5 Segurança e anti-flood — o que ativar (e o que evitar)

Módulos nativos disponíveis: `security` (honeypot, wp-nonce, csrf) e `captcha`
(reCAPTCHA v3, Turnstile, hCaptcha, Friendly Captcha).

| Camada | Efeito | Recomendação para o chat |
|---|---|---|
| **Honeypot** (`security/honeypot`) | Campo-isca invisível anti-bot | **Ligar.** Zero fricção, invisível. |
| **WP Nonce / CSRF** (`security/wp-nonce`, `security/csrf`) | Protege o submit contra forjic./replay | **Ligar.** Invisível. |
| **Captcha INVISÍVEL** (reCAPTCHA v3 / Turnstile) | Score anti-bot sem interação | **Opcional, ok.** Sem clique por mensagem. |
| **Captcha INTERATIVO** (hCaptcha/checkbox) | Exige clique/resolver | **Evitar** — fricção a cada envio quebra o chat. |
| **Limit Form Responses** | Capa total de respostas | **Não usar** no composer (capa a conversa). |

**Anti-flood por usuário (rate limit no ENVIO):** como o envio é do JFB, o ponto
de plugagem server-side é o hook **`jet-form-builder/form-handler/before-send`**
(`form-handler.php:311`) — ali dá para rejeitar se o usuário enviou > N mensagens
em X segundos (lançando exceção antes das actions). É uma **extensão futura**
(ponto identificado); os nossos rate limits atuais cobrem só polling/fetch.

---

## 11.6 Roteiro de ativação segura (passo a passo)

1. **Validação → Advanced** (form ou nos campos `message`/mídia). Isso liga a
   validação real e as **restrições de arquivo** (tamanho/tipo).
2. **Media Field**: definir `allowed mimes` (ex.: `image/*`), `max size`,
   `max files`. (O preview e o excluir já são nativos + revestidos.)
3. **Security**: honeypot **ON**, nonce/CSRF **ON**.
4. **Captcha**: se quiser, Turnstile ou reCAPTCHA **v3 (invisível)**.
5. **NÃO** ligar Limit Form Responses no composer.
6. *(Futuro)* anti-flood por usuário via `before-send`.
7. **Testar**: arquivo grande → aviso discreto acima do composer; envio válido →
   real-time normal; sem quebra de layout.

---

## 11.7 Config do Media Field (respondendo à dúvida do teste)

Na dúvida "adicionei Media ID / URL e o preview não aparece":

- **Insert Attachment / Value Format** (`media-field.php:264-268`) define **o que
  é SALVO** em `message_image`: **Media ID** (recomendado para o CCT — o card
  resolve a imagem pelo ID), **URL**, ou **Both**. **Isso NÃO afeta o preview.**
- O preview do JFB é criado no cliente por `createPreview` (`media.field.js`):
  `URL.createObjectURL(file)` → `<img>` **prepend** no `.__file`, só para
  `image/*`. Ele **não** dependia da sua config.
- **A causa do preview oculto era conflito de CSS/posição** (o JFB deixa o
  `.__file` com `opacity:.5` + ícone placeholder e o `.__files` podia resolver
  sob um wrapper `position:relative`), **corrigido na 1.1.1** (forçamos
  `opacity:1`/sem placeholder e ancoramos o absoluto no `<form>`).
- Outras chaves úteis: `max_files`, `max_size`, `allowed_mimes`.

**Recomendação:** Value Format = **Media ID** (mais robusto para exibir no card),
e o mapeamento da Action Insert CCT: `message` → texto, mídia → `message_image`.

---

## 11.8 Princípio (recap)

O segundo coração é do JetFormBuilder. O nosso código **integra e reveste**:
suprime o feio, torna os erros layout-safe, limpa o que ficou pendurado — e
deixa a **validação, a segurança e o envio** com quem faz isso nativamente.
Ativar essas camadas é **configuração**, e o plugin garante que ativá-las **não
quebre** a experiência.
