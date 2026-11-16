<?php
/**
 * Sistema de Monetização Limpa: Google AdSense, /ads.txt, Carrossel de Logos
 * de Lojas Patrocinadoras, Banners e Captação de Leads da página "Anuncie Aqui".
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Monetization {

	/**
	 * Registra hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_monetization_menu' ) );
		add_action( 'admin_post_tf_save_monetization', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_tf_submit_ad_lead', array( __CLASS__, 'handle_ad_lead_submission' ) );
		add_action( 'admin_post_nopriv_tf_submit_ad_lead', array( __CLASS__, 'handle_ad_lead_submission' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_ads_txt' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'maybe_output_adsense_script' ), 30 );
	}

	/**
	 * Retorna a lista padrão de logos de lojas patrocinadoras para o carrossel.
	 *
	 * @return array
	 */
	public static function get_default_sponsor_logos(): array {
		$theme_uri = get_template_directory_uri();
		$net_home  = network_home_url( '/' );
		return array(
			array(
				'name'     => 'Cellshop Duty Free Foz',
				'city'     => 'Foz do Iguaçu - BR',
				'logo_url' => $theme_uri . '/assets/images/sponsors/cellshop.svg',
				'initials' => 'CS',
				'color'    => '#0F172A',
				'url'      => $net_home . 'foz/lojas/cellshop-duty-free-foz/',
			),
			array(
				'name'     => 'Catuaí Palladium Shopping Center',
				'city'     => 'Foz do Iguaçu - BR',
				'logo_url' => $theme_uri . '/assets/images/sponsors/catuai-palladium.svg',
				'initials' => 'CP',
				'color'    => '#0D6E4F',
				'url'      => $net_home . 'foz/lojas/catuai-palladium-shopping-center/',
			),
			array(
				'name'     => 'Shopping China Importados',
				'city'     => 'Ciudad del Este - PY',
				'logo_url' => $theme_uri . '/assets/images/sponsors/shopping-china.svg',
				'initials' => 'SC',
				'color'    => '#C84B31',
				'url'      => $net_home . 'cde/lojas/shopping-china-importados-cde/',
			),
			array(
				'name'     => 'Marco das Três Fronteiras',
				'city'     => 'Foz do Iguaçu - BR',
				'logo_url' => $theme_uri . '/assets/images/sponsors/marco-fronteiras.svg',
				'initials' => '3F',
				'color'    => '#B45309',
				'url'      => $net_home . 'foz/atrativos/marco-das-tres-fronteiras/',
			),
			array(
				'name'     => 'Atlas Câmbio & Turismo',
				'city'     => 'Foz do Iguaçu - BR',
				'logo_url' => $theme_uri . '/assets/images/sponsors/atlas-cambio.svg',
				'initials' => 'AC',
				'color'    => '#0F172A',
				'url'      => $net_home . 'foz/servicos/atlas-cambio-foz-do-iguacu/',
			),
			array(
				'name'     => 'Anuncie Sua Marca Aqui',
				'city'     => 'Destaque na Rede',
				'logo_url' => '',
				'initials' => '+ SUA LOGO',
				'color'    => '#0D9488',
				'url'      => $net_home . 'anuncie-aqui/',
			),
		);
	}

	/**
	 * Obtém as configurações globais de monetização da rede.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$defaults = array(
			'adsense_enabled'     => false,
			'adsense_client_id'   => '',
			'adsense_slot_banner' => '',
			'adsense_slot_side'   => '',
			'ads_txt_content'     => "# ads.txt — Onde na Fronteira (ondenafronteira.com.br)\n# Insira seu ID do Google AdSense no painel Monetizacao & Ads\n",
			'sponsor_enabled'     => true,
			'sponsor_name'        => 'Espaço Oficial para Parceiros da Tríplice Fronteira',
			'sponsor_tagline'     => 'Destaque seu hotel, loja em Ciudad del Este, restaurante, casa de câmbio ou agência de passeios para milhares de viajantes em Foz, CDE e Puerto Iguazú.',
			'sponsor_cta_text'    => 'Anuncie Aqui — Ver Planos e Formatos',
			'sponsor_cta_url'     => '',
			'sponsor_badge'       => '⭐ Destaque Patrocinado',
			'carousel_enabled'    => true,
			'carousel_title'      => 'Lojas e Marcas Parceiras em Destaque',
			'sponsor_logos_json'  => wp_json_encode( self::get_default_sponsor_logos(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);

		$saved = get_site_option( 'tf_monetization_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Injeta script assíncrono do Google AdSense somente se o Publisher ID estiver configurado.
	 */
	public static function maybe_output_adsense_script(): void {
		$s = self::get_settings();
		if ( empty( $s['adsense_enabled'] ) || empty( $s['adsense_client_id'] ) ) {
			return;
		}
		$client = esc_attr( $s['adsense_client_id'] );
		echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client . '" crossorigin="anonymous"></script>' . "\n";
	}

	/**
	 * Serve o arquivo /ads.txt na raiz.
	 */
	public static function serve_ads_txt(): void {
		$uri = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! is_string( $uri ) || ! preg_match( '#/ads\.txt$#', $uri ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		$s = self::get_settings();
		echo $s['ads_txt_content'] . "\n";
		exit;
	}

	/**
	 * Renderiza o Carrossel com a Logo das Lojas Patrocinadoras.
	 *
	 * @param bool $echo Se deve imprimir diretamente.
	 * @return string
	 */
	public static function render_sponsors_carousel( bool $echo = true ): string {
		$s = self::get_settings();
		if ( empty( $s['carousel_enabled'] ) ) {
			return '';
		}

		$logos = json_decode( (string) $s['sponsor_logos_json'], true );
		if ( ! is_array( $logos ) || empty( $logos ) ) {
			$logos = self::get_default_sponsor_logos();
		}

		$anuncie_url = home_url( '/anuncie-aqui/' );

		$carousel_id = 'tf-sponsors-' . wp_rand( 100, 999 );
		ob_start();
		?>
		<section class="tf-sponsors-carousel-section" aria-label="<?php echo esc_attr( $s['carousel_title'] ); ?>">
			<div class="tf-sponsors-carousel__header">
				<div style="display:flex;align-items:center;gap:10px;">
					<span class="tf-sponsors-carousel__label">🤝 <?php echo esc_html( $s['carousel_title'] ); ?></span>
				</div>
				<div style="display:flex;align-items:center;gap:12px;">
					<a href="<?php echo esc_url( $anuncie_url ); ?>" class="tf-sponsors-carousel__cta">Anuncie sua marca &rarr;</a>
					<div class="tf-sponsors-nav-btns" aria-hidden="true">
						<button type="button" class="tf-sponsors-nav-btn tf-sponsors-prev" onclick="document.getElementById('<?php echo esc_attr( $carousel_id ); ?>').scrollBy({left:-240,behavior:'smooth'})" aria-label="Anterior">&lsaquo;</button>
						<button type="button" class="tf-sponsors-nav-btn tf-sponsors-next" onclick="document.getElementById('<?php echo esc_attr( $carousel_id ); ?>').scrollBy({left:240,behavior:'smooth'})" aria-label="Próximo">&rsaquo;</button>
					</div>
				</div>
			</div>
			<div class="tf-sponsors-track-wrap" id="<?php echo esc_attr( $carousel_id ); ?>">
				<div class="tf-sponsors-track">
					<?php foreach ( $logos as $item ) : ?>
						<?php
						$name     = $item['name'] ?? 'Parceiro';
						$city     = $item['city'] ?? '';
						$logo_url = $item['logo_url'] ?? '';
						$initials = $item['initials'] ?? mb_substr( $name, 0, 2 );
						$color    = $item['color'] ?? '#0F172A';
						$url      = ! empty( $item['url'] ) ? $item['url'] : $anuncie_url;

						// Mapeamento forçado para garantir que os links levem sempre aos empreendimentos dentro do próprio site
						$internal_map = array(
							'cellshop'       => network_home_url( 'foz/lojas/cellshop-duty-free-foz/' ),
							'catuaí'         => network_home_url( 'foz/lojas/catuai-palladium-shopping-center/' ),
							'catuai'         => network_home_url( 'foz/lojas/catuai-palladium-shopping-center/' ),
							'shopping china' => network_home_url( 'cde/lojas/shopping-china-importados-cde/' ),
							'marco'          => network_home_url( 'foz/atrativos/marco-das-tres-fronteiras/' ),
							'atlas'          => network_home_url( 'foz/servicos/atlas-cambio-foz-do-iguacu/' ),
							'anuncie'        => network_home_url( 'anuncie-aqui/' ),
						);

						$lower_name = strtolower( $name );
						foreach ( $internal_map as $key => $int_url ) {
							if ( strpos( $lower_name, $key ) !== false ) {
								$url = $int_url;
								break;
							}
						}

						if ( strpos( $url, '/' ) === 0 ) {
							$url = network_home_url( ltrim( $url, '/' ) );
						}

						$net_domain  = wp_parse_url( network_home_url(), PHP_URL_HOST );
						$link_domain = wp_parse_url( $url, PHP_URL_HOST );
						$is_internal = ( $link_domain === $net_domain ) || empty( $link_domain );
						$target      = $is_internal ? '_self' : '_blank';
						$rel         = $is_internal ? 'noopener' : 'noopener sponsored';
						?>
						<a class="tf-sponsor-logo-card" href="<?php echo esc_url( $url ); ?>" target="<?php echo esc_attr( $target ); ?>" rel="<?php echo esc_attr( $rel ); ?>" title="<?php echo esc_attr( $name . ( $city ? ' — ' . $city : '' ) ); ?>">
							<?php if ( ! empty( $logo_url ) ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" class="tf-sponsor-logo-img" />
							<?php else : ?>
								<span class="tf-sponsor-logo-badge" style="background:<?php echo esc_attr( $color ); ?>;">
									<?php echo esc_html( $initials ); ?>
								</span>
							<?php endif; ?>
							<span class="tf-sponsor-logo-info">
								<strong><?php echo esc_html( $name ); ?></strong>
								<?php if ( $city ) : ?>
									<small><?php echo esc_html( $city ); ?></small>
								<?php endif; ?>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
		$html = (string) ob_get_clean();
		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $html;
		}
		return $html;
	}

	/**
	 * Renderiza um slot de anúncio limpo e não-intrusivo (AdSense ou Banner Institucional p/ Anuncie Aqui).
	 *
	 * @param string $placement 'horizontal' ou 'sidebar'.
	 * @param bool   $echo      Se deve imprimir diretamente.
	 * @return string
	 */
	public static function render_ad_slot( string $placement = 'horizontal', bool $echo = true ): string {
		$s       = self::get_settings();
		$anuncie = home_url( '/anuncie-aqui/' );
		$is_side = ( 'sidebar' === $placement );
		$min_h   = $is_side ? '250px' : '100px';
		$slot_id = $is_side ? $s['adsense_slot_side'] : $s['adsense_slot_banner'];

		ob_start();
		echo '<aside class="tf-ad-slot tf-ad-slot--' . esc_attr( $placement ) . '" aria-label="Publicidade e Patrocinadores" style="margin:24px 0;">';
		echo '<div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">';
		echo '<span>Publicidade • Espaço Patrocinado</span>';
		echo '<a href="' . esc_url( $anuncie ) . '" style="color:#64748b;font-weight:700;text-decoration:none;">Anuncie aqui →</a>';
		echo '</div>';

		if ( ! empty( $s['adsense_enabled'] ) && ! empty( $s['adsense_client_id'] ) && ! empty( $slot_id ) ) {
			echo '<div style="min-height:' . esc_attr( $min_h ) . ';background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;">';
			echo '<ins class="adsbygoogle" style="display:block;width:100%;" data-ad-client="' . esc_attr( $s['adsense_client_id'] ) . '" data-ad-slot="' . esc_attr( $slot_id ) . '" data-ad-format="auto" data-full-width-responsive="true"></ins>';
			echo '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
			echo '</div>';
		} elseif ( ! empty( $s['sponsor_enabled'] ) ) {
			$cta_url = ! empty( $s['sponsor_cta_url'] ) ? $s['sponsor_cta_url'] : $anuncie;
			?>
			<div style="min-height:<?php echo esc_attr( $min_h ); ?>;background:linear-gradient(135deg,#ffffff 0%,#f1f5f9 100%);border:1px dashed #94a3b8;border-radius:12px;padding:18px 22px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;">
				<div style="max-width:680px;">
					<span class="tf-badge tf-badge--top100" style="margin-bottom:6px;"><?php echo esc_html( $s['sponsor_badge'] ); ?></span>
					<strong style="display:block;font-size:1.05rem;color:#0f172a;margin-bottom:4px;"><?php echo esc_html( $s['sponsor_name'] ); ?></strong>
					<span style="font-size:0.88rem;color:#475569;display:block;"><?php echo esc_html( $s['sponsor_tagline'] ); ?></span>
				</div>
				<div>
					<a class="tf-btn tf-btn--outline" style="padding:10px 18px;font-size:0.88rem;" href="<?php echo esc_url( $cta_url ); ?>">
						<?php echo esc_html( $s['sponsor_cta_text'] ); ?> →
					</a>
				</div>
			</div>
			<?php
		}
		echo '</aside>';

		$html = (string) ob_get_clean();
		if ( $echo ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $html;
		}
		return $html;
	}

	/**
	 * Processa o envio do formulário público da página /anuncie-aqui/.
	 */
	public static function handle_ad_lead_submission(): void {
		if ( ! isset( $_POST['tf_ad_lead_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tf_ad_lead_nonce'] ) ), 'tf_submit_ad_lead_action' ) ) {
			wp_die( esc_html__( 'Verificação de segurança falhou.', 'onde-na-fronteira' ) );
		}

		$lead = array(
			'date'     => gmdate( 'Y-m-d H:i:s' ),
			'company'  => sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) ),
			'city'     => sanitize_text_field( wp_unslash( $_POST['company_city'] ?? '' ) ),
			'segment'  => sanitize_text_field( wp_unslash( $_POST['company_segment'] ?? '' ) ),
			'plan'     => sanitize_text_field( wp_unslash( $_POST['desired_plan'] ?? '' ) ),
			'contact'  => sanitize_text_field( wp_unslash( $_POST['contact_name'] ?? '' ) ),
			'email'    => sanitize_email( wp_unslash( $_POST['contact_email'] ?? '' ) ),
			'whatsapp' => sanitize_text_field( wp_unslash( $_POST['contact_whatsapp'] ?? '' ) ),
			'message'  => sanitize_textarea_field( wp_unslash( $_POST['contact_message'] ?? '' ) ),
		);

		$leads = get_site_option( 'tf_advertiser_leads', array() );
		if ( ! is_array( $leads ) ) {
			$leads = array();
		}
		array_unshift( $leads, $lead );
		update_site_option( 'tf_advertiser_leads', array_slice( $leads, 0, 200 ) );

		$ref = wp_get_referer() ?: home_url( '/anuncie-aqui/' );
		wp_safe_redirect( add_query_arg( 'lead_sent', '1', $ref ) );
		exit;
	}

	/**
	 * Registra o menu administrativo "Monetização & Ads".
	 */
	public static function register_monetization_menu(): void {
		add_menu_page(
			'Monetização, Google Ads & Patrocinadores — Onde na Fronteira',
			'Monetização & Ads',
			'manage_options',
			'tf-monetization',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-chart-line',
			29
		);
	}

	/**
	 * Salva as configurações de AdSense, Carrossel de Logos e Patrocinadores.
	 */
	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'tf_monetization_save_action', 'tf_monetization_nonce' );

		$new_settings = array(
			'adsense_enabled'     => ! empty( $_POST['adsense_enabled'] ),
			'adsense_client_id'   => sanitize_text_field( wp_unslash( $_POST['adsense_client_id'] ?? '' ) ),
			'adsense_slot_banner' => sanitize_text_field( wp_unslash( $_POST['adsense_slot_banner'] ?? '' ) ),
			'adsense_slot_side'   => sanitize_text_field( wp_unslash( $_POST['adsense_slot_side'] ?? '' ) ),
			'ads_txt_content'     => sanitize_textarea_field( wp_unslash( $_POST['ads_txt_content'] ?? '' ) ),
			'sponsor_enabled'     => ! empty( $_POST['sponsor_enabled'] ),
			'sponsor_name'        => sanitize_text_field( wp_unslash( $_POST['sponsor_name'] ?? '' ) ),
			'sponsor_tagline'     => sanitize_text_field( wp_unslash( $_POST['sponsor_tagline'] ?? '' ) ),
			'sponsor_cta_text'    => sanitize_text_field( wp_unslash( $_POST['sponsor_cta_text'] ?? '' ) ),
			'sponsor_cta_url'     => esc_url_raw( wp_unslash( $_POST['sponsor_cta_url'] ?? '' ) ),
			'sponsor_badge'       => sanitize_text_field( wp_unslash( $_POST['sponsor_badge'] ?? '' ) ),
			'carousel_enabled'    => ! empty( $_POST['carousel_enabled'] ),
			'carousel_title'      => sanitize_text_field( wp_unslash( $_POST['carousel_title'] ?? 'Lojas e Marcas Parceiras em Destaque' ) ),
			'sponsor_logos_json'  => wp_unslash( $_POST['sponsor_logos_json'] ?? '' ),
		);

		update_site_option( 'tf_monetization_settings', $new_settings );

		if ( function_exists( 'tf_flush_site_page_cache' ) ) {
			foreach ( array( 1, 2, 3, 4 ) as $b_id ) {
				tf_flush_site_page_cache( $b_id );
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'tf-monetization', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renderiza o painel de controle de Monetização, Carrossel de Logos, Google AdSense e Leads.
	 */
	public static function render_admin_page(): void {
		$s     = self::get_settings();
		$leads = get_site_option( 'tf_advertiser_leads', array() );
		?>
		<div class="wrap">
			<h1>💰 Monetização, Carrossel de Patrocinadores &amp; Google Ads — Onde na Fronteira</h1>
			<p>Configure o Carrossel de Logos das Lojas Patrocinadoras, os blocos do Google AdSense, o arquivo <code>/ads.txt</code> e o destaque de parceiros em toda a rede Multisite.</p>

			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong>Configurações de monetização salvas em toda a rede!</strong></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="background:#fff;padding:20px;border:1px solid #cbd5e1;border-radius:8px;max-width:920px;margin-bottom:28px;">
				<?php wp_nonce_field( 'tf_monetization_save_action', 'tf_monetization_nonce' ); ?>
				<input type="hidden" name="action" value="tf_save_monetization" />

				<h2>1. Carrossel com a Logo das Lojas Patrocinadoras (Shortcode: <code>[tf_sponsors_carousel]</code>)</h2>
				<table class="form-table">
					<tr>
						<th>Ativar Carrossel de Logos</th>
						<td><input type="checkbox" name="carousel_enabled" value="1" <?php checked( $s['carousel_enabled'], true ); ?> /> Exibir carrossel com as logos das lojas parceiras nas páginas principais</td>
					</tr>
					<tr>
						<th>Título do Carrossel</th>
						<td><input type="text" name="carousel_title" value="<?php echo esc_attr( $s['carousel_title'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Lojas do Carrossel (JSON)<br><small style="font-weight:normal;color:#64748b;">Informe <code>name</code>, <code>city</code>, <code>logo_url</code> (URL da imagem da logo), <code>initials</code>, <code>color</code> e <code>url</code>.</small></th>
						<td><textarea name="sponsor_logos_json" rows="10" class="large-text code"><?php echo esc_textarea( $s['sponsor_logos_json'] ); ?></textarea></td>
					</tr>
				</table>

				<h2>2. Google AdSense &amp; ads.txt (Shortcode: <code>[tf_ad_banner]</code>)</h2>
				<table class="form-table">
					<tr>
						<th>Ativar Google AdSense</th>
						<td><input type="checkbox" name="adsense_enabled" value="1" <?php checked( $s['adsense_enabled'], true ); ?> /> Renderizar blocos AdSense nos slots reservados</td>
					</tr>
					<tr>
						<th>Publisher ID (Client ID)</th>
						<td><input type="text" name="adsense_client_id" value="<?php echo esc_attr( $s['adsense_client_id'] ); ?>" placeholder="ca-pub-0000000000000000" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Slot ID — Banner Horizontal</th>
						<td><input type="text" name="adsense_slot_banner" value="<?php echo esc_attr( $s['adsense_slot_banner'] ); ?>" placeholder="1234567890" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Slot ID — Sidebar (Single 300x250)</th>
						<td><input type="text" name="adsense_slot_side" value="<?php echo esc_attr( $s['adsense_slot_side'] ); ?>" placeholder="0987654321" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Conteúdo do <code>/ads.txt</code></th>
						<td><textarea name="ads_txt_content" rows="3" class="large-text"><?php echo esc_textarea( $s['ads_txt_content'] ); ?></textarea></td>
					</tr>
				</table>

				<h2>3. Banner de Patrocinador Direto / Chamada Comercial</h2>
				<table class="form-table">
					<tr>
						<th>Ativar Card de Patrocinador</th>
						<td><input type="checkbox" name="sponsor_enabled" value="1" <?php checked( $s['sponsor_enabled'], true ); ?> /> Exibir espaço de patrocinador nativo</td>
					</tr>
					<tr>
						<th>Selo / Badge</th>
						<td><input type="text" name="sponsor_badge" value="<?php echo esc_attr( $s['sponsor_badge'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>Título / Nome do Patrocinador</th>
						<td><input type="text" name="sponsor_name" value="<?php echo esc_attr( $s['sponsor_name'] ); ?>" class="large-text" /></td>
					</tr>
					<tr>
						<th>Descrição Curta</th>
						<td><input type="text" name="sponsor_tagline" value="<?php echo esc_attr( $s['sponsor_tagline'] ); ?>" class="large-text" /></td>
					</tr>
					<tr>
						<th>Texto do Botão (CTA)</th>
						<td><input type="text" name="sponsor_cta_text" value="<?php echo esc_attr( $s['sponsor_cta_text'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th>URL de Destino (vazio = /anuncie-aqui/)</th>
						<td><input type="url" name="sponsor_cta_url" value="<?php echo esc_attr( $s['sponsor_cta_url'] ); ?>" class="large-text" placeholder="https://..." /></td>
					</tr>
				</table>

				<button type="submit" class="button button-primary">Salvar Configurações de Monetização</button>
			</form>

			<h2>📬 Propostas e Contatos Recebidos via "Anuncie Aqui" (<?php echo esc_html( (string) count( $leads ) ); ?>)</h2>
			<table class="wp-list-table widefat fixed striped" style="max-width:1000px;">
				<thead>
					<tr>
						<th>Data</th>
						<th>Empresa / Cidade</th>
						<th>Segmento / Plano</th>
						<th>Contato / WhatsApp</th>
						<th>Mensagem</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $leads ) ) : ?>
						<tr><td colspan="5">Nenhuma solicitação recebida ainda pelo formulário de anunciantes.</td></tr>
					<?php else : ?>
						<?php foreach ( $leads as $l ) : ?>
							<tr>
								<td><?php echo esc_html( $l['date'] ?? '' ); ?></td>
								<td><strong><?php echo esc_html( $l['company'] ?? '' ); ?></strong><br><small><?php echo esc_html( $l['city'] ?? '' ); ?></small></td>
								<td><?php echo esc_html( ( $l['segment'] ?? '' ) . ' • ' . ( $l['plan'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( $l['contact'] ?? '' ); ?><br><?php echo esc_html( $l['email'] ?? '' ); ?><br><strong><?php echo esc_html( $l['whatsapp'] ?? '' ); ?></strong></td>
								<td><?php echo esc_html( $l['message'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
