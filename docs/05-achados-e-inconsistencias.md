# 05 — Achados e Inconsistências

Achados obtidos **validando o código do projeto contra a raiz real dos plugins**
(`core-plugins`), como manda o protocolo do `core-plugins/README.md`. Não são
"bugs a corrigir agora" (estamos em planejamento) — são pontos que a nova versão
deve resolver, e que explicam parte da fragilidade atual.

Cada item indica o **impacto** e a **evidência**.

---

## 5.1 Hook de `last_message_at` registrado em um hook inexistente ⚠️ (alto)

**O que o projeto faz:** `conversa-v2-backend-api.php:116`
```
add_action( 'jet-engine/custom-content-types/data/inserted-item',
            'chat_conversa_touch_last_message_at', 10, 2 );
```
com callback assinando `($item_id, $cct_instance)`.

**O que a raiz oferece:** buscando em todo o JetEngine 3.8.10.1, **o hook
`jet-engine/custom-content-types/data/inserted-item` não existe**. Os hooks realmente
disparados na inserção de um item de CCT são (`custom-content-types/inc/item-handler.php`):

- `jet-engine/custom-content-types/create-item/mensagens_` — args `($item, $this)` (`:448`)
- `jet-engine/custom-content-types/created-item/mensagens_` — args `($item, $item_id, $this)` (`:453`)
- `jet-engine/custom-content-types/updated-item/mensagens_` — args `($item, $prev_item, $this)` (`:462`)

**Impacto:** o hook nunca dispara → `last_message_at` **nunca é atualizado** por esse
caminho. Além do nome, a **assinatura também não bate**: o projeto espera
`($item_id, $cct_instance)`, mas o hook correto entrega `($item, $item_id, $this)`.
O meta `last_message_at`, na prática, é código morto hoje.

**Para a nova versão:** usar `created-item/mensagens_` (ou `updated-item/mensagens_`),
lendo `conversa_id` de `$item`. Esse mesmo hook é o **gatilho de servidor correto**
para "chegou mensagem nova" (relevante para real-time por push/SSE no futuro).

---

## 5.2 O nome do campo de texto da mensagem é **adivinhado** ⚠️ (médio)

**O que o projeto faz:** `conversa-v4.1...php:409` (`extract_message_text`) tenta uma
lista de candidatos — `mensagem`, `message`, `mensagem_texto`, `texto`, `conteudo`,
`content`, ... (`:420-432`) — e, se nada casar, varre a linha ignorando campos de
serviço e devolve o primeiro texto não-numérico (`:443-475`). Há uma constante de
override `CHAT_CONVERSA_V4_MESSAGE_FIELD` deixada **vazia** (`:67-69`).

**Fato conhecido:** o campo real é **`message`** (declarado pelo autor e coerente com
o modelo do CCT). Logo, hoje "funciona por sorte" — cai no 2º candidato.

**Impacto:** frágil e implícito. Se um dia existir outro campo de texto na linha (ex.:
uma legenda de imagem), a heurística pode devolver o campo errado. É um sintoma de o
runtime **não conhecer o schema do CCT** — ele deveria ler o campo pelo nome real, não
adivinhar.

**Para a nova versão:** o campo (e o schema em geral) deve ser **conhecido/configurado**,
não inferido. O JetEngine expõe os campos do CCT via a Factory do CCT.

---

## 5.3 `data_envio` duplica capacidade nativa (`cct_created`) ℹ️ (baixo)

Toda linha de CCT já recebe `cct_created` e `cct_modified` com `current_time('mysql')`
(`item-handler.php:445-446`) e um `_ID` incremental (`db.php:280`). O projeto criou o
campo `data_envio` para o mesmo papel de "timestamp da mensagem".

**Impacto:** baixo, mas é duplicação. `MAX(data_envio)` (usado no `status`) poderia ser
`MAX(cct_created)`; ordenação temporal já é garantida por `ORDER BY _ID`.

**Para a nova versão:** avaliar usar os campos de serviço nativos e reservar campos
próprios só para dado que o JetEngine não fornece.

---

## 5.4 Dois "context guards" paralelos ℹ️ (baixo/médio)

O `context-guard` v2 define `chat_conversa_context()` como fonte única do contexto
(`role`, IDs, etc.). Mas o `v4.1` **reimplementa** seu próprio gate
`chat_conversa_v4_get_front_context()` (`:521`) e sua própria leitura de participantes
(`chat_conversa_v4_get_conversa_participants`, `:369`), em vez de reutilizar o guard.

**Impacto:** lógica duplicada = duas verdades para manter em sincronia (ex.: a regra de
"quem é participante" existe em dois lugares). Uma diferença sutil: o guard v2 trata
`role='other'` como **informativo/não-bloqueante** (`:166-169`), enquanto o v4.1
**bloqueia** `other` no front (`:546-548`) e nos endpoints (`:209-214`).

**Para a nova versão:** um único módulo de contexto/permissão, consumido por todos.

---

## 5.5 Três nonces e dois conjuntos de endpoints coexistindo ℹ️ (baixo)

Há o nonce/endpoints da v2 (`chat_conversa_nonce`, `chat_conversa_status`,
`chat_conversa_messages`) e os da v4.1 (`chat_conversa_v4_nonce`,
`chat_conversa_v4_status/after/full`). O v4.1 usa o `messages` da v2 apenas como
fallback "full".

**Impacto:** superfície de manutenção e de segurança maior que o necessário; dois
mecanismos de rate-limit; dois formatos de payload de `status` (a v2 tem `last_id`/
`count`; a v4.1 tem `last_id_total`/`last_id_published`/etc.).

**Para a nova versão:** um único endpoint/contrato de dados.

---

## 5.6 Renderização por `[jet_engine_listing]` e fetch HTTP interno ⚠️ (médio)

No fallback "full", `chat_conversa_render_listing()` tenta o shortcode
`[jet_engine_listing listing_id="56326"]` (`backend-api.php:499`) e, se falhar, faz um
`wp_safe_remote_get()` **no próprio permalink da página** e extrai o nó via DOMDocument
(`:531-629`).

**Impacto:** o fetch HTTP interno é o caminho mais caro possível (uma requisição HTTP
completa do site para si mesmo, com cookies, a cada refresh), e o mais frágil (depende
de o listing aparecer no HTML da página, de cache, de headers). O `try_direct_listing`
monta `$atts` com o typo histórico `lisitng_id` (`:486`) — que, de fato, é o nome do
setting no JetEngine (`ajax-handlers.php:369` usa `lisitng_id`), então **não é um erro**,
mas ilustra o quanto o projeto está preso a detalhes internos.

**Para a nova versão:** não fazer fetch HTTP de si mesmo. Se precisar renderizar
server-side, usar as APIs de render do JetEngine diretamente (`get_listing_item`/
`posts_loop`), como o próprio `listing_load_more` faz.

---

## 5.7 Acoplamento a IDs de elemento Elementor no JS 🚩 (alto — é o problema central)

Tratado em detalhe no [doc 04](04-realtime-e-mirror-renderer.md). Registrado aqui na
lista de achados por completude: `conversa-v4.1...php:931-942` depende de
`elementor-element-ebc134e/9a10ed6/6fc61e3/1178325/066944a/e02293e`, que vivem no
documento do Listing 56326 (fora do repo) e mudam quando o card é editado.

---

## 5.8 Dependência de configuração não versionada ℹ️ (contexto)

Vários números são **IDs de instância do site** e só têm sentido naquele WordPress:
Listing `56326`, Listing header `68686`, Listing lateral `57373`, Form `56386`, e os
IDs de elemento Elementor. Além disso, o real-time depende de o Form 56386 estar em
**modo submit AJAX** (ver [doc 02, §2.6](02-correlacao-plugins.md)) — uma configuração
feita na UI do JetFormBuilder, invisível no código.

**Impacto:** o projeto não é portátil nem reproduzível a partir do repositório; parte
do "estado" vive no banco/UI. Isso reforça a necessidade de empacotar como plugin com
configuração explícita.

---

## Resumo por severidade

| # | Achado | Severidade |
|---|--------|-----------|
| 5.7 | Acoplamento a IDs de elemento Elementor (mirror renderer) | 🚩 Alto (central) |
| 5.1 | Hook `data/inserted-item` inexistente → `last_message_at` morto | ⚠️ Alto |
| 5.2 | Campo de texto da mensagem adivinhado | ⚠️ Médio |
| 5.6 | Fetch HTTP interno / render por shortcode | ⚠️ Médio |
| 5.4 | Dois context guards paralelos | ℹ️ Baixo/Médio |
| 5.5 | Dois conjuntos de endpoints/nonces | ℹ️ Baixo |
| 5.3 | `data_envio` duplica `cct_created` | ℹ️ Baixo |
| 5.8 | Configuração não versionada (IDs, modo AJAX do form) | ℹ️ Contexto |
