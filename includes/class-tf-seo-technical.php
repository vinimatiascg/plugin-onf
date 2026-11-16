<?php
/**
 * SEO Técnico: Titles, Meta Descriptions, Canonicals, Paginação rel, Meta Robots,
 * Hreflang (pt-BR, es, en, x-default), Sitemap Index da Rede e robots.txt para IA.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_SEO_Technical {

	/**
	 * Registra todos os hooks de SEO técnico.
	 */
	public static function init(): void {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'filter_wp_robots' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'output_seo_head_tags' ), 5 );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_network_sitemap' ), 1 );

		// Remover canonical nativo para evitar duplicidade com o nosso canonical aware de i18n/filtros
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Gera Titles otimizados por template, CPT, cidade e idioma.
	 *
	 * @param string $title Title atual.
	 * @return string
	 */
	public static function filter_document_title( string $title ): string {
		$city = tf_get_current_city_context();
		$year = gmdate( 'Y' );

		if ( is_front_page() || is_home() ) {
			if ( 'raiz' === $city['slug'] ) {
				return "Onde na Fronteira — Guia de Foz do Iguaçu, Ciudad del Este e Puerto Iguazú ({$year})";
			}
			return "O que fazer, onde ficar, comer e comprar em {$city['name']} ({$year}) | Onde na Fronteira";
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$pt      = get_post_type( $post_id );
			$p_title = get_the_title( $post_id );

			if ( in_array( $pt, tf_get_establishment_post_types(), true ) ) {
				return "{$p_title} em {$city['name']}: Endereço, Horários e Dicas | Onde na Fronteira";
			}
			return "{$p_title} | Onde na Fronteira";
		}

		if ( is_post_type_archive() ) {
			if ( ! empty( $_GET['top100'] ) && 'cde' === $city['slug'] ) {
				return "Top 100 Lojas Mais Procuradas em Ciudad del Este ({$year}) | Onde na Fronteira";
			}
			$archive_name = post_type_archive_title( '', false );
			return "Melhores {$archive_name} em {$city['name']} ({$year}) — Guia Verificado | Onde na Fronteira";
		}

		if ( is_tax() || is_category() ) {
			$term_name = single_term_title( '', false );
			return "{$term_name} em {$city['name']} ({$year}) | Onde na Fronteira";
		}

		if ( is_search() ) {
			return sprintf( 'Busca por "%s" em %s | Onde na Fronteira', get_search_query(), $city['name'] );
		}

		return $title;
	}

	/**
	 * Define diretivas de indexação (noindex em buscas, 404, arquivos filtrados ou taxonomias vazias;
	 * max-image-preview:large para Google Discover nas páginas indexáveis).
	 *
	 * @param array $robots Diretivas atuais.
	 * @return array
	 */
	public static function filter_wp_robots( array $robots ): array {
		$has_filter_params = ! empty( $_GET['bairro'] ) || ! empty( $_GET['faixa_preco'] ) || ! empty( $_GET['comodidade'] ) || ! empty( $_GET['cat_slug'] );

		if ( is_search() || is_404() || $has_filter_params ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
			return $robots;
		}

		// Noindex em taxonomias ou arquivos vazios (páginas finas)
		if ( ( is_tax() || is_category() || is_tag() ) ) {
			$term = get_queried_object();
			if ( isset( $term->count ) && 0 === (int) $term->count ) {
				$robots['noindex'] = true;
				$robots['follow']  = true;
				return $robots;
			}
		}

		$robots['index']             = true;
		$robots['follow']            = true;
		$robots['max-image-preview'] = 'large';
		$robots['max-snippet']       = '-1';
		$robots['max-video-preview'] = '-1';

		return $robots;
	}

	/**
	 * Imprime meta description, canonical, rel prev/next e tags hreflang (pt-BR, es, en, x-default).
	 */
	public static function output_seo_head_tags(): void {
		$desc      = self::get_current_meta_description();
		$canonical = self::get_current_canonical_url();
		$lang      = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : 'pt';

		echo "\n<!-- Onde na Fronteira Technical SEO & Hreflang (tf-core) -->\n";
		if ( ! empty( $desc ) ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}

		if ( ! empty( $canonical ) ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
			echo '<link rel="alternate" hreflang="pt-BR" href="' . esc_url( $canonical ) . '" />' . "\n";
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $canonical ) . '" />' . "\n";
		}

		// Paginação rel="prev" e rel="next" em arquivos
		if ( is_archive() || is_home() ) {
			global $wp_query;
			$paged    = max( 1, (int) get_query_var( 'paged' ) );
			$max_page = (int) ( $wp_query->max_num_pages ?? 1 );
			if ( $paged > 1 ) {
				echo '<link rel="prev" href="' . esc_url( get_pagenum_link( $paged - 1 ) ) . '" />' . "\n";
			}
			if ( $paged < $max_page ) {
				echo '<link rel="next" href="' . esc_url( get_pagenum_link( $paged + 1 ) ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Gera a meta description única por página.
	 *
	 * @return string
	 */
	public static function get_current_meta_description(): string {
		$city = tf_get_current_city_context();
		$lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : 'pt';

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( 'es' === $lang ) {
				$i18n = json_decode( (string) get_post_meta( $post_id, '_tf_i18n_es', true ), true );
				if ( ! empty( $i18n['seo_desc'] ) ) {
					return wp_trim_words( $i18n['seo_desc'], 28 );
				}
			} elseif ( 'en' === $lang ) {
				$i18n = json_decode( (string) get_post_meta( $post_id, '_tf_i18n_en', true ), true );
				if ( ! empty( $i18n['seo_desc'] ) ) {
					return wp_trim_words( $i18n['seo_desc'], 28 );
				}
			}
			$direct = (string) get_post_meta( $post_id, '_tf_direct_answer', true );
			if ( ! empty( $direct ) ) {
				return wp_trim_words( wp_strip_all_tags( $direct ), 28 );
			}
			$excerpt = get_the_excerpt( $post_id );
			if ( ! empty( $excerpt ) ) {
				return wp_trim_words( wp_strip_all_tags( $excerpt ), 28 );
			}
		}

		if ( is_post_type_archive() ) {
			$pt_title = post_type_archive_title( '', false );
			return "Guia atualizado de {$pt_title} em {$city['name']} ({$city['country_name']}): endereços verificados, horários de funcionamento, faixa de preço e localização no mapa.";
		}

		if ( is_tax() || is_category() ) {
			$term_title = single_term_title( '', false );
			return "Confira os melhores estabelecimentos em {$term_title} ({$city['name']}) com endereço, telefone, horários e dicas verificadas no Onde na Fronteira.";
		}

		return get_bloginfo( 'description' );
	}

	/**
	 * Retorna a URL canônica limpa (sem parâmetros de filtro ou tracking).
	 *
	 * @return string
	 */
	public static function get_current_canonical_url(): string {
		if ( is_front_page() || is_home() ) {
			return home_url( '/' );
		}
		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}
		if ( is_post_type_archive() ) {
			$pt = get_query_var( 'post_type' );
			if ( is_array( $pt ) ) {
				$pt = reset( $pt );
			}
			return (string) get_post_type_archive_link( $pt );
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			$link = get_term_link( $term );
			return ! is_wp_error( $link ) ? (string) $link : home_url( '/' );
		}
		return home_url( '/' );
	}

	/**
	 * Gera o robots.txt por subsite com política explícita para rastreadores de IA (GEO) e sitemaps da rede.
	 *
	 * @param string $output Conteúdo padrão.
	 * @param bool   $public Se o site é público.
	 * @return string
	 */
	public static function filter_robots_txt( string $output, $public ): string {
		$net_home = network_home_url( '/' );
		$sub_home = home_url( '/' );

		$lines = array(
			'# robots.txt — Onde na Fronteira (ondenafronteira.com.br)',
			'# Politica GEO: Rastreadores de busca por IA permitidos para citacao e indexacao.',
			'User-agent: *',
			'Allow: /',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /*?s=',
			'Disallow: /search/',
			'Disallow: /*?bairro=',
			'Disallow: /*?faixa_preco=',
			'',
			'# AI & Generative Search Crawlers (Allowed)',
			'User-agent: OAI-SearchBot',
			'Allow: /',
			'',
			'User-agent: GPTBot',
			'Allow: /',
			'',
			'User-agent: ChatGPT-User',
			'Allow: /',
			'',
			'User-agent: ClaudeBot',
			'Allow: /',
			'',
			'User-agent: PerplexityBot',
			'Allow: /',
			'',
			'User-agent: Google-Extended',
			'Allow: /',
			'',
			'User-agent: Googlebot-News',
			'Allow: /',
			'',
			'# Sitemaps & LLMs Index',
			'Sitemap: ' . $net_home . 'network-sitemap.xml',
			'Sitemap: ' . $sub_home . 'wp-sitemap.xml',
			'Sitemap: ' . $sub_home . 'news-sitemap.xml',
			'# LLMs Context: ' . $net_home . 'llms.txt',
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Atende /network-sitemap.xml e subsite /robots.txt.
	 */
	public static function serve_network_sitemap(): void {
		$uri = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( is_string( $uri ) && preg_match( '#/robots\.txt$#', $uri ) ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo self::filter_robots_txt( '', true );
			exit;
		}
		if ( ! is_string( $uri ) || ! preg_match( '#/network-sitemap\.xml$#', $uri ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		$sites = is_multisite() ? get_sites( array( 'number' => 20 ) ) : array();
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		if ( ! empty( $sites ) ) {
			foreach ( $sites as $site ) {
				$s_url = get_site_url( (int) $site->blog_id, '/' );
				echo "\t<sitemap>\n";
				echo "\t\t<loc>" . esc_url( $s_url . 'wp-sitemap.xml' ) . "</loc>\n";
				echo "\t\t<lastmod>" . esc_html( gmdate( DATE_W3C ) ) . "</lastmod>\n";
				echo "\t</sitemap>\n";
				echo "\t<sitemap>\n";
				echo "\t\t<loc>" . esc_url( $s_url . 'news-sitemap.xml' ) . "</loc>\n";
				echo "\t\t<lastmod>" . esc_html( gmdate( DATE_W3C ) ) . "</lastmod>\n";
				echo "\t</sitemap>\n";
			}
		} else {
			echo "\t<sitemap><loc>" . esc_url( home_url( '/wp-sitemap.xml' ) ) . "</loc></sitemap>\n";
		}

		echo "</sitemapindex>\n";
		exit;
	}
}
