<?php
/**
 * Página de ajustes: estado, conexión, abilities, seguridad, usuario AI, backups y auditoría.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UI de administración.
 */
class Doers_Admin {

	const AI_USER_LOGIN = 'doers-ai';

	/**
	 * Registra el menú.
	 */
	public static function register_menu() {
		add_options_page(
			'Doers AI Connector',
			'Doers AI',
			'manage_options',
			'doers-ai',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Procesa los formularios.
	 *
	 * @return string Mensaje de resultado (vacío si no hubo acción).
	 */
	private static function handle_actions() {
		if ( empty( $_POST['doers_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		check_admin_referer( 'doers_ai_admin' );

		$action = sanitize_key( wp_unslash( $_POST['doers_action'] ) );

		switch ( $action ) {
			case 'save_settings':
				$settings = Doers_Settings::get();
				foreach ( array_keys( Doers_Settings::groups() ) as $group ) {
					$settings['groups'][ $group ] = ! empty( $_POST['group'][ $group ] );
				}
				$settings['read_only']     = ! empty( $_POST['read_only'] );
				$settings['rate_limit']    = isset( $_POST['rate_limit'] ) ? max( 0, absint( $_POST['rate_limit'] ) ) : 100;
				$settings['audit_max']     = isset( $_POST['audit_max'] ) ? max( 10, absint( $_POST['audit_max'] ) ) : 200;
				$settings['oauth_enabled'] = ! empty( $_POST['oauth_enabled'] );
				$settings['claudemd_auto'] = ! empty( $_POST['claudemd_auto'] );
				Doers_Settings::save( $settings );
				return 'Ajustes guardados.';

			case 'generate_claudemd':
				$result = Doers_ClaudeMD::generate( 'manual' );
				if ( is_wp_error( $result ) ) {
					return 'CLAUDE.md: ' . $result->get_error_message();
				}
				return 'CLAUDE.md ' . ( $result['created'] ? 'creado' : 'actualizado' ) . ' en ' . $result['path'] . '.';

			case 'save_context':
				$msg = self::save_context();
				Doers_ClaudeMD::maybe_auto_update( 'save_context' );
				return $msg;

			case 'sync_figma':
				$file_key = isset( $_POST['figma_file_key'] ) ? sanitize_text_field( wp_unslash( $_POST['figma_file_key'] ) ) : '';
				if ( isset( $_POST['figma_token'] ) && '' !== trim( (string) wp_unslash( $_POST['figma_token'] ) ) ) {
					Doers_Context::set_figma_token( sanitize_text_field( wp_unslash( $_POST['figma_token'] ) ) );
				}
				$result = Doers_Context::figma_sync( $file_key );
				if ( is_wp_error( $result ) ) {
					return 'Figma: ' . $result->get_error_message();
				}
				Doers_ClaudeMD::maybe_auto_update( 'sync_figma' );
				return sprintf( 'Figma sincronizado (vía %s): %d colores, %d estilos de texto.', $result['method'], $result['colors'], $result['type'] );

			case 'revoke_oauth_client':
				$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
				Doers_OAuth::revoke_client( $client_id );
				return 'Cliente OAuth revocado.';

			case 'revoke_oauth_tokens':
				Doers_OAuth::revoke_all_tokens();
				return 'Todos los tokens OAuth revocados.';

			case 'create_ai_user':
				return self::create_ai_user();

			case 'install_adapter':
				return self::install_adapter();

			case 'restore_backup':
				$backup_id = isset( $_POST['backup_id'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_id'] ) ) : '';
				$result    = Doers_Files::restore_backup( $backup_id );
				return is_wp_error( $result ) ? 'Error: ' . $result->get_error_message() : 'Backup restaurado: ' . $backup_id;

			case 'clear_audit':
				delete_option( Doers_Audit::OPTION );
				return 'Registro de auditoría vaciado.';
		}

		return '';
	}

	/**
	 * Crea (o repara) el usuario dedicado para la IA con permisos mínimos
	 * y genera una contraseña de aplicación que se muestra una sola vez.
	 *
	 * @return string
	 */
	private static function create_ai_user() {
		// Rol con las capabilities mínimas que usan las abilities.
		$caps = array(
			'read'                => true,
			'edit_posts'          => true,
			'edit_others_posts'   => true,
			'edit_published_posts' => true,
			'publish_posts'       => true,
			'delete_posts'        => false,
			'edit_pages'          => true,
			'edit_others_pages'   => true,
			'edit_published_pages' => true,
			'publish_pages'       => true,
			'upload_files'        => true,
			'unfiltered_html'     => true,
			'edit_theme_options'  => true,
			'edit_themes'         => true,
			'switch_themes'       => true,
			'activate_plugins'    => true,
			'install_plugins'     => true,
			'manage_options'      => true,
		);
		remove_role( 'doers_ai_agent' );
		add_role( 'doers_ai_agent', 'Doers AI Agent', $caps );

		$user = get_user_by( 'login', self::AI_USER_LOGIN );
		if ( ! $user ) {
			$user_id = wp_create_user( self::AI_USER_LOGIN, wp_generate_password( 32 ), 'ai@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
			if ( is_wp_error( $user_id ) ) {
				return 'Error: ' . $user_id->get_error_message();
			}
			$user = get_user_by( 'id', $user_id );
		}
		$user->set_role( 'doers_ai_agent' );

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return 'Error: las contraseñas de aplicación no están disponibles.';
		}
		$created = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			array( 'name' => 'Doers AI Connector ' . gmdate( 'Y-m-d H:i' ) )
		);
		if ( is_wp_error( $created ) ) {
			return 'Error: ' . $created->get_error_message();
		}

		// Mostrarla una única vez en esta carga.
		set_transient( 'doers_ai_new_password_' . get_current_user_id(), $created[0], 60 );

		return 'Usuario "' . self::AI_USER_LOGIN . '" listo con rol de permisos mínimos.';
	}

	/**
	 * Instala y activa el MCP Adapter desde la última release de GitHub.
	 *
	 * @return string
	 */
	private static function install_adapter() {
		if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return 'El MCP Adapter ya está activo.';
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return 'Error: la instalación de plugins está deshabilitada (DISALLOW_FILE_MODS).';
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/WordPress/mcp-adapter/releases/latest',
			array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/vnd.github+json' ) )
		);
		if ( is_wp_error( $response ) ) {
			return 'Error consultando GitHub: ' . $response->get_error_message();
		}
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$zip_url = '';
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'] ) && '.zip' === substr( $asset['browser_download_url'], -4 ) ) {
					$zip_url = $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( ! $zip_url ) {
			return 'Error: no se encontró un zip instalable en la última release. Instálalo manualmente desde github.com/WordPress/mcp-adapter/releases.';
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $zip_url );
		if ( is_wp_error( $result ) || true !== $result ) {
			return 'Error instalando el adapter. Instálalo manualmente desde GitHub.';
		}

		$file = $upgrader->plugin_info();
		if ( $file ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				return 'Instalado pero no activado: ' . $activated->get_error_message();
			}
			return 'MCP Adapter instalado y activado (' . esc_html( $release['tag_name'] ) . ').';
		}
		return 'Instalado. Actívalo desde la pantalla de Plugins.';
	}

	/**
	 * Guarda el contexto del proyecto (marca, diseño y documentos).
	 *
	 * @return string
	 */
	private static function save_context() {
		$ctx = Doers_Context::get();

		foreach ( array( 'name', 'tagline', 'audience', 'voice', 'notes' ) as $field ) {
			if ( isset( $_POST['brand'][ $field ] ) ) {
				$ctx['brand'][ $field ] = sanitize_textarea_field( wp_unslash( $_POST['brand'][ $field ] ) );
			}
		}
		foreach ( array( 'logo_url', 'primary', 'secondary', 'accent', 'heading_font', 'body_font', 'notes' ) as $field ) {
			if ( isset( $_POST['design'][ $field ] ) ) {
				$value = sanitize_textarea_field( wp_unslash( $_POST['design'][ $field ] ) );
				$ctx['design'][ $field ] = ( 'logo_url' === $field ) ? esc_url_raw( $value ) : $value;
			}
		}

		// Documentos en markdown: pares título/contenido.
		$docs    = array();
		$titles  = isset( $_POST['doc_title'] ) ? (array) wp_unslash( $_POST['doc_title'] ) : array(); // phpcs:ignore
		$bodies  = isset( $_POST['doc_content'] ) ? (array) wp_unslash( $_POST['doc_content'] ) : array(); // phpcs:ignore
		foreach ( $titles as $i => $title ) {
			$title   = sanitize_text_field( $title );
			$content = isset( $bodies[ $i ] ) ? wp_kses_post( $bodies[ $i ] ) : '';
			if ( '' !== trim( $title ) || '' !== trim( $content ) ) {
				$docs[] = array( 'title' => $title, 'content' => $content );
			}
		}

		// Subida opcional de un archivo .md.
		if ( ! empty( $_FILES['doc_file']['tmp_name'] ) && is_uploaded_file( $_FILES['doc_file']['tmp_name'] ) ) {
			$name = isset( $_FILES['doc_file']['name'] ) ? sanitize_file_name( $_FILES['doc_file']['name'] ) : 'documento.md';
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'md', 'txt', 'markdown' ), true ) ) {
				$raw = file_get_contents( $_FILES['doc_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false !== $raw && strlen( $raw ) <= 524288 ) {
					$docs[] = array( 'title' => $name, 'content' => wp_kses_post( $raw ) );
				}
			} else {
				return 'El archivo debe ser .md o .txt.';
			}
		}

		$ctx['docs'] = $docs;
		Doers_Context::save( $ctx );
		return 'Contexto del proyecto guardado.';
	}

	/**
	 * Renderiza la sección "Contexto del proyecto".
	 */
	private static function render_context_section() {
		$ctx          = Doers_Context::get();
		$theme_tokens = Doers_Context::theme_tokens();
		$has_token    = '' !== Doers_Context::figma_token();
		?>
		<h2>Contexto del proyecto</h2>
		<p class="description" style="max-width:680px">Esta información se entrega a la IA (ability <code>doers/get-project-context</code>) para que conozca la identidad, el sistema de diseño y el funcionamiento de esta web. Viaja con el sitio aunque se conecte por MCP sin carpeta.</p>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'doers_ai_admin' ); ?>
			<input type="hidden" name="doers_action" value="save_context">

			<h3>Marca</h3>
			<table class="form-table" style="max-width:780px">
				<tr><th>Nombre / proyecto</th><td><input type="text" class="regular-text" name="brand[name]" value="<?php echo esc_attr( $ctx['brand']['name'] ); ?>"></td></tr>
				<tr><th>Tagline</th><td><input type="text" class="regular-text" name="brand[tagline]" value="<?php echo esc_attr( $ctx['brand']['tagline'] ); ?>"></td></tr>
				<tr><th>Público objetivo</th><td><input type="text" class="regular-text" name="brand[audience]" value="<?php echo esc_attr( $ctx['brand']['audience'] ); ?>"></td></tr>
				<tr><th>Voz y tono</th><td><textarea name="brand[voice]" rows="2" class="large-text"><?php echo esc_textarea( $ctx['brand']['voice'] ); ?></textarea></td></tr>
				<tr><th>Notas de marca</th><td><textarea name="brand[notes]" rows="3" class="large-text"><?php echo esc_textarea( $ctx['brand']['notes'] ); ?></textarea></td></tr>
			</table>

			<h3>Sistema de diseño (manual)</h3>
			<table class="form-table" style="max-width:780px">
				<tr><th>Logo (URL)</th><td><input type="url" class="regular-text" name="design[logo_url]" value="<?php echo esc_attr( $ctx['design']['logo_url'] ); ?>"></td></tr>
				<tr><th>Color primario</th><td><input type="text" name="design[primary]" value="<?php echo esc_attr( $ctx['design']['primary'] ); ?>" placeholder="#000000"></td></tr>
				<tr><th>Color secundario</th><td><input type="text" name="design[secondary]" value="<?php echo esc_attr( $ctx['design']['secondary'] ); ?>" placeholder="#ffffff"></td></tr>
				<tr><th>Color de acento</th><td><input type="text" name="design[accent]" value="<?php echo esc_attr( $ctx['design']['accent'] ); ?>" placeholder="#2b5cff"></td></tr>
				<tr><th>Tipografía de títulos</th><td><input type="text" class="regular-text" name="design[heading_font]" value="<?php echo esc_attr( $ctx['design']['heading_font'] ); ?>"></td></tr>
				<tr><th>Tipografía de texto</th><td><input type="text" class="regular-text" name="design[body_font]" value="<?php echo esc_attr( $ctx['design']['body_font'] ); ?>"></td></tr>
				<tr><th>Notas de diseño</th><td><textarea name="design[notes]" rows="3" class="large-text"><?php echo esc_textarea( $ctx['design']['notes'] ); ?></textarea></td></tr>
			</table>

			<h3>Documentación (Markdown)</h3>
			<p class="description">Explica cómo funciona la web, convenciones, estructura de contenido, etc. Deja una fila vacía para añadir; vacía título y contenido para eliminarla.</p>
			<?php
			$docs = $ctx['docs'];
			$docs[] = array( 'title' => '', 'content' => '' ); // Fila vacía para añadir.
			foreach ( $docs as $doc ) :
				?>
				<p><input type="text" class="regular-text" name="doc_title[]" placeholder="Título del documento" value="<?php echo esc_attr( $doc['title'] ); ?>"></p>
				<p><textarea name="doc_content[]" rows="5" class="large-text" placeholder="Contenido en Markdown"><?php echo esc_textarea( $doc['content'] ); ?></textarea></p>
				<hr style="max-width:780px;border:none;border-top:1px solid #e0e0e0">
			<?php endforeach; ?>
			<p><label>Importar un archivo .md: <input type="file" name="doc_file" accept=".md,.txt,.markdown"></label></p>

			<p><button class="button button-primary">Guardar contexto</button></p>
		</form>

		<?php if ( $theme_tokens['palette'] || $theme_tokens['font_families'] || $theme_tokens['font_sizes'] ) : ?>
		<h3>Tokens del tema activo (<code><?php echo esc_html( get_stylesheet() ); ?></code>)</h3>
		<p class="description">Definidos en el <code>theme.json</code> del tema; se entregan a la IA sin que tengas que copiarlos.</p>
		<table class="widefat striped" style="max-width:780px">
			<tbody>
				<?php if ( $theme_tokens['palette'] ) : ?>
				<tr><td><strong>Colores</strong></td><td>
					<?php
					foreach ( $theme_tokens['palette'] as $c ) :
						?>
						<span title="<?php echo esc_attr( $c['slug'] . ' · ' . $c['color'] ); ?>" style="display:inline-block;width:16px;height:16px;border-radius:3px;border:1px solid #ccc;vertical-align:middle;margin-right:4px;background:<?php echo esc_attr( $c['color'] ); ?>"></span>
					<?php endforeach; ?>
				</td></tr>
				<?php endif; ?>
				<?php if ( $theme_tokens['font_families'] ) : ?>
				<tr><td><strong>Tipografías</strong></td><td><?php echo esc_html( implode( ', ', wp_list_pluck( $theme_tokens['font_families'], 'fontFamily' ) ) ); ?></td></tr>
				<?php endif; ?>
				<?php if ( $theme_tokens['font_sizes'] ) : ?>
				<tr><td><strong>Tamaños de fuente</strong></td><td><?php echo esc_html( implode( ', ', wp_list_pluck( $theme_tokens['font_sizes'], 'slug' ) ) ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h3>Sincronizar con Figma</h3>
		<p class="description" style="max-width:780px">Trae colores y tipografías desde un archivo de Figma. Intenta la API de variables (plan Enterprise) y, si no está disponible, usa los estilos publicados. El token se guarda cifrado en la base de datos y <strong>nunca</strong> se expone por MCP.</p>
		<?php if ( $ctx['figma']['last_sync'] ) : ?>
			<p><strong>Última sincronización:</strong> <?php echo esc_html( $ctx['figma']['last_sync'] ); ?> — <?php echo esc_html( $ctx['figma']['summary'] ); ?></p>
		<?php endif; ?>
		<?php $figma_error = get_option( 'doers_ai_figma_last_error', '' ); ?>
		<?php if ( $figma_error ) : ?>
			<div class="notice notice-error inline" style="max-width:780px"><p><strong>La última sincronización falló con un error fatal:</strong><br><code><?php echo esc_html( $figma_error ); ?></code></p></div>
		<?php endif; ?>
		<?php $figma_progress = get_option( 'doers_ai_figma_progress', '' ); ?>
		<?php if ( $figma_progress ) : ?>
			<div class="notice notice-warning inline" style="max-width:780px"><p><strong>La última sincronización no terminó.</strong> Último paso registrado:<br><code><?php echo esc_html( $figma_progress ); ?></code></p></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'doers_ai_admin' ); ?>
			<input type="hidden" name="doers_action" value="sync_figma">
			<table class="form-table" style="max-width:780px">
				<tr><th>File key</th><td><input type="text" class="regular-text" name="figma_file_key" value="<?php echo esc_attr( $ctx['figma']['file_key'] ); ?>" placeholder="p. ej. AbCdEf123..."><p class="description">Está en la URL del archivo: figma.com/file/<strong>FILE_KEY</strong>/...</p></td></tr>
				<tr><th>Token de acceso</th><td><input type="password" class="regular-text" name="figma_token" value="" placeholder="<?php echo $has_token ? 'Guardado (déjalo vacío para conservarlo)' : 'figd_...'; ?>"><p class="description">Token personal de Figma con permiso de lectura de archivos.</p></td></tr>
			</table>
			<p><button class="button">Sincronizar con Figma</button></p>
		</form>

		<?php if ( ! empty( $ctx['figma']['colors'] ) || ! empty( $ctx['figma']['type'] ) ) : ?>
		<h4>Diseño sincronizado de Figma</h4>
		<?php if ( ! empty( $ctx['figma']['colors'] ) ) : ?>
			<p><strong>Colores (<?php echo count( $ctx['figma']['colors'] ); ?>):</strong></p>
			<div style="display:flex;flex-wrap:wrap;gap:6px;max-width:780px">
				<?php foreach ( $ctx['figma']['colors'] as $c ) : ?>
					<span title="<?php echo esc_attr( ( '' !== $c['name'] ? $c['name'] . ' · ' : '' ) . $c['value'] ); ?>" style="display:inline-flex;align-items:center;gap:6px;border:1px solid #ddd;border-radius:4px;padding:3px 8px 3px 4px;font-size:12px">
						<span style="display:inline-block;width:18px;height:18px;border-radius:3px;border:1px solid #ccc;background:<?php echo esc_attr( $c['value'] ); ?>"></span>
						<?php echo esc_html( $c['value'] ); ?>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $ctx['figma']['type'] ) ) : ?>
			<p style="margin-top:12px"><strong>Tipografías (<?php echo count( $ctx['figma']['type'] ); ?>):</strong></p>
			<table class="widefat striped" style="max-width:780px">
				<thead><tr><th>Estilo</th><th>Familia</th><th>Peso</th><th>Tamaño</th></tr></thead>
				<tbody>
				<?php foreach ( $ctx['figma']['type'] as $t ) : ?>
					<tr>
						<td><?php echo esc_html( $t['name'] ); ?></td>
						<td><?php echo esc_html( $t['family'] ); ?></td>
						<td><?php echo esc_html( $t['weight'] ); ?></td>
						<td><?php echo esc_html( '' !== $t['size'] ? $t['size'] . 'px' : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php endif; ?>

		<h3>CLAUDE.md del sitio</h3>
		<p class="description" style="max-width:780px">Genera/actualiza un <code>CLAUDE.md</code> en la raíz del sitio (<code><?php echo esc_html( Doers_ClaudeMD::target_path() ); ?></code>) con un bloque automático (estado del sitio, contexto, reglas y la directiva de leer <code>get-project-context</code>). Tus notas manuales fuera de ese bloque se conservan. Claude lo lee solo al trabajar en la carpeta conectada.</p>
		<?php $cmd_last = get_option( 'doers_ai_claudemd_last', '' ); ?>
		<?php if ( $cmd_last ) : ?>
			<p><strong>Última generación:</strong> <?php echo esc_html( $cmd_last ); ?><?php echo file_exists( Doers_ClaudeMD::target_path() ) ? '' : ' — <em>(el archivo ya no existe en disco)</em>'; ?></p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'doers_ai_admin' ); ?>
			<input type="hidden" name="doers_action" value="generate_claudemd">
			<p><button class="button"><?php echo $cmd_last ? 'Actualizar CLAUDE.md ahora' : 'Generar CLAUDE.md'; ?></button></p>
		</form>
		<hr style="margin:24px 0">
		<?php
	}

	/**
	 * Renderiza la página.
	 */
	public static function render() {
		$notice   = self::handle_actions();
		$deps     = doers_ai_dependencies();
		$endpoint = rest_url( 'doers-ai/mcp' );
		$settings = Doers_Settings::get();
		$ai_user  = get_user_by( 'login', self::AI_USER_LOGIN );
		$new_pass = get_transient( 'doers_ai_new_password_' . get_current_user_id() );
		if ( $new_pass ) {
			delete_transient( 'doers_ai_new_password_' . get_current_user_id() );
		}
		$backups = Doers_Files::list_backups();
		$backups = array_slice( $backups['backups'], 0, 20 );
		?>
		<div class="wrap">
			<h1>Doers AI Connector <small style="font-weight:normal;color:#666">v<?php echo esc_html( DOERS_AI_VERSION ); ?></small></h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( $new_pass ) : ?>
				<div class="notice notice-success">
					<p><strong>Contraseña de aplicación del usuario <?php echo esc_html( self::AI_USER_LOGIN ); ?> (cópiala ahora, no se volverá a mostrar):</strong></p>
					<p><code style="font-size:15px"><?php echo esc_html( $new_pass ); ?></code></p>
				</div>
			<?php endif; ?>

			<h2>Estado</h2>
			<table class="widefat striped" style="max-width:680px">
				<tbody>
					<tr><td>Abilities API (core 6.9+)</td><td><?php echo $deps['abilities'] ? '✅ Disponible' : '❌ No disponible'; ?></td></tr>
					<tr><td>MCP Adapter</td>
						<td>
						<?php if ( $deps['adapter'] ) : ?>
							✅ Activo
						<?php else : ?>
							❌ Falta
							<form method="post" style="display:inline">
								<?php wp_nonce_field( 'doers_ai_admin' ); ?>
								<input type="hidden" name="doers_action" value="install_adapter">
								<button class="button button-small">Instalar automáticamente</button>
							</form>
						<?php endif; ?>
						</td>
					</tr>
					<tr><td>Edición de archivos</td><td><?php echo Doers_Files::editing_allowed() ? '✅ Permitida' : '⚠️ Deshabilitada (DISALLOW_FILE_EDIT/MODS)'; ?></td></tr>
					<tr><td>Modo solo lectura</td><td><?php echo $settings['read_only'] ? '🔒 Activado' : 'Desactivado'; ?></td></tr>
				</tbody>
			</table>

			<h2>Conexión desde Claude</h2>
			<p><strong>Endpoint MCP:</strong> <code><?php echo esc_html( $endpoint ); ?></code></p>

			<h3>Opción 1 — Conector directo con OAuth (recomendada en sitios con HTTPS)</h3>
			<?php if ( ! empty( $settings['oauth_enabled'] ) ) : ?>
				<p style="max-width:680px">En claude.ai / app de Claude: <em>Ajustes → Conectores → Añadir conector personalizado</em>, pega el endpoint de arriba y autoriza con tu sesión de WordPress. Sin proxy, sin JSON, sin contraseñas de aplicación.</p>
				<?php if ( ! is_ssl() ) : ?>
					<p style="max-width:680px"><strong>⚠️ Este sitio no usa HTTPS.</strong> claude.ai requiere HTTPS para conectores remotos: en local, usa la Opción 2 (o un túnel como ngrok para probar OAuth).</p>
				<?php endif; ?>
				<table class="widefat striped" style="max-width:680px">
					<thead><tr><th>Cliente autorizado</th><th>Alta</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( Doers_OAuth::clients() as $cid => $client ) : ?>
						<tr>
							<td><?php echo esc_html( $client['name'] ); ?> <code style="font-size:11px"><?php echo esc_html( substr( $cid, 0, 18 ) ); ?>…</code></td>
							<td><?php echo esc_html( gmdate( 'Y-m-d', $client['created'] ) ); ?></td>
							<td>
								<form method="post">
									<?php wp_nonce_field( 'doers_ai_admin' ); ?>
									<input type="hidden" name="doers_action" value="revoke_oauth_client">
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $cid ); ?>">
									<button class="button button-small">Revocar</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! Doers_OAuth::clients() ) : ?>
						<tr><td colspan="3">Ningún cliente autorizado todavía.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				<p>
					Tokens de acceso activos: <strong><?php echo (int) Doers_OAuth::active_token_count(); ?></strong>
					<?php if ( Doers_OAuth::active_token_count() ) : ?>
					<form method="post" style="display:inline;margin-left:8px">
						<?php wp_nonce_field( 'doers_ai_admin' ); ?>
						<input type="hidden" name="doers_action" value="revoke_oauth_tokens">
						<button class="button button-small">Revocar todos</button>
					</form>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p>OAuth está deshabilitado en los ajustes de abajo.</p>
			<?php endif; ?>

			<h3>Opción 2 — Proxy local con contraseña de aplicación (sitios locales / Claude Desktop)</h3>
			<p>Recomendado: usa el usuario dedicado <code><?php echo esc_html( self::AI_USER_LOGIN ); ?></code> en lugar de tu cuenta de administrador.</p>
			<form method="post" style="margin-bottom:12px">
				<?php wp_nonce_field( 'doers_ai_admin' ); ?>
				<input type="hidden" name="doers_action" value="create_ai_user">
				<button class="button button-primary">
					<?php echo $ai_user ? 'Regenerar credenciales del usuario AI' : 'Crear usuario AI dedicado'; ?>
				</button>
			</form>
			<pre style="background:#f6f7f7;padding:12px;max-width:680px;overflow:auto"><code>{
  "mcpServers": {
    "<?php echo esc_html( sanitize_title( get_bloginfo( 'name' ) ) ); ?>": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "<?php echo esc_html( $endpoint ); ?>",
        "WP_API_USERNAME": "<?php echo esc_html( self::AI_USER_LOGIN ); ?>",
        "WP_API_PASSWORD": "CONTRASEÑA-DE-APLICACIÓN"
      }
    }
  }
}</code></pre>

			<?php self::render_context_section(); ?>

			<h2>Abilities y seguridad</h2>
			<form method="post">
				<?php wp_nonce_field( 'doers_ai_admin' ); ?>
				<input type="hidden" name="doers_action" value="save_settings">
				<table class="form-table" style="max-width:680px">
					<tr>
						<th>Grupos habilitados</th>
						<td>
							<?php foreach ( Doers_Settings::groups() as $key => $label ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="group[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings['groups'][ $key ] ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description">Los grupos desactivados desaparecen de las herramientas que ve la IA.</p>
						</td>
					</tr>
					<tr>
						<th>Modo solo lectura</th>
						<td>
							<label><input type="checkbox" name="read_only" value="1" <?php checked( $settings['read_only'] ); ?>> Bloquear todas las operaciones de escritura</label>
						</td>
					</tr>
					<tr>
						<th>Conexión OAuth 2.1</th>
						<td>
							<label><input type="checkbox" name="oauth_enabled" value="1" <?php checked( ! empty( $settings['oauth_enabled'] ) ); ?>> Permitir conexión directa de clientes MCP remotos (claude.ai)</label>
						</td>
					</tr>
					<tr>
						<th>CLAUDE.md automático</th>
						<td>
							<label><input type="checkbox" name="claudemd_auto" value="1" <?php checked( ! empty( $settings['claudemd_auto'] ) ); ?>> Mantener actualizado el <code>CLAUDE.md</code> de la raíz del sitio al guardar contexto o sincronizar Figma</label>
						</td>
					</tr>
					<tr>
						<th>Límite de escrituras/hora</th>
						<td><input type="number" name="rate_limit" min="0" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>" style="width:90px"> <span class="description">0 = sin límite</span></td>
					</tr>
					<tr>
						<th>Entradas máx. de auditoría</th>
						<td><input type="number" name="audit_max" min="10" value="<?php echo esc_attr( $settings['audit_max'] ); ?>" style="width:90px"></td>
					</tr>
				</table>
				<p><button class="button button-primary">Guardar ajustes</button></p>
			</form>

			<h2>Backups de archivos (últimos 20)</h2>
			<table class="widefat striped" style="max-width:980px">
				<thead><tr><th>Fecha</th><th>Tema</th><th>Archivo</th><th>Tamaño</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<tr>
						<td><?php echo esc_html( $backup['timestamp'] ); ?></td>
						<td><?php echo esc_html( $backup['theme'] ); ?></td>
						<td><code><?php echo esc_html( $backup['path'] ); ?></code></td>
						<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
						<td>
							<form method="post" onsubmit="return confirm('¿Restaurar este archivo? El estado actual también se respaldará.')">
								<?php wp_nonce_field( 'doers_ai_admin' ); ?>
								<input type="hidden" name="doers_action" value="restore_backup">
								<input type="hidden" name="backup_id" value="<?php echo esc_attr( $backup['backup_id'] ); ?>">
								<button class="button button-small">Restaurar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $backups ) : ?>
					<tr><td colspan="5">No hay backups todavía.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px">Auditoría</h2>
			<form method="post" style="margin-bottom:8px">
				<?php wp_nonce_field( 'doers_ai_admin' ); ?>
				<input type="hidden" name="doers_action" value="clear_audit">
				<button class="button button-small">Vaciar registro</button>
			</form>
			<table class="widefat striped" style="max-width:980px">
				<thead><tr><th>Fecha (UTC)</th><th>Ability</th><th>Usuario</th><th>Input</th><th>OK</th></tr></thead>
				<tbody>
				<?php foreach ( Doers_Audit::recent() as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['time'] ); ?></td>
						<td><code><?php echo esc_html( $row['ability'] ); ?></code></td>
						<td><?php echo esc_html( $row['user'] ); ?></td>
						<td style="max-width:420px;word-break:break-all"><?php echo esc_html( $row['input'] ); ?></td>
						<td><?php echo $row['ok'] ? '✅' : '❌'; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! Doers_Audit::recent() ) : ?>
					<tr><td colspan="5">Sin actividad todavía.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
