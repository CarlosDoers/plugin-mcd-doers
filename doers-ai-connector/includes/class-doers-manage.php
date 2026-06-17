<?php
/**
 * Gestión del sitio: info, temas y plugins.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities de gestión.
 */
class Doers_Manage {

	/**
	 * Información general del sitio.
	 *
	 * @return array
	 */
	public static function site_info() {
		global $wp_version;
		$theme = wp_get_theme();
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'wp_version'  => $wp_version,
			'php_version' => PHP_VERSION,
			'language'    => get_locale(),
			'permalinks'  => get_option( 'permalink_structure' ),
			'active_theme' => array(
				'slug'    => get_stylesheet(),
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'is_block_theme' => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
			),
			'front_page'  => array(
				'show_on_front' => get_option( 'show_on_front' ),
				'page_on_front' => (int) get_option( 'page_on_front' ),
			),
		);
	}

	/**
	 * Lista los temas instalados.
	 *
	 * @return array
	 */
	public static function list_themes() {
		$active = get_stylesheet();
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $active,
			);
		}
		return array( 'themes' => $themes );
	}

	/**
	 * Activa un tema instalado.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function activate_theme( $input ) {
		$slug  = sanitize_file_name( $input['stylesheet'] );
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'doers_invalid_theme', 'El tema no existe: ' . $slug );
		}
		if ( $theme->errors() ) {
			return new WP_Error( 'doers_broken_theme', 'El tema tiene errores: ' . $theme->errors()->get_error_message() );
		}
		switch_theme( $slug );
		return array(
			'activated' => $slug,
			'name'      => $theme->get( 'Name' ),
		);
	}

	/**
	 * Lista plugins instalados.
	 *
	 * @return array
	 */
	public static function list_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		return array( 'plugins' => $plugins );
	}

	/**
	 * Activa o desactiva un plugin.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function toggle_plugin( $input ) {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file   = plugin_basename( sanitize_text_field( $input['plugin'] ) );
		$action = sanitize_key( $input['action'] );

		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			return new WP_Error( 'doers_invalid_plugin', 'El plugin no existe: ' . $file );
		}

		// Protección: no permitir que la IA desactive este propio conector ni el adapter.
		$protected = array( plugin_basename( DOERS_AI_DIR . 'doers-ai-connector.php' ) );
		if ( 'deactivate' === $action && in_array( $file, $protected, true ) ) {
			return new WP_Error( 'doers_protected_plugin', 'Este plugin está protegido y no puede desactivarse vía MCP.' );
		}

		// Desactivar es potencialmente disruptivo: exigir confirmación explícita.
		if ( 'deactivate' === $action && empty( $input['confirm'] ) ) {
			return new WP_Error( 'doers_confirm_required', 'Desactivar un plugin puede afectar al sitio. Repite la llamada con confirm=true si el usuario lo ha aprobado.' );
		}

		if ( 'activate' === $action ) {
			$result = activate_plugin( $file );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} elseif ( 'deactivate' === $action ) {
			deactivate_plugins( $file );
		} else {
			return new WP_Error( 'doers_invalid_action', 'Acción no válida. Usa "activate" o "deactivate".' );
		}

		return array(
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * Instala (y opcionalmente activa) un plugin del repositorio de WordPress.org.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function install_plugin( $input ) {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new WP_Error( 'doers_file_mods_disabled', 'La instalación de plugins está deshabilitada (DISALLOW_FILE_MODS).' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$slug = sanitize_key( $input['slug'] );

		// ¿Ya está instalado?
		foreach ( get_plugins() as $file => $data ) {
			if ( 0 === strpos( $file, $slug . '/' ) ) {
				if ( ! empty( $input['activate'] ) && ! is_plugin_active( $file ) ) {
					$activated = activate_plugin( $file );
					if ( is_wp_error( $activated ) ) {
						return $activated;
					}
				}
				return array(
					'plugin'  => $file,
					'status'  => 'already_installed',
					'active'  => is_plugin_active( $file ),
				);
			}
		}

		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'doers_install_failed', 'La instalación no se completó.' );
		}

		$file = $upgrader->plugin_info();
		if ( $file && ! empty( $input['activate'] ) ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				return $activated;
			}
		}

		return array(
			'plugin' => $file,
			'status' => 'installed',
			'active' => $file ? is_plugin_active( $file ) : false,
		);
	}

	/**
	 * Actualiza ajustes del sitio de una lista blanca.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function update_site_settings( $input ) {
		$updated = array();

		$text_options = array( 'blogname', 'blogdescription' );
		foreach ( $text_options as $option ) {
			if ( isset( $input[ $option ] ) ) {
				update_option( $option, sanitize_text_field( $input[ $option ] ) );
				$updated[] = $option;
			}
		}

		if ( isset( $input['show_on_front'] ) && in_array( $input['show_on_front'], array( 'posts', 'page' ), true ) ) {
			update_option( 'show_on_front', $input['show_on_front'] );
			$updated[] = 'show_on_front';
		}

		foreach ( array( 'page_on_front', 'page_for_posts' ) as $option ) {
			if ( isset( $input[ $option ] ) ) {
				$page_id = absint( $input[ $option ] );
				if ( $page_id && ( ! get_post( $page_id ) || 'page' !== get_post( $page_id )->post_type ) ) {
					return new WP_Error( 'doers_invalid_page', $option . ': el ID no corresponde a una página.' );
				}
				update_option( $option, $page_id );
				$updated[] = $option;
			}
		}

		if ( isset( $input['permalink_structure'] ) ) {
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( sanitize_option( 'permalink_structure', $input['permalink_structure'] ) );
			flush_rewrite_rules();
			$updated[] = 'permalink_structure';
		}

		if ( ! $updated ) {
			return new WP_Error( 'doers_nothing_to_update', 'No se indicó ningún ajuste válido.' );
		}

		return array( 'updated' => $updated );
	}
}
