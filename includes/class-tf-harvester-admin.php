<?php
/**
 * Painel Administrativo do Alimentador Contínuo (Harvester)
 * Varredura de locais via Google Maps / Google Places API (New), com fila, deduplicação e moderação.
 *
 * @package TF_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Harvester_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'register_network_admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_trigger' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_settings_save' ) );
	}

	public static function register_admin_menu(): void {
		add_menu_page(
			__( 'Alimentador Contínuo', 'onde-na-fronteira' ),
			__( '⚡ Alimentador', 'onde-na-fronteira' ),
			'manage_options',
			'tf-harvester',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-cloud-upload',
			26
		);
	}

	public static function register_network_admin_menu(): void {
		add_menu_page(
			__( 'Alimentador Contínuo da Rede', 'onde-na-fronteira' ),
			__( '⚡ Alimentador Rede', 'onde-na-fronteira' ),
			'manage_network_options',
			'tf-harvester',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-cloud-upload',
			26
		);
	}

	/**
	 * URL base do painel (rede ou site) para redirecionamentos após POST.
	 */
	private static function dashboard_url(): string {
		$from_network = is_multisite() && ! empty( $_POST['tf_is_network'] ); // phpcs:ignore WordPress.Security.NonceVerification
		return $from_network ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	public static function handle_manual_trigger(): void {
		if ( ! is_admin() || ! isset( $_POST['tf_trigger_harvest_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tf_trigger_harvest_nonce'] ) ), 'tf_trigger_harvest_action' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		// Executa 1 rodada do alimentador Google Places (nativo em PHP, sem script externo).
		$batch_type = sanitize_key( $_POST['tf_batch_type'] ?? 'google' );
		if ( 'google_scan' === $batch_type ) {
			TF_Google_Places::run_network( 5, 0 );
		} elseif ( 'google_process' === $batch_type ) {
			TF_Google_Places::run_network( 0, 15 );
		} else {
			TF_Google_Places::run_network( 2, 10 );
		}

		update_option( 'tf_harvester_last_manual_trigger', current_time( 'mysql' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'tf-harvester', 'triggered' => '1' ), self::dashboard_url() ) );
		exit;
	}

	/**
	 * Salva a chave da Google Places API e as preferências do alimentador (opções de rede).
	 */
	public static function handle_settings_save(): void {
		if ( ! is_admin() || ! isset( $_POST['tf_places_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tf_places_settings_nonce'] ) ), 'tf_places_settings_action' ) ) {
			return;
		}
		$can = is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
		if ( ! $can ) {
			return;
		}

		$key = isset( $_POST['tf_google_places_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['tf_google_places_api_key'] ) ) ) : '';
		if ( ! empty( $_POST['tf_places_remove_key'] ) ) {
			delete_site_option( 'tf_google_places_api_key' );
		} elseif ( '' !== $key ) {
			update_site_option( 'tf_google_places_api_key', $key );
		}

		update_site_option( 'tf_places_enabled', ! empty( $_POST['tf_places_enabled'] ) );
		update_site_option( 'tf_places_daily_target', max( 10, min( 1000, (int) ( $_POST['tf_places_daily_target'] ?? 100 ) ) ) );

		TF_Google_Places::maybe_schedule_cron();

		wp_safe_redirect( add_query_arg( array( 'page' => 'tf-harvester', 'saved' => '1' ), self::dashboard_url() ) );
		exit;
	}

	public static function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_network_options' ) ) {
			return;
		}

		// Conta quantos estabelecimentos estão pendentes em toda a rede
		$cpts = tf_get_establishment_post_types();
		$total_pending = 0;
		$pending_list = array();

		$sites = is_multisite() ? get_sites( array( 'number' => 10 ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );
		foreach ( $sites as $site ) {
			if ( is_multisite() ) {
				switch_to_blog( (int) $site->blog_id );
			}
			$city = tf_get_current_city_context();
			foreach ( $cpts as $cpt ) {
				$q = new WP_Query( array(
					'post_type'      => $cpt,
					'post_status'    => array( 'pending', 'draft' ),
					'posts_per_page' => 15,
					'no_found_rows'  => true,
				) );
				$total_pending += count( $q->posts );
				foreach ( $q->posts as $p ) {
					$pending_list[] = array(
						'id'         => $p->ID,
						'title'      => get_the_title( $p->ID ),
						'cpt'        => $cpt,
						'city'       => $city['name'],
						'date'       => get_the_date( 'd/m/Y H:i', $p->ID ),
						'score'      => (int) get_post_meta( $p->ID, '_tf_ai_confidence', true ),
						'has_thumb'  => has_post_thumbnail( $p->ID ),
						'maps_url'   => (string) get_post_meta( $p->ID, '_tf_google_maps_url', true ),
						'blog_id'    => (int) $site->blog_id,
					);
				}
			}
			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		// Ordena lista de pendentes recentes
		usort( $pending_list, function( $a, $b ) {
			return $b['id'] <=> $a['id'];
		} );
		$pending_list = array_slice( $pending_list, 0, 15 );

		$last_run         = (array) get_site_option( 'tf_places_last_run', array() );
		$stats            = TF_Google_Places::get_queue_stats();
		$has_key          = '' !== TF_Google_Places::get_api_key();
		$google_queued    = $stats['queued'];
		$google_processed = $stats['processed'];
		$can_settings     = is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
		$is_network       = is_network_admin() ? '1' : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '⚡ Alimentador Contínuo (Harvester)', 'onde-na-fronteira' ); ?></h1>
			<p style="color:#64748B;font-size:1.02rem;">
				Rotina autônoma de varredura lenta e contínua do Google Maps / Google Places API na Tríplice Fronteira.
				Os estabelecimentos descobertos são deduplicados, validados e enviados para a fila de moderação sem sobrecarregar o servidor.
			</p>

			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( '✓ Configurações do Google Places salvas.', 'onde-na-fronteira' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['triggered'] ) && ! empty( $last_run ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice <?php echo empty( $last_run['errors'] ) ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
					<p>
						<?php
						printf(
							/* translators: 1: novos na fila, 2: criados, 3: atualizados, 4: ignorados */
							esc_html__( '✓ Rodada concluída: %1$d novos locais na fila, %2$d enviados para moderação, %3$d atualizados, %4$d ignorados.', 'onde-na-fronteira' ),
							(int) ( $last_run['found'] ?? 0 ),
							(int) ( $last_run['created'] ?? 0 ),
							(int) ( $last_run['updated'] ?? 0 ),
							(int) ( $last_run['skipped'] ?? 0 )
						);
						?>
					</p>
					<?php foreach ( (array) ( $last_run['errors'] ?? array() ) as $err ) : ?>
						<p style="color:#B91C1C;">⚠ <?php echo esc_html( (string) $err ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Configure a chave da Google Places API no quadro "Google Maps / Google Places API" abaixo para ativar a varredura.', 'onde-na-fronteira' ); ?></p></div>
			<?php endif; ?>

			<!-- Painel de Métricas -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:18px;margin:24px 0;">
				<div class="card" style="margin:0;padding:20px;border-left:4px solid #0D9488;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">Fila Google Places</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $google_queued ); ?> na fila</div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#059669;">● <?php echo esc_html( (string) $google_processed ); ?> já processados/auditados</p>
					<?php if ( ! empty( $last_run['finished_at'] ) ) : ?>
						<p style="margin:4px 0 0;font-size:0.8rem;color:#64748B;">Última rodada: <?php echo esc_html( (string) $last_run['finished_at'] ); ?></p>
					<?php endif; ?>
				</div>

				<div class="card" style="margin:0;padding:20px;border-left:4px solid #D97706;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">Aguardando Moderação</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $total_pending ); ?> locais</div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#64748B;">Prontos para aprovação rápida na rede</p>
				</div>

				<div class="card" style="margin:0;padding:20px;border-left:4px solid #3B82F6;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">Cidades Monitoradas</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;">3 Destinos</div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#64748B;">Foz do Iguaçu (BR), Ciudad del Este (PY) e Puerto Iguazú (AR)</p>
				</div>
			</div>

			<!-- Ações e Fila de Moderação -->
			<div class="card" style="padding:22px;margin-bottom:24px;max-width:none;">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
					<div>
						<h2 style="margin:0 0 6px;">Alimentador Google Places &amp; Moderação</h2>
						<p style="margin:0;color:#64748B;">
							O alimentador busca novos locais no Google Maps e atualiza estabelecimentos já cadastrados se encontrar dados mais recentes (telefone, notas, avaliações e horários).
						</p>
					</div>
					<div style="display:flex;gap:12px;flex-wrap:wrap;">
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'tf_trigger_harvest_action', 'tf_trigger_harvest_nonce' ); ?>
							<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
							<input type="hidden" name="tf_batch_type" value="google_process" />
							<button type="submit" class="button button-secondary" <?php disabled( ! $has_key ); ?>>
								▶ Processar Próximo Lote (15 locais)
							</button>
						</form>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'tf_trigger_harvest_action', 'tf_trigger_harvest_nonce' ); ?>
							<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
							<input type="hidden" name="tf_batch_type" value="google_scan" />
							<button type="submit" class="button button-secondary" <?php disabled( ! $has_key ); ?>>
								🔍 Buscar Mais no Google Places
							</button>
						</form>
						<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=tf-approval-queue' ) ); ?>" style="line-height:2.1;">
							⚡ Abrir Fila de Moderação (Aprovar em Lote) &rarr;
						</a>
					</div>
				</div>
			</div>

			<?php if ( $can_settings ) : ?>
				<?php $stored_key = (string) get_site_option( 'tf_google_places_api_key', '' ); ?>
				<!-- Configurações Google Places -->
				<div class="card" style="padding:22px;margin-bottom:24px;max-width:none;">
					<h2 style="margin-top:0;">🔑 Google Maps / Google Places API</h2>
					<form method="post">
						<?php wp_nonce_field( 'tf_places_settings_action', 'tf_places_settings_nonce' ); ?>
						<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="tf_google_places_api_key">Chave da API</label></th>
								<td>
									<?php if ( TF_Google_Places::api_key_from_constant() ) : ?>
										<p><strong>Definida no wp-config.php</strong> (constante <code>TF_GOOGLE_PLACES_API_KEY</code>).</p>
									<?php else : ?>
										<input type="password" class="regular-text" id="tf_google_places_api_key" name="tf_google_places_api_key" value="" autocomplete="off"
											placeholder="<?php echo esc_attr( $stored_key ? 'Chave salva (final …' . substr( $stored_key, -4 ) . ') — deixe em branco para manter' : 'AIza…' ); ?>" />
										<?php if ( $stored_key ) : ?>
											<label style="margin-left:10px;"><input type="checkbox" name="tf_places_remove_key" value="1" /> Remover chave salva</label>
										<?php endif; ?>
										<p class="description">Ative a <strong>Places API (New)</strong> no Google Cloud e restrinja a chave somente a essa API. Opção mais segura: <code>define( 'TF_GOOGLE_PLACES_API_KEY', '...' );</code> no wp-config.php.</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">Varredura automática</th>
								<td>
									<label><input type="checkbox" name="tf_places_enabled" value="1" <?php checked( (bool) get_site_option( 'tf_places_enabled', false ) ); ?> /> Rodar de hora em hora via WP-Cron</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="tf_places_daily_target">Meta diária (rede)</label></th>
								<td>
									<input type="number" min="10" max="1000" id="tf_places_daily_target" name="tf_places_daily_target" value="<?php echo esc_attr( (string) TF_Google_Places::get_daily_target() ); ?>" class="small-text" /> locais processados por dia
									<p class="description">Cada local processado consome 1 chamada Place Details; cada página de busca consome 1 chamada Text Search.</p>
								</td>
							</tr>
						</table>
						<?php submit_button( 'Salvar configurações', 'primary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>

			<!-- Tabela de Últimos Locais na Fila -->
			<div class="card" style="padding:20px;max-width:none;">
				<h3 style="margin-top:0;">Últimos Estabelecimentos Captados Aguardando Moderação</h3>
				<?php if ( empty( $pending_list ) ) : ?>
					<p style="color:#64748B;">Nenhum estabelecimento pendente no momento. Todos os locais foram revisados ou estão publicados!</p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width:50px;">Foto</th>
								<th>Título</th>
								<th style="width:130px;">Cidade</th>
								<th style="width:120px;">Categoria</th>
								<th style="width:100px;">Completude</th>
								<th style="width:130px;">Data de Entrada</th>
								<th style="width:170px;">Ação</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending_list as $item ) : ?>
								<tr>
									<td>
										<?php if ( $item['has_thumb'] ) : ?>
											<span style="color:#059669;font-weight:700;">[Foto]</span>
										<?php else : ?>
											<span style="color:#94A3B8;font-size:0.8rem;">Em branco</span>
										<?php endif; ?>
									</td>
									<td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
									<td><?php echo esc_html( $item['city'] ); ?></td>
									<td><span style="background:#E2E8F0;padding:2px 8px;border-radius:4px;font-size:0.8rem;"><?php echo esc_html( $item['cpt'] ); ?></span></td>
									<td>
										<span style="font-weight:700;color:<?php echo $item['score'] >= 85 ? '#059669' : '#D97706'; ?>">
											<?php echo esc_html( (string) $item['score'] ); ?>%
										</span>
									</td>
									<td><small><?php echo esc_html( $item['date'] ); ?></small></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url( get_admin_url( $item['blog_id'], 'admin.php?page=tf-approval-queue' ) ); ?>">
											Moderar
										</a>
										<?php if ( $item['maps_url'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $item['maps_url'] ); ?>" target="_blank" rel="noopener">Maps</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

TF_Harvester_Admin::init();
