<?php
/**
 * Shortcodes Modulares, Integração com Elementor para Páginas Principais
 * e Formatador de Horários de Funcionamento em Português.
 *
 * Permite editar todas as páginas principais (Home Raiz, Home Foz, Home CDE,
 * Home Puerto Iguazú e Anuncie Aqui) diretamente pelo Elementor para adicionar
 * ou remover banners, carrosséis e seções.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Shortcodes_Elementor {

	/**
	 * Registra shortcodes e suporte ao Elementor.
	 */
	public static function init(): void {
		add_shortcode( 'tf_hero', array( __CLASS__, 'sc_hero' ) );
		add_shortcode( 'tf_geo_box', array( __CLASS__, 'sc_geo_box' ) );
		add_shortcode( 'tf_sponsors_carousel', array( __CLASS__, 'sc_sponsors_carousel' ) );
		add_shortcode( 'tf_ad_banner', array( __CLASS__, 'sc_ad_banner' ) );
		add_shortcode( 'tf_city_cards', array( __CLASS__, 'sc_city_cards' ) );
		add_shortcode( 'tf_category_hubs', array( __CLASS__, 'sc_category_hubs' ) );
		add_shortcode( 'tf_top100_cde_banner', array( __CLASS__, 'sc_top100_cde_banner' ) );
		add_shortcode( 'tf_recent_establishments', array( __CLASS__, 'sc_recent_establishments' ) );
		add_shortcode( 'tf_border_crossing_table', array( __CLASS__, 'sc_border_crossing_table' ) );
		add_shortcode( 'tf_interactive_map', array( __CLASS__, 'sc_interactive_map' ) );
	}

	/**
	 * Converte dias da semana em inglês (do JSON estruturado Schema.org) para Português legível.
	 *
	 * @param string $opening_raw JSON ou texto de horários.
	 * @return string HTML seguro com horários em português.
	 */
	public static function format_opening_hours_pt( string $opening_raw ): string {
		$opening_raw = trim( $opening_raw );
		if ( empty( $opening_raw ) ) {
			return '';
		}

		$day_map = array(
			'Monday'    => 'Segunda-feira',
			'Tuesday'   => 'Terça-feira',
			'Wednesday' => 'Quarta-feira',
			'Thursday'  => 'Quinta-feira',
			'Friday'    => 'Sexta-feira',
			'Saturday'  => 'Sábado',
			'Sunday'    => 'Domingo',
		);

		if ( strpos( $opening_raw, '[' ) === 0 ) {
			$oh_arr = json_decode( $opening_raw, true );
			if ( is_array( $oh_arr ) ) {
				$lines = array();
				foreach ( $oh_arr as $oh ) {
					$days   = (array) ( $oh['days'] ?? array() );
					$opens  = (string) ( $oh['opens'] ?? '' );
					$closes = (string) ( $oh['closes'] ?? '' );

					if ( count( $days ) === 7 ) {
						$day_label = 'Segunda a Domingo (Todos os dias)';
					} elseif ( $days === array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ) ) {
						$day_label = 'Segunda a Sábado';
					} elseif ( $days === array( 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) ) {
						$day_label = 'Terça a Domingo';
					} elseif ( $days === array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ) ) {
						$day_label = 'Segunda a Sexta-feira';
					} elseif ( $days === array( 'Friday', 'Saturday' ) ) {
						$day_label = 'Sexta-feira e Sábado';
					} else {
						$pt_days = array();
						foreach ( $days as $d ) {
							$pt_days[] = $day_map[ $d ] ?? $d;
						}
						$day_label = implode( ', ', $pt_days );
					}

					if ( '00:00' === $opens && '23:59' === $closes ) {
						$lines[] = esc_html( $day_label . ': Aberto 24 horas' );
					} else {
						$lines[] = esc_html( $day_label . ': ' . $opens . ' às ' . $closes );
					}
				}
				return implode( '<br>', $lines );
			}
		}

		// Substituir termos em inglês caso seja string simples
		$translated = strtr( $opening_raw, $day_map );
		return esc_html( $translated );
	}

	/**
	 * Shortcode [tf_hero]: Renderiza o Hero da página inicial (Raiz ou Cidade).
	 */
	public static function sc_hero(): string {
		$city_ctx = tf_get_current_city_context();
		ob_start();
		if ( 'raiz' === $city_ctx['slug'] ) :
			?>
			<section class="tf-hero">
				<div class="tf-container">
					<span class="tf-hero__badge">🌎 Brasil • Paraguai • Argentina</span>
					<h1>Onde na Fronteira: O Guia Definitivo da Tríplice Fronteira</h1>
					<p class="tf-hero__lead">
						Explore hotéis, restaurantes, lojas verificadas, shoppings, duty free, casas de câmbio, atrativos e passeios em <strong>Foz do Iguaçu (BR)</strong>, <strong>Ciudad del Este (PY)</strong> e <strong>Puerto Iguazú (AR)</strong>.
					</p>
					<form class="tf-search-bar" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
						<label class="screen-reader-text" for="tf-root-search">Buscar na Tríplice Fronteira</label>
						<input type="search" id="tf-root-search" name="s" placeholder="Ex: Cataratas, Shopping China, Parrilla, Câmbio, Hotel..." required />
						<button type="submit" class="tf-btn">Buscar Agora</button>
					</form>
				</div>
			</section>
		<?php else : ?>
			<section class="tf-hero">
				<div class="tf-container">
					<span class="tf-hero__badge"><?php echo esc_html( $city_ctx['country_name'] . ' • ' . $city_ctx['region'] ); ?></span>
					<h1>O que fazer, onde comer, comprar e ficar em <?php echo esc_html( $city_ctx['name'] ); ?></h1>
					<p class="tf-hero__lead"><?php echo esc_html( get_bloginfo( 'description' ) ); ?></p>
					<form class="tf-search-bar" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
						<label class="screen-reader-text" for="tf-city-search">Buscar em <?php echo esc_html( $city_ctx['name'] ); ?></label>
						<input type="search" id="tf-city-search" name="s" placeholder="Buscar em <?php echo esc_attr( $city_ctx['name'] ); ?> (ex: nome, bairro, categoria)..." />
						<select name="post_type" aria-label="Filtrar por tipo">
							<option value="">Todas as Categorias</option>
							<option value="hotel">Hotéis &amp; Hospedagem</option>
							<option value="restaurante">Restaurantes &amp; Gastronomia</option>
							<option value="loja">Lojas, Shoppings &amp; Duty Free</option>
							<option value="atrativo">Atrativos Turísticos</option>
							<option value="passeio">Passeios &amp; Ingressos</option>
							<option value="servico">Câmbio, Agências &amp; Locadoras</option>
						</select>
						<button type="submit" class="tf-btn">Filtrar</button>
					</form>
				</div>
			</section>
			<?php
		endif;
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_geo_box]: Renderiza a caixa de Resposta Rápida GEO.
	 */
	public static function sc_geo_box(): string {
		$city_ctx = tf_get_current_city_context();
		$box_title = ( 'raiz' === $city_ctx['slug'] )
			? 'Sobre a Região da Tríplice Fronteira'
			: 'Sobre a cidade de ' . $city_ctx['name'];
		ob_start();
		?>
		<div class="tf-container">
			<div class="tf-geo-box" role="region" aria-label="<?php echo esc_attr( $box_title ); ?>">
				<span class="tf-geo-box__label">📍 <?php echo esc_html( $box_title ); ?></span>
				<p class="tf-geo-box__text">
					<?php if ( 'raiz' === $city_ctx['slug'] ) : ?>
						A <strong>Tríplice Fronteira</strong> reúne <strong>Foz do Iguaçu (Brasil)</strong>, <strong>Ciudad del Este (Paraguai)</strong> e <strong>Puerto Iguazú (Argentina)</strong>, conectadas pelas pontes Internacional da Amizade e Tancredo Neves. Para atravessar, brasileiros precisam de RG físico emitido há menos de 10 anos, CIN ou Passaporte vigente (CNH vale apenas para deslocamento urbano imediato em CDE e não é aceita na imigração argentina).
					<?php elseif ( 'foz' === $city_ctx['slug'] ) : ?>
						<strong>Foz do Iguaçu (PR, Brasil)</strong> é a principal base hoteleira e de ecoturismo da Tríplice Fronteira, abrigando o Parque Nacional do Iguaçu (Cataratas), a Usina Hidrelétrica de Itaipu, o Parque das Aves e o Marco das Três Fronteiras, com ampla rede de hotéis na Av. das Cataratas e no Centro.
					<?php elseif ( 'cde' === $city_ctx['slug'] ) : ?>
						<strong>Ciudad del Este (Alto Paraná, Paraguai)</strong> é o principal centro comercial e de importados da Tríplice Fronteira, acessível pela Ponte Internacional da Amizade. As lojas funcionam em sua maioria das 7h às 16h (horário local), concentradas no Microcentro em shoppings como Paris, China, Cellshop, Monalisa e Del Este.
					<?php else : ?>
						<strong>Puerto Iguazú (Misiones, Argentina)</strong> destaca-se pela gastronomia de carnes nobres (parrillas), vinhos argentinos, a tradicional Feirinha da Av. Brasil, o Duty Free Shop Puerto Iguazú e o lado argentino das Cataratas (Garganta do Diabo), acessível pela Ponte Tancredo Neves.
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_sponsors_carousel]: Renderiza o carrossel com a logo das lojas patrocinadoras.
	 */
	public static function sc_sponsors_carousel(): string {
		if ( ! class_exists( 'TF_Monetization' ) ) {
			return '';
		}
		return '<div class="tf-container">' . TF_Monetization::render_sponsors_carousel( false ) . '</div>';
	}

	/**
	 * Shortcode [tf_ad_banner placement="horizontal"]: Renderiza um espaço de banner / Google Ads.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public static function sc_ad_banner( $atts = array() ): string {
		if ( ! class_exists( 'TF_Monetization' ) ) {
			return '';
		}
		$atts      = shortcode_atts( array( 'placement' => 'horizontal' ), $atts );
		$placement = sanitize_key( $atts['placement'] );
		return '<div class="tf-container">' . TF_Monetization::render_ad_slot( $placement, false ) . '</div>';
	}

	/**
	 * Shortcode [tf_city_cards]: Cards de destino das 3 cidades (usado na Home Raiz).
	 */
	public static function sc_city_cards(): string {
		$net_home = network_home_url( '/' );
		ob_start();
		?>
		<section class="tf-section tf-container" aria-labelledby="destinos-heading">
			<div class="tf-section__header">
				<div>
					<h2 id="destinos-heading" class="tf-section__title">Escolha a Cidade da Tríplice Fronteira</h2>
					<p class="tf-section__subtitle">Cada destino conta com um portal exclusivo, filtros por bairro e estabelecimentos verificados.</p>
				</div>
			</div>
			<div class="tf-grid tf-grid--3">
				<article class="tf-card tf-city-portal-card" style="border-top: 5px solid #0D6E4F;">
					<div class="tf-card__content" style="padding: 32px 28px 24px;">
						<div class="tf-card__badges">
							<span class="tf-badge" style="background:#D1FAE5;color:#064E3B;">🇧🇷 Brasil • PR</span>
							<span class="tf-badge tf-badge--price">Moeda: Real (BRL / Pix)</span>
						</div>
						<h3 class="tf-card__title" style="margin: 12px 0 10px; font-size: 1.5rem; font-weight: 800;">
							<a href="<?php echo esc_url( $net_home . 'foz/' ); ?>">Foz do Iguaçu</a>
						</h3>
						<p class="tf-card__excerpt" style="font-size: 0.95rem; line-height: 1.6; color: var(--tf-text-subtle);">
							Base principal de hospedagem e ecoturismo: Cataratas do Iguaçu (lado brasileiro), Usina de Itaipu, Parque das Aves, Marco das Três Fronteiras, resorts, churrascarias e gastronomia árabe.
						</p>
					</div>
					<div class="tf-card__meta" style="padding: 20px 28px 24px;">
						<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'foz/hoteis/' ); ?>">Hotéis</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'foz/atrativos/' ); ?>">Atrativos</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'foz/restaurantes/' ); ?>">Restaurantes</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'foz/passeios/' ); ?>">Passeios</a>
						</div>
						<a class="tf-btn" style="background:#0D6E4F; width:100%; text-align:center; justify-content:center;" href="<?php echo esc_url( $net_home . 'foz/' ); ?>">Explorar Foz do Iguaçu →</a>
					</div>
				</article>

				<article class="tf-card tf-city-portal-card" style="border-top: 5px solid #C84B31;">
					<div class="tf-card__content" style="padding: 32px 28px 24px;">
						<div class="tf-card__badges">
							<span class="tf-badge" style="background:#FEE2E2;color:#7F1D1D;">🇵🇾 Paraguai • Alto Paraná</span>
							<span class="tf-badge tf-badge--top100">★ Top 100 Lojas</span>
						</div>
						<h3 class="tf-card__title" style="margin: 12px 0 10px; font-size: 1.5rem; font-weight: 800;">
							<a href="<?php echo esc_url( $net_home . 'cde/' ); ?>">Ciudad del Este</a>
						</h3>
						<p class="tf-card__excerpt" style="font-size: 0.95rem; line-height: 1.6; color: var(--tf-text-subtle);">
							Maior polo de compras e importados da América Latina: os principais shoppings (China, Paris, Del Este, Monalisa, Cellshop), eletrônicos, perfumes, casas de câmbio oficiais e Saltos del Monday.
						</p>
					</div>
					<div class="tf-card__meta" style="padding: 20px 28px 24px;">
						<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'cde/lojas/?top100=1' ); ?>">Top 100 Lojas</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'cde/lojas/' ); ?>">Shoppings</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'cde/servicos/' ); ?>">Câmbio</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'cde/restaurantes/' ); ?>">Restaurantes</a>
						</div>
						<a class="tf-btn" style="background:#C84B31; width:100%; text-align:center; justify-content:center;" href="<?php echo esc_url( $net_home . 'cde/' ); ?>">Explorar Ciudad del Este →</a>
					</div>
				</article>

				<article class="tf-card tf-city-portal-card" style="border-top: 5px solid #0A5C8A;">
					<div class="tf-card__content" style="padding: 32px 28px 24px;">
						<div class="tf-card__badges">
							<span class="tf-badge" style="background:#E0F2FE;color:#0C4A6E;">🇦🇷 Argentina • Misiones</span>
							<span class="tf-badge tf-badge--price">Gastronomia &amp; Vinhos</span>
						</div>
						<h3 class="tf-card__title" style="margin: 12px 0 10px; font-size: 1.5rem; font-weight: 800;">
							<a href="<?php echo esc_url( $net_home . 'iguazu/' ); ?>">Puerto Iguazú</a>
						</h3>
						<p class="tf-card__excerpt" style="font-size: 0.95rem; line-height: 1.6; color: var(--tf-text-subtle);">
							Charme argentino, Garganta do Diabo no Parque Nacional Iguazú, tradicionais parrillas de bife de chorizo, Feirinha de Puerto Iguazú, adegas de vinhos Malbec, Duty Free Shop e lodges na Selva Iryapú.
						</p>
					</div>
					<div class="tf-card__meta" style="padding: 20px 28px 24px;">
						<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'iguazu/restaurantes/' ); ?>">Parrillas</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'iguazu/lojas/' ); ?>">Duty Free &amp; Vinhos</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'iguazu/atrativos/' ); ?>">Cataratas AR</a>
							<a class="tf-badge" href="<?php echo esc_url( $net_home . 'iguazu/hoteis/' ); ?>">Lodges</a>
						</div>
						<a class="tf-btn" style="background:#0A5C8A; width:100%; text-align:center; justify-content:center;" href="<?php echo esc_url( $net_home . 'iguazu/' ); ?>">Explorar Puerto Iguazú →</a>
					</div>
				</article>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_category_hubs]: Grade de categorias por cidade.
	 */
	public static function sc_category_hubs(): string {
		$city_ctx = tf_get_current_city_context();
		ob_start();
		?>
		<section class="tf-section tf-container" aria-labelledby="hubs-heading">
			<div class="tf-section__header">
				<div>
					<h2 id="hubs-heading" class="tf-section__title">Explore <?php echo esc_html( $city_ctx['name'] ); ?> por Categoria</h2>
					<p class="tf-section__subtitle">Acesse listas completas com filtros por bairro, faixa de preço e comodidades.</p>
				</div>
			</div>
			<div class="tf-grid tf-grid--categories">
				<a class="tf-category-card" href="<?php echo esc_url( home_url( '/hoteis/' ) ); ?>">
					<div class="tf-category-card__icon" aria-hidden="true">🏨</div>
					<h3 class="tf-category-card__title">Hotéis &amp; Resorts</h3>
					<p class="tf-category-card__desc">Onde se hospedar com conforto em <?php echo esc_html( $city_ctx['name'] ); ?></p>
				</a>
				<a class="tf-category-card" href="<?php echo esc_url( home_url( '/restaurantes/' ) ); ?>">
					<div class="tf-category-card__icon" aria-hidden="true">🍽️</div>
					<h3 class="tf-category-card__title">Restaurantes</h3>
					<p class="tf-category-card__desc">Gastronomia, parrillas, bares e cafeterias</p>
				</a>
				<a class="tf-category-card" href="<?php echo esc_url( home_url( '/lojas/' ) ); ?>">
					<div class="tf-category-card__icon" aria-hidden="true">🛍️</div>
					<h3 class="tf-category-card__title">Lojas &amp; Shoppings</h3>
					<p class="tf-category-card__desc">Guia de compras verificadas e Duty Free</p>
				</a>
				<a class="tf-category-card" href="<?php echo esc_url( home_url( '/atrativos/' ) ); ?>">
					<div class="tf-category-card__icon" aria-hidden="true">🏞️</div>
					<h3 class="tf-category-card__title">Atrativos</h3>
					<p class="tf-category-card__desc">Pontos turísticos, maravilhas e parques</p>
				</a>
				<a class="tf-category-card" href="<?php echo esc_url( home_url( '/passeios/' ) ); ?>">
					<div class="tf-category-card__icon" aria-hidden="true">🎟️</div>
					<h3 class="tf-category-card__title">Passeios</h3>
					<p class="tf-category-card__desc">Ingressos, ecoturismo e experiências</p>
				</a>
				<a class="tf-category-card" href="<?php echo esc_url( home_url( '/servicos/' ) ); ?>">
					<div class="tf-category-card__icon" aria-hidden="true">💱</div>
					<h3 class="tf-category-card__title">Câmbio &amp; Serviços</h3>
					<p class="tf-category-card__desc">Casas de câmbio, receptivo e locadoras</p>
				</a>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_top100_cde_banner]: Banner Top 100 Lojas CDE.
	 */
	public static function sc_top100_cde_banner(): string {
		ob_start();
		?>
		<section class="tf-section tf-container" aria-labelledby="top100-heading" style="margin-top: 20px; margin-bottom: 20px;">
			<div class="tf-card tf-top100-banner-card" style="background:linear-gradient(135deg,#7F1D1D,#C84B31);color:#FFFFFF;border:none;padding:36px 32px;border-radius:var(--tf-radius);box-shadow:var(--tf-shadow-md);">
				<span class="tf-badge tf-badge--top100" style="align-self:flex-start;margin-bottom:12px;background:rgba(255,255,255,0.2);color:#FFFFFF;border-color:rgba(255,255,255,0.3);backdrop-filter:blur(4px);">★ Guia Oficial de Compras no Paraguai</span>
				<h2 id="top100-heading" style="margin:0 0 10px;font-size:clamp(1.5rem,2.8vw,1.9rem);font-weight:800;color:#FFFFFF;letter-spacing:-0.02em;">Top 100 Lojas Mais Procuradas em Ciudad del Este</h2>
				<p style="margin:0 0 18px;color:rgba(255,255,255,0.92);max-width:760px;font-size:1rem;line-height:1.6;">
					Consulte a seleção de lojas confiáveis e shoppings verificados em Ciudad del Este com endereço exato, horários de funcionamento, formas de pagamento (Pix, Dólar e Real) e localização no mapa.
				</p>
				<div>
					<a class="tf-btn" style="background:#FFFFFF;color:#7F1D1D;font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,0.2);" href="<?php echo esc_url( home_url( '/lojas/?top100=1' ) ); ?>">
						Ver Ranking Top 100 Lojas CDE →
					</a>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_recent_establishments count="9"]: Grade de estabelecimentos recentes.
	 *
	 * @param array $atts Atributos.
	 * @return string
	 */
	public static function sc_recent_establishments( $atts = array() ): string {
		$atts     = shortcode_atts( array( 'count' => 9 ), $atts );
		$city_ctx = tf_get_current_city_context();
		ob_start();
		?>
		<section class="tf-section tf-container" aria-labelledby="recent-est-heading">
			<div class="tf-section__header">
				<div>
					<h2 id="recent-est-heading" class="tf-section__title">Destaques Verificados em <?php echo esc_html( $city_ctx['name'] ); ?></h2>
					<p class="tf-section__subtitle">Registros revisados com endereço, horários e geolocalização confirmados.</p>
				</div>
			</div>
			<?php
			$recent_est = new WP_Query(
				array(
					'post_type'      => tf_get_establishment_post_types(),
					'post_status'    => 'publish',
					'posts_per_page' => (int) $atts['count'],
					'no_found_rows'  => true,
				)
			);
			if ( $recent_est->have_posts() && function_exists( 'tf_render_establishment_card' ) ) :
				$all_posts = $recent_est->posts;
				if ( count( $all_posts ) >= 4 && function_exists( 'tf_render_bento_grid' ) ) {
					$bento_slice = array_slice( $all_posts, 0, 4 );
					$grid_slice  = array_slice( $all_posts, 4 );
					tf_render_bento_grid( $bento_slice );
					if ( ! empty( $grid_slice ) ) {
						echo '<div class="tf-grid tf-grid--3" style="margin-top:24px;">';
						foreach ( $grid_slice as $p ) {
							tf_render_establishment_card( $p->ID );
						}
						echo '</div>';
					}
				} else {
					echo '<div class="tf-grid tf-grid--3">';
					while ( $recent_est->have_posts() ) :
						$recent_est->the_post();
						tf_render_establishment_card( get_the_ID() );
					endwhile;
					echo '</div>';
				}
				wp_reset_postdata();
			else :
				?>
				<div class="tf-card">
					<p style="margin:0;color:var(--tf-text-muted);">
						Os estabelecimentos de <strong><?php echo esc_html( $city_ctx['name'] ); ?></strong> estão na Fila Unificada de Aprovação. Utilize o menu superior para explorar as categorias!
					</p>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_border_crossing_table]: Link em destaque para o Guia Completo de Documentos, Moedas e Cotas.
	 */
	public static function sc_border_crossing_table(): string {
		ob_start();
		?>
		<section id="guia-travessia" class="tf-container" style="margin-top: 20px; margin-bottom: 20px;">
			<div class="tf-card" style="padding: 28px 32px; background: var(--tf-surface-elevated); border: 1px solid var(--tf-border); border-left: 5px solid var(--tf-accent); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 18px; border-radius: var(--tf-radius); box-shadow: var(--tf-shadow);">
				<div style="max-width: 700px;">
					<span class="tf-badge" style="background:#E0F2FE; color:#0369A1; margin-bottom: 8px; font-size: 0.8rem; font-weight: 700;">
						🛂 Planejamento &amp; Imigração
					</span>
					<h3 style="margin: 0 0 6px; font-size: 1.3rem; font-weight: 800; color: var(--tf-text); letter-spacing: -0.02em;">
						Guia Completo: Documentos, Moedas e Cotas na Tríplice Fronteira
					</h3>
					<p style="margin: 0; font-size: 0.94rem; color: var(--tf-text-subtle); line-height: 1.55;">
						Consulte as regras oficiais de RG físico e passaporte, proibição de CNH na Argentina, cota de US$ 500 terrestre da Receita Federal, seguro Carta Verde e dicas de compras e câmbio.
					</p>
				</div>
				<div>
					<a href="<?php echo esc_url( home_url( '/documentos-moedas-cotas/' ) ); ?>" class="tf-btn" style="background: var(--tf-accent); font-size: 0.95rem; padding: 14px 26px; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px; font-weight: 700;">
						Acessar Guia Completo &rarr;
					</a>
				</div>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode [tf_interactive_map]: Mapa interativo Leaflet + OpenStreetMap.
	 */
	public static function sc_interactive_map(): string {
		$city_ctx = tf_get_current_city_context();
		$zoom     = ( 'raiz' === $city_ctx['slug'] ) ? '12' : '13';
		ob_start();
		?>
		<section id="mapa-fronteira" class="tf-section tf-container" aria-labelledby="mapa-heading" style="margin-top: 20px; margin-bottom: 20px;">
			<div class="tf-section__header" style="margin-top: 20px; margin-bottom: 20px;">
				<div>
					<h2 id="mapa-heading" class="tf-section__title" style="margin-top: 20px; margin-bottom: 8px;">Mapa Interativo — <?php echo esc_html( $city_ctx['name'] ); ?></h2>
					<p class="tf-section__subtitle" style="margin-bottom: 20px;">Visualize estabelecimentos verificados em tempo real via OpenStreetMap.</p>
				</div>
			</div>
			<div
				id="tf-map"
				class="tf-map-container"
				data-lat="<?php echo esc_attr( (string) $city_ctx['lat'] ); ?>"
				data-lng="<?php echo esc_attr( (string) $city_ctx['lng'] ); ?>"
				data-zoom="<?php echo esc_attr( $zoom ); ?>"
				data-endpoint="<?php echo esc_url( home_url( '/wp-json/tf/v1/map-pins' ) ); ?>"
				aria-label="Mapa interativo de <?php echo esc_attr( $city_ctx['name'] ); ?>"
			></div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Cria/atualiza a Página Inicial estática configurada para edição no Elementor em cada site da rede.
	 */
	public static function setup_elementor_home_pages(): void {
		$city_ctx = tf_get_current_city_context();
		$slug     = 'pagina-inicial';
		$existing = get_page_by_path( $slug );

		if ( 'raiz' === $city_ctx['slug'] ) {
			$shortcodes = array(
				'[tf_hero]',
				'[tf_sponsors_carousel]',
				'[tf_geo_box]',
				'[tf_city_cards]',
				'[tf_ad_banner placement="horizontal"]',
				'[tf_border_crossing_table]',
				'[tf_interactive_map]',
			);
		} else {
			$shortcodes = array(
				'[tf_hero]',
				'[tf_sponsors_carousel]',
				'[tf_geo_box]',
				'[tf_category_hubs]',
			);
			if ( 'cde' === $city_ctx['slug'] ) {
				$shortcodes[] = '[tf_top100_cde_banner]';
			}
			$shortcodes[] = '[tf_ad_banner placement="horizontal"]';
			$shortcodes[] = '[tf_recent_establishments count="9"]';
			$shortcodes[] = '[tf_interactive_map]';
		}

		$post_content = implode( "\n\n", $shortcodes );

		// Construir estrutura JSON nativa de seções/widgets do Elementor (_elementor_data)
		$elementor_elements = array();
		foreach ( $shortcodes as $idx => $sc ) {
			$elementor_elements[] = array(
				'id'       => substr( md5( 'sec_' . $city_ctx['slug'] . '_' . $idx ), 0, 7 ),
				'elType'   => 'section',
				'settings' => array( 'layout' => 'full_width', 'gap' => 'no' ),
				'elements' => array(
					array(
						'id'       => substr( md5( 'col_' . $city_ctx['slug'] . '_' . $idx ), 0, 7 ),
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => substr( md5( 'wid_' . $city_ctx['slug'] . '_' . $idx ), 0, 7 ),
								'elType'     => 'widget',
								'widgetType' => 'shortcode',
								'settings'   => array( 'shortcode' => $sc ),
								'elements'   => array(),
							),
						),
					),
				),
			);
		}

		if ( ! $existing ) {
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_name'    => $slug,
					'post_title'   => 'Página Inicial — ' . $city_ctx['name'],
					'post_content' => $post_content,
					'post_status'  => 'publish',
				)
			);
		} else {
			$page_id = $existing->ID;
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_content' => $post_content,
				)
			);
		}

		if ( ! is_wp_error( $page_id ) && $page_id > 0 ) {
			update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
			update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_elements ) ) );

			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $page_id );
		}

		// Habilitar todos os CPTs e Páginas no Elementor
		update_option( 'elementor_cpt_support', array( 'page', 'post', 'hotel', 'restaurante', 'loja', 'atrativo', 'passeio', 'servico' ) );
	}
}
