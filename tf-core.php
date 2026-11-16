<?php
/**
 * Plugin Name: Onde na Fronteira — Core Network Plugin (tf-core)
 * Plugin URI:  https://ondenafronteira.com.br
 * Description: Arquitetura central da rede Onde na Fronteira: Custom Post Types, Taxonomias, Metacampos REST, Deduplicação, Ingestão IA, Schema.org JSON-LD, SEO Técnico, GEO (llms.txt), Google News Sitemap, Social Discovery e Alimentador Google Maps / Google Places.
 * Version:     1.5.0
 * Author:      Hermes Agent
 * Author URI:  https://ondenafronteira.com.br
 * License:     GPL-2.0+
 * Text Domain: onde-na-fronteira
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TF_CORE_VERSION', '1.5.0' );
define( 'TF_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TF_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once TF_CORE_PATH . 'includes/class-tf-post-types.php';
require_once TF_CORE_PATH . 'includes/class-tf-taxonomies.php';
require_once TF_CORE_PATH . 'includes/class-tf-meta-fields.php';
require_once TF_CORE_PATH . 'includes/class-tf-rest-api.php';
require_once TF_CORE_PATH . 'includes/class-tf-schema-jsonld.php';
require_once TF_CORE_PATH . 'includes/class-tf-seed-terms.php';
require_once TF_CORE_PATH . 'includes/class-tf-seo-technical.php';
require_once TF_CORE_PATH . 'includes/class-tf-geo-llms.php';
require_once TF_CORE_PATH . 'includes/class-tf-news-sitemap.php';
require_once TF_CORE_PATH . 'includes/class-tf-social-discovery.php';
require_once TF_CORE_PATH . 'includes/class-tf-approval-queue.php';
require_once TF_CORE_PATH . 'includes/class-tf-monetization.php';
require_once TF_CORE_PATH . 'includes/class-tf-shortcodes-elementor.php';
require_once TF_CORE_PATH . 'includes/class-tf-official-images.php';
require_once TF_CORE_PATH . 'includes/class-tf-google-places.php';
require_once TF_CORE_PATH . 'includes/class-tf-harvester-admin.php';
require_once TF_CORE_PATH . 'includes/class-tf-github-updater.php';
require_once TF_CORE_PATH . 'includes/class-tf-analytics.php';

/**
 * Inicializa todos os módulos do plugin tf-core.
 */
function tf_core_init(): void {
	TF_Post_Types::init();
	TF_Taxonomies::init();
	TF_Meta_Fields::init();
	TF_REST_API::init();
	TF_Schema_JSONLD::init();
	TF_Seed_Terms::init();
	TF_SEO_Technical::init();
	TF_GEO_LLMs::init();
	TF_News_Sitemap::init();
	TF_Social_Discovery::init();
	TF_Approval_Queue::init();
	TF_Monetization::init();
	TF_Shortcodes_Elementor::init();
	TF_Analytics::init();
}
add_action( 'plugins_loaded', 'tf_core_init' );

/**
 * Retorna a lista de CPTs de estabelecimentos gerenciados pelo tf-core.
 *
 * @return string[]
 */
function tf_get_establishment_post_types(): array {
	return array( 'hotel', 'restaurante', 'loja', 'atrativo', 'passeio', 'servico' );
}

/**
 * Identifica os metadados geográficos e de contexto do subsite atual.
 *
 * @return array
 */
function tf_get_current_city_context(): array {
	$slug = get_option( 'tf_city_slug', '' );
	if ( empty( $slug ) ) {
		$path = function_exists( 'get_blog_details' ) ? trim( get_blog_details()->path ?? '/', '/' ) : '';
		$slug = $path ? $path : 'raiz';
	}

	$cities = array(
		'foz'    => array(
			'slug'         => 'foz',
			'name'         => 'Foz do Iguaçu',
			'country'      => 'BR',
			'country_name' => 'Brasil',
			'region'       => 'PR',
			'currency'     => 'BRL',
			'lang'         => 'pt-BR',
			'lat'          => -25.5478,
			'lng'          => -54.5882,
			'color'        => '#0D6E4F',
			'wiki'         => 'https://pt.wikipedia.org/wiki/Foz_do_Igua%C3%A7u',
		),
		'cde'    => array(
			'slug'         => 'cde',
			'name'         => 'Ciudad del Este',
			'country'      => 'PY',
			'country_name' => 'Paraguai',
			'region'       => 'Alto Paraná',
			'currency'     => 'USD',
			'lang'         => 'pt-BR',
			'lat'          => -25.5097,
			'lng'          => -54.6111,
			'color'        => '#C84B31',
			'wiki'         => 'https://pt.wikipedia.org/wiki/Ciudad_del_Este',
		),
		'iguazu' => array(
			'slug'         => 'iguazu',
			'name'         => 'Puerto Iguazú',
			'country'      => 'AR',
			'country_name' => 'Argentina',
			'region'       => 'Misiones',
			'currency'     => 'ARS',
			'lang'         => 'pt-BR',
			'lat'          => -25.5991,
			'lng'          => -54.5735,
			'color'        => '#0A5C8A',
			'wiki'         => 'https://pt.wikipedia.org/wiki/Puerto_Iguaz%C3%BA',
		),
		'raiz'   => array(
			'slug'         => 'raiz',
			'name'         => 'Tríplice Fronteira',
			'country'      => 'BR',
			'country_name' => 'Brasil, Paraguai e Argentina',
			'region'       => 'Tríplice Fronteira',
			'currency'     => 'BRL',
			'lang'         => 'pt-BR',
			'lat'          => -25.5522,
			'lng'          => -54.5909,
			'color'        => '#0F172A',
			'wiki'         => 'https://pt.wikipedia.org/wiki/Tr%C3%ADplice_Fronteira',
		),
	);

	return $cities[ $slug ] ?? $cities['raiz'];
}
