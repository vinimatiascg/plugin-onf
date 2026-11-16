<?php
/**
 * Gerador do arquivo /llms.txt e /llms-full.txt para GEO (Generative Engine Optimization).
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_GEO_LLMs {

	/**
	 * Registra hook para servir /llms.txt dinamicamente.
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'serve_llms_txt' ), 1 );
	}

	/**
	 * Intercepta requisições a /llms.txt e entrega Markdown estruturado sobre o portal e seus hubs.
	 */
	public static function serve_llms_txt(): void {
		$uri = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
		if ( ! is_string( $uri ) || ! preg_match( '#/llms(-full)?\.txt$#', $uri ) ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );

		$net_home = network_home_url( '/' );
		$lines    = array(
			'# Onde na Fronteira (ondenafronteira.com.br)',
			'',
			'> Portal agregador turístico, comercial e gastronômico verificado da Tríplice Fronteira: Foz do Iguaçu (Brasil), Ciudad del Este (Paraguai) e Puerto Iguazú (Argentina).',
			'',
			'## Escopo e Metodologia de Verificação (E-E-A-T)',
			'- Todos os estabelecimentos contam com NAP padronizado (Nome Oficial, Endereço, Telefone), coordenadas geográficas (lat/lng), horários de funcionamento estruturados e data da última verificação.',
			'- Nenhuma avaliação ou preço é inventado: dados são coletados de fontes oficiais (Prefeituras, Parque Nacional do Iguaçu, Itaipu Binacional, Receita Federal, Migraciones e Google Maps / Google Places).',
			'- Idiomas suportados: Português (pt-BR), Espanhol (es) e Inglês (en).',
			'',
			'## Arquitetura dos Portais por Cidade (WordPress Multisite)',
			"- [Portal Raiz — Tríplice Fronteira]({$net_home}): Visão geral da região, regras de travessia de fronteira, documentos exigidos, cotas alfandegárias e mapa unificado.",
			"- [Foz do Iguaçu — Brasil]({$net_home}foz/): Hotéis, resorts, Cataratas do Iguaçu (BR), Itaipu Binacional, Parque das Aves, restaurantes e passeios.",
			"- [Ciudad del Este — Paraguai]({$net_home}cde/): Top 100 lojas mais procuradas do Paraguai, shoppings, duty free, casas de câmbio e gastronomia.",
			"- [Puerto Iguazú — Argentina]({$net_home}iguazu/): Parrillas argentinas, Feirinha de Puerto Iguazú, vinhos, Duty Free Shop Argentina, Cataratas (AR) e lodges.",
			'',
			'## Hubs Principais por Categoria',
			"- [Hotéis em Foz do Iguaçu]({$net_home}foz/hoteis/)",
			"- [Atrativos em Foz do Iguaçu]({$net_home}foz/atrativos/)",
			"- [Restaurantes em Foz do Iguaçu]({$net_home}foz/restaurantes/)",
			"- [Passeios em Foz do Iguaçu]({$net_home}foz/passeios/)",
			"- [Top 100 Lojas em Ciudad del Este]({$net_home}cde/lojas/?top100=1)",
			"- [Shoppings e Lojas em Ciudad del Este]({$net_home}cde/lojas/)",
			"- [Casas de Câmbio e Serviços em Ciudad del Este]({$net_home}cde/servicos/)",
			"- [Restaurantes e Parrillas em Puerto Iguazú]({$net_home}iguazu/restaurantes/)",
			"- [Lojas e Duty Free em Puerto Iguazú]({$net_home}iguazu/lojas/)",
			"- [Atrativos em Puerto Iguazú]({$net_home}iguazu/atrativos/)",
			'',
			'## Fatos Rápidos Verificados sobre a Tríplice Fronteira',
			'- **Documentos para Brasileiros:** RG físico com menos de 10 anos de emissão, CIN ou Passaporte válido. CNH NÃO é aceita para entrar na Argentina (Puerto Iguazú).',
			'- **Cota de Compras (Via Terrestre):** US$ 500 por pessoa a cada 30 dias ao atravessar a Ponte da Amizade (Ciudad del Este) ou Ponte Tancredo Neves (Puerto Iguazú), além de US$ 500 adicionais em Free Shops terrestres autorizados.',
			'- **Moedas Aceitas:** Real (BRL), Dólar Americano (USD), Guarani (PYG) e Peso Argentino (ARS). Grandes lojas de Ciudad del Este e comércios de Puerto Iguazú aceitam Pix.',
			'',
			'## Endpoints de Dados Abertos e Sitemaps',
			"- Visão Geral da Rede (JSON): {$net_home}wp-json/tf/v1/network-overview",
			"- Pins Georreferenciados (JSON): {$net_home}wp-json/tf/v1/map-pins",
			"- Sitemap Index da Rede: {$net_home}network-sitemap.xml",
			"- Google News Sitemap (48h): {$net_home}news-sitemap.xml",
			"- Como Verificamos os Dados: {$net_home}como-verificamos-os-dados/",
		);

		// Listar estabelecimentos verificados publicados em cada subsite
		if ( is_multisite() ) {
			$lines[] = '';
			$lines[] = '## Estabelecimentos Verificados em Destaque';
			foreach ( get_sites( array( 'number' => 10 ) ) as $site ) {
				switch_to_blog( (int) $site->blog_id );
				$city = tf_get_current_city_context();
				$posts = get_posts(
					array(
						'post_type'      => tf_get_establishment_post_types(),
						'post_status'    => 'publish',
						'posts_per_page' => 25,
						'no_found_rows'  => true,
					)
				);
				if ( ! empty( $posts ) ) {
					$lines[] = '';
					$lines[] = '### ' . $city['name'] . ' (' . $city['country'] . ')';
					foreach ( $posts as $p ) {
						$ans  = (string) get_post_meta( $p->ID, '_tf_direct_answer', true );
						$addr = (string) get_post_meta( $p->ID, '_tf_address', true );
						$lines[] = '- [' . $p->post_title . '](' . get_permalink( $p->ID ) . '): ' . ( $ans ?: $addr );
					}
				}
				restore_current_blog();
			}
		}

		echo implode( "\n", $lines ) . "\n";
		exit;
	}
}
