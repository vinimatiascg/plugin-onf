<?php
/**
 * Registro das Taxonomias específicas e compartilhadas da rede Onde na Fronteira.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Taxonomies {

	/**
	 * Registra hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ), 6 );
	}

	/**
	 * Registra todas as taxonomias dos CPTs.
	 */
	public static function register_taxonomies(): void {
		$all_cpts = tf_get_establishment_post_types();

		// 1. Taxonomias exclusivas por CPT
		self::register_single_taxonomy( 'categoria_hotel', array( 'hotel' ), 'Categoria de Hotel', 'Categorias de Hotéis', 'categoria-hotel', true );
		self::register_single_taxonomy( 'categoria_restaurante', array( 'restaurante' ), 'Categoria de Restaurante', 'Categorias de Restaurantes', 'categoria-restaurante', true );
		self::register_single_taxonomy( 'tipo_cozinha', array( 'restaurante' ), 'Tipo de Cozinha', 'Tipos de Cozinha', 'cozinha', true );
		self::register_single_taxonomy( 'categoria_loja', array( 'loja' ), 'Categoria de Loja / Shopping', 'Categorias de Lojas', 'categoria-loja', true );
		self::register_single_taxonomy( 'categoria_atrativo', array( 'atrativo' ), 'Categoria de Atrativo', 'Categorias de Atrativos', 'categoria-atrativo', true );
		self::register_single_taxonomy( 'categoria_passeio', array( 'passeio' ), 'Categoria de Passeio', 'Categorias de Passeios', 'categoria-passeio', true );
		self::register_single_taxonomy( 'tipo_passeio', array( 'passeio' ), 'Modalidade de Passeio', 'Modalidades de Passeio', 'tipo-passeio', true );
		self::register_single_taxonomy( 'tipo_servico', array( 'servico' ), 'Tipo de Serviço', 'Tipos de Serviço', 'tipo-servico', true );

		// 2. Taxonomias compartilhadas entre todos os estabelecimentos
		self::register_single_taxonomy( 'bairro', $all_cpts, 'Bairro / Zona', 'Bairros / Zonas', 'bairro', true );
		self::register_single_taxonomy( 'faixa_preco', $all_cpts, 'Faixa de Preço', 'Faixas de Preço', 'faixa-preco', false );
		self::register_single_taxonomy( 'comodidade', $all_cpts, 'Comodidade / Diferencial', 'Comodidades', 'comodidade', false );
	}

	/**
	 * Helper para registro padronizado com suporte a REST API.
	 *
	 * @param string   $taxonomy     Slug interno.
	 * @param string[] $object_types Post types associados.
	 * @param string   $singular     Nome singular.
	 * @param string   $plural       Nome plural.
	 * @param string   $rewrite_slug Slug de URL.
	 * @param bool     $hierarchical Se é hierárquica.
	 */
	private static function register_single_taxonomy(
		string $taxonomy,
		array $object_types,
		string $singular,
		string $plural,
		string $rewrite_slug,
		bool $hierarchical = true
	): void {
		register_taxonomy(
			$taxonomy,
			$object_types,
			array(
				'labels'            => array(
					'name'          => $plural,
					'singular_name' => $singular,
					'search_items'  => "Buscar {$plural}",
					'all_items'     => "Todas: {$plural}",
					'edit_item'     => "Editar {$singular}",
					'update_item'   => "Atualizar {$singular}",
					'add_new_item'  => "Adicionar {$singular}",
					'new_item_name' => "Nome de {$singular}",
					'menu_name'     => $plural,
				),
				'hierarchical'      => $hierarchical,
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => true,
				'show_in_rest'      => true,
				'rest_base'         => $taxonomy,
				'rewrite'           => array(
					'slug'         => $rewrite_slug,
					'with_front'   => false,
					'hierarchical' => $hierarchical,
				),
			)
		);
	}
}
