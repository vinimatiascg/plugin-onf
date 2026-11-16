<?php
/**
 * Gerador de Dados Estruturados Schema.org (JSON-LD) para SEO e GEO.
 *
 * Mapeamento oficial:
 * - Raiz -> Organization + WebSite (com SearchAction)
 * - Cidade -> City / Place + WebSite
 * - Hotel -> Hotel / LodgingBusiness
 * - Restaurante -> Restaurant
 * - Loja -> ShoppingCenter (se categoria = shopping) ou Store
 * - Atrativo -> TouristAttraction
 * - Passeio -> TouristTrip + Product (com Offer quando houver preço verificado)
 * - Serviço -> CurrencyExchange / FinancialService, TravelAgency ou AutoRental
 * - Post (Notícia) -> NewsArticle
 * - FAQ -> FAQPage (quando _tf_faq_json presente)
 *
 * Regra crítica: aggregateRating SOMENTE é incluído se houver avaliação real,
 * contagem > 0 e fonte verificável registrada.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Schema_JSONLD {

	/**
	 * Registra hook no wp_head.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output_schema_jsonld' ), 20 );
	}

	/**
	 * Imprime o bloco <script type="application/ld+json"> no <head>.
	 */
	public static function output_schema_jsonld(): void {
		$graph = self::build_schema_graph();
		if ( empty( $graph ) ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		echo "\n<!-- Onde na Fronteira Schema.org JSON-LD (tf-core) -->\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}

	/**
	 * Constrói o grafo de entidades Schema.org conforme o contexto da página atual.
	 *
	 * @return array
	 */
	public static function build_schema_graph(): array {
		$graph    = array();
		$city_ctx = tf_get_current_city_context();
		$home_url = home_url( '/' );
		$org_id   = network_home_url( '/#organization' );
		$site_id  = $home_url . '#website';

		// 1. Organization (sempre referenciável na rede)
		$graph[] = array(
			'@type'       => 'Organization',
			'@id'         => $org_id,
			'name'        => 'Onde na Fronteira',
			'url'         => network_home_url( '/' ),
			'description' => 'Agregador turístico, gastronômico e comercial da Tríplice Fronteira: Foz do Iguaçu (BR), Ciudad del Este (PY) e Puerto Iguazú (AR).',
			'areaServed'  => array(
				array( '@type' => 'City', 'name' => 'Foz do Iguaçu', 'addressCountry' => 'BR' ),
				array( '@type' => 'City', 'name' => 'Ciudad del Este', 'addressCountry' => 'PY' ),
				array( '@type' => 'City', 'name' => 'Puerto Iguazú', 'addressCountry' => 'AR' ),
			),
		);

		// 2. WebSite com SearchAction
		$graph[] = array(
			'@type'           => 'WebSite',
			'@id'             => $site_id,
			'url'             => $home_url,
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'inLanguage'      => 'pt-BR',
			'publisher'       => array( '@id' => $org_id ),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => $home_url . '?s={search_term_string}',
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		// 3. Home de Subsite de Cidade -> City / Place
		if ( ( is_front_page() || is_home() ) && 'raiz' !== $city_ctx['slug'] ) {
			$graph[] = array(
				'@type'       => array( 'City', 'Place' ),
				'@id'         => $home_url . '#city',
				'name'        => $city_ctx['name'],
				'url'         => $home_url,
				'sameAs'      => array( $city_ctx['wiki'] ),
				'address'     => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => $city_ctx['name'],
					'addressRegion'   => $city_ctx['region'],
					'addressCountry'  => $city_ctx['country'],
				),
				'geo'         => array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => $city_ctx['lat'],
					'longitude' => $city_ctx['lng'],
				),
			);
		}

		// 4. Single de CPTs ou Notícias
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$graph[] = self::build_breadcrumb_node( $post, $city_ctx );

				if ( in_array( $post->post_type, tf_get_establishment_post_types(), true ) ) {
					$est_node = self::build_establishment_node( $post, $city_ctx );
					if ( ! empty( $est_node ) ) {
						$graph[] = $est_node;
					}
				} elseif ( 'post' === $post->post_type ) {
					$graph[] = self::build_news_article_node( $post, $org_id );
				}

				$faq_node = self::build_faq_node( $post );
				if ( ! empty( $faq_node ) ) {
					$graph[] = $faq_node;
				}
			}
		}

		return $graph;
	}

	/**
	 * Constrói o nó Schema.org de um estabelecimento comercial/turístico.
	 *
	 * @param WP_Post $post     Post atual.
	 * @param array   $city_ctx Contexto da cidade.
	 * @return array
	 */
	public static function build_establishment_node( WP_Post $post, array $city_ctx ): array {
		$post_id   = $post->ID;
		$permalink = get_permalink( $post_id );
		$name      = get_post_meta( $post_id, '_tf_official_name', true ) ?: get_the_title( $post_id );
		$desc      = get_post_meta( $post_id, '_tf_direct_answer', true ) ?: get_the_excerpt( $post_id );
		$address   = get_post_meta( $post_id, '_tf_address', true );
		$postal    = get_post_meta( $post_id, '_tf_postal_code', true );
		$lat       = (float) get_post_meta( $post_id, '_tf_lat', true );
		$lng       = (float) get_post_meta( $post_id, '_tf_lng', true );
		$phone     = get_post_meta( $post_id, '_tf_phone', true ) ?: get_post_meta( $post_id, '_tf_whatsapp', true );
		$website   = get_post_meta( $post_id, '_tf_website', true );
		$price_rng = get_post_meta( $post_id, '_tf_price_range', true );
		$payments  = get_post_meta( $post_id, '_tf_payment_methods', true );
		$languages = get_post_meta( $post_id, '_tf_languages', true );

		// Determinar @type específico por CPT e categoria
		$schema_type = self::resolve_establishment_schema_type( $post );

		$node = array(
			'@type'       => $schema_type,
			'@id'         => $permalink . '#establishment',
			'name'        => $name,
			'description' => wp_strip_all_tags( $desc ),
			'url'         => $permalink,
		);

		// Imagem principal (Foto da Fachada / Estabelecimento)
		$thumb_url = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $thumb_url ) {
			$node['image'] = array( $thumb_url );
		}

		// Logo Oficial do Empreendimento / Loja
		$logo_url = (string) get_post_meta( $post_id, '_tf_logo_url', true );
		if ( ! empty( $logo_url ) ) {
			$node['logo'] = esc_url( $logo_url );
		}

		// Endereço (PostalAddress)
		$node['address'] = array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => $address ?: $city_ctx['name'],
			'addressLocality' => $city_ctx['name'],
			'addressRegion'   => $city_ctx['region'],
			'addressCountry'  => $city_ctx['country'],
		);
		if ( ! empty( $postal ) ) {
			$node['address']['postalCode'] = $postal;
		}

		// Coordenadas (GeoCoordinates)
		if ( 0.0 !== $lat || 0.0 !== $lng ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => $lat,
				'longitude' => $lng,
			);
		}

		// Telefone
		if ( ! empty( $phone ) ) {
			$node['telephone'] = $phone;
		}

		// SameAs (site oficial + redes sociais + fonte original)
		$same_as = array();
		foreach ( array( '_tf_website', '_tf_instagram', '_tf_facebook', '_tf_tiktok', '_tf_source_url' ) as $s_key ) {
			$val = trim( (string) get_post_meta( $post_id, $s_key, true ) );
			if ( ! empty( $val ) && filter_var( $val, FILTER_VALIDATE_URL ) ) {
				$same_as[] = $val;
			}
		}
		if ( ! empty( $same_as ) ) {
			$node['sameAs'] = array_values( array_unique( $same_as ) );
		}

		// Faixa de preço e pagamentos
		if ( ! empty( $price_rng ) ) {
			$node['priceRange'] = $price_rng;
		}
		if ( ! empty( $payments ) ) {
			$node['paymentAccepted'] = $payments;
		}
		if ( ! empty( $languages ) ) {
			$node['availableLanguage'] = array_map( 'trim', explode( ',', $languages ) );
		}

		// Horários de funcionamento (openingHoursSpecification)
		$hours_spec = self::parse_opening_hours( (string) get_post_meta( $post_id, '_tf_opening_hours', true ) );
		if ( ! empty( $hours_spec ) ) {
			$node['openingHoursSpecification'] = $hours_spec;
		}

		// Campos específicos de Restaurante (servesCuisine)
		if ( 'restaurante' === $post->post_type ) {
			$cuisines = wp_get_post_terms( $post_id, 'tipo_cozinha', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $cuisines ) && ! empty( $cuisines ) ) {
				$node['servesCuisine'] = $cuisines;
			}
		}

		// Campos específicos de Passeio (Offer quando houver preço verificado)
		if ( 'passeio' === $post->post_type ) {
			$price_val = (float) get_post_meta( $post_id, '_tf_price_value', true );
			$currency  = get_post_meta( $post_id, '_tf_price_currency', true ) ?: $city_ctx['currency'];
			if ( $price_val > 0 ) {
				$node['offers'] = array(
					'@type'         => 'Offer',
					'price'         => number_format( $price_val, 2, '.', '' ),
					'priceCurrency' => $currency,
					'availability'  => 'https://schema.org/InStock',
					'url'           => $website ?: $permalink,
				);
			}
		}

		// AggregateRating: APENAS se houver avaliações reais e fonte verificável (NUNCA inventar)
		$rating_val = (float) get_post_meta( $post_id, '_tf_rating_value', true );
		$rating_cnt = (int) get_post_meta( $post_id, '_tf_rating_count', true );
		$rating_src = trim( (string) get_post_meta( $post_id, '_tf_rating_source', true ) );

		if ( $rating_val > 0 && $rating_val <= 5 && $rating_cnt > 0 && ! empty( $rating_src ) ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => round( $rating_val, 1 ),
				'reviewCount' => $rating_cnt,
				'bestRating'  => 5,
				'worstRating' => 1,
			);
		}

		return $node;
	}

	/**
	 * Resolve o @type exato do Schema.org para cada CPT e subcategoria.
	 *
	 * @param WP_Post $post Post.
	 * @return string|string[]
	 */
	private static function resolve_establishment_schema_type( WP_Post $post ) {
		switch ( $post->post_type ) {
			case 'hotel':
				return array( 'Hotel', 'LodgingBusiness' );
			case 'restaurante':
				return 'Restaurant';
			case 'loja':
				$cats = wp_get_post_terms( $post->ID, 'categoria_loja', array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $cats ) && in_array( 'shopping', $cats, true ) ) {
					return 'ShoppingCenter';
				}
				return 'Store';
			case 'atrativo':
				return 'TouristAttraction';
			case 'passeio':
				$price_val = (float) get_post_meta( $post->ID, '_tf_price_value', true );
				return $price_val > 0 ? array( 'TouristTrip', 'Product' ) : 'TouristTrip';
			case 'servico':
				$types = wp_get_post_terms( $post->ID, 'tipo_servico', array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $types ) ) {
					if ( in_array( 'casa-de-cambio', $types, true ) || in_array( 'cambio', $types, true ) ) {
						return array( 'CurrencyExchange', 'FinancialService' );
					}
					if ( in_array( 'agencia-de-turismo', $types, true ) || in_array( 'agencia', $types, true ) ) {
						return 'TravelAgency';
					}
					if ( in_array( 'locadora-de-veiculos', $types, true ) || in_array( 'locadora', $types, true ) ) {
						return 'AutoRental';
					}
				}
				return 'LocalBusiness';
			default:
				return 'LocalBusiness';
		}
	}

	/**
	 * Converte JSON estruturado de horários em OpeningHoursSpecification do Schema.org.
	 *
	 * @param string $raw Horários brutos (JSON ou string).
	 * @return array
	 */
	private static function parse_opening_hours( string $raw ): array {
		$raw = trim( $raw );
		if ( empty( $raw ) ) {
			return array();
		}
		if ( strpos( $raw, '[' ) === 0 || strpos( $raw, '{' ) === 0 ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$specs = array();
				foreach ( $decoded as $item ) {
					if ( isset( $item['days'], $item['opens'], $item['closes'] ) ) {
						$specs[] = array(
							'@type'     => 'OpeningHoursSpecification',
							'dayOfWeek' => (array) $item['days'],
							'opens'     => $item['opens'],
							'closes'    => $item['closes'],
						);
					}
				}
				return $specs;
			}
		}
		return array();
	}

	/**
	 * Constrói o nó NewsArticle para posts de notícias e guias.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $org_id ID da Organization.
	 * @return array
	 */
	private static function build_news_article_node( WP_Post $post, string $org_id ): array {
		$permalink = get_permalink( $post->ID );
		$thumb_url = get_the_post_thumbnail_url( $post->ID, 'full' );
		$author    = get_the_author_meta( 'display_name', (int) $post->post_author ) ?: 'Equipe Editorial Onde na Fronteira';

		$node = array(
			'@type'            => 'NewsArticle',
			'@id'              => $permalink . '#newsarticle',
			'headline'         => get_the_title( $post->ID ),
			'description'      => wp_strip_all_tags( get_post_meta( $post->ID, '_tf_direct_answer', true ) ?: get_the_excerpt( $post->ID ) ),
			'datePublished'    => get_the_date( DATE_W3C, $post->ID ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post->ID ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $permalink,
			),
			'author'           => array(
				'@type' => 'Person',
				'name'  => $author,
			),
			'publisher'        => array( '@id' => $org_id ),
			'inLanguage'       => 'pt-BR',
		);

		if ( $thumb_url ) {
			$node['image'] = array( $thumb_url );
		}

		return $node;
	}

	/**
	 * Constrói BreadcrumbList hierárquico (Raiz -> Cidade -> CPT -> Post).
	 *
	 * @param WP_Post $post     Post atual.
	 * @param array   $city_ctx Contexto da cidade.
	 * @return array
	 */
	private static function build_breadcrumb_node( WP_Post $post, array $city_ctx ): array {
		$items = array();
		$pos   = 1;

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => 'Onde na Fronteira',
			'item'     => network_home_url( '/' ),
		);

		if ( 'raiz' !== $city_ctx['slug'] ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $city_ctx['name'],
				'item'     => home_url( '/' ),
			);
		}

		if ( in_array( $post->post_type, tf_get_establishment_post_types(), true ) ) {
			$pt_obj  = get_post_type_object( $post->post_type );
			$archive = get_post_type_archive_link( $post->post_type );
			if ( $pt_obj && $archive ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => $pos++,
					'name'     => $pt_obj->labels->name,
					'item'     => $archive,
				);
			}
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => get_the_title( $post->ID ),
			'item'     => get_permalink( $post->ID ),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => get_permalink( $post->ID ) . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Constrói FAQPage quando _tf_faq_json possui perguntas e respostas válidas.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function build_faq_node( WP_Post $post ): array {
		$raw = trim( (string) get_post_meta( $post->ID, '_tf_faq_json', true ) );
		if ( empty( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return array();
		}

		$questions = array();
		foreach ( $decoded as $q ) {
			if ( ! empty( $q['question'] ) && ! empty( $q['answer'] ) ) {
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( $q['question'] ),
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => wp_kses_post( $q['answer'] ),
					),
				);
			}
		}

		if ( empty( $questions ) ) {
			return array();
		}

		return array(
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $post->ID ) . '#faq',
			'mainEntity' => $questions,
		);
	}
}
