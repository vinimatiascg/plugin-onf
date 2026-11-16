<?php
/**
 * Google News Sitemap (/news-sitemap.xml) contendo apenas publicações das últimas 48 horas,
 * conforme diretrizes do Google News / Publisher Center.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_News_Sitemap {

	/**
	 * Registra hook.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'serve_news_sitemap' ), 1 );
	}

	/**
	 * Renderiza /news-sitemap.xml com os artigos publicados nas últimas 48h.
	 */
	public static function serve_news_sitemap(): void {
		$uri = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! is_string( $uri ) || ! preg_match( '#/news-sitemap\.xml$#', $uri ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=600' );

		$city_ctx      = tf_get_current_city_context();
		$blogs_to_scan = ( 'raiz' === $city_ctx['slug'] && is_multisite() )
			? wp_list_pluck( get_sites( array( 'number' => 10 ) ), 'blog_id' )
			: array( get_current_blog_id() );

		$cutoff_gmt = gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) );
		$items      = array();

		foreach ( $blogs_to_scan as $b_id ) {
			switch_to_blog( (int) $b_id );
			$q = new WP_Query(
				array(
					'post_type'      => array( 'post' ),
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'date_query'     => array(
						array(
							'column' => 'post_date_gmt',
							'after'  => $cutoff_gmt,
						),
					),
					'no_found_rows'  => true,
				)
			);

			foreach ( $q->posts as $p ) {
				$items[] = array(
					'loc'      => get_permalink( $p->ID ),
					'pub_name' => 'Onde na Fronteira',
					'lang'     => 'pt',
					'pub_date' => get_the_date( DATE_W3C, $p->ID ),
					'title'    => get_the_title( $p->ID ),
				);
			}
			restore_current_blog();
		}

		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $items as $it ) {
			echo "\t<url>\n";
			echo "\t\t<loc>" . esc_url( $it['loc'] ) . "</loc>\n";
			echo "\t\t<news:news>\n";
			echo "\t\t\t<news:publication>\n";
			echo "\t\t\t\t<news:name>" . esc_xml( $it['pub_name'] ) . "</news:name>\n";
			echo "\t\t\t\t<news:language>" . esc_xml( $it['lang'] ) . "</news:language>\n";
			echo "\t\t\t</news:publication>\n";
			echo "\t\t\t<news:publication_date>" . esc_html( $it['pub_date'] ) . "</news:publication_date>\n";
			echo "\t\t\t<news:title>" . esc_xml( $it['title'] ) . "</news:title>\n";
			echo "\t\t</news:news>\n";
			echo "\t</url>\n";
		}

		echo "</urlset>\n";
		exit;
	}
}
