<?php
/**
 * Integração Avançada de Sitemaps com Yoast SEO para Rede Multisite — Onde na Fronteira.
 *
 * Garante que:
 * 1. Todos os estabelecimentos (hotéis, lojas, restaurantes, atrativos, passeios, serviços)
 *    de Foz do Iguaçu, Ciudad del Este e Puerto Iguazú apareçam no sitemap_index.xml do Yoast;
 * 2. Disponibiliza o sitemap unificado /empreendimentos-sitemap.xml contendo todos os 209+ locais;
 * 3. Preenche os sitemaps de CPTs no domínio raiz (/hotel-sitemap.xml, /loja-sitemap.xml, etc.)
 *    com os registros agregados de todas as cidades;
 * 4. Inclui metadados de imagens (Google Image Sitemap) para indexação rica no Google Imagens.
 *
 * @package TF_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Yoast_Sitemaps {

	/**
	 * Inicializa hooks do Yoast SEO e interceptador de sitemaps.
	 */
	public static function init(): void {
		add_filter( 'wpseo_sitemap_index_links', array( __CLASS__, 'filter_sitemap_index_links' ), 20 );
		add_filter( 'wpseo_sitemap_index', array( __CLASS__, 'filter_sitemap_index_xml' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_custom_sitemaps' ), 1 );
	}

	/**
	 * Filtra o array de sitemaps do Yoast SEO no sitemap_index.xml raiz.
	 *
	 * @param array $links Lista de sitemaps incluídos pelo Yoast.
	 * @return array
	 */
	public static function filter_sitemap_index_links( array $links ): array {
		if ( ! is_multisite() || get_current_blog_id() !== 1 ) {
			return $links;
		}

		$cpts = tf_get_establishment_post_types();
		$net_home = network_home_url( '/' );
		$now_iso = gmdate( DATE_W3C );

		// 1. Adicionar o Sitemap Unificado de Todos os Empreendimentos
		$links[] = array(
			'loc'     => $net_home . 'empreendimentos-sitemap.xml',
			'lastmod' => $now_iso,
		);

		// 2. Adicionar os sitemaps agregados por tipo no domínio raiz
		foreach ( $cpts as $cpt ) {
			$links[] = array(
				'loc'     => $net_home . $cpt . '-sitemap.xml',
				'lastmod' => $now_iso,
			);
		}

		// 3. Adicionar os sitemaps de cada cidade / subsite
		$sites = get_sites( array( 'number' => 10 ) );
		foreach ( $sites as $site ) {
			$b_id = (int) $site->blog_id;
			if ( 1 === $b_id ) {
				continue;
			}

			$sub_url = get_site_url( $b_id, '/' );
			// Sitemap Index da Cidade
			$links[] = array(
				'loc'     => $sub_url . 'sitemap_index.xml',
				'lastmod' => $now_iso,
			);

			// Sitemaps específicos de CPT da cidade
			switch_to_blog( $b_id );
			foreach ( $cpts as $cpt ) {
				$counts = wp_count_posts( $cpt );
				if ( ( $counts->publish ?? 0 ) > 0 ) {
					$links[] = array(
						'loc'     => $sub_url . $cpt . '-sitemap.xml',
						'lastmod' => $now_iso,
					);
				}
			}
			restore_current_blog();
		}

		// Remover duplicatas de 'loc'
		$seen = array();
		$unique_links = array();
		foreach ( $links as $l ) {
			$loc = $l['loc'] ?? '';
			if ( ! empty( $loc ) && ! isset( $seen[ $loc ] ) ) {
				$seen[ $loc ] = true;
				$unique_links[] = $l;
			}
		}

		return $unique_links;
	}

	/**
	 * Fallback direto de string XML para garantir presença mesmo se o provider for reescrito.
	 *
	 * @param string $xml XML adicional do índice.
	 * @return string
	 */
	public static function filter_sitemap_index_xml( string $xml ): string {
		if ( ! is_multisite() || get_current_blog_id() !== 1 ) {
			return $xml;
		}

		$net_home = network_home_url( '/' );
		$now_iso  = gmdate( DATE_W3C );

		$append = '';
		$append .= "\t<sitemap>\n\t\t<loc>" . esc_url( $net_home . 'empreendimentos-sitemap.xml' ) . "</loc>\n\t\t<lastmod>" . esc_html( $now_iso ) . "</lastmod>\n\t</sitemap>\n";

		return $xml . $append;
	}

	/**
	 * Atende as requisições de sitemaps XML customizados ou agregados.
	 */
	public static function serve_custom_sitemaps(): void {
		$uri = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! is_string( $uri ) ) {
			return;
		}

		$cpts = tf_get_establishment_post_types();

		// A. Sitemap Unificado de Todos os Empreendimentos: /empreendimentos-sitemap.xml
		if ( preg_match( '#/empreendimentos-sitemap\.xml$#', $uri ) ) {
			self::render_all_establishments_sitemap();
			exit;
		}

		// B. Sitemaps de CPT agregados no domínio raiz: /{cpt}-sitemap.xml
		if ( get_current_blog_id() === 1 ) {
			foreach ( $cpts as $cpt ) {
				if ( preg_match( '#/' . preg_quote( $cpt, '#' ) . '-sitemap\.xml$#', $uri ) ) {
					self::render_aggregated_cpt_sitemap( $cpt );
					exit;
				}
			}
		}
	}

	/**
	 * Renderiza o sitemap completo com todos os 209+ estabelecimentos da Tríplice Fronteira.
	 */
	public static function render_all_establishments_sitemap(): void {
		$cpts  = tf_get_establishment_post_types();
		$sites = is_multisite() ? get_sites( array( 'number' => 10 ) ) : array( (object) array( 'blog_id' => 1 ) );
		$items = array();

		foreach ( $sites as $site ) {
			$b_id = (int) $site->blog_id;
			if ( is_multisite() ) {
				switch_to_blog( $b_id );
			}

			$query = new WP_Query(
				array(
					'post_type'      => $cpts,
					'post_status'    => 'publish',
					'posts_per_page' => 400,
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $p ) {
				$thumb_id  = get_post_thumbnail_id( $p->ID );
				$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '';
				$logo_url  = (string) get_post_meta( $p->ID, '_tf_logo_url', true );

				$items[] = array(
					'loc'       => get_permalink( $p->ID ),
					'lastmod'   => get_the_modified_date( 'c', $p->ID ),
					'title'     => get_the_title( $p->ID ),
					'image'     => $thumb_url ?: $logo_url,
				);
			}

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		self::output_urlset_xml( $items, 'Empreendimentos da Tríplice Fronteira' );
	}

	/**
	 * Renderiza sitemap agregado de um CPT específico com dados de todas as cidades.
	 *
	 * @param string $cpt Nome do Post Type (hotel, loja, restaurante, etc.).
	 */
	public static function render_aggregated_cpt_sitemap( string $cpt ): void {
		$sites = is_multisite() ? get_sites( array( 'number' => 10 ) ) : array( (object) array( 'blog_id' => 1 ) );
		$items = array();

		foreach ( $sites as $site ) {
			$b_id = (int) $site->blog_id;
			if ( is_multisite() ) {
				switch_to_blog( $b_id );
			}

			$query = new WP_Query(
				array(
					'post_type'      => $cpt,
					'post_status'    => 'publish',
					'posts_per_page' => 400,
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $p ) {
				$thumb_id  = get_post_thumbnail_id( $p->ID );
				$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'full' ) : '';
				$logo_url  = (string) get_post_meta( $p->ID, '_tf_logo_url', true );

				$items[] = array(
					'loc'       => get_permalink( $p->ID ),
					'lastmod'   => get_the_modified_date( 'c', $p->ID ),
					'title'     => get_the_title( $p->ID ),
					'image'     => $thumb_url ?: $logo_url,
				);
			}

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		self::output_urlset_xml( $items, ucfirst( $cpt ) . ' — Tríplice Fronteira' );
	}

	/**
	 * Gera a saída XML válida com cabeçalhos e suporte a Google Images.
	 *
	 * @param array<array> $items Lista de itens do sitemap.
	 * @param string       $label Rótulo do sitemap.
	 */
	public static function output_urlset_xml( array $items, string $label ): void {
		status_header( 200 );
		header( 'Content-Type: text/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );

		$xsl_url = home_url( '/wp-content/plugins/wordpress-seo/css/main-sitemap.xsl' );

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( $xsl_url ) . '"?>' . "\n";
		echo '<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-image/1.1 http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $items as $item ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $item['loc'] ) . "</loc>\n";
			if ( ! empty( $item['lastmod'] ) ) {
				echo "\t\t<lastmod>" . esc_html( $item['lastmod'] ) . "</lastmod>\n";
			}
			if ( ! empty( $item['image'] ) ) {
				echo "\t\t<image:image>\n";
				echo "\t\t\t<image:loc>" . esc_url( $item['image'] ) . "</image:loc>\n";
				if ( ! empty( $item['title'] ) ) {
					echo "\t\t\t<image:title>" . esc_html( $item['title'] ) . "</image:title>\n";
				}
				echo "\t\t</image:image>\n";
			}
			echo "\t</url>\n";
		}

		echo '</urlset>' . "\n";
		echo '<!-- XML Sitemap generated by Onde na Fronteira / Yoast SEO Integration (' . esc_html( $label ) . ' - Total: ' . count( $items ) . ') -->';
		exit;
	}
}
