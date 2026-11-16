<?php
/**
 * Alimentador de Estabelecimentos via Google Maps / Google Places API (New).
 *
 * Substitui a varredura baseada em OpenStreetMap por uma varredura nativa em PHP:
 *  1. Busca (Text Search) categorias × cidade dentro do retângulo geográfico de cada subsite;
 *  2. Enfileira os place_id encontrados em uma tabela própria por subsite;
 *  3. Processa a fila com Place Details, deduplica (place_id, nome, telefone, proximidade)
 *     e cria rascunhos "pendentes" para moderação ou atualiza registros já existentes.
 *
 * Roda automaticamente via WP-Cron (de hora em hora, no site principal da rede)
 * e também pode ser disparado manualmente no painel "⚡ Alimentador".
 *
 * Chave da API: constante TF_GOOGLE_PLACES_API_KEY no wp-config.php (recomendado)
 * ou campo no painel do Alimentador (opção de rede tf_google_places_api_key).
 *
 * @package TF_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Google_Places {

	const API_BASE      = 'https://places.googleapis.com/v1/';
	const CRON_HOOK     = 'tf_places_harvest_tick';
	const TABLE_VERSION = '1';
	const LOCK_KEY      = 'tf_places_harvest_lock';

	/**
	 * Raio aproximado (em graus) do retângulo de busca ao redor do centro de cada cidade (~9 km).
	 */
	const BBOX_DELTA = 0.085;

	public static function init(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_cron' ), 30 );
	}

	/* ---------------------------------------------------------------------
	 * Configurações
	 * ------------------------------------------------------------------ */

	public static function get_api_key(): string {
		if ( defined( 'TF_GOOGLE_PLACES_API_KEY' ) && TF_GOOGLE_PLACES_API_KEY ) {
			return (string) TF_GOOGLE_PLACES_API_KEY;
		}
		return (string) get_site_option( 'tf_google_places_api_key', '' );
	}

	public static function api_key_from_constant(): bool {
		return defined( 'TF_GOOGLE_PLACES_API_KEY' ) && TF_GOOGLE_PLACES_API_KEY;
	}

	public static function is_enabled(): bool {
		return (bool) get_site_option( 'tf_places_enabled', false ) && '' !== self::get_api_key();
	}

	public static function get_daily_target(): int {
		return max( 10, (int) get_site_option( 'tf_places_daily_target', 100 ) );
	}

	/**
	 * Agenda (ou remove) o evento de cron no site principal conforme a configuração.
	 */
	public static function maybe_schedule_cron(): void {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( self::is_enabled() && ! $scheduled ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		} elseif ( ! self::is_enabled() && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Catálogo de buscas (categoria do portal → consulta no Google)
	 * ------------------------------------------------------------------ */

	/**
	 * Lista de consultas varridas em rodízio em cada cidade.
	 * includedType segue a tabela de tipos do Google Places (New).
	 *
	 * @return array<int, array{post_type:string, query:string, type:string, terms:array}>
	 */
	public static function get_search_catalog(): array {
		$catalog = array(
			// Restaurantes
			array( 'post_type' => 'restaurante', 'query' => 'restaurantes', 'type' => 'restaurant', 'terms' => array() ),
			array( 'post_type' => 'restaurante', 'query' => 'churrascaria rodízio', 'type' => 'restaurant', 'terms' => array( 'categoria_restaurante' => array( 'Churrascaria / Rodízio' ), 'tipo_cozinha' => array( 'Brasileira' ) ) ),
			array( 'post_type' => 'restaurante', 'query' => 'parrilla', 'type' => 'restaurant', 'terms' => array( 'categoria_restaurante' => array( 'Parrilla Argentina' ), 'tipo_cozinha' => array( 'Argentina / Carnes Nobres' ) ) ),
			array( 'post_type' => 'restaurante', 'query' => 'restaurante árabe libanês', 'type' => 'restaurant', 'terms' => array( 'tipo_cozinha' => array( 'Árabe / Libanesa' ) ) ),
			array( 'post_type' => 'restaurante', 'query' => 'restaurante italiano', 'type' => 'restaurant', 'terms' => array( 'tipo_cozinha' => array( 'Italiana / Massas' ) ) ),
			array( 'post_type' => 'restaurante', 'query' => 'restaurante japonês', 'type' => 'restaurant', 'terms' => array( 'tipo_cozinha' => array( 'Asiática / Japonesa' ) ) ),
			array( 'post_type' => 'restaurante', 'query' => 'cafeteria confeitaria', 'type' => 'cafe', 'terms' => array( 'categoria_restaurante' => array( 'Cafeteria & Confeitaria' ) ) ),
			array( 'post_type' => 'restaurante', 'query' => 'bar pub', 'type' => 'bar', 'terms' => array( 'categoria_restaurante' => array( 'Bar & Pub' ) ) ),

			// Hotéis
			array( 'post_type' => 'hotel', 'query' => 'hotéis', 'type' => 'hotel', 'terms' => array( 'categoria_hotel' => array( 'Hotel Urbano' ) ) ),
			array( 'post_type' => 'hotel', 'query' => 'resort', 'type' => 'resort_hotel', 'terms' => array( 'categoria_hotel' => array( 'Resort' ) ) ),
			array( 'post_type' => 'hotel', 'query' => 'pousada', 'type' => 'lodging', 'terms' => array( 'categoria_hotel' => array( 'Pousada' ) ) ),
			array( 'post_type' => 'hotel', 'query' => 'hostel', 'type' => 'hostel', 'terms' => array( 'categoria_hotel' => array( 'Hostel' ) ) ),

			// Lojas / Compras
			array( 'post_type' => 'loja', 'query' => 'shopping center galeria', 'type' => 'shopping_mall', 'terms' => array( 'categoria_loja' => array( 'Shopping Center / Galeria' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'duty free shop', 'type' => '', 'terms' => array( 'categoria_loja' => array( 'Duty Free Shop' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'loja de eletrônicos informática', 'type' => 'electronics_store', 'terms' => array( 'categoria_loja' => array( 'Eletrônicos e Informática' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'perfumaria cosméticos', 'type' => '', 'terms' => array( 'categoria_loja' => array( 'Perfumaria e Cosméticos' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'loja de bebidas vinhos', 'type' => 'liquor_store', 'terms' => array( 'categoria_loja' => array( 'Bebidas, Vinhos e Alfajores' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'loja de roupas calçados esportes', 'type' => 'clothing_store', 'terms' => array( 'categoria_loja' => array( 'Moda, Calçados e Esportes' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'loja de departamentos importados', 'type' => 'department_store', 'terms' => array( 'categoria_loja' => array( 'Loja de Departamentos / Importados' ) ) ),
			array( 'post_type' => 'loja', 'query' => 'artesanato souvenirs', 'type' => 'gift_shop', 'terms' => array( 'categoria_loja' => array( 'Artesanato e Souvenirs' ) ) ),

			// Atrativos
			array( 'post_type' => 'atrativo', 'query' => 'pontos turísticos', 'type' => 'tourist_attraction', 'terms' => array() ),
			array( 'post_type' => 'atrativo', 'query' => 'parque natureza', 'type' => 'park', 'terms' => array( 'categoria_atrativo' => array( 'Natureza e Parques Nacionais' ) ) ),
			array( 'post_type' => 'atrativo', 'query' => 'museu', 'type' => 'museum', 'terms' => array( 'categoria_atrativo' => array( 'Parques Temáticos e Museus' ) ) ),
			array( 'post_type' => 'atrativo', 'query' => 'templo mesquita igreja histórica', 'type' => '', 'terms' => array( 'categoria_atrativo' => array( 'Templos Religiosos e Cultura' ) ) ),
			array( 'post_type' => 'atrativo', 'query' => 'marco das três fronteiras monumento histórico', 'type' => '', 'terms' => array( 'categoria_atrativo' => array( 'Marcos Fronteiriços e Históricos' ) ) ),

			// Passeios
			array( 'post_type' => 'passeio', 'query' => 'passeios turísticos tours', 'type' => 'tour_agency', 'terms' => array() ),
			array( 'post_type' => 'passeio', 'query' => 'passeio de barco', 'type' => '', 'terms' => array( 'categoria_passeio' => array( 'Navegação nos Rios Iguaçu e Paraná' ) ) ),
			array( 'post_type' => 'passeio', 'query' => 'transfer turístico', 'type' => '', 'terms' => array( 'tipo_passeio' => array( 'Transfer / Transporte Turístico' ) ) ),

			// Serviços
			array( 'post_type' => 'servico', 'query' => 'casa de câmbio', 'type' => '', 'terms' => array( 'tipo_servico' => array( 'Casa de Câmbio' ) ) ),
			array( 'post_type' => 'servico', 'query' => 'agência de turismo receptivo', 'type' => 'travel_agency', 'terms' => array( 'tipo_servico' => array( 'Agência de Turismo e Receptivo' ) ) ),
			array( 'post_type' => 'servico', 'query' => 'locadora de veículos', 'type' => 'car_rental', 'terms' => array( 'tipo_servico' => array( 'Locadora de Veículos' ) ) ),
		);

		/**
		 * Permite personalizar o catálogo de buscas do alimentador Google Places.
		 *
		 * @param array $catalog Lista de consultas.
		 */
		return (array) apply_filters( 'tf_google_places_search_catalog', $catalog );
	}

	/* ---------------------------------------------------------------------
	 * Fila (tabela por subsite)
	 * ------------------------------------------------------------------ */

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'tf_places_queue';
	}

	public static function maybe_create_table(): void {
		if ( get_option( 'tf_places_table_version' ) === self::TABLE_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			place_id varchar(191) NOT NULL,
			post_type varchar(40) NOT NULL DEFAULT '',
			search_query varchar(191) NOT NULL DEFAULT '',
			terms_json text NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			note varchar(191) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY place_id (place_id),
			KEY status (status)
		) {$charset};";

		dbDelta( $sql );
		update_option( 'tf_places_table_version', self::TABLE_VERSION );
	}

	/**
	 * Conta itens na fila de todos os subsites da rede.
	 *
	 * @return array{queued:int, processed:int, skipped:int, error:int}
	 */
	public static function get_queue_stats(): array {
		$stats = array( 'queued' => 0, 'processed' => 0, 'skipped' => 0, 'error' => 0 );
		self::for_each_city_site(
			function () use ( &$stats ) {
				global $wpdb;
				$table = self::table_name();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );
				foreach ( (array) $rows as $row ) {
					if ( isset( $stats[ $row['status'] ] ) ) {
						$stats[ $row['status'] ] += (int) $row['total'];
					}
				}
			}
		);
		return $stats;
	}

	/* ---------------------------------------------------------------------
	 * Execução
	 * ------------------------------------------------------------------ */

	/**
	 * Tick do WP-Cron: distribui a meta diária entre as horas do dia e as cidades.
	 */
	public static function run_cron_tick(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		$cities   = max( 1, count( self::get_city_site_ids() ) );
		$per_tick = (int) max( 1, ceil( self::get_daily_target() / 24 / $cities ) );
		self::run_network( 1, $per_tick );
	}

	/**
	 * Executa uma rodada em todos os subsites de cidade.
	 *
	 * @param int $search_queries Quantas consultas (páginas de resultados) buscar por cidade.
	 * @param int $process_batch  Quantos locais da fila processar por cidade.
	 * @return array Resumo da rodada.
	 */
	public static function run_network( int $search_queries, int $process_batch ): array {
		$summary = array(
			'started_at' => current_time( 'mysql' ),
			'found'      => 0,
			'created'    => 0,
			'updated'    => 0,
			'skipped'    => 0,
			'errors'     => array(),
		);

		if ( '' === self::get_api_key() ) {
			$summary['errors'][] = 'Chave da Google Places API não configurada.';
			update_site_option( 'tf_places_last_run', $summary );
			return $summary;
		}

		if ( get_site_transient( self::LOCK_KEY ) ) {
			$summary['errors'][] = 'Já existe uma rodada em andamento. Tente novamente em alguns minutos.';
			return $summary;
		}
		set_site_transient( self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );

		try {
			self::for_each_city_site(
				function ( array $city ) use ( $search_queries, $process_batch, &$summary ) {
					for ( $i = 0; $i < $search_queries; $i++ ) {
						$res = self::scan_next_query( $city );
						if ( is_wp_error( $res ) ) {
							$summary['errors'][] = $city['name'] . ': ' . $res->get_error_message();
							break;
						}
						$summary['found'] += $res;
					}

					if ( $process_batch > 0 ) {
						$res = self::process_queue( $city, $process_batch );
						foreach ( array( 'created', 'updated', 'skipped' ) as $k ) {
							$summary[ $k ] += $res[ $k ];
						}
						foreach ( $res['errors'] as $err ) {
							$summary['errors'][] = $city['name'] . ': ' . $err;
						}
					}
				}
			);
		} finally {
			delete_site_transient( self::LOCK_KEY );
		}

		$summary['finished_at'] = current_time( 'mysql' );
		$summary['errors']      = array_slice( array_unique( $summary['errors'] ), 0, 10 );
		update_site_option( 'tf_places_last_run', $summary );

		return $summary;
	}

	/**
	 * IDs dos subsites que representam uma cidade (ignora o portal raiz em redes Multisite).
	 *
	 * @return int[]
	 */
	public static function get_city_site_ids(): array {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() );
		}
		$ids = array();
		foreach ( get_sites( array( 'number' => 50, 'deleted' => 0, 'archived' => 0 ) ) as $site ) {
			switch_to_blog( (int) $site->blog_id );
			$city = tf_get_current_city_context();
			restore_current_blog();
			if ( 'raiz' !== $city['slug'] ) {
				$ids[] = (int) $site->blog_id;
			}
		}
		return $ids;
	}

	/**
	 * Executa um callback dentro do contexto de cada subsite de cidade.
	 *
	 * @param callable $callback Recebe o array de contexto da cidade.
	 */
	public static function for_each_city_site( callable $callback ): void {
		foreach ( self::get_city_site_ids() as $blog_id ) {
			$switched = is_multisite() && get_current_blog_id() !== $blog_id;
			if ( $switched ) {
				switch_to_blog( $blog_id );
			}
			self::maybe_create_table();
			$callback( tf_get_current_city_context() );
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Etapa 1: varredura (Text Search)
	 * ------------------------------------------------------------------ */

	/**
	 * Busca a próxima página da próxima consulta do catálogo e enfileira os resultados.
	 *
	 * @param array $city Contexto da cidade.
	 * @return int|WP_Error Número de novos locais enfileirados.
	 */
	public static function scan_next_query( array $city ) {
		$catalog = self::get_search_catalog();
		if ( empty( $catalog ) ) {
			return 0;
		}

		$state = get_option( 'tf_places_scan_state', array( 'index' => 0, 'page_token' => '', 'page' => 0 ) );
		$index = (int) ( $state['index'] ?? 0 ) % count( $catalog );
		$entry = $catalog[ $index ];

		$body = array(
			'textQuery'          => $entry['query'] . ' em ' . $city['name'],
			'languageCode'       => 'pt-BR',
			'regionCode'         => strtolower( $city['country'] ),
			'pageSize'           => 20,
			'locationRestriction' => array(
				'rectangle' => array(
					'low'  => array( 'latitude' => $city['lat'] - self::BBOX_DELTA, 'longitude' => $city['lng'] - self::BBOX_DELTA ),
					'high' => array( 'latitude' => $city['lat'] + self::BBOX_DELTA, 'longitude' => $city['lng'] + self::BBOX_DELTA ),
				),
			),
		);
		if ( ! empty( $entry['type'] ) ) {
			$body['includedType']       = $entry['type'];
			$body['strictTypeFiltering'] = false;
		}
		if ( ! empty( $state['page_token'] ) ) {
			$body['pageToken'] = $state['page_token'];
		}

		$res = self::request(
			'POST',
			'places:searchText',
			'places.id,places.displayName,places.businessStatus,nextPageToken',
			$body
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$added = 0;
		global $wpdb;
		$now = current_time( 'mysql' );
		foreach ( (array) ( $res['places'] ?? array() ) as $place ) {
			if ( empty( $place['id'] ) || 'CLOSED_PERMANENTLY' === ( $place['businessStatus'] ?? '' ) ) {
				continue;
			}
			$inserted = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'INSERT IGNORE INTO ' . self::table_name() . ' (place_id, post_type, search_query, terms_json, status, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %s)',
					$place['id'],
					$entry['post_type'],
					$entry['query'],
					wp_json_encode( $entry['terms'] ),
					'queued',
					$now,
					$now
				)
			);
			$added += (int) $inserted;
		}

		// Avança o cursor: próxima página da mesma consulta (até 3 páginas) ou próxima consulta.
		$next_token = (string) ( $res['nextPageToken'] ?? '' );
		$page       = (int) ( $state['page'] ?? 0 ) + 1;
		if ( '' !== $next_token && $page < 3 ) {
			$state = array( 'index' => $index, 'page_token' => $next_token, 'page' => $page );
		} else {
			$state = array( 'index' => ( $index + 1 ) % count( $catalog ), 'page_token' => '', 'page' => 0 );
		}
		update_option( 'tf_places_scan_state', $state, false );

		return $added;
	}

	/* ---------------------------------------------------------------------
	 * Etapa 2: processamento (Place Details → posts pendentes)
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $city  Contexto da cidade.
	 * @param int   $limit Quantidade máxima de locais.
	 * @return array{created:int, updated:int, skipped:int, errors:array}
	 */
	public static function process_queue( array $city, int $limit ): array {
		global $wpdb;
		$out   = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		$table = self::table_name();
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT %d", $limit ),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$details = self::request(
				'GET',
				'places/' . rawurlencode( $row['place_id'] ),
				'id,displayName,formattedAddress,addressComponents,location,nationalPhoneNumber,internationalPhoneNumber,websiteUri,rating,userRatingCount,regularOpeningHours,priceLevel,googleMapsUri,businessStatus,editorialSummary',
				null,
				array( 'languageCode' => 'pt-BR', 'regionCode' => strtolower( $city['country'] ) )
			);

			if ( is_wp_error( $details ) ) {
				// Local removido do Google: marca como erro e segue para o próximo.
				if ( 'tf_places_not_found' === $details->get_error_code() ) {
					self::mark_row( (int) $row['id'], 'error', 'nao_encontrado_no_google' );
					continue;
				}
				// Chave, cota, rede ou erro da API: interrompe o lote e mantém a fila intacta para a próxima rodada.
				$out['errors'][] = $details->get_error_message();
				break;
			}

			$result = self::upsert_establishment( $details, $row, $city );
			self::mark_row( (int) $row['id'], $result['status'], $result['note'], $result['post_id'] );
			if ( isset( $out[ $result['action'] ] ) ) {
				$out[ $result['action'] ]++;
			}
		}

		return $out;
	}

	private static function mark_row( int $id, string $status, string $note = '', int $post_id = 0 ): void {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'status'     => $status,
				'note'       => $note,
				'post_id'    => $post_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Cria ou atualiza o estabelecimento a partir do Place Details.
	 *
	 * @return array{action:string, status:string, note:string, post_id:int}
	 */
	public static function upsert_establishment( array $place, array $row, array $city ): array {
		$title     = trim( (string) ( $place['displayName']['text'] ?? '' ) );
		$post_type = in_array( $row['post_type'], tf_get_establishment_post_types(), true ) ? $row['post_type'] : 'loja';

		if ( '' === $title ) {
			return array( 'action' => 'skipped', 'status' => 'skipped', 'note' => 'sem_nome', 'post_id' => 0 );
		}

		// Descarta locais de outro país (as cidades da Tríplice Fronteira são vizinhas).
		$country = '';
		$postal  = '';
		foreach ( (array) ( $place['addressComponents'] ?? array() ) as $comp ) {
			$types = (array) ( $comp['types'] ?? array() );
			if ( in_array( 'country', $types, true ) ) {
				$country = strtoupper( (string) ( $comp['shortText'] ?? '' ) );
			}
			if ( in_array( 'postal_code', $types, true ) ) {
				$postal = (string) ( $comp['longText'] ?? '' );
			}
		}
		if ( 'raiz' !== $city['slug'] && '' !== $country && $country !== strtoupper( $city['country'] ) ) {
			return array( 'action' => 'skipped', 'status' => 'skipped', 'note' => 'fora_da_cidade_' . $country, 'post_id' => 0 );
		}

		$existing_id = self::find_post_by_place_id( (string) $place['id'] );
		if ( ! $existing_id ) {
			$dup = TF_REST_API::find_existing_duplicate(
				array(
					'title'     => $title,
					'post_type' => $post_type,
					'phone'     => $place['internationalPhoneNumber'] ?? '',
					'lat'       => $place['location']['latitude'] ?? 0.0,
					'lng'       => $place['location']['longitude'] ?? 0.0,
				)
			);
			$existing_id = $dup ? (int) $dup['post_id'] : 0;

			// O registro parecido já pertence a OUTRO local do Google (ex.: filial de mesma rede):
			// não sobrescreve; deixa para revisão manual.
			if ( $existing_id ) {
				$linked = (string) get_post_meta( $existing_id, '_tf_google_place_id', true );
				if ( '' !== $linked && $linked !== (string) $place['id'] ) {
					return array( 'action' => 'skipped', 'status' => 'skipped', 'note' => 'parecido_com_post_' . $existing_id, 'post_id' => $existing_id );
				}
			}
		}

		$closed = 'CLOSED_PERMANENTLY' === ( $place['businessStatus'] ?? '' );
		$meta   = self::build_meta( $place, $postal );

		if ( $existing_id ) {
			self::update_existing( $existing_id, $meta, $closed );
			return array( 'action' => 'updated', 'status' => 'processed', 'note' => 'atualizado', 'post_id' => $existing_id );
		}

		if ( $closed ) {
			return array( 'action' => 'skipped', 'status' => 'skipped', 'note' => 'fechado_permanentemente', 'post_id' => 0 );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => sanitize_text_field( $title ),
				'post_excerpt' => sanitize_textarea_field( (string) ( $place['editorialSummary']['text'] ?? '' ) ),
				'post_content' => '',
				'post_status'  => 'pending',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return array( 'action' => 'skipped', 'status' => 'error', 'note' => substr( $post_id->get_error_message(), 0, 190 ), 'post_id' => 0 );
		}

		$meta['_tf_verification_status'] = 'needs_review';
		self::save_meta( (int) $post_id, $meta );

		$terms = json_decode( (string) $row['terms_json'], true );
		$terms = is_array( $terms ) ? $terms : array();
		$price = self::price_term( (string) ( $place['priceLevel'] ?? '' ) );
		if ( $price ) {
			$terms['faixa_preco'] = array( $price );
		}
		self::assign_terms( (int) $post_id, $terms );

		return array( 'action' => 'created', 'status' => 'processed', 'note' => 'criado', 'post_id' => (int) $post_id );
	}

	/**
	 * Converte o Place Details nos metacampos do tf-core.
	 */
	private static function build_meta( array $place, string $postal ): array {
		$hours = '';
		if ( ! empty( $place['regularOpeningHours']['weekdayDescriptions'] ) ) {
			$hours = implode( "\n", array_map( 'strval', (array) $place['regularOpeningHours']['weekdayDescriptions'] ) );
		}

		$rating_count = (int) ( $place['userRatingCount'] ?? 0 );
		$maps_url     = (string) ( $place['googleMapsUri'] ?? '' );

		$meta = array(
			'_tf_official_name'    => (string) ( $place['displayName']['text'] ?? '' ),
			'_tf_address'          => (string) ( $place['formattedAddress'] ?? '' ),
			'_tf_postal_code'      => $postal,
			'_tf_lat'              => (float) ( $place['location']['latitude'] ?? 0.0 ),
			'_tf_lng'              => (float) ( $place['location']['longitude'] ?? 0.0 ),
			'_tf_phone'            => (string) ( $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? '' ),
			'_tf_website'          => (string) ( $place['websiteUri'] ?? '' ),
			'_tf_opening_hours'    => $hours,
			'_tf_price_range'      => self::price_label( (string) ( $place['priceLevel'] ?? '' ) ),
			'_tf_rating_value'     => (float) ( $place['rating'] ?? 0.0 ),
			'_tf_rating_count'     => $rating_count,
			'_tf_rating_source'    => $rating_count > 0 ? 'Google Maps' : '',
			'_tf_source_url'       => $maps_url,
			'_tf_source_name'      => 'Google Maps / Google Places',
			'_tf_last_verified_at' => gmdate( 'Y-m-d' ),
			'_tf_google_place_id'  => (string) $place['id'],
			'_tf_google_maps_url'  => $maps_url,
			'_tf_ai_confidence'    => self::completeness_score( $place ),
		);

		return array_filter(
			$meta,
			static function ( $v ) {
				return '' !== $v && 0.0 !== $v && 0 !== $v;
			}
		);
	}

	/**
	 * Atualiza apenas os dados "vivos" de um registro existente, sem tocar em título, texto ou status.
	 */
	private static function update_existing( int $post_id, array $meta, bool $closed ): void {
		// Campos sempre sincronizados com o Google.
		$always = array( '_tf_rating_value', '_tf_rating_count', '_tf_rating_source', '_tf_opening_hours', '_tf_google_place_id', '_tf_google_maps_url', '_tf_last_verified_at', '_tf_phone' );
		// Campos preenchidos apenas se estiverem vazios (preserva edição manual).
		$fill_empty = array( '_tf_address', '_tf_postal_code', '_tf_lat', '_tf_lng', '_tf_website', '_tf_price_range', '_tf_official_name', '_tf_source_url', '_tf_source_name' );

		$to_save = array();
		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $always, true ) ) {
				$to_save[ $key ] = $value;
			} elseif ( in_array( $key, $fill_empty, true ) ) {
				$current = get_post_meta( $post_id, $key, true );
				$is_empty_coord = in_array( $key, array( '_tf_lat', '_tf_lng' ), true ) && 0.0 === (float) $current;
				if ( '' === $current || null === $current || $is_empty_coord ) {
					$to_save[ $key ] = $value;
				}
			}
		}
		if ( $closed ) {
			$to_save['_tf_verification_status'] = 'closed';
		}
		self::save_meta( $post_id, $to_save );
	}

	private static function save_meta( int $post_id, array $meta ): void {
		$schema = TF_Meta_Fields::get_fields_schema();
		foreach ( $meta as $key => $value ) {
			if ( isset( $schema[ $key ] ) ) {
				$value = TF_Meta_Fields::sanitize_meta_value( $value, $schema[ $key ]['type'], $key );
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	private static function assign_terms( int $post_id, array $terms ): void {
		foreach ( $terms as $taxonomy => $names ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( get_post_type( $post_id ), $taxonomy ) ) {
				continue;
			}
			$ids = array();
			foreach ( (array) $names as $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				$term = term_exists( $name, $taxonomy );
				if ( ! $term ) {
					$term = wp_insert_term( $name, $taxonomy );
				}
				if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
					$ids[] = (int) $term['term_id'];
				}
			}
			if ( $ids ) {
				wp_set_object_terms( $post_id, $ids, $taxonomy, true );
			}
		}
	}

	public static function find_post_by_place_id( string $place_id ): int {
		if ( '' === $place_id ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => tf_get_establishment_post_types(),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_tf_google_place_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $place_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Nota de completude dos dados (0-100) usada na fila de moderação.
	 */
	private static function completeness_score( array $place ): int {
		$score = 50;
		$score += ! empty( $place['formattedAddress'] ) ? 10 : 0;
		$score += ! empty( $place['internationalPhoneNumber'] ) || ! empty( $place['nationalPhoneNumber'] ) ? 10 : 0;
		$score += ! empty( $place['websiteUri'] ) ? 10 : 0;
		$score += ! empty( $place['regularOpeningHours'] ) ? 10 : 0;
		$score += (int) ( $place['userRatingCount'] ?? 0 ) >= 20 ? 10 : 0;
		return min( 100, $score );
	}

	private static function price_label( string $level ): string {
		$map = array(
			'PRICE_LEVEL_FREE'           => 'Gratuito',
			'PRICE_LEVEL_INEXPENSIVE'    => '$',
			'PRICE_LEVEL_MODERATE'       => '$$',
			'PRICE_LEVEL_EXPENSIVE'      => '$$$',
			'PRICE_LEVEL_VERY_EXPENSIVE' => '$$$$',
		);
		return $map[ $level ] ?? '';
	}

	private static function price_term( string $level ): string {
		$map = array(
			'PRICE_LEVEL_FREE'           => 'Gratuito',
			'PRICE_LEVEL_INEXPENSIVE'    => '$ — Econômico',
			'PRICE_LEVEL_MODERATE'       => '$$ — Moderado',
			'PRICE_LEVEL_EXPENSIVE'      => '$$$ — Superior',
			'PRICE_LEVEL_VERY_EXPENSIVE' => '$$$$ — Premium / Luxo',
		);
		return $map[ $level ] ?? '';
	}

	/* ---------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------ */

	/**
	 * Chamada à Places API (New).
	 *
	 * @param string     $method     GET ou POST.
	 * @param string     $path       Caminho relativo a API_BASE.
	 * @param string     $field_mask Campos solicitados (define o custo da chamada).
	 * @param array|null $body       Corpo JSON (POST).
	 * @param array      $query      Parâmetros de query string (GET).
	 * @return array|WP_Error
	 */
	private static function request( string $method, string $path, string $field_mask, ?array $body = null, array $query = array() ) {
		$url = self::API_BASE . $path;
		if ( $query ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'X-Goog-Api-Key'   => self::get_api_key(),
				'X-Goog-FieldMask' => $field_mask,
				'Content-Type'     => 'application/json',
				// Permite chaves com restrição de "Sites" (referenciadores HTTP) configuradas com o domínio do portal.
				'Referer'          => home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tf_places_http', 'Falha de conexão com o Google: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}

		$message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
		$reason  = self::extract_error_reason( is_array( $data ) ? $data : array() );
		if ( '' !== $reason ) {
			$message .= ' [' . $reason . ']';
			$hint     = self::reason_hint( $reason );
			if ( '' !== $hint ) {
				$message .= ' → ' . $hint;
			}
		}
		if ( 404 === $code ) {
			return new WP_Error( 'tf_places_not_found', 'Local não encontrado no Google: ' . $message );
		}
		if ( in_array( $code, array( 401, 403 ), true ) || ( 400 === $code && false !== stripos( $message, 'api key' ) ) ) {
			return new WP_Error( 'tf_places_auth', 'Google Places recusou a chave (' . $code . '): ' . $message );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'tf_places_quota', 'Cota da Google Places API excedida: ' . $message );
		}
		return new WP_Error( 'tf_places_api', 'Google Places API (' . $code . '): ' . $message );
	}
	/**
	 * Extrai o motivo técnico (ErrorInfo.reason) da resposta de erro do Google.
	 */
	private static function extract_error_reason( array $data ): string {
		foreach ( (array) ( $data['error']['details'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && ! empty( $detail['reason'] ) ) {
				return (string) $detail['reason'];
			}
		}
		return (string) ( $data['error']['status'] ?? '' );
	}

	/**
	 * Explicação em português para os motivos de recusa mais comuns.
	 */
	private static function reason_hint( string $reason ): string {
		$hints = array(
			'API_KEY_HTTP_REFERRER_BLOCKED' => 'A chave tem restrição de "Sites" (referenciadores HTTP) e o domínio ' . wp_parse_url( home_url(), PHP_URL_HOST ) . ' não está na lista. Adicione "' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/*" e "*.' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/*", ou troque a restrição para "Endereços IP" com o IP do servidor.',
			'API_KEY_IP_ADDRESS_BLOCKED'    => 'A chave tem restrição de "Endereços IP" e o IP de saída do servidor não está na lista.',
			'API_KEY_SERVICE_BLOCKED'       => 'Em "Restrições de API" da chave, falta marcar a "Places API (New)" (a "Places API" antiga é outra API).',
			'SERVICE_DISABLED'              => 'A "Places API (New)" não está ativada no projeto do Google Cloud desta chave. Ative em APIs e serviços > Biblioteca.',
			'BILLING_DISABLED'              => 'O projeto do Google Cloud não tem faturamento ativo. Vincule uma conta de faturamento ao projeto.',
			'API_KEY_INVALID'               => 'A chave é inválida ou foi apagada. Confira se copiou a chave inteira.',
			'API_KEY_ANDROID_APP_BLOCKED'   => 'A chave está restrita a apps Android; use uma chave sem restrição de aplicativo ou restrita por IP/site.',
			'API_KEY_IOS_APP_BLOCKED'       => 'A chave está restrita a apps iOS; use uma chave sem restrição de aplicativo ou restrita por IP/site.',
			'RATE_LIMIT_EXCEEDED'           => 'Limite de requisições por minuto atingido; aguarde ou aumente a cota.',
			'RESOURCE_EXHAUSTED'            => 'Cota diária esgotada; aumente a cota em APIs e serviços > Places API (New) > Cotas.',
		);
		return $hints[ $reason ] ?? '';
	}
}

TF_Google_Places::init();
