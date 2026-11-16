<?php
/**
 * Social Discovery: Open Graph, Twitter Cards, Gerador Dinâmico de Imagens PNG
 * (1200x630 OG, 1080x1350 4:5 e 1080x1920 9:16) e Gerador Automático de Rascunhos
 * de Legendas para Instagram, Facebook, X, TikTok e Pinterest (salvos para aprovação).
 *
 * Regra crítica: NUNCA publicar nas redes sociais sem aprovação explícita do usuário.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Social_Discovery {

	/**
	 * Registra hooks.
	 */
	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output_og_twitter_tags' ), 8 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_social_routes' ) );
		add_action( 'save_post', array( __CLASS__, 'auto_generate_social_drafts_on_save' ), 20, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'register_social_admin_page' ) );
	}

	/**
	 * Imprime meta tags Open Graph e Twitter Cards completas com imagem 1200x630 por página.
	 */
	public static function output_og_twitter_tags(): void {
		$city      = tf_get_current_city_context();
		$title     = wp_get_document_title();
		$desc      = TF_SEO_Technical::get_current_meta_description();
		$url       = TF_SEO_Technical::get_current_canonical_url();
		$og_type   = is_singular() ? 'article' : 'website';
		$post_id   = is_singular() ? get_queried_object_id() : 0;

		$thumb_url = $post_id ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
		if ( empty( $thumb_url ) ) {
			$thumb_url = add_query_arg(
				array(
					'post_id' => $post_id,
					'ratio'   => 'og',
				),
				rest_url( 'tf/v1/og-image' )
			);
		}

		echo "\n<!-- Onde na Fronteira Open Graph & Twitter Cards (tf-core) -->\n";
		echo '<meta property="og:site_name" content="Onde na Fronteira" />' . "\n";
		echo '<meta property="og:locale" content="pt_BR" />' . "\n";
		echo '<meta property="og:locale:alternate" content="es_ES" />' . "\n";
		echo '<meta property="og:locale:alternate" content="en_US" />' . "\n";
		echo '<meta property="og:type" content="' . esc_attr( $og_type ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		echo '<meta property="og:image" content="' . esc_url( $thumb_url ) . '" />' . "\n";
		echo '<meta property="og:image:width" content="1200" />' . "\n";
		echo '<meta property="og:image:height" content="630" />' . "\n";
		echo '<meta property="og:image:alt" content="' . esc_attr( $title . ' — ' . $city['name'] ) . '" />' . "\n";

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url( $thumb_url ) . '" />' . "\n";
	}

	/**
	 * Registra rotas REST para geração de imagem OG/Vertical e gestão de rascunhos sociais.
	 */
	public static function register_social_routes(): void {
		// 1. Imagem dinâmica PNG (1200x630 OG, 1080x1350 4:5 ou 1080x1920 9:16)
		register_rest_route(
			'tf/v1',
			'/og-image',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'serve_dynamic_social_image' ),
			)
		);

		// 2. Listar rascunhos de redes sociais pendentes de aprovação
		register_rest_route(
			'tf/v1',
			'/social-drafts',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => array( __CLASS__, 'get_social_drafts_list' ),
			)
		);
	}

	/**
	 * Gera um card PNG em tempo real usando Imagick ou GD com nome + cidade + categoria
	 * nos formatos 1200x630 (og), 1080x1350 (4x5) ou 1080x1920 (9x16).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function serve_dynamic_social_image( WP_REST_Request $request ): void {
		$post_id = (int) $request->get_param( 'post_id' );
		$ratio   = sanitize_key( (string) $request->get_param( 'ratio' ) );
		$city    = tf_get_current_city_context();

		if ( '9x16' === $ratio ) {
			$width  = 1080;
			$height = 1920;
		} elseif ( '4x5' === $ratio ) {
			$width  = 1080;
			$height = 1350;
		} else {
			$width  = 1200;
			$height = 630;
		}

		$title    = $post_id ? get_the_title( $post_id ) : get_bloginfo( 'name' );
		$subtitle = $city['name'] . ' • ' . $city['country_name'];
		$badge    = 'ONDE NA FRONTEIRA — GUIA VERIFICADO';

		if ( $post_id ) {
			$pt_obj = get_post_type_object( get_post_type( $post_id ) );
			if ( $pt_obj ) {
				$badge = mb_strtoupper( $pt_obj->labels->singular_name . ' EM ' . $city['name'] );
			}
			$addr = (string) get_post_meta( $post_id, '_tf_address', true );
			if ( ! empty( $addr ) ) {
				$subtitle = $addr;
			}
		}

		header( 'Content-Type: image/png' );
		header( 'Cache-Control: public, max-age=86400' );

		$img = imagecreatetruecolor( $width, $height );

		// Cores baseadas na cidade
		$rgb_map = array(
			'foz'    => array( 13, 110, 79 ),
			'cde'    => array( 200, 75, 49 ),
			'iguazu' => array( 10, 92, 138 ),
			'raiz'   => array( 15, 23, 42 ),
		);
		$rgb = $rgb_map[ $city['slug'] ] ?? $rgb_map['raiz'];

		$bg_color     = imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] );
		$dark_overlay = imagecolorallocate( $img, (int) ( $rgb[0] * 0.55 ), (int) ( $rgb[1] * 0.55 ), (int) ( $rgb[2] * 0.55 ) );
		$white        = imagecolorallocate( $img, 255, 255, 255 );
		$gold         = imagecolorallocate( $img, 251, 191, 36 );
		$muted        = imagecolorallocate( $img, 226, 232, 240 );

		imagefilledrectangle( $img, 0, 0, $width, $height, $bg_color );
		imagefilledrectangle( $img, 40, 40, $width - 40, $height - 40, $dark_overlay );
		imagerectangle( $img, 40, 40, $width - 40, $height - 40, $gold );

		$ascii_badge = self::to_clean_ascii( $badge );
		$ascii_title = self::to_clean_ascii( $title );
		$ascii_sub   = self::to_clean_ascii( $subtitle );

		imagestring( $img, 5, 80, 90, $ascii_badge, $gold );

		// Quebrar título em linhas de até 42 caracteres
		$wrapped = explode( "\n", wordwrap( $ascii_title, 40, "\n", true ) );
		$y       = (int) ( $height * 0.35 );
		foreach ( array_slice( $wrapped, 0, 4 ) as $line ) {
			imagestring( $img, 5, 80, $y, $line, $white );
			$y += 34;
		}

		imagestring( $img, 4, 80, $y + 20, substr( $ascii_sub, 0, 75 ), $muted );
		imagestring( $img, 5, 80, $height - 95, 'ondenafronteira.com.br | Foz do Iguacu - Ciudad del Este - Puerto Iguazu', $gold );

		imagepng( $img );
		imagedestroy( $img );
		exit;
	}

	/**
	 * Gera automaticamente rascunhos de posts para Instagram, Facebook, X, TikTok e Pinterest
	 * quando um estabelecimento ou notícia é salvo. Salva apenas como rascunho para aprovação.
	 *
	 * @param int     $post_id ID do post.
	 * @param WP_Post $post    Objeto post.
	 */
	public static function auto_generate_social_drafts_on_save( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$allowed_types = array_merge( tf_get_establishment_post_types(), array( 'post' ) );
		if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
			return;
		}

		$existing = get_post_meta( $post_id, '_tf_social_drafts_json', true );
		if ( ! empty( $existing ) ) {
			return;
		}

		$drafts = self::build_social_drafts_for_post( $post_id );
		update_post_meta( $post_id, '_tf_social_drafts_json', wp_json_encode( $drafts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Constrói as legendas e roteiros para as 5 redes sociais + links das imagens 1200x630, 4:5 e 9:16.
	 *
	 * @param int $post_id ID do post.
	 * @return array
	 */
	public static function build_social_drafts_for_post( int $post_id ): array {
		$city       = tf_get_current_city_context();
		$title      = get_the_title( $post_id );
		$permalink  = get_permalink( $post_id );
		$direct_ans = (string) get_post_meta( $post_id, '_tf_direct_answer', true ) ?: get_the_excerpt( $post_id );
		$address    = (string) get_post_meta( $post_id, '_tf_address', true );
		$price      = (string) get_post_meta( $post_id, '_tf_price_range', true );
		$pt_obj     = get_post_type_object( get_post_type( $post_id ) );
		$pt_name    = $pt_obj ? $pt_obj->labels->singular_name : 'Destaque';

		$hashtags = '#OndenaFronteira #' . preg_replace( '/\W+/', '', $city['name'] ) . ' #TripliceFronteira #FozdoIguacu #CiudaddelEste #PuertoIguazu';

		return array(
			'approval_status' => 'pending_approval',
			'generated_at'    => gmdate( DATE_W3C ),
			'published_externally' => false,
			'media_templates' => array(
				'og_1200x630'      => add_query_arg( array( 'post_id' => $post_id, 'ratio' => 'og' ), rest_url( 'tf/v1/og-image' ) ),
				'feed_4x5_1080'    => add_query_arg( array( 'post_id' => $post_id, 'ratio' => '4x5' ), rest_url( 'tf/v1/og-image' ) ),
				'stories_9x16_1080'=> add_query_arg( array( 'post_id' => $post_id, 'ratio' => '9x16' ), rest_url( 'tf/v1/og-image' ) ),
			),
			'channels'        => array(
				'instagram' => array(
					'caption' => "📍 {$title} em {$city['name']} ({$pt_name})\n\n{$direct_ans}\n\n🗺️ Endereço: {$address}\n💰 Faixa de preço: {$price}\n\n🔗 Guia completo e mapa no link da bio: {$permalink}\n\n{$hashtags}",
					'format'  => 'Carrossel 4:5 (1080x1350) + Story 9:16',
				),
				'facebook'  => array(
					'post' => "Planejando sua visita a {$city['name']}? Confira a ficha verificada de {$title}!\n\n{$direct_ans}\n\n📍 Endereço: {$address}\n👉 Veja horários, mapa e dicas completas: {$permalink}",
				),
				'x_twitter' => array(
					'tweet' => "⚡ Guia Verificado em {$city['name']}: {$title}\n\n" . wp_trim_words( $direct_ans, 22 ) . "\n\n📍 Confira endereço, horários e mapa: {$permalink} {$hashtags}",
				),
				'tiktok'    => array(
					'script' => "[GANCHO 0-3s] Vai para {$city['name']} e quer saber se vale a pena conhecer {$title}?\n[CORPO 3-20s] {$direct_ans} Fica localizado em {$address}.\n[CTA 20-25s] Salve esse vídeo e veja o mapa e horários atualizados em ondenafronteira.com.br!",
					'format' => 'Vídeo Vertical 9:16 (1080x1920)',
				),
				'pinterest' => array(
					'pin_title'       => "{$title} em {$city['name']}: Guia Verificado, Horários e Dicas",
					'pin_description' => "{$direct_ans} Localização: {$address}. Planeje sua viagem na Tríplice Fronteira.",
					'destination_url' => $permalink,
					'board'           => "Guia de {$city['name']} — Tríplice Fronteira",
				),
			),
		);
	}

	/**
	 * Retorna via REST API todos os rascunhos sociais gerados no subsite atual para aprovação.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_social_drafts_list(): WP_REST_Response {
		$posts = get_posts(
			array(
				'post_type'      => array_merge( tf_get_establishment_post_types(), array( 'post' ) ),
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'posts_per_page' => 50,
				'meta_query'     => array(
					array(
						'key'     => '_tf_social_drafts_json',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$items = array();
		foreach ( $posts as $p ) {
			$raw = (string) get_post_meta( $p->ID, '_tf_social_drafts_json', true );
			$items[] = array(
				'post_id'     => $p->ID,
				'title'       => $p->post_title,
				'post_type'   => $p->post_type,
				'post_status' => $p->post_status,
				'drafts'      => json_decode( $raw, true ),
			);
		}

		return new WP_REST_Response( array( 'total' => count( $items ), 'items' => $items ), 200 );
	}

	/**
	 * Registra tela administrativa "Rascunhos Sociais (IA)" para aprovação manual do editor.
	 */
	public static function register_social_admin_page(): void {
		add_menu_page(
			'Rascunhos Redes Sociais — Onde na Fronteira',
			'Rascunhos Sociais',
			'edit_posts',
			'tf-social-drafts',
			array( __CLASS__, 'render_social_admin_page' ),
			'dashicons-share',
			28
		);
	}

	/**
	 * Renderiza o painel de revisão de rascunhos de redes sociais no wp-admin.
	 */
	public static function render_social_admin_page(): void {
		echo '<div class="wrap"><h1>📱 Rascunhos de Redes Sociais (Aguardando Sua Aprovação)</h1>';
		echo '<p>Estes posts foram gerados automaticamente a partir dos conteúdos cadastrados. <strong>Nenhuma publicação externa é feita sem sua aprovação.</strong></p>';

		$resp  = self::get_social_drafts_list();
		$data  = $resp->get_data();
		$items = $data['items'] ?? array();

		if ( empty( $items ) ) {
			echo '<div class="notice notice-info"><p>Nenhum rascunho social gerado ainda neste subsite.</p></div></div>';
			return;
		}

		foreach ( $items as $it ) {
			echo '<div style="background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin-bottom:16px;">';
			echo '<h2 style="margin-top:0;">' . esc_html( $it['title'] ) . ' <code>(' . esc_html( $it['post_type'] ) . ' - ' . esc_html( $it['post_status'] ) . ')</code></h2>';
			$ch = $it['drafts']['channels'] ?? array();
			if ( ! empty( $ch['instagram']['caption'] ) ) {
				echo '<strong>Instagram (Feed 4:5 / Story 9:16):</strong><pre style="white-space:pre-wrap;background:#f8fafc;padding:10px;border:1px solid #e2e8f0;">' . esc_html( $ch['instagram']['caption'] ) . '</pre>';
			}
			if ( ! empty( $ch['tiktok']['script'] ) ) {
				echo '<strong>TikTok / Reels (Roteiro 9:16):</strong><pre style="white-space:pre-wrap;background:#f8fafc;padding:10px;border:1px solid #e2e8f0;">' . esc_html( $ch['tiktok']['script'] ) . '</pre>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Converte texto UTF-8 em ASCII limpo para renderização na fonte bitmap do GD.
	 *
	 * @param string $text Texto original.
	 * @return string
	 */
	private static function to_clean_ascii( string $text ): string {
		$clean = remove_accents( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		return preg_replace( '/[^\x20-\x7E]/', ' ', $clean );
	}
}
