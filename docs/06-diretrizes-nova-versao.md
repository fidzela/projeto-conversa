# 06 — Diretrizes para a Nova Versão

> **Fase de planejamento — sem código de implementação.** Este documento define
> princípios, restrições e uma direção de arquitetura. As decisões finais e o código
> virão numa fase posterior.

O objetivo declarado: **reiniciar o projeto fundamentado na raiz do JetEngine,
Elementor, Elementor Pro e JetFormBuilder em todas as etapas**, remover o WPCode, e
não "engessar". Tudo aqui serve a esse objetivo e ao [Princípio Fundamental](README.md).

---

## 6.1 Os critérios de aceitação (traduzindo o princípio em regras testáveis)

Qualquer solução da nova versão deve passar nestes testes de "não engessar":

1. **Teste do layout:** editar o card no Elementor (mover/trocar/reordenar widgets)
   **não** pode quebrar o real-time nem exigir mexer em código. → Consequência: o JS
   **não** pode depender de IDs de elemento nem reconstruir HTML de card.
2. **Teste do campo novo:** adicionar um metafield ao CCT (ex.: `image`) e colocá-lo no
   card deve fazê-lo aparecer **tanto** no reload **quanto** na mensagem incremental,
   sem código novo específico daquele campo.
3. **Teste do layout alternativo:** trocar o Listing por outro (ou ter layout
   condicional) deve ser questão de **configuração**, não de reescrever o runtime.
4. **Teste do liga/desliga:** deve existir um ponto único para ativar/desativar o
   real-time (e, idealmente, escolher o modo).
5. **Teste da portabilidade:** instalar o plugin + importar os templates deve
   reproduzir o comportamento, sem "configuração secreta" perdida no banco.

Se uma proposta falha em qualquer um destes, ela repete o erro atual.

---

## 6.2 Regra de ouro: o Listing é a única fonte visual da verdade

**A renderização de uma mensagem — nova ou antiga — deve sempre passar pelo template
do Listing 56326 (ou seu sucessor).** O cliente nunca "monta" um card.

Isso resolve os testes 1, 2 e 3 de uma vez, porque o card sempre reflete o que está no
Elementor. A capacidade nativa que torna isso possível já foi comprovada em
[doc 02, §2.5](02-correlacao-plugins.md):

- servidor renderiza itens pelo `posts_loop`/`get_listing_item` (mesmo caminho do grid
  e do `listing_load_more`);
- cliente anexa com `append` + `initElementsHandlers` (mesmo caminho do load-more
  nativo).

**Direção (não-código):** a renderização incremental deve ser servida como **HTML de
itens do template real**, não como dados a serem "espelhados" no cliente. O ponto de
extensão a estudar é **como filtrar a query do Listing por "mensagens após o `_ID` X"**
— isto é território da **Query Builder do JetEngine** e das APIs de listagem, não de JS
customizado.

> Investigação pendente antes de codificar (respeitando o protocolo do core-plugins):
> mapear no JetEngine **como injetar um limite dinâmico "após ID"** na query do
> Listing/`posts_loop` de forma suportada (Query Builder + filtros
> `jet-engine/listing/grid/...`), e como reutilizar a assinatura de query segura do
> `listing_load_more` (`ajax-handlers.php:248`).

---

## 6.3 Empacotar como plugin real (fim do WPCode)

Substituir os 6 snippets do WPCode por **um plugin WordPress próprio**, com:

- **Header de plugin** e ativação/desativação controladas.
- **Ordem de carregamento determinística** (autoload), acabando com a dependência da
  ordem de ativação no WPCode.
- **Um único módulo de contexto/permissão** (unifica os dois guards do
  [doc 05, §5.4](05-achados-e-inconsistencias.md)).
- **Um único contrato de endpoints/dados** (unifica os dois conjuntos do §5.5).
- **Dependências declaradas** (checar `function_exists('jet_engine')`,
  `class_exists('Jet_Form_Builder\\...')`, Elementor ativo) e degradar com aviso claro.

Benefício direto ao princípio: o comportamento passa a ter **um lugar** — versionável,
revisável, com liga/desliga (teste 4) e configuração explícita (teste 5).

---

## 6.4 Integrar-se aos pontos de extensão reais (em vez de acoplar por cima)

Mapeamento dos ganchos **comprovados** que a nova versão deve preferir:

| Necessidade | Gancho nativo comprovado | Evidência |
|-------------|--------------------------|-----------|
| Reagir a "mensagem gravada" no servidor | `jet-engine/custom-content-types/created-item/mensagens_` | `item-handler.php:453` |
| Reagir a "mensagem enviada" no cliente | `jet-form-builder/ajax/on-success` | `jetformbuilder/modules/user-journey/module.php:594` |
| Renderizar itens pelo template real | `posts_loop()` / `get_listing_item()` | `render/listing-grid.php:1677`; `ajax-handlers.php:459` |
| Anexar itens no front | `ajaxGetListing({append:true})` + `initElementsHandlers` | `main.js:582,599` |
| Saber que o grid mudou | evento `jet-engine/listing-grid/after-load-more` | `main.js:811` |
| Conhecer o schema do CCT | Factory/DB do CCT (campos declarados) | `custom-content-types/inc/db.php`, `factory.php` |

Regra: **antes de escrever comportamento novo, procurar o gancho oficial** (Regra Nº 8
do core-plugins: reutilizar estruturas existentes, não criar arquitetura paralela).

---

## 6.5 O que **preservar** da versão atual (não jogar tudo fora)

Nem tudo é gambiarra; várias decisões são boas e devem migrar:

- **Endpoint `status` barato + hash** para detecção de mudança (`backend-api.php:261`).
  Manter o princípio "detecção barata, render sob demanda".
- **Gate em duas camadas** (leve pré-query, completo pós-query) — bom design
  (`context-guard.php`).
- **Scroll por contexto explícito** da v3 (matou o "scroll fantasma"). Manter a ideia
  de estado `stickToBottom` e funções por evento.
- **Composer com regras de ouro** (nunca forçar display no form, nunca colapsar wrapper
  com campo, limpar só o textarea) — lições reais de UX com JetFormBuilder.
- **Tab-lock** (uma aba primária) — evita polling/envio duplicado. Reavaliar
  complexidade, mas o conceito é válido.
- **Rate limiting** e validação de participante nos endpoints.

---

## 6.6 Modularização sugerida (conceitual)

Uma separação de responsabilidades que sustenta os critérios de §6.1 (nomes ilustrativos):

```
plugin conversa/
├─ contexto/permissão   (gate único; quem é participante; role)
├─ dados                 (leitura do CCT: status, "mensagens após ID"; conhece o schema)
├─ render                (SEMPRE via template do Listing; nada de HTML no cliente)
├─ transporte            (1 contrato AJAX; futuramente SSE/WebSocket plugável)
├─ runtime (JS)          (detecção de mudança, scroll, tab-lock; NÃO monta card)
├─ composer (JS/CSS)     (UX do form; desacoplado do render das mensagens)
└─ configuração          (IDs de listing/form, on/off do real-time, modo)
```

O ponto inegociável é a fronteira **render ↔ runtime**: o runtime pede itens e os
insere; **quem sabe desenhar um card é o Listing**, sempre.

---

## 6.7 Decisões em aberto (a validar com o autor / no código, antes de codar)

1. **Estratégia de transporte:** manter **polling** (simples, funciona hoje) ou evoluir
   para **SSE/WebSocket** (menos latência, mais infra)? O gancho de servidor
   `created-item/mensagens_` viabiliza push no futuro. → Decisão de produto + infra.
2. **Como filtrar "mensagens após ID" no Listing** de forma suportada: Query Builder
   com variável dinâmica? Filtro em `jet-engine/listing/grid/...`? Requer investigação
   dirigida na Query Builder (Nível 3 do protocolo core-plugins).
3. **Escopo do liga/desliga:** por conversa? global? por papel? (teste 4).
4. **Campos ricos** (imagem, etc.): confirmar que passarão pelo card do Listing
   automaticamente (teste 2) e definir upload no JetFormBuilder.
5. **Migração:** os dados atuais (CCT `mensagens_`, metas do CPT) permanecem; muda o
   código ao redor. Confirmar que nada do schema atual será quebrado.

---

## 6.8 Próximo passo recomendado

Antes de escrever qualquer linha da nova versão, fazer **uma investigação dirigida** de
2 e 6.7-item-2 acima (a Query Builder do JetEngine e o ponto de extensão de "após ID"),
seguindo o protocolo do `core-plugins/README.md`: localizar arquivos, classes, hooks e
o fluxo real, e só então propor a implementação. O restante da arquitetura (plugin,
módulos, unificação de endpoints) pode ser desenhado em paralelo, pois não depende
desse ponto.
