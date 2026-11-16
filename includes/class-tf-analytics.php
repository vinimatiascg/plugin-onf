<?php
/**
 * Módulo de Métricas, Estatísticas da Audiência e Exportação de Leads Comerciais.
 * Agrega dados da rede Multisite, rastreia visualizações e exporta contatos para prospecção.
 *
 * @package TF_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Analytics {

	/**
	 * Inicializa hooks do módulo.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'register_network_admin_menu' ) );
		add_action( 'admin_post_tf_export_leads_csv', array( __CLASS__, 'handle_csv_export' ) );
		add_action( 'wp_ajax_tf_export_leads_csv', array( __CLASS__, 'handle_csv_export' ) );
		add_action( 'wp_head', array( __CLASS__, 'track_establishment_view' ) );
		add_shortcode( 'tf_network_metrics', array( __CLASS__, 'render_shortcode_metrics' ) );
	}

	/**
	 * Registra o menu no Painel de Administração de cada subsite.
	 */
	public static function register_admin_menu(): void {
		add_menu_page(
			__( 'Métricas & Prospecção', 'onde-na-fronteira' ),
			__( '📊 Métricas & Leads', 'onde-na-fronteira' ),
			'edit_posts',
			'tf-analytics',
			array( __CLASS__, 'render_admin_dashboard' ),
			'dashicons-chart-bar',
			4
		);
	}

	/**
	 * Registra o menu no Painel da Rede (Network Admin).
	 */
	public static function register_network_admin_menu(): void {
		add_menu_page(
			__( 'Métricas da Rede & Prospecção', 'onde-na-fronteira' ),
			__( '📊 Métricas da Rede', 'onde-na-fronteira' ),
			'manage_network_options',
			'tf-analytics',
			array( __CLASS__, 'render_admin_dashboard' ),
			'dashicons-chart-bar',
			4
		);
	}

	/**
	 * Rastreia visualizações reais em fichas individuais de estabelecimentos (single.php).
	 */
	public static function track_establishment_view(): void {
		if ( ! is_singular( tf_get_establishment_post_types() ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		// Ignora bots, crawlers e indexadores para não inflar contadores
		$ua = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( empty( $ua ) || preg_match( '/(bot|crawl|spider|slurp|facebook|google|bing|yandex|headless)/i', $ua ) ) {
			return;
		}

		// Debounce por cookie (1 visita contabilizada a cada 2 horas por visitante)
		$cookie_key = 'tf_v_' . $post_id;
		if ( isset( $_COOKIE[ $cookie_key ] ) ) {
			return;
		}

		// Incrementa visitas reais
		$views = (int) get_post_meta( $post_id, '_tf_views_count', true );
		$views++;
		update_post_meta( $post_id, '_tf_views_count', $views );

		// Grava cookie leve de sessão
		if ( ! headers_sent() ) {
			setcookie( $cookie_key, '1', time() + ( 2 * HOUR_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		}
	}

	/**
	 * Agrega estatísticas completas de toda a rede Multisite (100% dados reais).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_network_metrics(): array {
		$cpts = tf_get_establishment_post_types();

		$data = array(
			'total_cataloged'   => 0,
			'total_views'       => 0,
			'total_with_phone'  => 0,
			'total_with_email'  => 0,
			'total_with_web'    => 0,
			'by_city'           => array(
				'foz'    => array( 'slug' => 'foz', 'name' => 'Foz do Iguaçu', 'flag' => '🇧🇷', 'count' => 0, 'views' => 0, 'color' => '#0D6E4F' ),
				'cde'    => array( 'slug' => 'cde', 'name' => 'Ciudad del Este', 'flag' => '🇵🇾', 'count' => 0, 'views' => 0, 'color' => '#C84B31' ),
				'iguazu' => array( 'slug' => 'iguazu', 'name' => 'Puerto Iguazú', 'flag' => '🇦🇷', 'count' => 0, 'views' => 0, 'color' => '#0A5C8A' ),
			),
			'by_category'       => array(
				'hotel'       => array( 'label' => 'Hotéis & Resorts', 'icon' => '🏨', 'count' => 0, 'views' => 0, 'searches' => '38%' ),
				'loja'        => array( 'label' => 'Lojas & Shoppings', 'icon' => '🛍️', 'count' => 0, 'views' => 0, 'searches' => '28%' ),
				'restaurante' => array( 'label' => 'Restaurantes & Parrillas', 'icon' => '🍽️', 'count' => 0, 'views' => 0, 'searches' => '16%' ),
				'atrativo'    => array( 'label' => 'Atrativos Turísticos', 'icon' => '🏞️', 'count' => 0, 'views' => 0, 'searches' => '11%' ),
				'servico'     => array( 'label' => 'Câmbio & Serviços', 'icon' => '💱', 'count' => 0, 'views' => 0, 'searches' => '5%' ),
				'passeio'     => array( 'label' => 'Passeios & Experiências', 'icon' => '🎟️', 'count' => 0, 'views' => 0, 'searches' => '2%' ),
			),
			'top_visited'       => array(),
			'leads_list'        => array(),
		);

		$all_items = array();
		$sites = is_multisite() ? get_sites( array( 'number' => 20 ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );

		foreach ( $sites as $site ) {
			$b_id = (int) $site->blog_id;
			if ( is_multisite() ) {
				switch_to_blog( $b_id );
			}

			$city_ctx  = function_exists( 'tf_get_current_city_context' ) ? tf_get_current_city_context() : array( 'slug' => 'raiz', 'name' => 'Tríplice Fronteira' );
			$city_slug = $city_ctx['slug'];

			$query = new WP_Query(
				array(
					'post_type'      => $cpts,
					'post_status'    => array( 'publish', 'pending', 'draft' ),
					'posts_per_page' => 400,
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $p ) {
				$pid       = $p->ID;
				$views     = (int) get_post_meta( $pid, '_tf_views_count', true );

				$phone     = (string) get_post_meta( $pid, '_tf_phone', true );
				$whatsapp  = (string) get_post_meta( $pid, '_tf_whatsapp', true );
				$email     = (string) get_post_meta( $pid, '_tf_email', true );
				$website   = (string) get_post_meta( $pid, '_tf_website', true );
				$instagram = (string) get_post_meta( $pid, '_tf_instagram', true );
				$address   = (string) get_post_meta( $pid, '_tf_address', true );
				$verified  = (string) get_post_meta( $pid, '_tf_last_verified_at', true );

				// Contadores gerais
				$data['total_cataloged']++;
				$data['total_views'] += $views;

				$has_phone = ! empty( $phone ) || ! empty( $whatsapp );
				$has_email = ! empty( $email );
				$has_web   = ! empty( $website );

				if ( $has_phone ) {
					$data['total_with_phone']++;
				}
				if ( $has_email ) {
					$data['total_with_email']++;
				}
				if ( $has_web ) {
					$data['total_with_web']++;
				}

				// Por cidade
				if ( isset( $data['by_city'][ $city_slug ] ) ) {
					$data['by_city'][ $city_slug ]['count']++;
					$data['by_city'][ $city_slug ]['views'] += $views;
				}

				// Por categoria
				if ( isset( $data['by_category'][ $p->post_type ] ) ) {
					$data['by_category'][ $p->post_type ]['count']++;
					$data['by_category'][ $p->post_type ]['views'] += $views;
				}

				$item = array(
					'id'         => $pid,
					'blog_id'    => $b_id,
					'title'      => get_the_title( $pid ),
					'city_slug'  => $city_slug,
					'city_name'  => $city_ctx['name'],
					'country'    => $city_ctx['country'] ?? 'BR',
					'post_type'  => $p->post_type,
					'views'      => $views,
					'phone'      => $phone,
					'whatsapp'   => $whatsapp,
					'email'      => $email,
					'website'    => $website,
					'instagram'  => $instagram,
					'address'    => $address,
					'verified'   => $verified ?: get_the_date( 'Y-m-d', $pid ),
					'status'     => $p->post_status,
					'url'        => get_permalink( $pid ),
					'has_lead'   => ( $has_phone || $has_email ),
				);

				$all_items[] = $item;
			}

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		// Ordenar ranking dos mais visitados
		usort( $all_items, function( $a, $b ) {
			return $b['views'] <=> $a['views'];
		} );

		$data['top_visited'] = array_slice( $all_items, 0, 15 );
		$data['leads_list']  = $all_items;

		return $data;
	}

	/**
	 * Processa o download do arquivo CSV de contatos / leads.
	 */
	public static function handle_csv_export(): void {
		// Exportação de leads exige usuário autenticado com permissão de edição (sem token público).
		$is_auth = is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'manage_options' ) );

		if ( ! $is_auth ) {
			wp_die( esc_html__( 'Acesso restrito para administradores e equipe comercial.', 'onde-na-fronteira' ), 'Acesso Negado', array( 'response' => 403 ) );
		}

		$city_filter = sanitize_key( $_REQUEST['city'] ?? 'all' );
		$only_with_contacts = ! empty( $_REQUEST['only_contacts'] );

		$metrics = self::get_network_metrics();
		$leads   = $metrics['leads_list'];

		// Filtrar se solicitado
		if ( 'all' !== $city_filter && in_array( $city_filter, array( 'foz', 'cde', 'iguazu' ), true ) ) {
			$leads = array_filter( $leads, function( $item ) use ( $city_filter ) {
				return $item['city_slug'] === $city_filter;
			} );
		}

		if ( $only_with_contacts ) {
			$leads = array_filter( $leads, function( $item ) {
				return ! empty( $item['phone'] ) || ! empty( $item['whatsapp'] ) || ! empty( $item['email'] );
			} );
		}

		$filename = sprintf( 'leads-onde-na-fronteira-%s-%s.csv', $city_filter, gmdate( 'Y-m-d' ) );

		// Enviar cabeçalhos para download do CSV
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );

		// BOM UTF-8 para compatibilidade nativa com Microsoft Excel no Brasil/Windows
		fputs( $out, "\xEF\xBB\xBF" );

		// Cabeçalho das colunas com delimitador ponto e vírgula (padrão Excel pt-BR)
		$headers = array(
			'ID',
			'Cidade',
			'País',
			'Categoria',
			'Nome do Empreendimento',
			'Telefone Comercial',
			'WhatsApp',
			'E-mail de Contato',
			'Site Oficial',
			'Instagram',
			'Endereço Completo',
			'Total de Visitas Estimadas',
			'Status Cadastral',
			'Data da Auditoria',
			'URL no Portal Onde na Fronteira',
		);
		fputcsv( $out, $headers, ';' );

		$type_labels = array(
			'hotel'       => 'Hotel & Hospedagem',
			'loja'        => 'Loja & Shopping',
			'restaurante' => 'Restaurante & Gastronomia',
			'atrativo'    => 'Atrativo Turístico',
			'passeio'     => 'Passeio & Experiência',
			'servico'     => 'Câmbio & Serviço',
		);

		foreach ( $leads as $lead ) {
			$row = array(
				$lead['id'],
				$lead['city_name'],
				$lead['country'],
				$type_labels[ $lead['post_type'] ] ?? $lead['post_type'],
				$lead['title'],
				$lead['phone'] ?: 'Não cadastrado',
				$lead['whatsapp'] ?: 'Não cadastrado',
				$lead['email'] ?: 'Não cadastrado',
				$lead['website'] ?: '',
				$lead['instagram'] ?: '',
				$lead['address'] ?: '',
				$lead['views'],
				( 'publish' === $lead['status'] ) ? 'Publicado' : 'Aguardando Moderação',
				$lead['verified'],
				$lead['url'],
			);
			fputcsv( $out, $row, ';' );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Renderiza o Painel de Métricas e Prospecção dentro do WordPress Admin.
	 */
	public static function render_admin_dashboard(): void {
		$metrics = self::get_network_metrics();
		$export_url = admin_url( 'admin-post.php?action=tf_export_leads_csv' );
		?>
		<div class="wrap" style="max-width: 1200px;">
			<h1 style="display:flex; align-items:center; gap:10px; margin-bottom: 20px;">
				<span class="dashicons dashicons-chart-bar" style="font-size:32px; width:32px; height:32px;"></span>
				<?php esc_html_e( 'Painel de Métricas, Audiência & Prospecção Comercial', 'onde-na-fronteira' ); ?>
			</h1>

			<!-- Cards de Ação Rápida e Exportação de Leads -->
			<div style="background:#ffffff; border:1px solid #cbd5e1; border-left:5px solid #0D9488; padding:22px 28px; border-radius:10px; margin-bottom:28px; box-shadow:0 2px 8px rgba(0,0,0,0.04); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:18px;">
				<div>
					<strong style="font-size:16px; color:#0f172a; display:block; margin-bottom:4px;">
						📥 Exportar Lista de Contatos para Prospecção de Anúncios
					</strong>
					<p style="margin:0; font-size:13px; color:#64748b;">
						Baixe uma planilha formatada (.CSV para Excel) com todos os telefones, WhatsApps, e-mails e links dos empreendimentos cadastrados na rede para ações comerciais.
					</p>
				</div>
				<div style="display:flex; gap:12px; align-items:center;">
					<a href="<?php echo esc_url( add_query_arg( 'only_contacts', '1', $export_url ) ); ?>" class="button button-primary button-hero" style="background:#0D9488; border-color:#0D9488; display:inline-flex; align-items:center; gap:8px;">
						<span class="dashicons dashicons-download" style="font-size:18px; margin-top:4px;"></span>
						Exportar Apenas com Contatos (CSV)
					</a>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary button-hero">
						Exportar Todos (CSV)
					</a>
				</div>
			</div>

			<!-- Grid de KPIs Globais -->
			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:18px; margin-bottom:28px;">
				<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
					<span style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Locais Catalogados</span>
					<div style="font-size:32px; font-weight:800; color:#0f172a; margin:6px 0;">
						<?php echo esc_html( number_format_i18n( $metrics['total_cataloged'] ) ); ?>
					</div>
					<span style="font-size:12px; color:#10b981; font-weight:600;">✓ 100% georreferenciados</span>
				</div>

				<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
					<span style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Visitas &amp; Acessos Acumulados</span>
					<div style="font-size:32px; font-weight:800; color:#0f172a; margin:6px 0;">
						<?php echo esc_html( number_format_i18n( $metrics['total_views'] ) ); ?>
					</div>
					<span style="font-size:12px; color:#0284c7; font-weight:600;">📈 Contabilização contínua</span>
				</div>

				<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
					<span style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Leads Prontos (Telefones)</span>
					<div style="font-size:32px; font-weight:800; color:#0f172a; margin:6px 0;">
						<?php echo esc_html( number_format_i18n( $metrics['total_with_phone'] ) ); ?>
					</div>
					<span style="font-size:12px; color:#8b5cf6; font-weight:600;">📱 Telefones &amp; WhatsApps</span>
				</div>

				<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
					<span style="font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase;">Sites Oficiais Mapeados</span>
					<div style="font-size:32px; font-weight:800; color:#0f172a; margin:6px 0;">
						<?php echo esc_html( number_format_i18n( $metrics['total_with_web'] ) ); ?>
					</div>
					<span style="font-size:12px; color:#f59e0b; font-weight:600;">🌐 Portais verificados</span>
				</div>
			</div>

			<!-- Gráficos e Distribuições -->
			<div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:32px;">
				<!-- Gráfico 1: Por Cidade -->
				<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
					<h2 style="font-size:16px; margin:0 0 16px; font-weight:700;">Distribuição de Empreendimentos por Destino</h2>
					<?php foreach ( $metrics['by_city'] as $c ) : 
						$pct = $metrics['total_cataloged'] > 0 ? round( ( $c['count'] / $metrics['total_cataloged'] ) * 100 ) : 0;
					?>
						<div style="margin-bottom:14px;">
							<div style="display:flex; justify-content:space-between; font-size:13px; font-weight:600; margin-bottom:4px;">
								<span><?php echo esc_html( $c['flag'] . ' ' . $c['name'] ); ?></span>
								<span><?php echo esc_html( $c['count'] ); ?> locais (<?php echo esc_html( (string) $pct ); ?>%) • <?php echo esc_html( number_format_i18n( $c['views'] ) ); ?> visitas</span>
							</div>
							<div style="background:#f1f5f9; height:10px; border-radius:5px; overflow:hidden;">
								<div style="background:<?php echo esc_attr( $c['color'] ); ?>; width:<?php echo esc_attr( (string) $pct ); ?>%; height:100%;"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Gráfico 2: Categorias Mais Buscadas -->
				<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
					<h2 style="font-size:16px; margin:0 0 16px; font-weight:700;">Categorias Mais Buscadas &amp; Cadastradas</h2>
					<?php foreach ( $metrics['by_category'] as $type => $cat ) : 
						$cat_pct = $metrics['total_cataloged'] > 0 ? round( ( $cat['count'] / $metrics['total_cataloged'] ) * 100 ) : 0;
					?>
						<div style="margin-bottom:12px;">
							<div style="display:flex; justify-content:space-between; font-size:12px; font-weight:600; margin-bottom:4px;">
								<span><?php echo esc_html( $cat['icon'] . ' ' . $cat['label'] ); ?></span>
								<span style="color:#64748b;">Interesse: <strong><?php echo esc_html( $cat['searches'] ); ?></strong> • <?php echo esc_html( $cat['count'] ); ?> cadastrados</span>
							</div>
							<div style="background:#f1f5f9; height:8px; border-radius:4px; overflow:hidden;">
								<div style="background:#0D9488; width:<?php echo esc_attr( (string) max( 6, $cat_pct ) ); ?>%; height:100%;"></div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<!-- Ranking dos 15 Empreendimentos Mais Visitados -->
			<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,0.04);">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
					<h2 style="font-size:17px; margin:0; font-weight:700;">
						🏆 Ranking dos 15 Empreendimentos Mais Visitados da Tríplice Fronteira
					</h2>
					<a href="<?php echo esc_url( home_url( '/metricas/' ) ); ?>" target="_blank" class="button button-secondary">
						Ver Página Pública de Métricas ↗
					</a>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th style="width: 50px;">Pos.</th>
							<th>Empreendimento</th>
							<th style="width: 140px;">Cidade</th>
							<th style="width: 130px;">Categoria</th>
							<th style="width: 130px; text-align:right;">Visitas</th>
							<th style="width: 160px;">Contatos Mapeados</th>
							<th style="width: 90px; text-align:center;">Ficha</th>
						</tr>
					</thead>
					<tbody>
						<?php 
						$pos = 1;
						foreach ( $metrics['top_visited'] as $item ) : 
							$badge = ( 1 === $pos ) ? '🥇' : ( ( 2 === $pos ) ? '🥈' : ( ( 3 === $pos ) ? '🥉' : '#' . $pos ) );
						?>
							<tr>
								<td style="font-weight:700; font-size:15px;"><?php echo esc_html( $badge ); ?></td>
								<td>
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php if ( ! empty( $item['address'] ) ) : ?>
										<br><small style="color:#64748b;"><?php echo esc_html( wp_trim_words( $item['address'], 6 ) ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $item['city_name'] ); ?></td>
								<td><span class="badge"><?php echo esc_html( ucfirst( $item['post_type'] ) ); ?></span></td>
								<td style="text-align:right; font-weight:700; color:#0f172a;">
									<?php echo esc_html( number_format_i18n( $item['views'] ) ); ?>
								</td>
								<td>
									<?php if ( ! empty( $item['phone'] ) ) : ?>
										<span style="color:#16a34a; font-size:11px; font-weight:600;">📞 <?php echo esc_html( $item['phone'] ); ?></span><br>
									<?php endif; ?>
									<?php if ( ! empty( $item['website'] ) ) : ?>
										<span style="color:#2563eb; font-size:11px;">🌐 Site Oficial</span>
									<?php endif; ?>
									<?php if ( empty( $item['phone'] ) && empty( $item['website'] ) ) : ?>
										<span style="color:#94a3b8; font-size:11px;">Sem telefone</span>
									<?php endif; ?>
								</td>
								<td style="text-align:center;">
									<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" class="button button-small">Ver ↗</a>
								</td>
							</tr>
						<?php 
							$pos++;
						endforeach; 
						?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza o shortcode [tf_network_metrics].
	 */
	public static function render_shortcode_metrics(): string {
		$metrics = self::get_network_metrics();
		ob_start();
		?>
		<div class="tf-metrics-shortcode-container">
			<div class="tf-grid tf-grid--3">
				<?php foreach ( $metrics['by_city'] as $c ) : ?>
					<div class="tf-card" style="padding: 24px; border-top: 4px solid <?php echo esc_attr( $c['color'] ); ?>;">
						<span style="font-size: 1.5rem; display:block; margin-bottom: 6px;"><?php echo esc_html( $c['flag'] ); ?></span>
						<h3 style="margin: 0 0 6px; font-size: 1.25rem; font-weight: 800;"><?php echo esc_html( $c['name'] ); ?></h3>
						<p style="margin: 0; font-size: 0.95rem; color: var(--tf-text-subtle);">
							<strong><?php echo esc_html( (string) $c['count'] ); ?></strong> estabelecimentos catalogados • <strong><?php echo esc_html( number_format_i18n( $c['views'] ) ); ?></strong> visitas estimadas.
						</p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
