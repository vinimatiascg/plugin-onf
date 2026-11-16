<?php
/**
 * Atualizador do tf-core via GitHub.
 *
 * Integra o plugin ao sistema nativo de atualizações do WordPress:
 *  - verifica a versão do cabeçalho `Version:` do tf-core.php na branch `main`
 *    (e/ou a última Release publicada) do repositório vinimatiascg/plugin-onf;
 *  - exibe o aviso "Há uma nova versão" em Plugins / Painel > Atualizações;
 *  - permite atualizar com um clique (pacote ZIP do GitHub), renomeando a pasta extraída
 *    para o nome da pasta instalada (tf-core);
 *  - página "Atualização GitHub" no menu do Alimentador (site e rede) com status,
 *    botão "Verificar agora", "Atualizar agora" e token opcional para repositório privado.
 *
 * Token opcional (repositório privado): constante TF_CORE_GITHUB_TOKEN no wp-config.php,
 * variável de ambiente TF_CORE_GITHUB_TOKEN ou campo na página (Fine-grained, Contents: Read-only).
 *
 * @package TF_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TF_GitHub_Plugin_Updater {

	const REPO_OWNER = 'vinimatiascg';
	const REPO_NAME  = 'plugin-onf';
	const BRANCH     = 'main';
	const MAIN_FILE  = 'tf-core.php';
	const CACHE_KEY  = 'tf_core_github_update_data';
	const PAGE_SLUG  = 'tf-core-updater';

	/** @var string Ex.: tf-core/tf-core.php */
	private static string $basename = '';

	/** @var string Ex.: tf-core */
	private static string $folder = '';

	public static function init(): void {
		self::$basename = plugin_basename( TF_CORE_PATH . self::MAIN_FILE );
		self::$folder   = dirname( self::$basename );

		// 1. Aviso nativo de atualização (Plugins e Painel > Atualizações).
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );

		// 2. Janela "Ver detalhes da versão".
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api' ), 20, 3 );

		// 3. Pasta extraída do ZIP (plugin-onf-main / vinimatiascg-plugin-onf-abc123) → tf-core.
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );

		// 4. Token no download do pacote quando o repositório for privado.
		add_filter( 'http_request_args', array( __CLASS__, 'authenticate_download' ), 10, 2 );

		// 5. Limpeza de cache.
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_after_update' ), 10, 2 );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );
		add_action( 'load-plugins.php', array( __CLASS__, 'maybe_force_check' ) );

		// 6. Página de administração + link na lista de plugins.
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
		add_action( 'network_admin_menu', array( __CLASS__, 'register_page' ), 30 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_filter( 'plugin_action_links_' . self::$basename, array( __CLASS__, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . self::$basename, array( __CLASS__, 'action_links' ) );
	}

	/* ---------------------------------------------------------------------
	 * GitHub
	 * ------------------------------------------------------------------ */

	private static function repo_path(): string {
		return self::REPO_OWNER . '/' . self::REPO_NAME;
	}

	public static function get_token(): string {
		if ( defined( 'TF_CORE_GITHUB_TOKEN' ) && is_string( TF_CORE_GITHUB_TOKEN ) && '' !== trim( TF_CORE_GITHUB_TOKEN ) ) {
			return trim( TF_CORE_GITHUB_TOKEN );
		}
		$env = getenv( 'TF_CORE_GITHUB_TOKEN' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return trim( $env );
		}
		$saved = get_site_option( 'tf_core_github_token', '' );
		return is_string( $saved ) ? trim( $saved ) : '';
	}

	private static function token_from_config(): bool {
		return ( defined( 'TF_CORE_GITHUB_TOKEN' ) && TF_CORE_GITHUB_TOKEN ) || getenv( 'TF_CORE_GITHUB_TOKEN' );
	}

	private static function headers( string $accept = 'application/vnd.github+json' ): array {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'WordPress/' . get_bloginfo( 'version' ) . '; tf-core-updater',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token = self::get_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	public static function installed_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( TF_CORE_PATH . self::MAIN_FILE, false, false );
		return (string) ( $data['Version'] ?? TF_CORE_VERSION );
	}

	/**
	 * Consulta a versão remota (cabeçalho do tf-core.php na branch e última Release).
	 *
	 * @param bool $force Ignorar o cache.
	 * @return array{remote_version:string, package_url:string, details_url:string, last_commit:string, checked_at:string, error:string}
	 */
	public static function fetch_remote( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['remote_version'] ) ) {
				return $cached;
			}
		}

		$repo        = self::repo_path();
		$has_token   = '' !== self::get_token();
		$remote_ver  = '';
		$error       = '';
		$details_url = 'https://github.com/' . $repo;
		// Repositório público: ZIP direto; privado (com token): zipball pela API.
		$package_url = $has_token
			? sprintf( 'https://api.github.com/repos/%s/zipball/%s', $repo, self::BRANCH )
			: sprintf( 'https://github.com/%s/archive/refs/heads/%s.zip', $repo, self::BRANCH );

		// 1. Cabeçalho do arquivo principal pela API (público ou privado).
		$res = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/contents/%s?ref=%s', $repo, self::MAIN_FILE, rawurlencode( self::BRANCH ) ),
			array( 'timeout' => 12, 'headers' => self::headers( 'application/vnd.github.raw+json' ) )
		);
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$remote_ver = self::parse_version( (string) wp_remote_retrieve_body( $res ) );
		} else {
			$code  = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
			$error = sprintf( '%s ao consultar %s', $code, $repo );
		}

		// 2. Fallback raw.githubusercontent.com (repositório público, API com rate-limit).
		if ( '' === $remote_ver && ! $has_token ) {
			$raw = wp_remote_get( sprintf( 'https://raw.githubusercontent.com/%s/%s/%s', $repo, self::BRANCH, self::MAIN_FILE ), array( 'timeout' => 10 ) );
			if ( ! is_wp_error( $raw ) && 200 === (int) wp_remote_retrieve_response_code( $raw ) ) {
				$remote_ver = self::parse_version( (string) wp_remote_retrieve_body( $raw ) );
				if ( '' !== $remote_ver ) {
					$error = '';
				}
			}
		}

		// 3. Release publicada com tag superior tem prioridade.
		$rel = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo ),
			array( 'timeout' => 10, 'headers' => self::headers() )
		);
		if ( ! is_wp_error( $rel ) && 200 === (int) wp_remote_retrieve_response_code( $rel ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $rel ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
				$tag = ltrim( trim( (string) $data['tag_name'] ), 'vV' );
				if ( '' === $remote_ver || version_compare( $tag, $remote_ver, '>' ) ) {
					$remote_ver  = $tag;
					$package_url = $has_token && ! empty( $data['zipball_url'] )
						? (string) $data['zipball_url']
						: sprintf( 'https://github.com/%s/archive/refs/tags/%s.zip', $repo, rawurlencode( (string) $data['tag_name'] ) );
					$details_url = ! empty( $data['html_url'] ) ? (string) $data['html_url'] : $details_url;
					$error       = '';
				}
			}
		}

		// 4. Último commit da branch (mostrado na página de status).
		$last_commit = '';
		$com         = wp_remote_get(
			sprintf( 'https://api.github.com/repos/%s/commits/%s', $repo, rawurlencode( self::BRANCH ) ),
			array( 'timeout' => 10, 'headers' => self::headers() )
		);
		if ( ! is_wp_error( $com ) && 200 === (int) wp_remote_retrieve_response_code( $com ) ) {
			$c = json_decode( (string) wp_remote_retrieve_body( $com ), true );
			if ( is_array( $c ) && ! empty( $c['sha'] ) ) {
				$msg         = strtok( (string) ( $c['commit']['message'] ?? '' ), "\n" );
				$last_commit = substr( (string) $c['sha'], 0, 7 ) . ' — ' . $msg . ' (' . (string) ( $c['commit']['committer']['date'] ?? '' ) . ')';
			}
		}

		$result = array(
			'remote_version' => $remote_ver,
			'package_url'    => $package_url,
			'details_url'    => $details_url,
			'last_commit'    => $last_commit,
			'checked_at'     => current_time( 'mysql' ),
			'error'          => $error,
		);
		set_site_transient( self::CACHE_KEY, $result, '' !== $remote_ver ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	private static function parse_version( string $php ): string {
		return preg_match( '/^[ \t\/*#@]*Version:\s*([^\r\n]+)/mi', $php, $m ) ? trim( $m[1] ) : '';
	}

	public static function has_update(): bool {
		$remote = self::fetch_remote();
		return '' !== $remote['remote_version'] && version_compare( $remote['remote_version'], self::installed_version(), '>' );
	}

	/* ---------------------------------------------------------------------
	 * Integração com o atualizador nativo
	 * ------------------------------------------------------------------ */

	/**
	 * @param mixed $transient Estado de atualizações de plugins.
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// Evita chamadas ao GitHub em páginas públicas.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $transient;
		}

		$remote = self::fetch_remote();
		if ( '' === $remote['remote_version'] ) {
			return $transient;
		}

		$item = (object) array(
			'id'          => 'github.com/' . self::repo_path(),
			'slug'        => self::$folder,
			'plugin'      => self::$basename,
			'new_version' => $remote['remote_version'],
			'url'         => $remote['details_url'],
			'package'     => $remote['package_url'],
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => get_bloginfo( 'version' ),
		);

		if ( version_compare( $remote['remote_version'], self::installed_version(), '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ self::$basename ] = $item;
			unset( $transient->no_update[ self::$basename ] );
		} else {
			unset( $transient->response[ self::$basename ] );
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ self::$basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Janela de detalhes da versão.
	 *
	 * @param false|object|array $result Resultado.
	 * @param string             $action Ação.
	 * @param object             $args   Argumentos.
	 * @return false|object|array
	 */
	public static function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::$folder !== $args->slug ) {
			return $result;
		}
		$remote = self::fetch_remote();

		return (object) array(
			'name'          => 'Onde na Fronteira — Core Network Plugin (tf-core)',
			'slug'          => self::$folder,
			'version'       => '' !== $remote['remote_version'] ? $remote['remote_version'] : self::installed_version(),
			'author'        => 'Onde na Fronteira',
			'homepage'      => 'https://github.com/' . self::repo_path(),
			'download_link' => $remote['package_url'],
			'last_updated'  => $remote['checked_at'],
			'sections'      => array(
				'description' => '<p>Plugin central da rede Onde na Fronteira. Atualizações distribuídas pelo GitHub (<a href="https://github.com/' . esc_attr( self::repo_path() ) . '" target="_blank" rel="noopener">' . esc_html( self::repo_path() ) . '</a>).</p>',
				'changelog'   => '<p>' . ( '' !== $remote['last_commit'] ? 'Último commit: ' . esc_html( $remote['last_commit'] ) : 'Veja o histórico de commits no GitHub.' ) . '</p>',
			),
		);
	}

	/**
	 * Renomeia a pasta extraída do ZIP para o nome da pasta instalada.
	 *
	 * @param string|WP_Error $source        Pasta extraída.
	 * @param string          $remote_source Pasta temporária pai.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $hook_extra    Metadados.
	 * @return string|WP_Error
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$is_ours = isset( $hook_extra['plugin'] ) && self::$basename === $hook_extra['plugin'];
		if ( ! $is_ours ) {
			$name    = basename( untrailingslashit( (string) $source ) );
			$is_ours = 0 === strpos( $name, self::REPO_NAME . '-' ) || 0 === strpos( $name, self::REPO_OWNER . '-' . self::REPO_NAME );
		}
		if ( ! $is_ours ) {
			return $source;
		}

		// Garante que o pacote contém o arquivo principal do plugin.
		if ( ! file_exists( trailingslashit( (string) $source ) . self::MAIN_FILE ) ) {
			return new WP_Error( 'tf_core_invalid_package', 'Pacote do GitHub inválido: ' . self::MAIN_FILE . ' não encontrado.' );
		}

		$target = trailingslashit( (string) $remote_source ) . trailingslashit( self::$folder );
		if ( trailingslashit( (string) $source ) === $target ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
			return $target;
		}
		return new WP_Error( 'tf_core_rename_failed', 'Não foi possível renomear a pasta do pacote para ' . self::$folder . '.' );
	}

	/**
	 * @param array  $args Argumentos HTTP.
	 * @param string $url  URL.
	 * @return array
	 */
	public static function authenticate_download( $args, $url ) {
		if ( ! is_array( $args ) || ! is_string( $url ) || false === strpos( $url, self::repo_path() ) ) {
			return $args;
		}
		$token = self::get_token();
		if ( '' !== $token && ( false !== strpos( $url, 'api.github.com' ) || false !== strpos( $url, 'github.com' ) ) ) {
			$args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}
		return $args;
	}

	public static function maybe_force_check(): void {
		if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * @param mixed $upgrader Upgrader.
	 * @param array $options  Opções.
	 */
	public static function purge_after_update( $upgrader, $options ): void {
		if ( is_array( $options ) && 'plugin' === ( $options['type'] ?? '' ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/* ---------------------------------------------------------------------
	 * Página de administração
	 * ------------------------------------------------------------------ */

	private static function capability(): string {
		return is_multisite() ? 'manage_network_plugins' : 'update_plugins';
	}

	private static function page_url( array $args = array() ): string {
		$base = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), $base );
	}

	/**
	 * URL nativa do WordPress que executa a atualização (com nonce).
	 */
	public static function upgrade_url(): string {
		$base = is_multisite() ? network_admin_url( 'update.php' ) : admin_url( 'update.php' );
		return wp_nonce_url(
			add_query_arg( array( 'action' => 'upgrade-plugin', 'plugin' => rawurlencode( self::$basename ) ), $base ),
			'upgrade-plugin_' . self::$basename
		);
	}

	public static function register_page(): void {
		// No Multisite a página fica só no Painel da Rede (o plugin é de rede).
		if ( is_multisite() && ! is_network_admin() ) {
			return;
		}
		add_submenu_page(
			'tf-harvester',
			__( 'Atualização do tf-core via GitHub', 'onde-na-fronteira' ),
			__( '🔄 Atualização GitHub', 'onde-na-fronteira' ),
			self::capability(),
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * @param array $links Links da linha do plugin.
	 * @return array
	 */
	public static function action_links( $links ): array {
		$url = is_multisite() ? network_admin_url( 'admin.php?page=' . self::PAGE_SLUG ) : admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Atualização GitHub', 'onde-na-fronteira' ) . '</a>' );
		return (array) $links;
	}

	public static function handle_actions(): void {
		if ( ! isset( $_POST['tf_core_updater_action'] ) || ! current_user_can( self::capability() ) ) {
			return;
		}
		check_admin_referer( 'tf_core_updater_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['tf_core_updater_action'] ) );

		if ( 'save_token' === $action ) {
			$token = isset( $_POST['tf_core_github_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['tf_core_github_token'] ) ) ) : '';
			if ( ! empty( $_POST['tf_core_remove_token'] ) ) {
				delete_site_option( 'tf_core_github_token' );
			} elseif ( '' !== $token ) {
				update_site_option( 'tf_core_github_token', $token );
			}
		}

		// Verifica agora e repassa o resultado ao WordPress.
		delete_site_transient( self::CACHE_KEY );
		self::fetch_remote( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		if ( 'update_now' === $action && self::has_update() ) {
			wp_safe_redirect( html_entity_decode( self::upgrade_url() ) );
			exit;
		}

		$back = ! empty( $_POST['tf_is_network'] ) ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'checked' => '1' ), $back ) );
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$remote     = self::fetch_remote();
		$installed  = self::installed_version();
		$remote_ver = $remote['remote_version'];
		$has_update = '' !== $remote_ver && version_compare( $remote_ver, $installed, '>' );
		$saved      = (string) get_site_option( 'tf_core_github_token', '' );
		$is_network = is_network_admin() ? '1' : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '🔄 Atualização do tf-core via GitHub', 'onde-na-fronteira' ); ?></h1>

			<?php if ( ! empty( $_GET['checked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Verificação concluída junto ao GitHub.', 'onde-na-fronteira' ); ?></p></div>
			<?php endif; ?>

			<div class="card" style="max-width:780px;padding:22px 26px;margin-top:18px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Status da versão', 'onde-na-fronteira' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Repositório</th>
						<td>
							<a href="<?php echo esc_url( 'https://github.com/' . self::repo_path() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( self::repo_path() ); ?></code></a>
							(branch <code><?php echo esc_html( self::BRANCH ); ?></code>)
						</td>
					</tr>
					<tr>
						<th scope="row">Pasta instalada</th>
						<td><code><?php echo esc_html( self::$basename ); ?></code></td>
					</tr>
					<tr>
						<th scope="row">Versão instalada</th>
						<td><strong><?php echo esc_html( $installed ); ?></strong></td>
					</tr>
					<tr>
						<th scope="row">Versão no GitHub</th>
						<td>
							<?php if ( '' !== $remote_ver ) : ?>
								<strong><?php echo esc_html( $remote_ver ); ?></strong>
								<?php if ( $has_update ) : ?>
									<span style="margin-left:8px;padding:2px 8px;background:#d63638;color:#fff;border-radius:3px;font-size:12px;font-weight:600;">Nova versão disponível</span>
								<?php else : ?>
									<span style="margin-left:8px;padding:2px 8px;background:#00a32a;color:#fff;border-radius:3px;font-size:12px;font-weight:600;">✓ Atualizado</span>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#d63638;">Não foi possível ler a versão remota (se o repositório for privado, informe um token abaixo).</span>
							<?php endif; ?>
							<?php if ( '' !== $remote['error'] ) : ?>
								<br><small style="color:#646970;"><?php echo esc_html( $remote['error'] ); ?></small>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' !== $remote['last_commit'] ) : ?>
						<tr>
							<th scope="row">Último commit</th>
							<td><code><?php echo esc_html( $remote['last_commit'] ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">Última verificação</th>
						<td><code><?php echo esc_html( $remote['checked_at'] ); ?></code></td>
					</tr>
				</table>

				<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;">
					<form method="post">
						<?php wp_nonce_field( 'tf_core_updater_nonce' ); ?>
						<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
						<input type="hidden" name="tf_core_updater_action" value="check_now" />
						<button type="submit" class="button button-secondary">🔄 Verificar atualização agora</button>
					</form>
					<?php if ( $has_update ) : ?>
						<form method="post">
							<?php wp_nonce_field( 'tf_core_updater_nonce' ); ?>
							<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
							<input type="hidden" name="tf_core_updater_action" value="update_now" />
							<button type="submit" class="button button-primary">⬇ Atualizar agora para <?php echo esc_html( $remote_ver ); ?></button>
						</form>
					<?php endif; ?>
				</div>
				<p class="description" style="margin-top:12px;">
					A versão é lida do cabeçalho <code>Version:</code> do <code><?php echo esc_html( self::MAIN_FILE ); ?></code> na branch <code><?php echo esc_html( self::BRANCH ); ?></code> (ou da última Release). Para publicar uma atualização, aumente a versão e faça push.
				</p>
			</div>

			<div class="card" style="max-width:780px;padding:22px 26px;margin-top:20px;">
				<h2 style="margin-top:0;">Token do GitHub (apenas se o repositório for privado)</h2>
				<p>Repositório público não precisa de token. Se for privado, use um <em>Fine-grained token</em> com acesso só a <code><?php echo esc_html( self::repo_path() ); ?></code> e permissão <strong>Contents: Read-only</strong>.</p>
				<?php if ( self::token_from_config() ) : ?>
					<p><strong style="color:#00a32a;">✓ Token definido no wp-config.php / variável de ambiente (TF_CORE_GITHUB_TOKEN).</strong></p>
				<?php else : ?>
					<form method="post">
						<?php wp_nonce_field( 'tf_core_updater_nonce' ); ?>
						<input type="hidden" name="tf_is_network" value="<?php echo esc_attr( $is_network ); ?>" />
						<input type="hidden" name="tf_core_updater_action" value="save_token" />
						<input type="password" name="tf_core_github_token" value="" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( '' !== $saved ? 'Token salvo (final …' . substr( $saved, -4 ) . ') — deixe em branco para manter' : 'github_pat_…' ); ?>" />
						<?php if ( '' !== $saved ) : ?>
							<label style="margin-left:10px;"><input type="checkbox" name="tf_core_remove_token" value="1" /> Remover token</label>
						<?php endif; ?>
						<p><button type="submit" class="button button-primary">Salvar token e testar</button></p>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

TF_GitHub_Plugin_Updater::init();
