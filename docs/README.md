# Base de Conhecimento — projeto-conversa

Chat "real-time" construído sobre **Crocoblock JetEngine + JetFormBuilder + Elementor/Elementor Pro**,
dentro do WordPress.

Esta pasta `docs/` é a **fonte única de verdade documental** do projeto. Ela consolida:

- o entendimento do código atual (os 6 arquivos hoje ativos via WPCode);
- os comentários que já existiam espalhados pelos arquivos;
- **a correlação comprovada** com a raiz dos plugins (repositório `core-plugins`);
- o histórico do maior ponto de conflito (renderização real-time do listing);
- as diretrizes para a próxima versão do projeto.

> **Princípio herdado do `core-plugins/README.md`:** o código é a fonte da verdade.
> Nada aqui foi assumido por "conhecimento geral de WordPress". Toda afirmação sobre
> comportamento de plugin está ancorada em `arquivo:linha` do repositório `core-plugins`
> (versões: JetEngine 3.8.10.1, JetFormBuilder 3.6.1.1, Elementor 4.1.4, Elementor Pro 4.1.2).

---

## Índice

| Documento | Conteúdo |
|-----------|----------|
| [01 — Arquitetura e Modelo de Dados](01-arquitetura.md) | CPT `conversa`, CCT `mensagens_`, metafields, o fluxo entrada→processamento→saída, e o template Elementor da single |
| [02 — Correlação entre Plugins (evidências)](02-correlacao-plugins.md) | Onde exatamente JetFormBuilder, JetEngine (CCT + Listing Grid) e Elementor se conectam, com `arquivo:linha` |
| [03 — Anatomia dos 6 Arquivos](03-anatomia-dos-arquivos.md) | Responsabilidade, hooks e API pública de cada arquivo hoje ativo |
| [04 — O Problema do Real-Time e o Mirror Renderer](04-realtime-e-mirror-renderer.md) | Por que o real-time foi difícil, o que é o "custom renderer" e por que ele quebra o princípio fundamental |
| [05 — Achados e Inconsistências](05-achados-e-inconsistencias.md) | Divergências entre o código do projeto e a raiz real dos plugins (ex.: hook inexistente) |
| [06 — Diretrizes para a Nova Versão](06-diretrizes-nova-versao.md) | Princípios de arquitetura para reescrever o projeto integrado à raiz dos plugins, sem WPCode e sem engessar |
| [07 — Plugin `conversa-chat`](07-plugin-conversa-chat.md) | **A nova versão implementada**: arquitetura do plugin, mapeamento nativo por necessidade, fluxo do tempo real e migração |
| [08 — Render incremental, limpeza e performance (1.0.1 / 1.0.2)](08-render-incremental-e-performance.md) | Correções pós-teste real: assets no incremental (primeiro item "pelado"), limpeza do textarea e o carregamento inicial "últimas N mensagens" resolvido no código (hooks nativos da Query), mantendo a CCT Query |

---

## Estado atual (resumo executivo)

- **O que é:** uma página de conversa 1‑a‑1 (artista ↔ convidado). O usuário envia
  mensagens por um formulário JetFormBuilder; as mensagens são gravadas num
  **Custom Content Type (CCT)** do JetEngine e exibidas por um **Listing Grid** do
  JetEngine dentro de um template single do Elementor.
- **Como roda hoje:** 6 snippets PHP inseridos e ativados **um a um via WPCode**
  (plugin de code-snippets). Não é um plugin próprio, não tem autoload, não tem
  ordem de carregamento garantida além da ordem de ativação no WPCode.
- **Origem do débito técnico:** o projeto **não nasceu** como chat em tempo real.
  A estrutura (CPT, CCT, metafields, single) foi feita só para o usuário **enviar**
  a mensagem. O real-time foi acoplado depois, por cima — daí a natureza de
  "gambiarra funcional".
- **Maior conflito:** conciliar o **envio** da mensagem com a **renderização
  incremental** ("aparecer a mensagem nova assim que enviada", sem recarregar a
  página e sem reconstruir a lista inteira). Ver [documento 04](04-realtime-e-mirror-renderer.md).

## Objetivo da fase atual

> Fase de **planejamento e entendimento**. Nenhum código novo de implementação.
> O entregável é **esta documentação**.

O objetivo declarado para depois é **iniciar uma nova versão** do projeto,
fundamentada na raiz do código do JetEngine, Elementor, Elementor Pro e
JetFormBuilder em **todas as etapas**, removendo a dependência do WPCode e sem
"engessar" (ver o princípio fundamental abaixo).

## O Princípio Fundamental (declarado pelo autor)

> Utilizar as ferramentas já existentes, **integrar**, e não fazer as coisas
> parecerem um "remendo". Se eu precisar alterar o listing, incluir uma alteração
> no layout, isso não pode quebrar tudo. Se eu adicionar no CCT um metafield
> `image` para o usuário enviar uma imagem além do texto — deve ser possível sem
> quebrar tudo. Poder ter layouts diferentes, ou ligar/desligar o real-time.
> **O código não pode tornar tudo engessado, sem possibilidade de customização.**

Este princípio é o critério de aceitação de qualquer decisão de arquitetura daqui
para frente. Ele é o fio condutor do [documento 06](06-diretrizes-nova-versao.md).

---

## Glossário rápido

| Termo | Significado no projeto |
|-------|------------------------|
| **CPT `conversa`** | Custom Post Type que representa uma conversa (o "quarto" do chat). Post ID de exemplo citado pelo autor: `670`. |
| **CCT `mensagens_`** | Custom Content Type do JetEngine (armazenamento em tabela própria `wp_jet_cct_mensagens_`). Cada linha é uma mensagem. |
| **Listing 56326** | JetEngine Listing Grid que renderiza as mensagens dentro de `#section-msgs-conversa`. É a **fonte visual da verdade** dos cards de mensagem. |
| **Form 56386** | Formulário JetFormBuilder (widget `jet-engine-booking-form`) no `#footer-conversa`, usado como composer de mensagem. |
| **Composer** | O formulário estilizado como caixa de digitação de chat. |
| **Runtime** | O JavaScript do `conversa-v4.1` que faz polling, tab-lock e a renderização incremental. |
| **Mirror / Custom Renderer** | Técnica do v4.1 de **clonar** um card já renderizado pelo Listing e trocar os campos — em vez de recarregar a lista. É o ponto que quebra o princípio fundamental. |
