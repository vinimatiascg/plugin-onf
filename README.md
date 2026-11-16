# Onde na Fronteira — Core Network Plugin (tf-core)

Plugin central da rede Multisite **Onde na Fronteira** (Foz do Iguaçu, Ciudad del Este e Puerto Iguazú):
CPTs, taxonomias, metacampos REST, deduplicação, ingestão, Schema.org, SEO técnico, llms.txt,
Google News Sitemap, Social Discovery, monetização e o **Alimentador de locais via Google Maps / Google Places**.

## Instalação

Copie esta pasta para `wp-content/plugins/tf-core` e ative em **Rede > Plugins** (plugin de rede).

## Alimentador Google Places (v1.2.0)

A varredura de estabelecimentos usa a **Google Places API (New)** diretamente em PHP:

1. **Varredura (Text Search):** para cada subsite de cidade, percorre em rodízio um catálogo de buscas
   (restaurantes, churrascarias, hotéis, shoppings, duty free, atrativos, passeios, casas de câmbio...)
   restritas a um retângulo de ~9 km ao redor do centro da cidade. Os `place_id` vão para a fila
   `{prefixo}_tf_places_queue` do subsite.
2. **Processamento (Place Details):** cada local da fila é detalhado (endereço, CEP, coordenadas,
   telefone, site, horários, faixa de preço, nota e nº de avaliações, link do Maps).
   - Locais de outro país (ex.: resultado paraguaio no subsite de Foz) são descartados.
   - Deduplicação por `_tf_google_place_id`, nome normalizado, telefone e proximidade.
   - **Novo local:** criado como `pending` (fila de moderação), com `_tf_verification_status = needs_review`.
   - **Local já cadastrado:** atualiza só dados vivos (nota, avaliações, horários, telefone, data de verificação);
     endereço, site e coordenadas só são preenchidos se estiverem vazios. Título, texto e status não são alterados.
   - Locais fechados permanentemente são marcados como `closed`.

### Configuração

Em **⚡ Alimentador Rede** (painel da rede):

- **Chave da API:** campo no painel, ou (recomendado) no `wp-config.php`:
  ```php
  define( 'TF_GOOGLE_PLACES_API_KEY', 'sua-chave' );
  ```
  Ative a *Places API (New)* no Google Cloud e restrinja a chave a essa API.
- **Varredura automática:** roda a cada 15 minutos pelo WP-Cron do site principal.
- **Meta diária:** total de locais detalhados por dia na rede (padrão 100). A cada rodada o plugin divide o que falta da meta pelas rodadas restantes até a meia-noite e reparte entre as cidades — distribuição uniforme, sem rajadas.

Os botões *Buscar Mais no Google Places* e *Processar Próximo Lote* executam uma rodada na hora.

O catálogo de buscas pode ser alterado pelo filtro `tf_google_places_search_catalog`.

### Custos

Cada página de busca = 1 chamada *Text Search*; cada local processado = 1 chamada *Place Details*.
Os campos pedidos (telefone, site, horários, avaliações) estão nas faixas Enterprise da API: acompanhe o faturamento no Google Cloud.

## Atualização pelo painel do WordPress (v1.3.0)

O plugin verifica sozinho se há versão nova no GitHub (`vinimatiascg/plugin-onf`, branch `main`):

- Lê o cabeçalho `Version:` do `tf-core.php` na branch `main` (ou a última *Release*, se tiver tag maior).
- Mostra o aviso nativo **"Há uma nova versão"** em *Rede > Plugins* e *Painel > Atualizações*, com o botão **Atualizar agora** do próprio WordPress.
- Página **⚡ Alimentador Rede > 🔄 Atualização GitHub**: versão instalada × GitHub, último commit, botões *Verificar atualização agora* e *Atualizar agora*.
- O ZIP baixado do GitHub é renomeado automaticamente para a pasta instalada (`tf-core`).
- Cache de 30 min; "Verificar novamente" em *Atualizações* força uma nova consulta.

**Para publicar uma atualização:** altere o código, aumente a versão em `tf-core.php` (cabeçalho `Version:` e `TF_CORE_VERSION`) e faça push na `main`.

**Repositório privado:** defina um token *Fine-grained* (Contents: Read-only) em `define( 'TF_CORE_GITHUB_TOKEN', '...' );` no `wp-config.php`, na variável de ambiente `TF_CORE_GITHUB_TOKEN` ou no campo da página.

## Otimizações da varredura (v1.4.0)

- **Duas etapas visíveis no painel:** *Descobertos no Google* (só o ID, aguardando detalhamento) e *Na Fila de Moderação* (rascunhos pendentes). Um local só aparece na moderação depois de detalhado.
- **Cobertura maior:** cada consulta roda numa **grade 3×3** sobre a cidade (até 60 resultados por célula, em vez de 60 por cidade) e o catálogo ganhou novas categorias (pizzarias, hamburguerias, peixarias, padarias, sorveterias, alta gastronomia, apart-hotéis, celulares, brinquedos, supermercados, parques temáticos, mirantes, farmácias, saúde, táxi).
- **Busca mais barata:** a descoberta pede só `places.id` (faixa *Text Search Essentials — IDs Only*); os dados completos vêm no Place Details.
- **Ritmo controlado:** cron de 15 min, meta diária distribuída, nova descoberta só quando a fila da cidade baixa de 80 itens, teto de 300 páginas de busca por cidade/dia.
- **Reverificação:** quando não há locais novos, os já processados há mais de 30 dias voltam para a fila (nota, avaliações, horários e telefone atualizados).
- **Painel:** progresso do dia, próxima rodada, números por cidade e motivos de descarte.
- **Desempenho:** contador da Fila de Aprovação com cache de 2 minutos (antes percorria todos os sites a cada tela do admin).

## Custo zero: limite mensal (v1.4.1)

- O detalhamento usa campos da faixa **Place Details Enterprise** (telefone, site, horários, nota, avaliações, preço): **1.000 chamadas grátis por mês**, depois US$ 20 por mil. O campo `editorialSummary` foi removido porque elevava cada chamada para *Enterprise + Atmosphere* (US$ 25 por mil).
- A descoberta usa **Text Search Essentials (IDs Only)**: gratuita e ilimitada.
- **Limite mensal** (padrão 1.000): o plugin conta os detalhamentos no mês de cobrança do Google (zera dia 1º, horário do Pacífico), espalha o saldo pelos dias restantes e pausa o detalhamento ao atingir o limite, inclusive nos botões manuais. Use 0 para desligar o limite.
- Meta diária padrão: 32 (32 × 31 = 992/mês).

## Fotos dos sites oficiais (v1.5.0)

Os termos do Google Maps Platform não permitem salvar fotos do Google Places, então a imagem destacada vem do **site oficial** de cada estabelecimento (o `websiteUri` obtido no Google):

- Lê a página inicial e procura, em ordem: `og:image:secure_url`, `og:image`, `twitter:image`, `<link rel="image_src">` e `image` do JSON-LD.
- Baixa, valida (JPG/PNG/WebP, mínimo 300×200 px, descarta ícones/SVG/GIF) e define como imagem destacada, com texto alternativo e o site como crédito (`_tf_image_credit_url`).
- Ignora redes sociais e encurtadores (Instagram, Facebook, TikTok, WhatsApp, Linktree, Google...).
- Roda junto do Alimentador (4 locais por cidade a cada 15 min) e pelo botão **📷 Buscar Fotos nos Sites Oficiais**. Sem custo de API do Google.
- Sites sem imagem aproveitável são verificados de novo após 30 dias; o painel mostra quantos locais têm foto, aguardam verificação, não têm imagem no site ou não têm site.
