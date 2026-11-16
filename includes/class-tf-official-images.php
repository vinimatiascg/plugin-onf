<?php
/**
 * Fotos dos sites oficiais dos estabelecimentos.
 *
 * As fotos do Google Places não podem ser armazenadas (termos do Google Maps Platform),
 * então a imagem destacada vem do SITE OFICIAL do estabelecimento (websiteUri obtido no
 * Google): og:image, twitter:image, image_src ou "image" do JSON-LD da página inicial.
 *
 * Roda em lotes pequenos junto do Alimentador (cron de 15 min) e pelo botão do painel.
 * Cada local é verificado no máximo uma vez a cada 30 dias, sem custo de API do Google.
 *
 * @package TF_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Official_Images {

	const CHECKED_META   = '_tf_image_checked_at';
	const RESULT_META    = '_tf_image_check_result';
	const RECHECK_DAYS   = 30;
	const MIN_WIDTH      = 300;
	const MIN_HEIGHT     = 200;
	const MAX_HTML_BYTES = 1572864; // 1,5 MB

	/**
	 * Hosts que não servem como fonte (redes sociais exigem login ou geram URLs temporárias).
	 */
	const BLOCKED_HOSTS = array( 'instagram.com', 'facebook.com', 'fb.com', 'fbcdn.net', 'tiktok.com', 'wa.me', 'whatsapp.com', 'linktr.ee', 'google.com', 'goo.gl', 'g.page', 'business.site', 'youtube.com', 'twitter.com', 'x.com' );

	/**
	 * Processa até $limit estabelecimentos sem foto do subsite atual.
	 *
	 * @return array{found:int, checked:int}
	 */
	public static function backfill( int $limit ): array {
		$out = array( 'found' => 0, 'checked' => 0 );
		if ( $limit <= 0 ) {
			return $out;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RECHECK_DAYS * DAY_IN_SECONDS );
		$ids    = get_posts(
			array(
				'post_type'      => tf_get_establishment_post_types(),
				'post_status'    => array( 'publish', 'pending', 'draft', 'future' ),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_tf_website', 'value' => '', 'compare' => '!=' ),
					array(
						'relation' => 'OR',
						array( 'key' => self::CHECKED_META, 'compare' => 'NOT EXISTS' ),
						array( 'key' => self::CHECKED_META, 'value' => $cutoff, 'compare' => '<', 'type' => 'DATETIME' ),
					),
				),
			)
		);

		foreach ( $ids as $post_id ) {
			$result = self::fetch_for_post( (int) $post_id );
			$out['checked']++;
			if ( 'ok' === $result ) {
				$out['found']++;
			}
		}
		return $out;
	}

	/**
	 * Tenta definir a imagem destacada de um post a partir do site oficial.
	 *
	 * @return string Resultado: ok | sem_site | site_bloqueado | site_indisponivel | sem_imagem | imagem_invalida
	 */
	public static function fetch_for_post( int $post_id ): string {
		$result = self::try_fetch( $post_id );
		update_post_meta( $post_id, self::CHECKED_META, gmdate( 'Y-m-d H:i:s' ) );
		update_post_meta( $post_id, self::RESULT_META, $result );
		return $result;
	}

	private static function try_fetch( int $post_id ): string {
		if ( has_post_thumbnail( $post_id ) ) {
			return 'ok';
		}
		$site = esc_url_raw( (string) get_post_meta( $post_id, '_tf_website', true ) );
		if ( '' === $site || ! wp_http_validate_url( $site ) ) {
			return 'sem_site';
		}
		if ( self::is_blocked_host( $site ) ) {
			return 'site_bloqueado';
		}

		$response = wp_safe_remote_get(
			$site,
			array(
				'timeout'             => 8,
				'redirection'         => 4,
				'limit_response_size' => self::MAX_HTML_BYTES,
				'user-agent'          => 'Mozilla/5.0 (compatible; OndeNaFronteiraBot/1.0; +' . home_url( '/' ) . ')',
				'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'site_indisponivel';
		}

		$html       = (string) wp_remote_retrieve_body( $response );
		$candidates = self::extract_image_urls( $html, $site );
		if ( empty( $candidates ) ) {
			return 'sem_imagem';
		}

		$title = get_the_title( $post_id );
		foreach ( array_slice( $candidates, 0, 4 ) as $img_url ) {
			if ( self::sideload( $post_id, $img_url, $site, $title ) ) {
				return 'ok';
			}
		}
		return 'imagem_invalida';
	}

	private static function is_blocked_host( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( self::BLOCKED_HOSTS as $blocked ) {
			if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extrai URLs de imagem de capa do HTML, em ordem de preferência.
	 *
	 * @return string[]
	 */
	public static function extract_image_urls( string $html, string $base ): array {
		$urls = array();
		$head = substr( $html, 0, 400000 );

		// 1. <meta property="og:image" ...> / twitter:image (atributos em qualquer ordem).
		if ( preg_match_all( '/<meta\b[^>]*>/i', $head, $metas ) ) {
			$priority = array( 'og:image:secure_url' => 0, 'og:image' => 1, 'og:image:url' => 1, 'twitter:image' => 2, 'twitter:image:src' => 2 );
			$found    = array();
			foreach ( $metas[0] as $tag ) {
				$key = self::attr( $tag, 'property' ) ?: self::attr( $tag, 'name' ) ?: self::attr( $tag, 'itemprop' );
				$key = strtolower( trim( (string) $key ) );
				if ( 'image' === $key ) {
					$key = 'twitter:image';
				}
				if ( isset( $priority[ $key ] ) ) {
					$content = self::attr( $tag, 'content' );
					if ( $content ) {
						$found[] = array( $priority[ $key ], $content );
					}
				}
			}
			usort( $found, static fn( $a, $b ) => $a[0] <=> $b[0] );
			foreach ( $found as $f ) {
				$urls[] = $f[1];
			}
		}

		// 2. <link rel="image_src" href="...">
		if ( preg_match_all( '/<link\b[^>]*>/i', $head, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( 'image_src' === strtolower( (string) self::attr( $tag, 'rel' ) ) && self::attr( $tag, 'href' ) ) {
					$urls[] = self::attr( $tag, 'href' );
				}
			}
		}

		// 3. JSON-LD "image" (LocalBusiness, Restaurant, Hotel...).
		if ( preg_match_all( '/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				$data = json_decode( html_entity_decode( trim( $json ) ), true );
				if ( is_array( $data ) ) {
					self::collect_jsonld_images( $data, $urls );
				}
			}
		}

		$clean = array();
		foreach ( $urls as $u ) {
			$abs = self::absolute_url( html_entity_decode( trim( (string) $u ) ), $base );
			if ( $abs && ! preg_match( '/\.(svg|ico|gif)(\?|$)/i', $abs ) && ! in_array( $abs, $clean, true ) ) {
				$clean[] = $abs;
			}
		}
		return $clean;
	}

	/**
	 * Coleta "image" / "contentUrl" / "url" de imagens no JSON-LD (string, lista ou ImageObject).
	 *
	 * @param mixed    $node JSON decodificado.
	 * @param string[] $urls Acumulador.
	 */
	private static function collect_jsonld_images( $node, array &$urls, bool $in_image = false ): void {
		if ( is_string( $node ) ) {
			if ( $in_image ) {
				$urls[] = $node;
			}
			return;
		}
		if ( ! is_array( $node ) ) {
			return;
		}
		foreach ( $node as $key => $value ) {
			$is_image_key = in_array( $key, array( 'image', 'contentUrl', 'thumbnailUrl' ), true )
				|| ( $in_image && 'url' === $key );
			$child_in_image = $is_image_key || ( $in_image && is_int( $key ) );
			self::collect_jsonld_images( $value, $urls, $child_in_image );
		}
	}

	private static function attr( string $tag, string $name ): string {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $m ) ) {
			return (string) ( $m[2] ?? '' ) . ( $m[3] ?? '' ) . ( $m[4] ?? '' );
		}
		return '';
	}

	private static function absolute_url( string $url, string $base ): string {
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( ! preg_match( '#^https?://#i', $url ) ) {
			$parts  = wp_parse_url( $base );
			$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			if ( 0 === strpos( $url, '/' ) ) {
				$url = $origin . $url;
			} else {
				$dir = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
				$url = $origin . ( $dir ?: '/' ) . $url;
			}
		}
		return wp_http_validate_url( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Baixa, valida (tipo e tamanho mínimo) e define como imagem destacada.
	 */
	private static function sideload( int $post_id, string $img_url, string $site, string $title ): bool {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $img_url, 12 );
		if ( is_wp_error( $tmp ) ) {
			return false;
		}

		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
		$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		if ( ! isset( $exts[ $mime ] ) || $info[0] < self::MIN_WIDTH || $info[1] < self::MIN_HEIGHT ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		$att_id = media_handle_sideload(
			array(
				'name'     => sanitize_title( $title ) . '-site-oficial.' . $exts[ $mime ],
				'tmp_name' => $tmp,
			),
			$post_id,
			$title
		);
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}

		set_post_thumbnail( $post_id, (int) $att_id );
		update_post_meta( (int) $att_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );
		update_post_meta( (int) $att_id, '_tf_image_credit_url', esc_url_raw( $site ) );
		update_post_meta( $post_id, '_tf_official_image_src', esc_url_raw( $img_url ) );
		return true;
	}

	/**
	 * Estatísticas da rede: com foto, sem site, aguardando verificação etc.
	 *
	 * @return array<string,int>
	 */
	public static function get_stats(): array {
		global $wpdb;
		$stats = array( 'with_image' => 0, 'pending_check' => 0, 'no_website' => 0, 'not_found' => 0 );
		TF_Google_Places::for_each_city_site(
			function () use ( &$stats, $wpdb ) {
				$types = "'" . implode( "','", array_map( 'esc_sql', tf_get_establishment_post_types() ) ) . "'";
				$base  = "FROM {$wpdb->posts} p WHERE p.post_type IN ({$types}) AND p.post_status IN ('publish','pending','draft','future')";
				$thumb = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id')";
				$site  = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_tf_website' AND m.meta_value <> '')";
				$chk   = "EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '" . self::CHECKED_META . "')";
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stats['with_image']    += (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND {$thumb}" );
				$stats['no_website']    += (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND NOT {$thumb} AND NOT {$site}" );
				$stats['pending_check'] += (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND NOT {$thumb} AND {$site} AND NOT {$chk}" );
				$stats['not_found']     += (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND NOT {$thumb} AND {$site} AND {$chk}" );
				// phpcs:enable
			}
		);
		return $stats;
	}
}
