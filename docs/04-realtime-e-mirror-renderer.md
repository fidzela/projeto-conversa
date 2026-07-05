# 04 — O Problema do Real-Time e o Mirror Renderer

Este é o **maior ponto de conflito** do projeto, nas palavras do autor. Este
documento explica: (a) por que o real-time foi difícil; (b) o que é o "custom
renderer" / mirror renderer; (c) por que exatamente ele **quebra o princípio
fundamental**; e (d) o que a raiz dos plugins oferece como alternativa.

---

## 4.1 A origem do problema

> O projeto **não nasceu** como chat real-time. Toda a estrutura — CPT, CCT,
> metafields, single, listing — foi feita só para o usuário **enviar** a mensagem
> e vê-la **no reload**. O real-time foi acoplado por cima, depois.

Nativamente, o pipeline é **request-response**:

```
enviar → grava no CCT → (só no próximo reload) o Listing 56326 re-consulta e re-renderiza
```

Para transformar isso em "a mensagem aparece sozinha", é preciso, **sem reload**:

1. **Detectar** que há mensagem nova (polling / evento de submit) → resolvido bem
   pelo endpoint `status` barato (hash de `last_id|count`).
2. **Renderizar** a mensagem nova **no mesmo formato visual do Listing** e inseri-la
   no DOM.

O passo 2 é onde está todo o conflito.

---

## 4.2 As tentativas (a evolução v2 → v4.1)

| Versão | Estratégia de renderização | Problema |
|--------|----------------------------|----------|
| v2 (`backend-api`) | **Full refresh**: re-renderiza o Listing inteiro (via shortcode `[jet_engine_listing]` ou fetch HTTP + DOMDocument) e **substitui** todo o `.jet-listing-grid__items`. | Correto visualmente (usa o template real), mas **caro** e **destrutivo**: recarrega todas as mensagens a cada nova, pisca a tela, perde estado de scroll/DOM, e o fetch HTTP interno é pesadíssimo. |
| v4.1 (`incremental-runtime`) | **Append incremental via mirror**: busca só os dados das mensagens novas (`after_id`) e **clona no cliente** um card já existente, trocando os textos. | Barato e não-destrutivo, **mas** acopla o JS à estrutura interna do Listing 56326 (ver §4.4). |

> A citação-chave do autor:
> *"Durante a criação foi tentado atualizar apenas o NOVO ITEM adicionado ao listing,
> e não conseguimos. A solução foi um renderer customizado onde ele cria o HTML
> baseado no meu listing → e é exatamente isso que quebra todo o meu princípio, porque
> qualquer alteração no listing não é refletida no HTML do single da conversa."*

---

## 4.3 Como o Mirror Renderer funciona (v4.1)

Passo a passo, no `conversa-v4.1...php`:

1. **No boot**, o runtime pega os cards que o Listing **já renderizou** no
   carregamento da página e guarda **um de cada lado** como molde:
   `collectPrototypes()` (`:883`) — procura o primeiro item com
   `.chat-msg-card--artist` e o primeiro com `.chat-msg-card--guest` e faz
   `cloneNode(true)`.
2. **Ao detectar mensagem nova**, o backend devolve **dados** (não HTML):
   `{ id, side, message, display_name, time_label, ... }` (`prepare_message_item`, `:383`).
3. **No cliente**, `renderItemFromPrototype()` (`:1011`) clona o molde do lado certo e:
   - `updateRootAttrs()` (`:967`) troca `data-message-id`, `data-post-id`,
     `jet-listing-dynamic-post-{id}`, `data-from-user`, classes de lado, e ajusta
     `data-queried-id` de `{oldId}|cct:mensagens_` para `{newId}|cct:mensagens_`;
   - `updateDynamicFields()` (`:928`) injeta nome/mensagem/hora nos **slots**.
4. **Append** no `.jet-listing-grid__items` via `appendItems()` (`:1026`).

O ponto frágil é o passo 3, `updateDynamicFields`. Os "slots" são **seletores de
elemento Elementor hard-coded** (`:931-942`):

```
guest:  nome  → .elementor-element-ebc134e .jet-listing-dynamic-field__content
        msg   → .elementor-element-9a10ed6 ...
        hora  → .elementor-element-6fc61e3 ...
artist: nome  → .elementor-element-1178325 ...
        msg   → .elementor-element-066944a ...
        hora  → .elementor-element-e02293e ...
```

Há um fallback por ordem (`contents[0]/[1]/[2]`, `:958-960`), mas ele também assume a
ordem exata nome→mensagem→hora dentro do card.

---

## 4.4 Por que isso quebra o Princípio Fundamental

O mirror renderer transforma **o layout do Listing 56326 em um contrato rígido e
implícito com o JavaScript**. Os IDs de elemento (`ebc134e`, `9a10ed6`, ...) são
**gerados pelo Elementor** e vivem no documento do Listing 56326 — que, como mostrado
em [doc 01, §1.3](01-arquitetura.md), **nem sequer está no template single**.

Consequências práticas — exatamente os cenários que o autor quer evitar:

- **Editar o card no Elementor** (mover o nome, trocar um widget, reordenar) muda os
  IDs de elemento → os seletores hard-coded não casam mais → mensagens novas aparecem
  **sem texto**, ou caem no full-refresh a cada mensagem (perdendo a vantagem do
  incremental).
- **Adicionar um metafield novo** (ex.: `image`, para enviar imagem junto do texto)
  **não** aparece nas mensagens incrementais: o mirror só conhece os 3 slots
  (nome/mensagem/hora). O card só mostraria a imagem **no reload** (quando o Listing
  real renderiza), criando uma incoerência entre "mensagem recém-enviada" e "mensagem
  após reload".
- **Ter layouts diferentes de card** (ou um layout condicional) é incompatível: o
  molde é um clone estático de um único card existente.
- **Ligar/desligar o real-time** não é trivial porque o comportamento está espalhado
  por 6 snippets acoplados, sem um ponto único de configuração.

Em resumo: **o Elementor/JetEngine deveria ser a fonte visual única da verdade, mas o
mirror renderer cria uma segunda fonte (o JS) que precisa ser mantida em sincronia
manual com a primeira.** Toda mudança no listing exige lembrar de atualizar o JS —
que é a definição de "engessado".

---

## 4.5 A alternativa que a raiz dos plugins já oferece

Como documentado em [doc 02, §2.5](02-correlacao-plugins.md), o **próprio JetEngine já
renderiza e anexa itens novos pelo template real**, via o handler AJAX
`jet_engine_ajax` / `listing_load_more`:

- servidor: `posts_loop()` renderiza os itens pelo **mesmo caminho do grid**
  (`ajax-handlers.php:459`) e devolve HTML pronto;
- cliente: `ajaxGetListing({ append: true })` faz `container.append($html)` **e**
  `initElementsHandlers($html)` para religar os widgets (`main.js:582,599`).

Isso significa que "renderizar só o item novo pelo template real" **não é um problema
sem solução** — é uma capacidade nativa. O desafio da nova versão é **direcionar essa
capacidade** para "mensagens com `_ID > last_known`" em vez de "próxima página de
paginação". Isso é uma questão de **query** (a Query do Listing precisa aceitar um
limite dinâmico do tipo "após o ID X"), não de reimplementar renderização no cliente.

> As diretrizes concretas para fazer isso — respeitando o princípio fundamental —
> estão no [doc 06](06-diretrizes-nova-versao.md). Aqui basta registrar: **a
> renderização incremental deve voltar a passar pelo template do Listing**, para que
> o Elementor/JetEngine continue sendo a única fonte visual da verdade.

---

## 4.6 Sintomas históricos correlatos (já mitigados, mas informativos)

- **"Segundo scroll fantasma"** (10-15s após o boot): causado, na v2, por observers
  de DOM/imagem disparando scroll por inferência. Resolvido na v3 com funções de
  scroll por contexto explícito (ver [doc 03, §3.5](03-anatomia-dos-arquivos.md)).
- **Bug de precedência de vírgula no CSS do composer**: seletor `A, B.classe`
  interpretado como `A` (sem a classe) + `B.classe`. Resolvido na v2.1 com o gate por
  classe única `.chat-conversa-composer`.
- **Restauração do textarea pelo JFB após o envio**: mitigado com limpeza em múltiplos
  ticks (`form-composer.php:559-563`).

Esses três mostram um padrão: **muito esforço foi gasto lutando contra efeitos
colaterais de acoplar comportamento por cima do Elementor/JetEngine, em vez de
integrar-se aos mecanismos deles.** É esse padrão que a nova versão precisa inverter.
