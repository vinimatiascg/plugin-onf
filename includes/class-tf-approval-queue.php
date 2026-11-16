<?php
/**
 * Fila Unificada de Aprovação Rápida e em Lote (Todos os CPTs e Todos os Subsites na mesma lista).
 *
 * Permite ao administrador:
 * - Visualizar em uma única lista todos os locais aguardando aprovação (pending/draft),
 *   independente do tipo (hotel, restaurante, loja, atrativo, passeio, servico) e da cidade;
 * - Aprovar em 1 clique (Aprovação Rápida) qualquer item individual;
 * - Selecionar múltiplos itens e aprovar em lote (Bulk Approve), ou aprovar todos com nota >= 85;
 * - Visualizar miniatura da foto oficial (ou indicação "Sem foto"), nota de confiança IA e fonte.
 *
 * @package TF_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_Approval_Queue {

	/**
	 * Registra hooks administrativos e de barra superior.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'network_admin_menu', array( __CLASS__, 'register_admin_menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_badge' ), 90 );
		add_action( 'admin_post_tf_queue_action', array( __CLASS__, 'handle_queue_actions' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'flush_pending_count' ), 10, 3 );
	}

	/**
	 * Adiciona o menu "Fila de Aprovação" no painel com contador de itens pendentes.
	 */
	public static function register_admin_menu(): void {
		$pending_count = self::count_total_pending_network();
		$badge         = $pending_count > 0
			? ' <span class="awaiting-mod count-' . esc_attr( (string) $pending_count ) . '"><span class="pending-count">' . esc_html( (string) $pending_count ) . '</span></span>'
			: '';

		add_menu_page(
			'Fila Unificada de Aprovação — Onde na Fronteira',
			'Fila Aprovação' . $badge,
			'edit_posts',
			'tf-approval-queue',
			array( __CLASS__, 'render_queue_page' ),
			'dashicons-yes-alt',
			3
		);
	}

	/**
	 * Adiciona atalho direto na barra superior do WordPress (Admin Bar) com contador global.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Barra admin.
	 */
	public static function add_admin_bar_badge( WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$count = self::count_total_pending_network();
		$wp_admin_bar->add_node(
			array(
				'id'    => 'tf-approval-queue-bar',
				'title' => '⚡ Fila de Aprovação (' . $count . ')',
				'href'  => admin_url( 'admin.php?page=tf-approval-queue' ),
			)
		);
	}

	/**
	 * Conta o total de estabelecimentos em status pending/draft em toda a rede Multisite.
	 *
	 * @return int
	 */
	public static function count_total_pending_network(): int {
		// Cache curto: o contador aparece em todas as telas do admin e na barra superior.
		$cached = get_site_transient( 'tf_pending_network_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$cpts  = tf_get_establishment_post_types();
		$total = 0;
		$sites = is_multisite() ? get_sites( array( 'number' => 20 ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );

		foreach ( $sites as $site ) {
			if ( is_multisite() ) {
				switch_to_blog( (int) $site->blog_id );
			}
			foreach ( $cpts as $cpt ) {
				$counts = wp_count_posts( $cpt );
				$total += (int) ( $counts->pending ?? 0 ) + (int) ( $counts->draft ?? 0 );
			}
			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		set_site_transient( 'tf_pending_network_count', $total, 2 * MINUTE_IN_SECONDS );
		return $total;
	}

	/**
	 * Invalida o contador quando um estabelecimento muda de status.
	 *
	 * @param string  $new_status Novo status.
	 * @param string  $old_status Status anterior.
	 * @param WP_Post $post       Post.
	 */
	public static function flush_pending_count( $new_status, $old_status, $post ): void {
		if ( $new_status !== $old_status && $post instanceof WP_Post && in_array( $post->post_type, tf_get_establishment_post_types(), true ) ) {
			delete_site_transient( 'tf_pending_network_count' );
		}
	}

	/**
	 * Processa ações de aprovação rápida (1 clique) e aprovação em lote (checkboxes ou nota >= 85).
	 */
	public static function handle_queue_actions(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'onde-na-fronteira' ) );
		}

		check_admin_referer( 'tf_queue_nonce_action', 'tf_queue_nonce' );

		$op       = sanitize_key( wp_unslash( $_REQUEST['tf_op'] ?? '' ) );
		$approved = 0;
		$trashed  = 0;

		if ( 'quick_approve' === $op ) {
			$blog_id = (int) ( $_REQUEST['blog_id'] ?? get_current_blog_id() );
			$post_id = (int) ( $_REQUEST['post_id'] ?? 0 );
			if ( $post_id > 0 ) {
				if ( is_multisite() ) {
					switch_to_blog( $blog_id );
				}
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
				if ( function_exists( 'tf_flush_site_page_cache' ) ) {
					tf_flush_site_page_cache( $blog_id );
				}
				if ( is_multisite() ) {
					restore_current_blog();
				}
				$approved = 1;
			}
		} elseif ( 'quick_trash' === $op ) {
			$blog_id = (int) ( $_REQUEST['blog_id'] ?? get_current_blog_id() );
			$post_id = (int) ( $_REQUEST['post_id'] ?? 0 );
			if ( $post_id > 0 ) {
				if ( is_multisite() ) {
					switch_to_blog( $blog_id );
				}
				wp_trash_post( $post_id );
				if ( is_multisite() ) {
					restore_current_blog();
				}
				$trashed = 1;
			}
		} elseif ( 'bulk_submit' === $op ) {
			$bulk_type = sanitize_key( wp_unslash( $_POST['bulk_action_type'] ?? 'approve_selected' ) );
			$items     = isset( $_POST['queue_items'] ) && is_array( $_POST['queue_items'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['queue_items'] ) ) : array();

			if ( 'approve_high_confidence' === $bulk_type ) {
				// Aprovar todos na rede com nota de confiança >= 85
				$sites = is_multisite() ? get_sites( array( 'number' => 20 ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );
				foreach ( $sites as $s ) {
					$b_id = (int) $s->blog_id;
					if ( is_multisite() ) {
						switch_to_blog( $b_id );
					}
					$posts = get_posts(
						array(
							'post_type'      => tf_get_establishment_post_types(),
							'post_status'    => array( 'pending', 'draft' ),
							'posts_per_page' => 300,
						)
					);
					foreach ( $posts as $p ) {
						$conf = (int) get_post_meta( $p->ID, '_tf_ai_confidence', true );
						if ( $conf >= 85 ) {
							wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'publish' ) );
							$approved++;
						}
					}
					if ( function_exists( 'tf_flush_site_page_cache' ) ) {
						tf_flush_site_page_cache( $b_id );
					}
					if ( is_multisite() ) {
						restore_current_blog();
					}
				}
			} else {
				// Aprovar ou mover para lixeira os itens marcados (formato "blog_id:post_id")
				foreach ( $items as $pair ) {
					if ( strpos( $pair, ':' ) === false ) {
						continue;
					}
					list( $b_id, $p_id ) = array_map( 'intval', explode( ':', $pair, 2 ) );
					if ( $b_id > 0 && $p_id > 0 ) {
						if ( is_multisite() ) {
							switch_to_blog( $b_id );
						}
						if ( 'trash_selected' === $bulk_type ) {
							wp_trash_post( $p_id );
							$trashed++;
						} else {
							wp_update_post( array( 'ID' => $p_id, 'post_status' => 'publish' ) );
							$approved++;
						}
						if ( function_exists( 'tf_flush_site_page_cache' ) ) {
							tf_flush_site_page_cache( $b_id );
						}
						if ( is_multisite() ) {
							restore_current_blog();
						}
					}
				}
			}
		}

		$redirect = add_query_arg(
			array(
				'page'     => 'tf-approval-queue',
				'approved' => $approved,
				'trashed'  => $trashed,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Renderiza a tela unificada de aprovação rápida e em lote.
	 */
	public static function render_queue_page(): void {
		$filter_city   = sanitize_key( wp_unslash( $_GET['city_filter'] ?? 'all' ) );
		$filter_cpt    = sanitize_key( wp_unslash( $_GET['cpt_filter'] ?? 'all' ) );
		$filter_status = sanitize_key( wp_unslash( $_GET['status_filter'] ?? 'pending_draft' ) );

		$cpts = ( 'all' !== $filter_cpt && in_array( $filter_cpt, tf_get_establishment_post_types(), true ) )
			? array( $filter_cpt )
			: tf_get_establishment_post_types();

		$statuses = ( 'publish' === $filter_status ) ? array( 'publish' ) : array( 'pending', 'draft' );

		$sites = is_multisite() ? get_sites( array( 'number' => 20 ) ) : array( (object) array( 'blog_id' => get_current_blog_id() ) );
		$rows  = array();

		foreach ( $sites as $site ) {
			$b_id = (int) $site->blog_id;
			if ( is_multisite() ) {
				switch_to_blog( $b_id );
			}
			$city = tf_get_current_city_context();
			if ( 'all' !== $filter_city && $city['slug'] !== $filter_city ) {
				if ( is_multisite() ) {
					restore_current_blog();
				}
				continue;
			}

			$posts = get_posts(
				array(
					'post_type'      => $cpts,
					'post_status'    => $statuses,
					'posts_per_page' => 250,
					'orderby'        => 'ID',
					'order'          => 'DESC',
				)
			);

			foreach ( $posts as $p ) {
				$pt_obj    = get_post_type_object( $p->post_type );
				$thumb_url = get_the_post_thumbnail_url( $p->ID, 'thumbnail' );
				$rows[]    = array(
					'blog_id'     => $b_id,
					'city_name'   => $city['name'],
					'city_slug'   => $city['slug'],
					'post_id'     => $p->ID,
					'title'       => $p->post_title,
					'post_type'   => $p->post_type,
					'pt_label'    => $pt_obj ? $pt_obj->labels->singular_name : $p->post_type,
					'status'      => $p->post_status,
					'thumb_url'   => $thumb_url ?: '',
					'address'     => (string) get_post_meta( $p->ID, '_tf_address', true ),
					'phone'       => (string) get_post_meta( $p->ID, '_tf_phone', true ),
					'confidence'  => (int) get_post_meta( $p->ID, '_tf_ai_confidence', true ),
					'source_url'  => (string) get_post_meta( $p->ID, '_tf_source_url', true ),
					'source_name' => (string) get_post_meta( $p->ID, '_tf_source_name', true ),
					'edit_url'    => get_edit_post_link( $p->ID ),
					'view_url'    => get_permalink( $p->ID ),
				);
			}

			if ( is_multisite() ) {
				restore_current_blog();
			}
		}

		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				⚡ Fila Unificada de Aprovação Rápida e em Lote — Onde na Fronteira
			</h1>
			<p>Todos os locais captados pelo pipeline (Hotéis, Restaurantes, Lojas/Shoppings, Atrativos, Passeios e Serviços) de todas as cidades reunidos em uma única lista para curadoria ágil.</p>

			<?php if ( ! empty( $_GET['approved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong>✓ <?php echo esc_html( (string) (int) $_GET['approved'] ); ?> registro(s) aprovado(s) e publicado(s) com sucesso!</strong></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['trashed'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p><strong><?php echo esc_html( (string) (int) $_GET['trashed'] ); ?> registro(s) movido(s) para a lixeira.</strong></p></div>
			<?php endif; ?>

			<!-- Filtros Rápidos da Fila -->
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="background:#fff;padding:12px 16px;border:1px solid #cbd5e1;border-radius:8px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
				<input type="hidden" name="page" value="tf-approval-queue" />
				<label><strong>Cidade:</strong>
					<select name="city_filter">
						<option value="all" <?php selected( $filter_city, 'all' ); ?>>🌎 Todas as Cidades (Rede)</option>
						<option value="foz" <?php selected( $filter_city, 'foz' ); ?>>🇧🇷 Foz do Iguaçu</option>
						<option value="cde" <?php selected( $filter_city, 'cde' ); ?>>🇵🇾 Ciudad del Este</option>
						<option value="iguazu" <?php selected( $filter_city, 'iguazu' ); ?>>🇦🇷 Puerto Iguazú</option>
					</select>
				</label>
				<label><strong>Tipo de Local:</strong>
					<select name="cpt_filter">
						<option value="all" <?php selected( $filter_cpt, 'all' ); ?>>Todos os Tipos (Unificado)</option>
						<option value="atrativo" <?php selected( $filter_cpt, 'atrativo' ); ?>>Atrativos Turísticos</option>
						<option value="hotel" <?php selected( $filter_cpt, 'hotel' ); ?>>Hotéis</option>
						<option value="restaurante" <?php selected( $filter_cpt, 'restaurante' ); ?>>Restaurantes</option>
						<option value="loja" <?php selected( $filter_cpt, 'loja' ); ?>>Lojas &amp; Shoppings</option>
						<option value="passeio" <?php selected( $filter_cpt, 'passeio' ); ?>>Passeios</option>
						<option value="servico" <?php selected( $filter_cpt, 'servico' ); ?>>Serviços (Câmbio/Agência/Locadora)</option>
					</select>
				</label>
				<label><strong>Status:</strong>
					<select name="status_filter">
						<option value="pending_draft" <?php selected( $filter_status, 'pending_draft' ); ?>>⏳ Aguardando Aprovação (Pendentes / Rascunhos)</option>
						<option value="publish" <?php selected( $filter_status, 'publish' ); ?>>✅ Já Publicados</option>
					</select>
				</label>
				<button type="submit" class="button button-secondary">Filtrar Lista</button>
			</form>

			<!-- Formulário de Aprovação em Lote -->
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'tf_queue_nonce_action', 'tf_queue_nonce' ); ?>
				<input type="hidden" name="action" value="tf_queue_action" />
				<input type="hidden" name="tf_op" value="bulk_submit" />

				<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px;">
					<select name="bulk_action_type" required>
						<option value="approve_selected">✓ Aprovar e Publicar Selecionados</option>
						<option value="approve_high_confidence">⚡ Aprovar Todos na Fila com Confiança IA &gt;= 85%</option>
						<option value="trash_selected">✗ Mover Selecionados para a Lixeira</option>
					</select>
					<button type="submit" class="button button-primary">Executar Ação em Lote</button>
					<span style="margin-left:auto;font-weight:600;color:#475569;">Total listado: <?php echo esc_html( (string) count( $rows ) ); ?> locais</span>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<td id="cb" class="manage-column column-cb check-column">
								<input type="checkbox" onclick="document.querySelectorAll('.tf-queue-cb').forEach(c => c.checked = this.checked);" />
							</td>
							<th style="width:74px;">Foto Oficial</th>
							<th style="width:120px;">Cidade / Tipo</th>
							<th>Nome do Local &amp; Endereço</th>
							<th style="width:110px;">Confiança IA</th>
							<th style="width:150px;">Fonte Oficial</th>
							<th style="width:220px;">Aprovação Rápida (1 Clique)</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr><td colspan="7">Nenhum local aguardando aprovação com os filtros atuais.</td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $r ) : ?>
								<?php
								$quick_approve_url = wp_nonce_url(
									add_query_arg(
										array(
											'action'  => 'tf_queue_action',
											'tf_op'   => 'quick_approve',
											'blog_id' => $r['blog_id'],
											'post_id' => $r['post_id'],
										),
										$action_url
									),
									'tf_queue_nonce_action',
									'tf_queue_nonce'
								);
								$quick_trash_url = wp_nonce_url(
									add_query_arg(
										array(
											'action'  => 'tf_queue_action',
											'tf_op'   => 'quick_trash',
											'blog_id' => $r['blog_id'],
											'post_id' => $r['post_id'],
										),
										$action_url
									),
									'tf_queue_nonce_action',
									'tf_queue_nonce'
								);
								?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" class="tf-queue-cb" name="queue_items[]" value="<?php echo esc_attr( $r['blog_id'] . ':' . $r['post_id'] ); ?>" />
									</th>
									<td>
										<?php if ( ! empty( $r['thumb_url'] ) ) : ?>
											<img src="<?php echo esc_url( $r['thumb_url'] ); ?>" alt="" style="width:60px;height:45px;object-fit:cover;border-radius:6px;border:1px solid #cbd5e1;" />
										<?php else : ?>
											<span style="display:inline-block;font-size:11px;color:#64748b;background:#f1f5f9;padding:4px 6px;border-radius:4px;text-align:center;">Sem foto</span>
										<?php endif; ?>
									</td>
									<td>
										<strong><?php echo esc_html( $r['city_name'] ); ?></strong><br>
										<span style="background:#e2e8f0;color:#0f172a;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600;"><?php echo esc_html( $r['pt_label'] ); ?></span>
									</td>
									<td>
										<strong style="font-size:14px;"><?php echo esc_html( $r['title'] ); ?></strong>
										<span style="font-size:11px;color:#64748b;">(ID <?php echo esc_html( (string) $r['post_id'] ); ?> • <?php echo esc_html( $r['status'] ); ?>)</span><br>
										<small style="color:#475569;">📍 <?php echo esc_html( $r['address'] ); ?> <?php echo $r['phone'] ? ' | 📞 ' . esc_html( $r['phone'] ) : ''; ?></small>
									</td>
									<td>
										<span style="background:<?php echo $r['confidence'] >= 85 ? '#dcfce7' : '#fef3c7'; ?>;color:<?php echo $r['confidence'] >= 85 ? '#166534' : '#92400e'; ?>;padding:4px 8px;border-radius:999px;font-weight:700;">
											<?php echo esc_html( $r['confidence'] . '/100' ); ?>
										</span>
									</td>
									<td>
										<?php if ( ! empty( $r['source_url'] ) ) : ?>
											<a href="<?php echo esc_url( $r['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $r['source_name'] ?: 'Ver Fonte' ); ?> ↗</a>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td>
										<div style="display:flex;gap:6px;flex-wrap:wrap;">
											<?php if ( 'publish' !== $r['status'] ) : ?>
												<a href="<?php echo esc_url( $quick_approve_url ); ?>" class="button button-primary button-small" style="background:#15803d;border-color:#15803d;">✓ Aprovar</a>
											<?php endif; ?>
											<a href="<?php echo esc_url( $r['edit_url'] ); ?>" class="button button-secondary button-small">✎ Editar</a>
											<a href="<?php echo esc_url( $quick_trash_url ); ?>" class="button button-link-delete button-small">✗ Lixeira</a>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</form>
		</div>
		<?php
	}
}
