<?php
/**
 * Página de ajustes: estado, conexión, abilities, seguridad, usuario AI y auditoría.
 *
 * El contexto del proyecto NO vive aquí: se aporta como un CLAUDE.md en la
 * carpeta que se conecta en Cowork (lo lee la IA automáticamente).
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
	 * Registra el menú de primer nivel en el admin.
	 */
	public static function register_menu() {
		add_menu_page(
			'Doers AI Connector',
			'Doers AI',
			'manage_options',
			'doers-ai',
			array( __CLASS__, 'render' ),
			'dashicons-format-chat',
			58
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
				Doers_Settings::save( $settings );
				return 'Ajustes guardados.';

			case 'create_ai_user':
				return self::create_ai_user();

			case 'install_adapter':
				return self::install_adapter();

			case 'revoke_oauth_client':
				$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
				Doers_OAuth::revoke_client( $client_id );
				return 'Cliente OAuth revocado.';

			case 'revoke_oauth_tokens':
				Doers_OAuth::revoke_all_tokens();
				return 'Todos los tokens OAuth revocados.';

			case 'clear_audit':
				delete_option( Doers_Audit::OPTION );
				return 'Registro de auditoría vaciado.';
		}

		return '';
	}

	/**
	 * Crea (o repara) el usuario dedicado para la IA con permisos mínimos
	 * (solo contenido y medios) y genera una contraseña de aplicación.
	 *
	 * @return string
	 */
	private static function create_ai_user() {
		$caps = array(
			'read'                 => true,
			'edit_posts'           => true,
			'edit_others_posts'    => true,
			'edit_published_posts' => true,
			'publish_posts'        => true,
			'edit_pages'           => true,
			'edit_others_pages'    => true,
			'edit_published_pages' => true,
			'publish_pages'        => true,
			'upload_files'         => true,
			'unfiltered_html'      => true,
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

		set_transient( 'doers_ai_new_password_' . get_current_user_id(), $created[0], 60 );
		return 'Usuario "' . self::AI_USER_LOGIN . '" listo con rol de permisos mínimos (contenido y medios).';
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
	 * Estilos del panel.
	 */
	private static function styles() {
		?>
		<style>
			.doers-wrap{max-width:940px}
			.doers-hd{background:#13183b;border-radius:12px;padding:22px 26px;margin:14px 0 6px;color:#fff}
			.doers-hd h1{color:#fff;margin:0 0 6px;font-size:24px;display:flex;align-items:center;gap:10px}
			.doers-hd p{color:#c7d0ee;margin:0;max-width:680px;font-size:13.5px;line-height:1.5}
			.doers-pill{display:inline-block;background:#2b5cff;color:#fff;border-radius:999px;padding:2px 11px;font-size:12px;font-weight:600;vertical-align:middle}
			.doers-card{background:#fff;border:1px solid #e3e7f0;border-radius:10px;padding:6px 22px 20px;margin:16px 0;box-shadow:0 1px 2px rgba(16,24,40,.04)}
			.doers-card h2{font-size:16px;border-bottom:1px solid #eef1f7;padding-bottom:12px;margin:18px 0 14px}
			.doers-card h3{font-size:13.5px;color:#13183b;margin:18px 0 8px}
			.doers-ok{color:#16864a;font-weight:600}
			.doers-warn{color:#b26a00;font-weight:600}
			.doers-off{color:#7a8290}
			.doers-status td{padding:9px 12px}
			.doers-status td:first-child{font-weight:600;color:#3a4252;width:230px}
			.doers-code{background:#0f1430;color:#e7ecff;border-radius:8px;padding:14px 16px;max-width:680px;overflow:auto;font-size:12.5px;line-height:1.5}
			.doers-code .k{color:#8aa0ff}
			.doers-pager{display:flex;align-items:center;gap:10px;margin:10px 0}
			.doers-pager .doers-info{color:#64748b;font-size:12.5px}
			.doers-wrap .button-primary{background:#2b5cff;border-color:#2b5cff}
			.doers-wrap .button-primary:hover{background:#1f49d6;border-color:#1f49d6}
			.doers-note{color:#64748b;font-size:12.5px;max-width:680px}
		</style>
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
		$audit = Doers_Audit::recent();
		self::styles();
		?>
		<div class="wrap doers-wrap">

			<div class="doers-hd">
				<h1><span class="dashicons dashicons-format-chat" style="font-size:26px;width:26px;height:26px"></span> Doers AI Connector <span class="doers-pill">v<?php echo esc_html( DOERS_AI_VERSION ); ?></span></h1>
				<p>Crea y edita contenido (páginas y entradas) desde el chat de Claude, reutilizando los bloques que ya existen en esta web. El contexto del proyecto se aporta como un archivo <code>CLAUDE.md</code> en la carpeta que se conecta en Cowork.</p>
			</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php if ( $new_pass ) : ?>
				<div class="notice notice-success">
					<p><strong>Contraseña de aplicación del usuario <?php echo esc_html( self::AI_USER_LOGIN ); ?> (cópiala ahora, no se volverá a mostrar):</strong></p>
					<p><code style="font-size:15px"><?php echo esc_html( $new_pass ); ?></code></p>
				</div>
			<?php endif; ?>

			<div class="doers-card">
				<h2>Estado</h2>
				<table class="doers-status" style="width:100%;max-width:680px">
					<tbody>
						<tr><td>Abilities API (core 6.9+)</td><td><?php echo $deps['abilities'] ? '<span class="doers-ok">✅ Disponible</span>' : '<span class="doers-warn">❌ No disponible</span>'; ?></td></tr>
						<tr><td>MCP Adapter</td>
							<td>
							<?php if ( $deps['adapter'] ) : ?>
								<span class="doers-ok">✅ Activo</span>
							<?php else : ?>
								<span class="doers-warn">❌ Falta</span> &nbsp;
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'doers_ai_admin' ); ?>
									<input type="hidden" name="doers_action" value="install_adapter">
									<button class="button button-small">Instalar automáticamente</button>
								</form>
							<?php endif; ?>
							</td>
						</tr>
						<tr><td>Modo solo lectura</td><td><?php echo $settings['read_only'] ? '<span class="doers-warn">🔒 Activado</span>' : '<span class="doers-off">Desactivado</span>'; ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="doers-card">
				<h2>Conexión desde Claude</h2>
				<p><strong>Endpoint MCP:</strong> <code><?php echo esc_html( $endpoint ); ?></code></p>

				<h3>Opción 1 — Conector directo con OAuth (sitios con HTTPS)</h3>
				<?php if ( ! empty( $settings['oauth_enabled'] ) ) : ?>
					<p class="doers-note">En claude.ai / app de Claude: <em>Ajustes → Conectores → Añadir conector personalizado</em>, pega el endpoint de arriba y autoriza con tu sesión de WordPress.</p>
					<?php if ( ! is_ssl() ) : ?>
						<p class="doers-note"><strong>⚠️ Este sitio no usa HTTPS.</strong> claude.ai requiere HTTPS para conectores remotos: usa la Opción 2 en local.</p>
					<?php endif; ?>
					<table class="widefat striped" style="max-width:680px">
						<thead><tr><th>Cliente autorizado</th><th>Alta</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( Doers_OAuth::clients() as $cid => $client ) : ?>
							<tr>
								<td><?php echo esc_html( $client['name'] ); ?></td>
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
				<?php else : ?>
					<p class="doers-note">OAuth está deshabilitado en los ajustes de abajo.</p>
				<?php endif; ?>

				<h3>Opción 2 — Proxy local con contraseña de aplicación</h3>
				<p class="doers-note">Usa el usuario dedicado <code><?php echo esc_html( self::AI_USER_LOGIN ); ?></code> (permisos mínimos: contenido y medios), no tu cuenta de administrador.</p>
				<form method="post" style="margin:10px 0">
					<?php wp_nonce_field( 'doers_ai_admin' ); ?>
					<input type="hidden" name="doers_action" value="create_ai_user">
					<button class="button button-primary">
						<?php echo $ai_user ? 'Regenerar credenciales del usuario AI' : 'Crear usuario AI dedicado'; ?>
					</button>
				</form>
				<pre class="doers-code"><code>{
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
			</div>

			<div class="doers-card">
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
							<td><label><input type="checkbox" name="read_only" value="1" <?php checked( $settings['read_only'] ); ?>> Bloquear todas las operaciones de escritura</label></td>
						</tr>
						<tr>
							<th>Conexión OAuth 2.1</th>
							<td><label><input type="checkbox" name="oauth_enabled" value="1" <?php checked( ! empty( $settings['oauth_enabled'] ) ); ?>> Permitir conexión directa de clientes MCP remotos (claude.ai)</label></td>
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
			</div>

			<div class="doers-card">
				<h2>Auditoría</h2>
				<div class="doers-pager">
					<button type="button" class="button button-small" id="doers-audit-prev">‹ Anteriores</button>
					<button type="button" class="button button-small" id="doers-audit-next">Siguientes ›</button>
					<span class="doers-info" id="doers-audit-info"></span>
					<span style="flex:1"></span>
					<form method="post" onsubmit="return confirm('¿Vaciar todo el registro de auditoría?')">
						<?php wp_nonce_field( 'doers_ai_admin' ); ?>
						<input type="hidden" name="doers_action" value="clear_audit">
						<button class="button button-small">Vaciar registro</button>
					</form>
				</div>
				<table class="widefat striped" id="doers-audit" style="max-width:940px">
					<thead><tr><th>Fecha (UTC)</th><th>Ability</th><th>Usuario</th><th>Input</th><th>OK</th></tr></thead>
					<tbody>
					<?php foreach ( $audit as $row ) : ?>
						<tr data-row>
							<td style="white-space:nowrap"><?php echo esc_html( $row['time'] ); ?></td>
							<td><code><?php echo esc_html( $row['ability'] ); ?></code></td>
							<td><?php echo esc_html( $row['user'] ); ?></td>
							<td style="max-width:420px;word-break:break-all"><?php echo esc_html( $row['input'] ); ?></td>
							<td><?php echo $row['ok'] ? '✅' : '❌'; ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $audit ) : ?>
						<tr><td colspan="5">Sin actividad todavía.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<script>
		(function(){
			var rows = Array.prototype.slice.call(document.querySelectorAll('#doers-audit tbody tr[data-row]'));
			var per = 15, page = 0;
			var info = document.getElementById('doers-audit-info');
			var prev = document.getElementById('doers-audit-prev');
			var next = document.getElementById('doers-audit-next');
			if(!rows.length){ if(info){info.textContent='Sin registros.';} if(prev){prev.disabled=true;} if(next){next.disabled=true;} return; }
			function pages(){ return Math.max(1, Math.ceil(rows.length/per)); }
			function render(){
				rows.forEach(function(r,i){ r.style.display = (i>=page*per && i<(page+1)*per) ? '' : 'none'; });
				info.textContent = 'Página ' + (page+1) + ' de ' + pages() + ' · ' + rows.length + ' registros';
				prev.disabled = (page<=0);
				next.disabled = (page>=pages()-1);
			}
			prev.addEventListener('click', function(){ if(page>0){ page--; render(); } });
			next.addEventListener('click', function(){ if(page<pages()-1){ page++; render(); } });
			render();
		})();
		</script>
		<?php
	}
}
