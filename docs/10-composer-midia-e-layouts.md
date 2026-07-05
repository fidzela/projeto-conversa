# 10 — Composer: correções, módulo de mídia e layouts (1.1.0)

Este documento cobre a fase 1.1.0: as **correções** de UX do composer, o novo
**módulo de mídia** (enviar imagem junto da mensagem) e o mecanismo de
**layouts de composer** armazenados pelo plugin.

> Princípio fundamental (recap): o plugin **reveste e integra** o que os plugins
> já fazem; começa e termina onde o nativo não chega. Nada aqui recria uploader,
> preview, validação ou envio — tudo isso é do JetFormBuilder. O plugin só
> **posiciona, veste e orquestra**. Toda referência `arquivo:linha` aponta para
> o repositório `core-plugins` (JetFormBuilder 3.6.1.1).

---

## 10.1 Correções de UX do composer

As três correções pedidas. Todas vivem no cliente (`composer.js` + `chat.css`),
sem tocar no envio do form.

### a) Auto-grow do textarea sem o "desce e sobe"

**Sintoma:** ao digitar, quando o texto da 1ª linha chegava perto do botão de
enviar, ele passava "por baixo" do botão e o campo ficava oscilando
(subindo/descendo) até fechar a linha.

**Causa raiz:** a classe `.conversa-composer-is-expanded` troca a reserva
lateral do botão (padding à direita) por uma reserva no rodapé — ou seja, **muda
a largura útil do textarea**. O código media o `scrollHeight` numa largura e
renderizava noutra; a cada tecla no limiar de quebra da linha, a decisão
"expandir?" invertia → oscilação.

**Correção** (`composer.js::autoSize`), em duas frentes:

1. **Expansão sticky:** uma vez expandido, o composer só volta ao estado
   compacto quando o texto **esvazia**. Isso remove o vai-e-volta no limiar.
2. **Dupla medição:** decide o estado → aplica a classe (que define a largura
   final) → **só então** mede a altura. O `scrollHeight` passa a bater com a
   largura real. O botão fica fixo e o texto "sobe" sem atropelar nada.

O tamanho do textarea é guiado só pelo **texto**; a expansão idem. Uma imagem
anexada (layout de mídia) conta como conteúdo só para o **estado do botão**
(não deixa "cinza"), não para a altura.

### b) Sem o balão nativo de "Preencha este campo"

O balão é a validação nativa do browser (constraint validation) disparada pelo
atributo `required`. É feio e fora do tom do chat.

**Correção** (`composer.js::suppressNativeValidation`): escuta o evento
`invalid` na fase de captura e chama `preventDefault()`. Isso **suprime só o
popup** — a obrigatoriedade continua (o form inválido não envia; o botão fica
cinza no estado vazio). Não usamos `novalidate` justamente para **não** deixar
o envio vazio chegar ao servidor e virar uma mensagem de erro visível.

### c) Mensagem de status só quando há problema

O JetFormBuilder, ao enviar, injeta uma mensagem
`.jet-form-builder-message` com o modificador `--success` **ou** `--error`
(`includes/form-messages/builder.php:73-74` + `status-info.php:80-82`:
`get_css_class()` devolve `'success'` ou `'error'`).

Num chat, o **sucesso é redundante** — a mensagem já aparece na lista pelo
real-time. Então (`chat.css` §2):

- `.jet-form-builder-message--success { display: none }` → esconde o sucesso;
- `.jet-form-builder-message--error` → visível, como um **balão discreto
  flutuando acima** do composer (posição absoluta, sem empurrar a pílula).

Antes, o CSS escondia **todas** as mensagens (inclusive erro). Agora o erro —
a única situação em que o usuário precisa de aviso — aparece.

---

## 10.2 Módulo de mídia: enviar imagem na mensagem

### O que é

O CCT `mensagens_` ganhou o metafield **`message_image`**. A mensagem agora pode
carregar uma imagem além do texto (extensível a outros tipos no futuro). A
**exibição** já funciona: o autor incluiu a imagem no card do Listing (contexto
dinâmico da coluna) e o render incremental mostra a imagem automaticamente,
porque usa o **template real** do Listing (ver docs/09 — o coração). Trocar o
layout do card **não quebrou o real-time**: prova viva do "não engessar".

O que faltava era o **envio**: o composer precisava de um jeito de anexar a
imagem. É o que a 1.1.0 entrega — **sem recriar uploader**.

### Como o JetFormBuilder já resolve o upload (e por que não recriamos)

O JFB tem um **Media Field** nativo. Ao ser adicionado a um form, ele renderiza
(`templates/fields/media-field.php`):

```
<div class="jet-form-builder__field-wrap jet-form-builder-file-upload">
  <div class="jet-form-builder-file-upload__content">
    <div class="jet-form-builder-file-upload__loader">…spinner…</div>
    <div class="jet-form-builder-file-upload__files">…previews…</div>   ← miniaturas
  </div>
  <div class="jet-form-builder-file-upload__fields">
    <input type="file" class="… jet-form-builder-file-upload__input">   ← o "anexar"
  </div>
  <div class="jet-form-builder-file-upload__message">…tamanho máx…</div>
  <div class="jet-form-builder-file-upload__errors is-hidden"></div>
</div>
```

Cada preview (`templates/fields/image-preview.php`) já vem com a miniatura e o
**botão de excluir** nativos:

```
<div class="jet-form-builder-file-upload__file" data-file="%file_url%">
  <img src="%file_url%" width="100px" height="100px">              ← preview
  <div class="jet-form-builder-file-upload__file-remove" …>        ← excluir (X)
    <svg>…lixeira…</svg>
  </div>
</div>
```

O upload, a criação da miniatura (`media.field.js` → `createPreview`), o
**excluir** (`addRemoveHandler` no `.__file-remove`) e o envio do arquivo são
**100% do JFB**. Recriar qualquer parte disso seria engessar e duplicar.

### O que o plugin faz (a fronteira)

Só **reveste** o campo nativo, no estilo da referência (input do chat, com `+`
à esquerda, sem microfone):

- **`composer.js::wireMedia`** detecta `.jet-form-builder-file-upload` no form e
  marca `.conversa-composer-has-media`. Observa a lista de previews e alterna
  `.conversa-composer-has-previews` (para reservar o topo só quando há anexo),
  remedindo a altura.
- **`chat.css` §2b** posiciona e veste (tudo `!important`, escopo
  `.conversa-composer-has-media`):
  - o `.__fields` (input file) vira o botão **`+`** no canto inferior esquerdo;
    o input fica transparente cobrindo o botão (clicar abre o seletor);
  - o `.__files` vira a **régua de miniaturas** no topo; cada `.__file` é
    **50×50 `object-fit: cover`**;
  - o `.__file-remove` nativo é revestido como um **X em círculo** no canto
    (escondemos o SVG de lixeira e desenhamos o X por `::before`);
  - o texto ocupa a **largura total**; a barra inferior (`+` à esquerda,
    **enviar** à direita) fica fixa embaixo.

Posicionamos os filhos-chave (previews e `+`) em **absoluto relativo ao
`<form>`** (que é `position: relative`), neutralizando `position/margin` dos
wrappers intermediários — assim o layout independe da profundidade do markup do
bloco.

### Como AUTORAR (passo a passo, no WP admin)

O plugin **não cria form nem coluna** — o autor monta no JFB (Regra de Ouro):

1. **CCT:** a coluna `message_image` já existe em `mensagens_` (tipo mídia).
2. **Form (duplicado):** duplique o form do composer (**não altere o atual**).
   No form novo, adicione um **Media Field**.
   - Não marque o Media Field como *required* (imagem é opcional).
   - Se quiser permitir **imagem sem texto**, deixe o campo de mensagem
     **não-obrigatório**; senão o `required` do texto bloqueia o envio só-imagem.
3. **Action "Insert/Update CCT":** mapeie o Media Field → coluna
   `message_image` (e mantenha o mapeamento do texto → `message`).
4. **Elementor:** coloque o form novo no `#footer-conversa`. O revestimento
   (`+`/previews) liga sozinho (feature-detect do campo de mídia).
5. **Card do Listing:** exiba `message_image` como imagem dinâmica (o autor já
   fez e testou). O real-time mostra a imagem sem mudança no plugin.

---

## 10.3 Layouts de composer (armazenados; base para o futuro)

O plugin passa a **guardar "layouts" de formulário** do composer. Cada layout
aponta para um `form_id` do JFB e declara suas features. É configuração —
trocar/duplicar o composer não exige código (não engessa).

`class-conversa-chat.php`:

```php
'composer_layouts' => array(
    'text'  => array( 'label' => 'Texto',        'form_id' => 56386, 'media' => false ),
    'media' => array( 'label' => 'Texto + mídia', 'form_id' => 0,     'media' => true,
                      'media_field' => 'message_image' ),
),
'default_layout' => 'media',
'message_image_field' => 'message_image',
```

- **`text`** → o composer atual (só texto). **Não é alterado.**
- **`media`** → a cópia com Media Field. É o **padrão** (`default_layout`).
  `form_id => 0` significa "layout registrado, mas ainda sem form" (inerte até
  receber o ID do form duplicado). O revestimento visual, porém, **não depende
  do ID**: liga por feature-detect assim que o form com Media Field entra na
  página.

`composer_forms()` une, por `form_id`, o registro de layouts (fonte das
features) com a lista legada `form_ids` (compat, tratada como texto). O
resultado alimenta:

- os **seletores** que o `assets` publica ao front (quais `<form>` são o
  composer do chat, para o `composer.js` e o `runtime.js`);
- o mapa `forms` no config do front (features por form; o `composer.js` também
  faz feature-detect, isto é o "mapa oficial").

> **Para o `media` virar padrão de fato**, informe o `form_id` do form duplicado
> no `composer_layouts['media']['form_id']` (via filtro `conversa-chat/settings`
> ou direto no default). Sem isso, o layout fica registrado mas o form não é
> reconhecido para o real-time (o revestimento visual liga do mesmo jeito).

### Filtros

| Filtro | Uso |
|--------|-----|
| `conversa-chat/settings` | Trocar `composer_layouts`, `default_layout`, `message_image_field`, `form_ids`. |
| `conversa-chat/composer-forms` | Ajustar o mapa final `form_id → features` em runtime. |

---

## 10.4 O que dá para mudar sem quebrar (não engessar)

| Mudança | Como | Quebra? |
|---------|------|---------|
| Layout do card com imagem | Autoração no Listing (imagem dinâmica de `message_image`) | Não — render é o template real |
| Trocar/duplicar o form do composer | Novo `form_id` num layout | Não |
| Ligar/desligar mídia | Feature do layout (`media`) + presença do Media Field | Não |
| Outro tipo de mídia (futuro) | Outro campo + outra coluna; mesmo mecanismo | Não |
| Voltar para só-texto | `default_layout => 'text'` e usar o form de texto | Não |

---

## 10.5 Checklist de release 1.1.0

- [x] Auto-grow sticky + dupla medição (sem "desce e sobe").
- [x] Balão nativo de required suprimido (mantém a obrigatoriedade).
- [x] Status do JFB só em erro (sucesso escondido).
- [x] Revestimento do Media Field nativo (`+`, previews 50×50 cover, X).
- [x] Registro de layouts (`composer_layouts`, `default_layout`, `composer_forms()`).
- [x] Verificação visual do composer (render do CSS sobre o DOM nativo).
- [ ] Informar o `form_id` do form de mídia duplicado (autoração pendente).
