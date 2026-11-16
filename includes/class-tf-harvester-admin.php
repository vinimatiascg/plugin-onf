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
			// Descobre (5 páginas por cidade) e já detalha um pequeno lote para aparecer na moderação.
			TF_Google_Places::run_network( 5, 5 );
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
		update_site_option( 'tf_places_daily_target', max( 1, min( 1000, (int) ( $_POST['tf_places_daily_target'] ?? 32 ) ) ) );
		update_site_option( 'tf_places_monthly_cap', max( 0, min( 100000, (int) ( $_POST['tf_places_monthly_cap'] ?? 1000 ) ) ) );

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
		$usage            = TF_Google_Places::get_daily_usage();
		$auto_on          = TF_Google_Places::is_enabled();
		$next_cron        = wp_next_scheduled( TF_Google_Places::CRON_HOOK );
		$reason_labels    = array(
			'fora_da_cidade_BR'        => 'Fora da cidade (Brasil)',
			'fora_da_cidade_PY'        => 'Fora da cidade (Paraguai)',
			'fora_da_cidade_AR'        => 'Fora da cidade (Argentina)',
			'fechado_permanentemente'  => 'Fechado permanentemente',
			'parecido_com_post'        => 'Parecido com outro local já cadastrado',
			'sem_nome'                 => 'Sem nome no Google',
			'nao_encontrado_no_google' => 'Removido do Google',
		);
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
							esc_html__( '✓ Rodada concluída: %1$d locais novos descobertos no Google (aguardando detalhamento), %2$d enviados para a Fila de Moderação, %3$d já cadastrados atualizados, %4$d descartados.', 'onde-na-fronteira' ),
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

			<?php if ( $has_key && ! $auto_on ) : ?>
				<div class="notice notice-warning"><p><strong>A varredura automática está desligada.</strong> Os locais descobertos só entram na Fila de Moderação quando são detalhados. Marque "Rodar automaticamente" nas configurações abaixo para o alimentador trabalhar sozinho ao longo do dia.</p></div>
			<?php endif; ?>

			<?php if ( ! $has_key ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'Configure a chave da Google Places API no quadro "Google Maps / Google Places API" abaixo para ativar a varredura.', 'onde-na-fronteira' ); ?></p></div>
			<?php endif; ?>

			<!-- Painel de Métricas -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:18px;margin:24px 0;">
				<div class="card" style="margin:0;padding:20px;border-left:4px solid #0D9488;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">1. Descobertos no Google</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $google_queued ); ?></div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#64748B;">aguardando detalhamento (ainda não aparecem na moderação)</p>
				</div>

				<div class="card" style="margin:0;padding:20px;border-left:4px solid #D97706;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">2. Na Fila de Moderação</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $total_pending ); ?></div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#64748B;">rascunhos pendentes prontos para aprovar</p>
				</div>

				<div class="card" style="margin:0;padding:20px;border-left:4px solid #059669;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">Processados (total)</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $google_processed ); ?></div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#64748B;"><?php echo esc_html( (string) $stats['created'] ); ?> criados · <?php echo esc_html( (string) ( $stats['skipped'] + $stats['error'] ) ); ?> descartados</p>
				</div>

				<div class="card" style="margin:0;padding:20px;border-left:4px solid #3B82F6;">
					<h3 style="margin:0 0 8px;font-size:0.9rem;text-transform:uppercase;color:#64748B;">Hoje</h3>
					<div style="font-size:1.8rem;font-weight:700;color:#0F172A;"><?php echo esc_html( (string) $usage['details'] ); ?> / <?php echo esc_html( (string) TF_Google_Places::effective_daily_target() ); ?></div>
					<p style="margin:6px 0 0;font-size:0.85rem;color:#64748B;">
						detalhados hoje · <?php echo esc_html( (string) array_sum( (array) $usage['searches'] ) ); ?> páginas de busca<br>
						<?php if ( $auto_on && $next_cron ) : ?>
							Próxima rodada automática: <?php echo esc_html( wp_date( 'H:i', (int) $next_cron ) ); ?>
						<?php else : ?>
							<span style="color:#B45309;">Automático desligado</span>
						<?php endif; ?>
					</p>
				</div>
			</div>

			<?php if ( ! empty( $stats['cities'] ) || ! empty( $stats['reasons'] ) ) : ?>
				<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:18px;margin:0 0 24px;">
					<?php if ( ! empty( $stats['cities'] ) ) : ?>
						<div class="card" style="margin:0;padding:16px 20px;max-width:none;">
							<h3 style="margin-top:0;">Por cidade</h3>
							<table class="widefat striped">
								<thead><tr><th>Cidade</th><th>Descobertos</th><th>Processados</th><th>Descartados</th></tr></thead>
								<tbody>
									<?php foreach ( $stats['cities'] as $c_name => $c ) : ?>
										<tr>
											<td><?php echo esc_html( (string) $c_name ); ?></td>
											<td><?php echo esc_html( (string) $c['queued'] ); ?></td>
											<td><?php echo esc_html( (string) $c['processed'] ); ?></td>
											<td><?php echo esc_html( (string) ( $c['skipped'] + $c['error'] ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $stats['reasons'] ) ) : ?>
						<div class="card" style="margin:0;padding:16px 20px;max-width:none;">
							<h3 style="margin-top:0;">Motivos de descarte</h3>
							<table class="widefat striped">
								<tbody>
									<?php foreach ( $stats['reasons'] as $r_key => $r_total ) : ?>
										<tr>
											<td><?php echo esc_html( $reason_labels[ $r_key ] ?? (string) $r_key ); ?></td>
											<td style="width:80px;text-align:right;"><?php echo esc_html( (string) $r_total ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<!-- Ações e Fila de Moderação -->
			<div class="card" style="padding:22px;margin-bottom:24px;max-width:none;">
				<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
					<div>
						<h2 style="margin:0 0 6px;">Alimentador Google Places &amp; Moderação</h2>
						<p style="margin:0;color:#64748B;">
							<strong>Como funciona:</strong> (1) o alimentador <em>descobre</em> locais no Google Maps por categoria e região; (2) depois <em>detalha</em> cada um (endereço, telefone, site, horários, nota) e o envia para a Fila de Moderação, ou atualiza o cadastro existente. No automático, isso é distribuído ao longo do dia.
						</p>
					</div>
					<div style="display:flex;gap:12px;flex-wrap:wrap;">
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'tf_trigger_harvest_action', 'tf_trigger_harvest_nonce' ); ?>
							<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
							<input type="hidden" name="tf_batch_type" value="google_process" />
							<button type="submit" class="button button-secondary" <?php disabled( ! $has_key ); ?>>
								▶ Detalhar e Enviar à Moderação (15 por cidade)
							</button>
						</form>
						<form method="post" style="display:inline;">
							<?php wp_nonce_field( 'tf_trigger_harvest_action', 'tf_trigger_harvest_nonce' ); ?>
							<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
							<input type="hidden" name="tf_batch_type" value="google_scan" />
							<button type="submit" class="button button-secondary" <?php disabled( ! $has_key ); ?>>
								🔍 Descobrir Novos Locais no Google
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
									<label><input type="checkbox" name="tf_places_enabled" value="1" <?php checked( (bool) get_site_option( 'tf_places_enabled', false ) ); ?> /> Rodar automaticamente (a cada 15 minutos, distribuindo a meta diária ao longo do dia)</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="tf_places_monthly_cap">Limite mensal (rede)</label></th>
								<td>
									<input type="number" min="0" max="100000" id="tf_places_monthly_cap" name="tf_places_monthly_cap" value="<?php echo esc_attr( (string) TF_Google_Places::get_monthly_cap() ); ?>" class="small-text" /> detalhamentos por mês
									<?php $m_usage = TF_Google_Places::get_monthly_usage(); ?>
									<span style="margin-left:8px;color:#64748B;">usados neste mês: <strong><?php echo esc_html( (string) $m_usage['details'] ); ?></strong></span>
									<p class="description"><strong>1000</strong> = cota gratuita mensal do Google para <em>Place Details Enterprise</em>. Ao atingir, o detalhamento pausa até o dia 1º (a descoberta continua, pois é gratuita). Use <strong>0</strong> para sem limite.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="tf_places_daily_target">Meta diária (rede)</label></th>
								<td>
									<input type="number" min="1" max="1000" id="tf_places_daily_target" name="tf_places_daily_target" value="<?php echo esc_attr( (string) TF_Google_Places::get_daily_target() ); ?>" class="small-text" /> locais processados por dia
									<p class="description">Total de locais detalhados por dia em toda a rede, divididos igualmente entre as cidades e espalhados em rodadas de 15 minutos (ex.: 32/dia ≈ 1 a cada 45 min; 32 × 31 dias = 992, dentro da cota gratuita de 1.000/mês). Cada local detalhado = 1 chamada <em>Place Details</em> (faixa Enterprise, paga após a cota gratuita mensal do Google). As buscas de descoberta usam a faixa mais barata (<em>IDs Only</em>). Locais já cadastrados são reverificados a cada 30 dias.</p>
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
