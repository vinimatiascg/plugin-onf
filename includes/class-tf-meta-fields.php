<?php
/**
 * Registro de Metacampos Nativos (register_post_meta + REST API) e Metaboxes Administrativas.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Meta_Fields {

	/**
	 * Inicializa hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_all_meta' ), 10 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_boxes' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enfileira os scripts da biblioteca de mídia nativa do WordPress nas telas de edição de CPTs.
	 *
	 * @param string $hook_suffix Sufixo da página atual no admin.
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Retorna a especificação de todos os metacampos suportados pelo tf-core.
	 *
	 * @return array<string, array>
	 */
	public static function get_fields_schema(): array {
		return array(
			'_tf_official_name'       => array( 'type' => 'string',  'default' => '',         'label' => 'Nome Oficial do Empreendimento' ),
			'_tf_logo_url'            => array( 'type' => 'string',  'default' => '',         'label' => 'Logo da Loja / Empreendimento (URL ou Upload)' ),
			'_tf_direct_answer'       => array( 'type' => 'string',  'default' => '',         'label' => 'Resposta Direta GEO (40-60 palavras)' ),
			'_tf_address'             => array( 'type' => 'string',  'default' => '',         'label' => 'Endereço Completo' ),
			'_tf_postal_code'         => array( 'type' => 'string',  'default' => '',         'label' => 'CEP / Código Postal' ),
			'_tf_lat'                 => array( 'type' => 'number',  'default' => 0.0,        'label' => 'Latitude' ),
			'_tf_lng'                 => array( 'type' => 'number',  'default' => 0.0,        'label' => 'Longitude' ),
			'_tf_phone'               => array( 'type' => 'string',  'default' => '',         'label' => 'Telefone Comercial' ),
			'_tf_whatsapp'            => array( 'type' => 'string',  'default' => '',         'label' => 'WhatsApp' ),
			'_tf_email'               => array( 'type' => 'string',  'default' => '',         'label' => 'E-mail Comercial de Contato' ),
			'_tf_website'             => array( 'type' => 'string',  'default' => '',         'label' => 'Site Oficial (URL)' ),
			'_tf_instagram'           => array( 'type' => 'string',  'default' => '',         'label' => 'Instagram (URL ou @usuario)' ),
			'_tf_facebook'            => array( 'type' => 'string',  'default' => '',         'label' => 'Facebook (URL)' ),
			'_tf_tiktok'              => array( 'type' => 'string',  'default' => '',         'label' => 'TikTok (URL)' ),
			'_tf_views_count'         => array( 'type' => 'integer', 'default' => 0,          'label' => 'Total de Visitas e Acessos' ),
			'_tf_opening_hours'       => array( 'type' => 'string',  'default' => '',         'label' => 'Horários de Funcionamento (JSON ou texto)' ),
			'_tf_price_range'         => array( 'type' => 'string',  'default' => '',         'label' => 'Faixa de Preço (ex: $$, R$ 80 - R$ 150)' ),
			'_tf_price_value'         => array( 'type' => 'number',  'default' => 0.0,        'label' => 'Valor Base Numérico (para Offer de Passeio)' ),
			'_tf_price_currency'      => array( 'type' => 'string',  'default' => 'BRL',      'label' => 'Moeda do Preço (BRL, USD, PYG, ARS)' ),
			'_tf_languages'           => array( 'type' => 'string',  'default' => 'pt-BR',    'label' => 'Idiomas Atendidos (ex: pt-BR, es, en)' ),
			'_tf_payment_methods'     => array( 'type' => 'string',  'default' => '',         'label' => 'Formas de Pagamento Aceitas' ),
			'_tf_source_url'          => array( 'type' => 'string',  'default' => '',         'label' => 'Fonte Original Verificada (URL)' ),
			'_tf_source_name'         => array( 'type' => 'string',  'default' => '',         'label' => 'Nome da Fonte Oficial' ),
			'_tf_last_verified_at'    => array( 'type' => 'string',  'default' => '',         'label' => 'Data da Última Verificação (YYYY-MM-DD)' ),
			'_tf_verification_status' => array( 'type' => 'string',  'default' => 'verified', 'label' => 'Status de Verificação (verified/needs_review/outdated/closed)' ),
			'_tf_ai_confidence'       => array( 'type' => 'integer', 'default' => 0,          'label' => 'Nota de Confiança da IA (0-100)' ),
			'_tf_ai_model'            => array( 'type' => 'string',  'default' => '',         'label' => 'Modelo de IA Utilizado' ),
			'_tf_ai_assisted'         => array( 'type' => 'boolean', 'default' => false,      'label' => 'Conteúdo Assistido por IA' ),
			'_tf_is_top_100_cde'      => array( 'type' => 'boolean', 'default' => false,      'label' => 'Top 100 Lojas Mais Procuradas (Ciudad del Este)' ),
			'_tf_top_100_rank'        => array( 'type' => 'integer', 'default' => 0,          'label' => 'Posição no Ranking Top 100 CDE (1-100)' ),
			'_tf_parent_shopping_id'  => array( 'type' => 'integer', 'default' => 0,          'label' => 'ID do Shopping onde está localizada' ),
			'_tf_rating_value'        => array( 'type' => 'number',  'default' => 0.0,        'label' => 'Nota de Avaliação Real Verificável (1.0 a 5.0)' ),
			'_tf_rating_count'        => array( 'type' => 'integer', 'default' => 0,          'label' => 'Total de Avaliações Reais Verificáveis' ),
			'_tf_rating_source'       => array( 'type' => 'string',  'default' => '',         'label' => 'Fonte Verificável da Avaliação (URL/Nome)' ),
			'_tf_faq_json'            => array( 'type' => 'string',  'default' => '',         'label' => 'Perguntas Frequentes FAQPage (JSON array)' ),
			'_tf_social_drafts_json'  => array( 'type' => 'string',  'default' => '',         'label' => 'Rascunhos para Redes Sociais (JSON)' ),
		);
	}

	/**
	 * Registra os metacampos em todos os CPTs e também em posts/pages para REST API.
	 */
	public static function register_all_meta(): void {
		$post_types = array_merge( tf_get_establishment_post_types(), array( 'post', 'page' ) );
		$fields     = self::get_fields_schema();

		foreach ( $post_types as $post_type ) {
			foreach ( $fields as $meta_key => $spec ) {
				register_post_meta(
					$post_type,
					$meta_key,
					array(
						'type'              => $spec['type'],
						'description'       => $spec['label'],
						'single'            => true,
						'default'           => $spec['default'],
						'show_in_rest'      => true,
						'sanitize_callback' => function ( $value ) use ( $spec, $meta_key ) {
							return self::sanitize_meta_value( $value, $spec['type'], $meta_key );
						},
						'auth_callback'     => function () {
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		}
	}

	/**
	 * Sanitiza o valor de acordo com o tipo declarado.
	 *
	 * @param mixed  $value    Valor recebido.
	 * @param string $type     Tipo esperado.
	 * @param string $meta_key Chave do metacampo.
	 * @return mixed
	 */
	public static function sanitize_meta_value( $value, string $type, string $meta_key ) {
		switch ( $type ) {
			case 'boolean':
				return (bool) $value;
			case 'integer':
				$int_val = (int) $value;
				if ( '_tf_ai_confidence' === $meta_key ) {
					return max( 0, min( 100, $int_val ) );
				}
				return $int_val;
			case 'number':
				return (float) $value;
			case 'string':
			default:
				if ( in_array( $meta_key, array( '_tf_website', '_tf_source_url', '_tf_facebook', '_tf_tiktok', '_tf_logo_url' ), true ) ) {
					return esc_url_raw( (string) $value );
				}
				if ( '_tf_email' === $meta_key ) {
					return sanitize_email( (string) $value );
				}
				if ( in_array( $meta_key, array( '_tf_opening_hours', '_tf_faq_json', '_tf_i18n_es', '_tf_i18n_en', '_tf_social_drafts_json', '_tf_direct_answer' ), true ) ) {
					return sanitize_textarea_field( (string) $value );
				}
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Registra a metabox administrativa para edição manual segura.
	 */
	public static function add_meta_boxes(): void {
		$screens = array_merge( tf_get_establishment_post_types(), array( 'post' ) );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'tf_establishment_meta_box',
				__( 'Dados do Empreendimento (Onde na Fronteira)', 'onde-na-fronteira' ),
				array( __CLASS__, 'render_meta_box' ),
				$screen,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Renderiza a metabox com nonce e campos escapados.
	 *
	 * @param WP_Post $post Objeto post atual.
	 */
	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'tf_save_meta_box_action', 'tf_meta_box_nonce' );
		$fields = self::get_fields_schema();

		// Banner de orientação sobre Imagem Destacada (Fachada) vs Logo
		?>
		<div style="background:#f0fdf4; border:1px solid #bbf7d0; border-left:4px solid #16a34a; padding:12px 16px; border-radius:6px; margin:8px 0 16px;">
			<strong style="color:#15803d; font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:4px;">
				<span class="dashicons dashicons-format-image" style="font-size:18px;"></span>
				Orientação Editorial sobre Imagens
			</strong>
			<p style="margin:0; color:#166534; font-size:12px; line-height:1.5;">
				• <strong>Logo da Loja / Empreendimento (abaixo):</strong> Envie o arquivo da marca/logotipo (PNG transparente, SVG ou WebP).<br>
				• <strong>Imagem Destacada (painel lateral direito):</strong> Deve ser a <strong>foto da FACHADA</strong> ou do ambiente do estabelecimento real.
			</p>
		</div>

		<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:8px 0;">
		<?php
		foreach ( $fields as $key => $spec ) {
			$val  = get_post_meta( $post->ID, $key, true );
			$type = $spec['type'];

			// Tratamento visual especializado para o Logo do Empreendimento com Upload de Mídia
			if ( '_tf_logo_url' === $key ) {
				$logo_val = (string) $val;
				?>
				<div style="grid-column:1 / -1; background:#f8fafc; border:1px solid #cbd5e1; padding:14px 16px; border-radius:8px;">
					<label for="_tf_logo_url" style="display:block; font-weight:700; font-size:13px; margin-bottom:6px; color:#0f172a;">
						🏷️ <?php echo esc_html( $spec['label'] ); ?> <code style="font-size:11px;color:#64748b;">(<?php echo esc_html( $key ); ?>)</code>
					</label>
					<div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
						<div id="tf_logo_preview" style="width:70px; height:70px; border:2px dashed #94a3b8; border-radius:8px; display:flex; align-items:center; justify-content:center; background:#ffffff; overflow:hidden; padding:4px;">
							<?php if ( ! empty( $logo_val ) ) : ?>
								<img src="<?php echo esc_url( $logo_val ); ?>" alt="Logo Preview" style="max-width:100%; max-height:100%; object-fit:contain;" />
							<?php else : ?>
								<span style="font-size:10px; color:#94a3b8; text-align:center; font-weight:600;">Sem logo</span>
							<?php endif; ?>
						</div>
						<div style="flex:1; min-width:240px;">
							<input type="text" id="_tf_logo_url" name="_tf_logo_url" value="<?php echo esc_attr( $logo_val ); ?>" placeholder="https://... ou clique em Selecionar / Enviar Logo" style="width:100%; margin-bottom:8px;" />
							<div style="display:flex; gap:8px;">
								<button type="button" class="button button-secondary" id="tf_upload_logo_btn">
									<span class="dashicons dashicons-upload" style="margin-top:3px;font-size:16px;"></span> Selecionar / Enviar Logo
								</button>
								<button type="button" class="button" id="tf_remove_logo_btn" style="<?php echo empty( $logo_val ) ? 'display:none;' : ''; ?>">
									✕ Remover Logo
								</button>
							</div>
						</div>
					</div>
				</div>
				<?php
				continue;
			}

			$wide = in_array( $key, array( '_tf_direct_answer', '_tf_opening_hours', '_tf_faq_json', '_tf_i18n_es', '_tf_i18n_en', '_tf_social_drafts_json' ), true );
			echo '<div style="' . ( $wide ? 'grid-column:1 / -1;' : '' ) . '">';
			echo '<label for="' . esc_attr( $key ) . '" style="display:block;font-weight:600;margin-bottom:4px;">' . esc_html( $spec['label'] ) . ' <code style="font-size:11px;color:#64748b;">(' . esc_html( $key ) . ')</code></label>';
			if ( 'boolean' === $type ) {
				echo '<input type="checkbox" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="1" ' . checked( (bool) $val, true, false ) . ' />';
			} elseif ( $wide ) {
				echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" rows="3" style="width:100%;">' . esc_textarea( (string) $val ) . '</textarea>';
			} else {
				$input_type = ( 'number' === $type || 'integer' === $type ) ? 'number' : 'text';
				$step       = ( 'number' === $type ) ? 'step="any"' : '';
				echo '<input type="' . esc_attr( $input_type ) . '" ' . $step . ' id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $val ) . '" style="width:100%;" />';
			}
			echo '</div>';
		}
		echo '</div>';
		?>
		<script>
		jQuery(document).ready(function($){
			var frame;
			$('#tf_upload_logo_btn').on('click', function(e){
				e.preventDefault();
				if (frame) {
					frame.open();
					return;
				}
				frame = wp.media({
					title: 'Selecionar ou Enviar Logo do Empreendimento',
					button: { text: 'Usar esta Logo' },
					multiple: false,
					library: { type: 'image' }
				});
				frame.on('select', function(){
					var attachment = frame.state().get('selection').first().toJSON();
					$('#_tf_logo_url').val(attachment.url);
					$('#tf_logo_preview').html('<img src="' + attachment.url + '" style="max-width:100%;max-height:100%;object-fit:contain;" />');
					$('#tf_remove_logo_btn').show();
				});
				frame.open();
			});
			$('#tf_remove_logo_btn').on('click', function(e){
				e.preventDefault();
				$('#_tf_logo_url').val('');
				$('#tf_logo_preview').html('<span style="font-size:10px;color:#94a3b8;text-align:center;font-weight:600;">Sem logo</span>');
				$(this).hide();
			});
		});
		</script>
		<?php
	}

	/**
	 * Salva os metacampos verificando nonce, autosave e permissões.
	 *
	 * @param int     $post_id ID do post.
	 * @param WP_Post $post    Objeto post.
	 */
	public static function save_meta_boxes( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['tf_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tf_meta_box_nonce'] ) ), 'tf_save_meta_box_action' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = self::get_fields_schema();
		foreach ( $fields as $key => $spec ) {
			if ( 'boolean' === $spec['type'] ) {
				$bool_val = isset( $_POST[ $key ] ) && '1' === $_POST[ $key ];
				update_post_meta( $post_id, $key, $bool_val );
			} elseif ( isset( $_POST[ $key ] ) ) {
				$raw_val = wp_unslash( $_POST[ $key ] );
				$clean   = self::sanitize_meta_value( $raw_val, $spec['type'], $key );
				update_post_meta( $post_id, $key, $clean );
			}
		}
	}
}
