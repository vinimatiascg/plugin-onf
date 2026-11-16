<?php
/**
 * Registro dos Custom Post Types (CPTs) da rede Onde na Fronteira.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Post_Types {

	/**
	 * Registra hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_post_types' ), 5 );
	}

	/**
	 * Registra os 6 CPTs principais: hotel, restaurante, loja, atrativo, passeio, servico.
	 */
	public static function register_post_types(): void {
		$supports = array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions', 'author' );

		// 1. Hotel
		register_post_type(
			'hotel',
			array(
				'labels'       => self::build_labels( 'Hotel', 'Hotéis', 'o' ),
				'description'  => __( 'Hotéis, resorts, pousadas e hostels na Tríplice Fronteira.', 'onde-na-fronteira' ),
				'public'       => true,
				'has_archive'  => 'hoteis',
				'rewrite'      => array(
					'slug'       => 'hoteis',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-building',
				'menu_position'=> 20,
				'supports'     => $supports,
				'show_in_rest' => true,
				'rest_base'    => 'hoteis',
			)
		);

		// 2. Restaurante
		register_post_type(
			'restaurante',
			array(
				'labels'       => self::build_labels( 'Restaurante', 'Restaurantes', 'o' ),
				'description'  => __( 'Restaurantes, churrascarias, parrillas, bares e cafés.', 'onde-na-fronteira' ),
				'public'       => true,
				'has_archive'  => 'restaurantes',
				'rewrite'      => array(
					'slug'       => 'restaurantes',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-food',
				'menu_position'=> 21,
				'supports'     => $supports,
				'show_in_rest' => true,
				'rest_base'    => 'restaurantes',
			)
		);

		// 3. Loja (Inclui Shoppings, Duty Free e Top 100 CDE)
		register_post_type(
			'loja',
			array(
				'labels'       => self::build_labels( 'Loja / Shopping', 'Lojas e Shoppings', 'a' ),
				'description'  => __( 'Lojas, shoppings, duty free e centros comerciais (incluindo Top 100 CDE).', 'onde-na-fronteira' ),
				'public'       => true,
				'hierarchical' => true,
				'has_archive'  => 'lojas',
				'rewrite'      => array(
					'slug'       => 'lojas',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-cart',
				'menu_position'=> 22,
				'supports'     => array_merge( $supports, array( 'page-attributes' ) ),
				'show_in_rest' => true,
				'rest_base'    => 'lojas',
			)
		);

		// 4. Atrativo (Ponto Turístico)
		register_post_type(
			'atrativo',
			array(
				'labels'       => self::build_labels( 'Atrativo Turístico', 'Atrativos Turísticos', 'o' ),
				'description'  => __( 'Pontos turísticos, parques nacionais, marcos e monumentos.', 'onde-na-fronteira' ),
				'public'       => true,
				'has_archive'  => 'atrativos',
				'rewrite'      => array(
					'slug'       => 'atrativos',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-location-alt',
				'menu_position'=> 23,
				'supports'     => $supports,
				'show_in_rest' => true,
				'rest_base'    => 'atrativos',
			)
		);

		// 5. Passeio (Experiências e Ingressos)
		register_post_type(
			'passeio',
			array(
				'labels'       => self::build_labels( 'Passeio', 'Passeios', 'o' ),
				'description'  => __( 'Passeios, excursões, circuitos e ingressos turísticos.', 'onde-na-fronteira' ),
				'public'       => true,
				'has_archive'  => 'passeios',
				'rewrite'      => array(
					'slug'       => 'passeios',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-tickets-alt',
				'menu_position'=> 24,
				'supports'     => $supports,
				'show_in_rest' => true,
				'rest_base'    => 'passeios',
			)
		);

		// 6. Serviço Turístico (Casas de Câmbio, Agências de Turismo, Locadoras de Veículos)
		register_post_type(
			'servico',
			array(
				'labels'       => self::build_labels( 'Serviço (Câmbio / Agência / Locadora)', 'Serviços Turísticos', 'o' ),
				'description'  => __( 'Casas de câmbio, agências de turismo e locadoras de veículos.', 'onde-na-fronteira' ),
				'public'       => true,
				'has_archive'  => 'servicos',
				'rewrite'      => array(
					'slug'       => 'servicos',
					'with_front' => false,
				),
				'menu_icon'    => 'dashicons-money-alt',
				'menu_position'=> 25,
				'supports'     => $supports,
				'show_in_rest' => true,
				'rest_base'    => 'servicos',
			)
		);
	}

	/**
	 * Gera array de labels em português seguindo WPCS.
	 *
	 * @param string $singular Nome singular.
	 * @param string $plural   Nome plural.
	 * @param string $gender   Artigo ('o' ou 'a').
	 * @return array
	 */
	private static function build_labels( string $singular, string $plural, string $gender = 'o' ): array {
		$novo     = ( 'a' === $gender ) ? 'Nova' : 'Novo';
		$todos    = ( 'a' === $gender ) ? 'Todas as' : 'Todos os';
		$nenhum   = ( 'a' === $gender ) ? 'Nenhuma' : 'Nenhum';
		$encontr  = ( 'a' === $gender ) ? 'encontrada' : 'encontrado';

		return array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'name_admin_bar'     => $singular,
			'add_new'            => "Adicionar {$novo}",
			'add_new_item'       => "Adicionar {$novo} {$singular}",
			'new_item'           => "{$novo} {$singular}",
			'edit_item'          => "Editar {$singular}",
			'view_item'          => "Ver {$singular}",
			'all_items'          => "{$todos} {$plural}",
			'search_items'       => "Buscar {$plural}",
			'not_found'          => "{$nenhum} {$singular} {$encontr}.",
			'not_found_in_trash' => "{$nenhum} {$singular} na lixeira.",
		);
	}
}
