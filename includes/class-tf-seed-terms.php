<?php
/**
 * Semeador de termos iniciais das taxonomias para cada subsite da rede.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Seed_Terms {

	/**
	 * Registra hook em admin_init / wp_loaded para garantir termos base uma única vez por versão.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_seed_default_terms' ), 20 );
	}

	/**
	 * Cria termos iniciais nas taxonomias caso ainda não tenham sido semeados nesta versão.
	 */
	public static function maybe_seed_default_terms(): void {
		if ( get_option( 'tf_seeded_terms_version' ) === TF_CORE_VERSION ) {
			return;
		}

		$city = tf_get_current_city_context();

		// 1. Faixas de Preço
		$price_terms = array(
			'gratuito' => 'Gratuito',
			'economico'=> '$ — Econômico',
			'moderado' => '$$ — Moderado',
			'superior' => '$$$ — Superior',
			'luxo'     => '$$$$ — Premium / Luxo',
		);
		self::insert_terms_map( 'faixa_preco', $price_terms );

		// 2. Comodidades
		$amenities = array(
			'wifi-gratis'          => 'Wi-Fi Grátis',
			'estacionamento'       => 'Estacionamento',
			'acessivel-pcd'        => 'Acessível para PCD',
			'aceita-pix'           => 'Aceita Pix',
			'ar-condicionado'      => 'Ar-condicionado',
			'pet-friendly'         => 'Pet Friendly',
			'atendimento-portugues'=> 'Atendimento em Português',
			'piscina'              => 'Piscina',
			'cafe-da-manha'        => 'Café da Manhã Incluso',
			'tax-free'             => 'Regime Tax Free / Duty Free',
		);
		self::insert_terms_map( 'comodidade', $amenities );

		// 3. Categorias de Hotel
		self::insert_terms_map(
			'categoria_hotel',
			array(
				'resort'       => 'Resort',
				'hotel-urbano' => 'Hotel Urbano',
				'hotel-boutique'=> 'Hotel Boutique',
				'pousada'      => 'Pousada',
				'hostel'       => 'Hostel',
				'eco-lodge'    => 'Eco Lodge',
			)
		);

		// 4. Categorias de Restaurante & Tipos de Cozinha
		self::insert_terms_map(
			'categoria_restaurante',
			array(
				'churrascaria'        => 'Churrascaria / Rodízio',
				'parrilla-argentina'  => 'Parrilla Argentina',
				'alta-gastronomia'    => 'Alta Gastronomia',
				'restaurante-familiar'=> 'Restaurante Familiar',
				'bar-e-pub'           => 'Bar & Pub',
				'cafeteria-confeitaria'=> 'Cafeteria & Confeitaria',
			)
		);
		self::insert_terms_map(
			'tipo_cozinha',
			array(
				'brasileira' => 'Brasileira',
				'argentina'  => 'Argentina / Carnes Nobres',
				'paraguaia'  => 'Paraguaia / Guarani',
				'arabe'      => 'Árabe / Libanesa',
				'italiana'   => 'Italiana / Massas',
				'asiatica'   => 'Asiática / Japonesa',
				'peixes'     => 'Peixes de Rio (Dourado / Surubim)',
			)
		);

		// 5. Categorias de Loja (incluindo Shopping, Duty Free, Casa de Câmbio)
		self::insert_terms_map(
			'categoria_loja',
			array(
				'shopping'              => 'Shopping Center / Galeria',
				'duty-free'             => 'Duty Free Shop',
				'eletronicos-informatica'=> 'Eletrônicos e Informática',
				'perfumaria-cosmeticos' => 'Perfumaria e Cosméticos',
				'bebidas-vinhos'        => 'Bebidas, Vinhos e Alfajores',
				'moda-esportes'         => 'Moda, Calçados e Esportes',
				'multidepartamento'     => 'Loja de Departamentos / Importados',
				'artesanato-souvenirs'  => 'Artesanato e Souvenirs',
			)
		);

		// 6. Categorias de Atrativo
		self::insert_terms_map(
			'categoria_atrativo',
			array(
				'natureza-parques'     => 'Natureza e Parques Nacionais',
				'usinas-engenharia'    => 'Complexo Hidrelétrico e Engenharia',
				'marcos-historicos'    => 'Marcos Fronteiriços e Históricos',
				'templos-cultura'      => 'Templos Religiosos e Cultura',
				'parques-tematicos'    => 'Parques Temáticos e Museus',
			)
		);

		// 7. Categorias e Modalidades de Passeio
		self::insert_terms_map(
			'categoria_passeio',
			array(
				'cataratas-aventura' => 'Cataratas e Aventura',
				'city-tour-compras'  => 'City Tour e Tour de Compras',
				'navegacao-barco'    => 'Navegação nos Rios Iguaçu e Paraná',
				'noturno-gastronomico'=> 'Passeio Noturno e Gastronômico',
			)
		);
		self::insert_terms_map(
			'tipo_passeio',
			array(
				'ingresso-oficial'  => 'Ingresso Oficial',
				'tour-compartilhado'=> 'Tour Regular Compartilhado',
				'tour-privativo'    => 'Passeio Privativo',
				'transfer-transporte'=> 'Transfer / Transporte Turístico',
			)
		);

		// 8. Tipos de Serviço (Casas de Câmbio, Agências, Locadoras)
		self::insert_terms_map(
			'tipo_servico',
			array(
				'casa-de-cambio'      => 'Casa de Câmbio',
				'agencia-de-turismo'  => 'Agência de Turismo e Receptivo',
				'locadora-de-veiculos'=> 'Locadora de Veículos',
			)
		);

		// 9. Bairros / Zonas específicos por cidade
		$bairros_por_cidade = array(
			'foz'    => array(
				'centro-foz'          => 'Centro',
				'vila-yolanda'        => 'Vila Yolanda',
				'corredor-turistico'  => 'Avenida das Cataratas (Corredor Turístico)',
				'parque-nacional-foz' => 'Parque Nacional do Iguaçu',
				'itaipu-zona-norte'   => 'Região de Itaipu / Vila A',
				'ponte-da-amizade'    => 'Vila Portes / Ponte da Amizade',
			),
			'cde'    => array(
				'microcentro-cde'     => 'Microcentro Comercial (Ponte da Amizade)',
				'avenida-san-blas'    => 'Avenida San Blas',
				'avenida-monseñor-rodriguez' => 'Avenida Monseñor Rodríguez',
				'km-4-cde'            => 'Km 4 / Superviaduto',
				'lago-de-la-republica'=> 'Lago de la República',
				'presidente-franco'   => 'Presidente Franco / Saltos del Monday',
			),
			'iguazu' => array(
				'centro-iguazu'       => 'Centro de Puerto Iguazú',
				'feirinha-iguazu'     => 'Região da Feirinha (Av. Brasil)',
				'hito-tres-fronteras' => 'Costanera / Hito Tres Fronteras',
				'selva-irapay'        => 'Selva Iryapú (600 Hectáreas)',
				'ruta-12-duty-free'   => 'Ruta Nacional 12 / Duty Free',
				'parque-nacional-arg' => 'Parque Nacional Iguazú (Argentina)',
			),
			'raiz'   => array(
				'foz-do-iguacu'       => 'Foz do Iguaçu (BR)',
				'ciudad-del-este'     => 'Ciudad del Este (PY)',
				'puerto-iguazu'       => 'Puerto Iguazú (AR)',
			),
		);

		$bairros = $bairros_por_cidade[ $city['slug'] ] ?? $bairros_por_cidade['raiz'];
		self::insert_terms_map( 'bairro', $bairros );

		update_option( 'tf_seeded_terms_version', TF_CORE_VERSION );
	}

	/**
	 * Insere array associativo (slug => nome) em uma taxonomia.
	 *
	 * @param string $taxonomy Taxonomia.
	 * @param array  $terms    Mapa slug => nome.
	 */
	private static function insert_terms_map( string $taxonomy, array $terms ): void {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		foreach ( $terms as $slug => $name ) {
			if ( ! term_exists( $slug, $taxonomy ) ) {
				wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			}
		}
	}
}
