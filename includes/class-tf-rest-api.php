<?php
/**
 * Endpoints REST API customizados para o Agente de IA, Mapa Leaflet e Visão da Rede.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_REST_API {

	/**
	 * Registra hooks REST.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registra rotas sob o namespace tf/v1.
	 */
	public static function register_routes(): void {
		// 1. Visão geral da rede e contadores por subsite
		register_rest_route(
			'tf/v1',
			'/network-overview',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_network_overview' ),
			)
		);

		// 2. Pins leves para o mapa interativo (Leaflet + OpenStreetMap)
		register_rest_route(
			'tf/v1',
			'/map-pins',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_map_pins' ),
			)
		);

		// 3. Verificação de duplicidade (nome + endereço + telefone + coordenadas)
		register_rest_route(
			'tf/v1',
			'/check-duplicate',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => array( __CLASS__, 'check_duplicate' ),
			)
		);

		// 4. Ingestão inteligente do pipeline IA (respeita trava de revisão/confiança >= 85)
		register_rest_route(
			'tf/v1',
			'/ingest',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => array( __CLASS__, 'ingest_establishment' ),
			)
		);
	}

	/**
	 * Retorna resumo dos subsites da rede e contagem de registros publicados/rascunhos.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_network_overview(): WP_REST_Response {
		$sites = get_sites( array( 'number' => 20 ) );
		$data  = array();
		$cpts  = tf_get_establishment_post_types();

		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			switch_to_blog( $blog_id );
			$counts = array();
			foreach ( $cpts as $cpt ) {
				$c = wp_count_posts( $cpt );
				$counts[ $cpt ] = array(
					'publish' => (int) ( $c->publish ?? 0 ),
					'draft'   => (int) ( $c->draft ?? 0 ),
					'pending' => (int) ( $c->pending ?? 0 ),
				);
			}
			$city = tf_get_current_city_context();
			$data[] = array(
				'blog_id' => $blog_id,
				'name'    => get_bloginfo( 'name' ),
				'url'     => home_url( '/' ),
				'path'    => $site->path,
				'city'    => $city,
				'counts'  => $counts,
			);
			restore_current_blog();
		}

		return new WP_REST_Response(
			array(
				'network' => 'Onde na Fronteira',
				'sites'   => $data,
			),
			200
		);
	}

	/**
	 * Retorna os pins georreferenciados do subsite atual (ou de toda a rede se na raiz).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_map_pins( WP_REST_Request $request ): WP_REST_Response {
		$cpt_filter = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$cpts       = $cpt_filter && in_array( $cpt_filter, tf_get_establishment_post_types(), true )
			? array( $cpt_filter )
			: tf_get_establishment_post_types();

		$city_ctx = tf_get_current_city_context();
		$pins     = array();

		$blogs_to_query = ( 'raiz' === $city_ctx['slug'] && is_multisite() )
			? wp_list_pluck( get_sites( array( 'number' => 10 ) ), 'blog_id' )
			: array( get_current_blog_id() );

		foreach ( $blogs_to_query as $b_id ) {
			switch_to_blog( (int) $b_id );
			$b_city = tf_get_current_city_context();
			$query  = new WP_Query(
				array(
					'post_type'      => $cpts,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $p ) {
				$lat = (float) get_post_meta( $p->ID, '_tf_lat', true );
				$lng = (float) get_post_meta( $p->ID, '_tf_lng', true );
				if ( 0.0 === $lat && 0.0 === $lng ) {
					continue;
				}
				// Validação geográfica: apenas coordenadas legítimas na região da Tríplice Fronteira.
				// Impede que dados externos (ex: Costa Rica ou outras cidades) distorçam os limites do mapa.
				if ( $lat < -26.5 || $lat > -25.0 || $lng < -55.5 || $lng > -54.0 ) {
					continue;
				}
				$pins[] = array(
					'id'          => $p->ID,
					'blog_id'     => (int) $b_id,
					'city'        => $b_city['name'],
					'city_slug'   => $b_city['slug'],
					'title'       => get_the_title( $p->ID ),
					'post_type'   => $p->post_type,
					'url'         => get_permalink( $p->ID ),
					'lat'         => $lat,
					'lng'         => $lng,
					'address'     => (string) get_post_meta( $p->ID, '_tf_address', true ),
					'price_range' => (string) get_post_meta( $p->ID, '_tf_price_range', true ),
					'is_top_100'  => (bool) get_post_meta( $p->ID, '_tf_is_top_100_cde', true ),
				);
			}
			restore_current_blog();
		}

		return new WP_REST_Response( array( 'pins' => $pins, 'total' => count( $pins ) ), 200 );
	}

	/**
	 * Verifica se já existe registro no subsite por nome, endereço, telefone ou coordenadas próximas (< 40m).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function check_duplicate( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: array();
		$dup    = self::find_existing_duplicate( $params );

		return new WP_REST_Response(
			array(
				'is_duplicate' => ! empty( $dup ),
				'match'        => $dup,
			),
			200
		);
	}

	/**
	 * Busca duplicata interna por título normalizado, telefone ou coordenadas + endereço.
	 *
	 * @param array $params Dados do estabelecimento.
	 * @return array|null
	 */
	public static function find_existing_duplicate( array $params ): ?array {
		$title     = sanitize_text_field( $params['title'] ?? '' );
		$post_type = sanitize_key( $params['post_type'] ?? 'loja' );
		$phone     = preg_replace( '/\D+/', '', (string) ( $params['phone'] ?? '' ) );
		$lat       = (float) ( $params['lat'] ?? 0.0 );
		$lng       = (float) ( $params['lng'] ?? 0.0 );

		$existing = get_posts(
			array(
				'post_type'      => in_array( $post_type, tf_get_establishment_post_types(), true ) ? $post_type : tf_get_establishment_post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 250,
				'no_found_rows'  => true,
			)
		);

		$norm_title = self::normalize_string( $title );

		foreach ( $existing as $p ) {
			$p_norm = self::normalize_string( $p->post_title );
			if ( ! empty( $norm_title ) && $norm_title === $p_norm ) {
				return array(
					'post_id' => $p->ID,
					'reason'  => 'exact_normalized_title',
					'title'   => $p->post_title,
					'status'  => $p->post_status,
				);
			}

			$p_phone = preg_replace( '/\D+/', '', (string) get_post_meta( $p->ID, '_tf_phone', true ) );
			if ( strlen( $phone ) >= 8 && $phone === $p_phone ) {
				return array(
					'post_id' => $p->ID,
					'reason'  => 'matching_phone',
					'title'   => $p->post_title,
					'status'  => $p->post_status,
				);
			}

			$p_lat = (float) get_post_meta( $p->ID, '_tf_lat', true );
			$p_lng = (float) get_post_meta( $p->ID, '_tf_lng', true );
			$pct   = 0.0;
			similar_text( $norm_title, $p_norm, $pct );
			if ( 0.0 !== $lat && 0.0 !== $p_lat && abs( $lat - $p_lat ) < 0.00035 && abs( $lng - $p_lng ) < 0.00035 && $pct >= 75.0 ) {
				return array(
					'post_id' => $p->ID,
					'reason'  => 'proximity_and_name_similarity',
					'title'   => $p->post_title,
					'status'  => $p->post_status,
				);
			}
		}

		return null;
	}

	/**
	 * Endpoint de ingestão do pipeline IA com deduplicação e controle estrito de status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function ingest_establishment( WP_REST_Request $request ): WP_REST_Response {
		$params    = $request->get_json_params() ?: array();
		$post_type = sanitize_key( $params['post_type'] ?? 'loja' );

		if ( ! in_array( $post_type, array_merge( tf_get_establishment_post_types(), array( 'post' ) ), true ) ) {
			return new WP_REST_Response( array( 'error' => 'Invalid post_type' ), 400 );
		}

		$title = sanitize_text_field( $params['title'] ?? '' );
		if ( empty( $title ) ) {
			return new WP_REST_Response( array( 'error' => 'Missing title' ), 400 );
		}

		// 1. Verificar duplicata antes de criar (a menos que update_existing = true)
		$dup = self::find_existing_duplicate(
			array(
				'title'     => $title,
				'post_type' => $post_type,
				'phone'     => $params['meta']['_tf_phone'] ?? '',
				'lat'       => $params['meta']['_tf_lat'] ?? 0.0,
				'lng'       => $params['meta']['_tf_lng'] ?? 0.0,
			)
		);

		$update_existing = ! empty( $params['update_existing'] );
		if ( $dup && ! $update_existing ) {
			return new WP_REST_Response(
				array(
					'action'    => 'skipped_duplicate',
					'duplicate' => $dup,
				),
				200
			);
		}

		// 2. Determinar status (Regra: tudo entra como draft/pending; só publica se confidence >= 85 E tf_allow_auto_publish ativo)
		$confidence   = (int) ( $params['meta']['_tf_ai_confidence'] ?? 0 );
		$auto_allowed = (bool) get_option( 'tf_allow_auto_publish', false );
		$req_status   = sanitize_key( $params['status'] ?? 'draft' );

		if ( 'publish' === $req_status && ( ! $auto_allowed || $confidence < 85 ) ) {
			$final_status = 'pending';
		} elseif ( in_array( $req_status, array( 'draft', 'pending', 'publish' ), true ) ) {
			$final_status = $req_status;
		} else {
			$final_status = 'draft';
		}

		$post_arr = array(
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => wp_kses_post( $params['content'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $params['excerpt'] ?? '' ),
			'post_status'  => $final_status,
		);

		if ( ! empty( $params['slug'] ) ) {
			$post_arr['post_name'] = sanitize_title( $params['slug'] );
		}

		if ( $dup && $update_existing ) {
			$post_arr['ID'] = (int) $dup['post_id'];
			// Preserva status publicado caso o post já esteja no ar
			if ( 'publish' === get_post_status( (int) $dup['post_id'] ) ) {
				$post_arr['post_status'] = 'publish';
			}
			$post_id        = wp_update_post( $post_arr, true );
			$action         = 'updated';
		} else {
			$post_id = wp_insert_post( $post_arr, true );
			$action  = 'created';
		}

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response( array( 'error' => $post_id->get_error_message() ), 500 );
		}

		// 3. Salvar metacampos validados
		$schema_fields = TF_Meta_Fields::get_fields_schema();
		$meta_input    = $params['meta'] ?? array();
		foreach ( $meta_input as $m_key => $m_val ) {
			if ( isset( $schema_fields[ $m_key ] ) ) {
				$clean_val = TF_Meta_Fields::sanitize_meta_value( $m_val, $schema_fields[ $m_key ]['type'], $m_key );
				update_post_meta( $post_id, $m_key, $clean_val );
			}
		}

		// Garantir data de verificação se não enviada
		if ( empty( get_post_meta( $post_id, '_tf_last_verified_at', true ) ) ) {
			update_post_meta( $post_id, '_tf_last_verified_at', gmdate( 'Y-m-d' ) );
		}

		// 4. Associar termos de taxonomias (cria o termo caso não exista)
		$tax_input = $params['taxonomies'] ?? array();
		foreach ( $tax_input as $tax_slug => $terms ) {
			if ( ! taxonomy_exists( $tax_slug ) ) {
				continue;
			}
			$term_ids = array();
			foreach ( (array) $terms as $term_name ) {
				$term_name = sanitize_text_field( (string) $term_name );
				if ( empty( $term_name ) ) {
					continue;
				}
				$existing_term = term_exists( $term_name, $tax_slug );
				if ( ! $existing_term ) {
					$existing_term = wp_insert_term( $term_name, $tax_slug );
				}
				if ( ! is_wp_error( $existing_term ) && isset( $existing_term['term_id'] ) ) {
					$term_ids[] = (int) $existing_term['term_id'];
				}
			}
			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $tax_slug, false );
			}
		}

		// 5. Foto do site oficial (se existir e for válida; caso contrário, mantém em branco sem inventar foto)
		if ( array_key_exists( 'featured_image_url', $params ) ) {
			$img_url = esc_url_raw( trim( (string) $params['featured_image_url'] ) );
			if ( ! empty( $img_url ) ) {
				self::maybe_sideload_official_image( $post_id, $img_url, $title );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		return new WP_REST_Response(
			array(
				'action'        => $action,
				'post_id'       => $post_id,
				'status'        => $final_status,
				'ai_confidence' => $confidence,
				'has_thumbnail' => has_post_thumbnail( $post_id ),
				'permalink'     => get_permalink( $post_id ),
			),
			201
		);
	}

	/**
	 * Faz o download seguro da foto do site oficial para a biblioteca de mídia do subsite e define como thumbnail.
	 * Se falhar ou não for imagem válida, mantém sem imagem destacada (em branco).
	 *
	 * @param int    $post_id ID do post.
	 * @param string $img_url URL da imagem oficial.
	 * @param string $desc    Descrição/Alt text.
	 */
	private static function maybe_sideload_official_image( int $post_id, string $img_url, string $desc ): void {
		$existing_src = (string) get_post_meta( $post_id, '_tf_official_image_src', true );
		if ( $existing_src === $img_url && has_post_thumbnail( $post_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $img_url, 15 );
		if ( is_wp_error( $tmp ) ) {
			return;
		}

		$path_part = (string) parse_url( $img_url, PHP_URL_PATH );
		$ext       = strtolower( pathinfo( $path_part, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			$ext = 'jpg';
		}

		$file_array = array(
			'name'     => sanitize_title( $desc ) . '-oficial.' . $ext,
			'tmp_name' => $tmp,
		);

		$att_id = media_handle_sideload( $file_array, $post_id, $desc );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp );
			return;
		}

		set_post_thumbnail( $post_id, (int) $att_id );
		update_post_meta( (int) $att_id, '_wp_attachment_image_alt', sanitize_text_field( $desc ) );
		update_post_meta( $post_id, '_tf_official_image_src', $img_url );
	}

	/**
	 * Normaliza texto para comparação de deduplicação.
	 *
	 * @param string $str Texto.
	 * @return string
	 */
	private static function normalize_string( string $str ): string {
		$str = remove_accents( mb_strtolower( trim( $str ) ) );
		return preg_replace( '/[^a-z0-9]+/', '', $str );
	}
}
