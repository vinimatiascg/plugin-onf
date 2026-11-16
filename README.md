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
- **Varredura automática:** roda de hora em hora pelo WP-Cron do site principal.
- **Meta diária:** total de locais processados por dia na rede (padrão 100).

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
