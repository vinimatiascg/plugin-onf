<?php
/**
 * Painel Administrativo do Alimentador Contínuo (Harvester)
 * Monitoramento de importação lenta e contínua (~100 locais/dia) com validação e fila.
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

		// Dispara 1 rodada do alimentador Google Places ou legado
		$batch_type = sanitize_key( $_POST['tf_batch_type'] ?? 'google' );
		if ( 'google_scan' === $batch_type ) {
			$cmd = 'python3 /var/www/pipeline/harvester/google_places_harvester.py --search-queries 5 --process-batch 0 > /dev/null 2>&1 &';
		} elseif ( 'google_process' === $batch_type ) {
			$cmd = 'python3 /var/www/pipeline/harvester/google_places_harvester.py --search-queries 0 --process-batch 15 --delay 1.0 > /dev/null 2>&1 &';
		} else {
			$cmd = 'python3 /var/www/pipeline/harvester/google_places_harvester.py --search-queries 2 --process-batch 10 --delay 1.0 > /dev/null 2>&1 &';
		}
		exec( $cmd );

		update_option( 'tf_harvester_last_manual_trigger', current_time( 'mysql' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'tf-harvester', 'triggered' => '1' ), admin_url( 'admin.php' ) ) );
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

		$sites = get_sites( array( 'number' => 10 ) );
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
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
						'blog_id'    => (int) $site->blog_id,
					);
				}
			}
			restore_current_blog();
		}

		// Ordena lista de pendentes recentes
		usort( $pending_list, function( $a, $b ) {
			return $b['id'] <=> $a['id'];
		} );
		$pending_list = array_slice( $pending_list, 0, 15 );

		$daily_target = 100;
		// Estimativa diária baseada em pendentes e criados hoje
		$today_estimate = count( $pending_list );
		$progress_pct = min( 100, round( ( $today_estimate / $daily_target ) * 100 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '⚡ Alimentador Contínuo (Harvester)', 'onde-na-fronteira' ); ?></h1>
			<p style="color:#64748B;font-size:1.02rem;">
				Rotina autônoma de varredura lenta e contínua do Google Maps, OpenStreetMap e fontes oficiais da Tríplice Fronteira.
				Os estabelecimentos descobertos são deduplicados, validados e enviados para a fila de moderação sem sobrecarregar o servidor.
			</p>

			<?php if ( ! empty( $_GET['triggered'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( '✓ Rodada de captação iniciada em segundo plano! Novos estabelecimentos aparecerão na fila abaixo em instantes.', 'onde-na-fronteira' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Painel de Métricas -->
			<?php
			$sqlite_path = '/var/www/pipeline/data/google_places_harvest.db';
			if ( ! file_exists( $sqlite_path ) ) {
				$sqlite_path = dirname( ABSPATH ) . '/pipeline/data/google_places_harvest.db';
			}
			$google_queued = 0;
			$google_processed = 0;
			if ( file_exists( $sqlite_path ) && class_exists( 'SQLite3' ) ) {
				try {
					$db = new SQLite3( $sqlite_path, SQLITE3_OPEN_READONLY );
					$google_queued = (int) $db->querySingle( "SELECT count(*) FROM candidate_places WHERE status = 'queued'" );
					$google_processed = (int) $db->querySingle( "SELECT count(*) FROM candidate_places WHERE status = 'processed'" );
					$db->close();
				} catch ( Exception $e ) {}
			}
			?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:18px;margin:24px 0;">
				<div class="card" style="margin:0;padding:20px;border-left:4px solid #0D9488;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">Fila Google Places</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $google_queued ); ?> na fila</div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#059669;">● <?php echo esc_html( (string) $google_processed ); ?> já processados/auditados</p>
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
			<div class="card" style="padding:22px;margin-bottom:24px;">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
					<div>
						<h2 style="margin:0 0 6px;">Alimentador Google Places &amp; Moderação</h2>
						<p style="margin:0;color:#64748B;">
							O alimentador busca novos locais no Google e atualiza estabelecimentos já cadastrados se encontrar dados mais recentes (telefone, notas, fotos e horários).
						</p>
					</div>
					<div style="display:flex;gap:12px;flex-wrap:wrap;">
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'tf_trigger_harvest_action', 'tf_trigger_harvest_nonce' ); ?>
							<input type="hidden" name="tf_batch_type" value="google_process" />
							<button type="submit" class="button button-secondary">
								▶ Processar Próximo Lote (15 locais)
							</button>
						</form>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'tf_trigger_harvest_action', 'tf_trigger_harvest_nonce' ); ?>
							<input type="hidden" name="tf_batch_type" value="google_scan" />
							<button type="submit" class="button button-secondary">
								🔍 Buscar Mais no Google Places
							</button>
						</form>
						<a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=tf-approval-queue' ) ); ?>" style="line-height:2.1;">
							⚡ Abrir Fila de Moderação (Aprovar em Lote) &rarr;
						</a>
					</div>
				</div>
			</div>

			<!-- Tabela de Últimos Locais na Fila -->
			<div class="card" style="padding:20px;">
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
								<th style="width:100px;">Confiança IA</th>
								<th style="width:130px;">Data de Entrada</th>
								<th style="width:120px;">Ação</th>
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
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=tf-approval-queue' ) ); ?>">
											Moderar
										</a>
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
