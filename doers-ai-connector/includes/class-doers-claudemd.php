<?php
/**
 * Generador y mantenedor del archivo CLAUDE.md en la raíz del sitio.
 *
 * Escribe un bloque gestionado (entre marcas) siempre actualizado con el
 * estado del sitio, el contexto del proyecto y las reglas de trabajo, y
 * preserva intacto cualquier contenido manual fuera de ese bloque.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestión del CLAUDE.md.
 */
class Doers_ClaudeMD {

	const START = '<!-- DOERS:AUTO:START -->';
	const END   = '<!-- DOERS:AUTO:END -->';

	/**
	 * Ruta destino: raíz del sitio (la carpeta que se conecta en Cowork).
	 *
	 * @return string
	 */
	public static function target_path() {
		return ABSPATH . 'CLAUDE.md';
	}

	/**
	 * Genera o actualiza el CLAUDE.md preservando el contenido manual.
	 *
	 * @param string $reason Motivo (para el log de auditoría).
	 * @return array|WP_Error
	 */
	public static function generate( $reason = 'manual' ) {
		$path  = self::target_path();
		$block = self::START . "\n" . self::managed_block() . "\n" . self::END;

		$existing = file_exists( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$created  = ( '' === $existing && ! file_exists( $path ) );

		if ( false !== strpos( $existing, self::START ) && false !== strpos( $existing, self::END ) ) {
			// Reemplazar solo el bloque gestionado, conservar el resto.
			$content = preg_replace(
				'/' . preg_quote( self::START, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '/s',
				$block,
				$existing
			);
		} elseif ( '' !== $existing ) {
			// Archivo manual sin marcas: anteponer el bloque, conservar todo lo demás.
			$content = $block . "\n\n" . $existing;
		} else {
			// Archivo nuevo.
			$content = "# CLAUDE.md — " . self::esc( get_bloginfo( 'name' ) ) . "\n\n"
				. $block . "\n\n"
				. "## Notas del proyecto\n\n"
				. "_Escribe aquí el conocimiento específico del proyecto (convenciones, arquitectura, riesgos…). Esta parte no la sobrescribe el plugin._\n";
		}

		$written = file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error( 'doers_claudemd_failed', 'No se pudo escribir CLAUDE.md en la raíz del sitio (revisa permisos de ' . esc_html( $path ) . ').' );
		}

		update_option( 'doers_ai_claudemd_last', gmdate( 'Y-m-d H:i' ) . ' UTC', false );

		if ( class_exists( 'Doers_Audit' ) ) {
			Doers_Audit::log( 'doers/generate-claude-md', array( 'reason' => $reason ), true );
		}

		return array(
			'path'    => $path,
			'bytes'   => $written,
			'created' => $created,
		);
	}

	/**
	 * Regenera el bloque solo si el auto-mantenimiento está activo.
	 *
	 * @param string $reason Motivo.
	 * @return void
	 */
	public static function maybe_auto_update( $reason = 'auto' ) {
		$settings = Doers_Settings::get();
		if ( empty( $settings['claudemd_auto'] ) ) {
			return;
		}
		self::generate( $reason );
	}

	/**
	 * Construye el contenido del bloque gestionado (sin las marcas).
	 *
	 * @return string
	 */
	private static function managed_block() {
		$ctx      = Doers_Context::build_context();
		$settings = Doers_Settings::get();

		$lines   = array();
		$lines[] = '<!-- Sección gestionada automáticamente por Doers AI Connector. No la edites a mano: se regenera. Escribe tus notas FUERA de este bloque. -->';
		$lines[] = '';
		$lines[] = '# Contexto del sitio (Doers AI)';
		$lines[] = '';
		$lines[] = '_Actualizado automáticamente el ' . gmdate( 'Y-m-d H:i' ) . ' UTC._';
		$lines[] = '';

		// Regla de oro + directiva.
		$lines[] = '## Cómo trabajar en este sitio';
		$lines[] = '';
		$lines[] = '- **Antes de cualquier tarea de contenido o diseño, llama primero a `doers/get-project-context`** y respeta lo que devuelva: tokens de color y tipografía (incluidos los de Figma), voz de marca y convenciones del proyecto.';
		$lines[] = '- **El tema se edita por archivos** (plantillas, CSS/JS) en la carpeta conectada; **el contenido se edita por MCP** (páginas, entradas, menús, medios, ajustes).';
		$lines[] = '- **Pide confirmación antes de cualquier operación destructiva o irreversible** (borrar contenido, cambiar portada/permalinks, activar/desactivar tema o plugins, sobrescrituras masivas).';
		$lines[] = '';

		// Estado del sitio.
		$front_id    = (int) get_option( 'page_on_front' );
		$front_label = 'posts' === get_option( 'show_on_front' )
			? 'últimas entradas'
			: ( $front_id ? 'página estática "' . self::esc( get_the_title( $front_id ) ) . '" (id ' . $front_id . ')' : 'página estática' );

		$lines[] = '## Estado del sitio';
		$lines[] = '';
		$lines[] = '- URL: `' . esc_url_raw( home_url() ) . '`';
		$lines[] = '- WordPress ' . self::esc( get_bloginfo( 'version' ) ) . ', PHP ' . self::esc( phpversion() ) . ', idioma `' . self::esc( get_locale() ) . '`.';
		$lines[] = '- Permalinks: `' . self::esc( get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'simple (plain)' ) . '`.';
		$lines[] = '- Portada: ' . $front_label . '.';
		$lines[] = '- Tema activo: `' . self::esc( $ctx['site']['active_theme'] ) . '`' . ( wp_is_block_theme() ? ' (tema de bloques / FSE).' : ' (tema clásico, sin theme.json).' );
		$lines[] = '';

		// Plugins activos.
		$plugins = self::active_plugins();
		if ( $plugins ) {
			$lines[] = '## Plugins activos';
			$lines[] = '';
			foreach ( $plugins as $p ) {
				$lines[] = '- ' . self::esc( $p );
			}
			$lines[] = '';
		}

		// Contenido.
		$pages = wp_count_posts( 'page' );
		$posts = wp_count_posts( 'post' );
		$menus = wp_get_nav_menus();
		$lines[] = '## Contenido';
		$lines[] = '';
		$lines[] = '- Páginas publicadas: ' . ( isset( $pages->publish ) ? (int) $pages->publish : 0 ) . '. Entradas publicadas: ' . ( isset( $posts->publish ) ? (int) $posts->publish : 0 ) . '.';
		if ( ! is_wp_error( $menus ) && $menus ) {
			$lines[] = '- Menús: ' . self::esc( implode( ', ', wp_list_pluck( $menus, 'name' ) ) ) . '.';
		}
		$lines[] = '';

		// Sistema de diseño / contexto del proyecto.
		$brand   = $ctx['brand'];
		$figma   = $ctx['design_system']['figma'];
		$ttokens = $ctx['design_system']['theme_json'];
		$lines[] = '## Sistema de diseño y marca';
		$lines[] = '';
		$lines[] = '- Marca: ' . ( '' !== trim( $brand['name'] ) ? '`' . self::esc( $brand['name'] ) . '`' : '_sin definir_' )
			. ( '' !== trim( $brand['voice'] ) ? '. Voz: ' . self::esc( $brand['voice'] ) : '' ) . '.';
		if ( ! empty( $figma['last_sync'] ) ) {
			$lines[] = '- Figma: sincronizado el ' . self::esc( $figma['last_sync'] ) . ' — ' . self::esc( $figma['summary'] ) . '. Colores y tipografías disponibles vía `doers/get-project-context`.';
		} else {
			$lines[] = '- Figma: no sincronizado.';
		}
		$lines[] = '- Tokens de `theme.json`: ' . ( count( $ttokens['palette'] ) ? count( $ttokens['palette'] ) . ' colores definidos por el tema' : 'ninguno (tema sin theme.json; el diseño vive en el CSS o en Figma)' ) . '.';
		$lines[] = '- Documentos de proyecto en el conector: ' . count( $ctx['docs'] ) . '.';
		$lines[] = '';

		// Grupos de abilities activos.
		$enabled = array();
		foreach ( Doers_Settings::groups() as $key => $label ) {
			if ( ! empty( $settings['groups'][ $key ] ) ) {
				$enabled[] = $key;
			}
		}
		$lines[] = '## Capacidades MCP activas';
		$lines[] = '';
		$lines[] = '- Grupos habilitados: ' . ( $enabled ? '`' . implode( '`, `', $enabled ) . '`' : 'ninguno' ) . '.';
		if ( ! empty( $settings['read_only'] ) ) {
			$lines[] = '- ⚠️ **Modo solo lectura activo**: las operaciones de escritura están bloqueadas.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Lista de nombres de plugins activos.
	 *
	 * @return string[]
	 */
	private static function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$names  = array();
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ]['Name'] ) ) {
				$names[] = $all[ $file ]['Name'] . ( isset( $all[ $file ]['Version'] ) ? ' ' . $all[ $file ]['Version'] : '' );
			}
		}
		sort( $names );
		return $names;
	}

	/**
	 * Saneado mínimo para texto que va a Markdown (evita romper marcas/HTML).
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function esc( $text ) {
		return trim( wp_strip_all_tags( (string) $text ) );
	}
}
