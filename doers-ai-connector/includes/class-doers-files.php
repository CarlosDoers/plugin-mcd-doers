<?php
/**
 * Operaciones de archivos de tema, con validación de rutas y backups automáticos.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Acceso seguro a archivos dentro de wp-content/themes.
 */
class Doers_Files {

	/**
	 * Extensiones que se permiten leer/escribir.
	 *
	 * @var string[]
	 */
	private static $allowed_extensions = array( 'php', 'css', 'js', 'json', 'html', 'svg', 'txt', 'md' );

	/**
	 * Tamaño máximo permitido por escritura (bytes).
	 *
	 * @var int
	 */
	const MAX_WRITE_BYTES = 2097152; // 2 MB.

	/**
	 * Número de backups que se conservan por archivo.
	 *
	 * @var int
	 */
	const BACKUP_RETENTION = 10;

	/**
	 * Archivos cuya sobrescritura exige confirmación explícita
	 * (un error aquí puede tumbar todo el sitio).
	 *
	 * @param string $relative Ruta relativa dentro del tema.
	 * @return bool
	 */
	private static function is_critical( $relative ) {
		$base = strtolower( basename( $relative ) );
		$rel  = strtolower( ltrim( str_replace( '\\', '/', $relative ), '/' ) );
		return 'functions.php' === $base || 'style.css' === $rel;
	}

	/**
	 * ¿Está permitida la edición de archivos en este sitio?
	 *
	 * @return bool
	 */
	public static function editing_allowed() {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		return true;
	}

	/**
	 * Resuelve y valida una ruta relativa dentro de un tema.
	 *
	 * @param string $theme        Slug del tema (stylesheet). Vacío = tema activo.
	 * @param string $relative     Ruta relativa dentro del tema.
	 * @param bool   $must_exist   Exigir que el archivo exista.
	 * @return string|WP_Error Ruta absoluta validada o error.
	 */
	public static function resolve( $theme, $relative, $must_exist = true ) {
		$theme = $theme ? sanitize_file_name( $theme ) : get_stylesheet();

		$theme_root = realpath( get_theme_root() );
		$theme_dir  = realpath( get_theme_root() . '/' . $theme );

		if ( false === $theme_root || false === $theme_dir || 0 !== strpos( $theme_dir, $theme_root ) ) {
			return new WP_Error( 'doers_invalid_theme', 'El tema indicado no existe.' );
		}

		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return new WP_Error( 'doers_invalid_path', 'Ruta no válida.' );
		}

		$ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::$allowed_extensions, true ) ) {
			return new WP_Error( 'doers_invalid_ext', 'Extensión no permitida: ' . $ext );
		}

		$target = $theme_dir . '/' . $relative;

		if ( $must_exist ) {
			$real = realpath( $target );
			if ( false === $real || 0 !== strpos( $real, $theme_dir . DIRECTORY_SEPARATOR ) ) {
				return new WP_Error( 'doers_not_found', 'El archivo no existe: ' . $relative );
			}
			return $real;
		}

		// Para escritura de archivos nuevos: validar el directorio contenedor.
		$parent = dirname( $target );
		if ( ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'doers_mkdir_failed', 'No se pudo crear el directorio.' );
		}
		$real_parent = realpath( $parent );
		if ( false === $real_parent || ( $real_parent !== $theme_dir && 0 !== strpos( $real_parent, $theme_dir . DIRECTORY_SEPARATOR ) ) ) {
			return new WP_Error( 'doers_invalid_path', 'Ruta fuera del tema.' );
		}

		return $real_parent . '/' . basename( $target );
	}

	/**
	 * Lista archivos de un tema.
	 *
	 * @param string $theme Slug del tema. Vacío = activo.
	 * @return array|WP_Error
	 */
	public static function list_files( $theme = '' ) {
		$theme     = $theme ? sanitize_file_name( $theme ) : get_stylesheet();
		$theme_dir = realpath( get_theme_root() . '/' . $theme );
		if ( false === $theme_dir ) {
			return new WP_Error( 'doers_invalid_theme', 'El tema indicado no existe.' );
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $theme_dir, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[] = array(
					'path' => ltrim( str_replace( $theme_dir, '', $file->getPathname() ), '/\\' ),
					'size' => $file->getSize(),
				);
			}
		}
		usort( $files, function ( $a, $b ) {
			return strcmp( $a['path'], $b['path'] );
		} );

		return array(
			'theme' => $theme,
			'files' => $files,
		);
	}

	/**
	 * Lee un archivo de tema.
	 *
	 * @param string $theme    Slug.
	 * @param string $relative Ruta relativa.
	 * @return array|WP_Error
	 */
	public static function read( $theme, $relative ) {
		$path = self::resolve( $theme, $relative, true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return new WP_Error( 'doers_read_failed', 'No se pudo leer el archivo.' );
		}
		return array(
			'path'    => $relative,
			'content' => $content,
		);
	}

	/**
	 * Escribe un archivo de tema, con backup previo si existía.
	 *
	 * Salvaguardas: límite de tamaño, confirmación en archivos críticos,
	 * lint de sintaxis para PHP, y verificación de salud con auto-rollback.
	 *
	 * @param string $theme    Slug.
	 * @param string $relative Ruta relativa.
	 * @param string $content  Contenido.
	 * @param bool   $confirm  Confirmación explícita (requerida en archivos críticos).
	 * @return array|WP_Error
	 */
	public static function write( $theme, $relative, $content, $confirm = false ) {
		if ( ! self::editing_allowed() ) {
			return new WP_Error( 'doers_file_edit_disabled', 'La edición de archivos está deshabilitada en este sitio (DISALLOW_FILE_EDIT/MODS).' );
		}

		// Límite de tamaño.
		$size = strlen( $content );
		if ( $size > self::MAX_WRITE_BYTES ) {
			return new WP_Error(
				'doers_file_too_large',
				sprintf( 'El archivo supera el límite de %d KB permitido por escritura.', (int) ( self::MAX_WRITE_BYTES / 1024 ) )
			);
		}

		$path = self::resolve( $theme, $relative, false );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		// Confirmación obligatoria en archivos que pueden tumbar el sitio.
		if ( self::is_critical( $relative ) && ! $confirm ) {
			return new WP_Error(
				'doers_confirm_required',
				sprintf( '«%s» es un archivo crítico: un error de sintaxis puede tumbar todo el sitio. Repite la llamada con confirm=true si el usuario lo ha aprobado.', $relative )
			);
		}

		$ext = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

		// Validación de sintaxis PHP previa (si el servidor lo permite).
		if ( 'php' === $ext ) {
			$lint = self::php_lint( $content );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}

		$backup = '';
		if ( file_exists( $path ) ) {
			$backup = self::backup( $theme ? $theme : get_stylesheet(), $relative, $path );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
		}

		$written = file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return new WP_Error( 'doers_write_failed', 'No se pudo escribir el archivo.' );
		}

		// Verificación de salud + auto-rollback (solo para PHP, que es lo que puede provocar un error fatal).
		$rolled_back = false;
		if ( 'php' === $ext ) {
			$health = self::health_check();
			if ( is_wp_error( $health ) ) {
				self::rollback( $path, $backup );
				return new WP_Error(
					'doers_write_reverted',
					'La escritura provocó un error en el sitio (' . $health->get_error_message() . '). Se ha revertido automáticamente al estado anterior; el archivo no se ha modificado.'
				);
			}
		}

		return array(
			'path'        => $relative,
			'bytes'       => $written,
			'backup'      => $backup,
			'created'     => '' === $backup,
			'rolled_back' => $rolled_back,
		);
	}

	/**
	 * Valida la sintaxis de un fragmento de PHP con `php -l`.
	 * Si no se puede ejecutar PHP por CLI (exec deshabilitado), se omite sin bloquear.
	 *
	 * @param string $content Contenido PHP.
	 * @return true|WP_Error
	 */
	private static function php_lint( $content ) {
		if ( ! function_exists( 'exec' ) ) {
			return true;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return true;
		}

		$tmp = wp_tempnam( 'doers-lint' );
		if ( ! $tmp ) {
			return true;
		}
		file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Preferir el binario CLI; PHP_BINARY en FPM no sirve para -l.
		$php = ( defined( 'PHP_BINARY' ) && PHP_BINARY && false !== strpos( strtolower( PHP_BINARY ), 'php' ) && false === strpos( strtolower( PHP_BINARY ), 'fpm' ) ) ? PHP_BINARY : 'php';

		$out = array();
		$ret = 0;
		@exec( escapeshellarg( $php ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $out, $ret ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore

		$output = implode( "\n", $out );
		// Solo bloqueamos ante un error de sintaxis claro: si el binario no se pudo
		// ejecutar (ret 127, sin mensaje de PHP), no debemos bloquear una escritura válida.
		if ( 0 !== $ret && preg_match( '/(syntax error|parse error|fatal error)/i', $output ) ) {
			return new WP_Error( 'doers_php_syntax', 'El PHP tiene un error de sintaxis y no se ha guardado: ' . trim( $output ) );
		}
		return true;
	}

	/**
	 * Comprueba que el front del sitio responde sin error fatal (>=500).
	 *
	 * @return true|WP_Error
	 */
	private static function health_check() {
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 12,
				'redirection' => 1,
				'sslverify'   => false,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);

		// Si no podemos contactar (loopback bloqueado, etc.), no asumimos rotura.
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 500 ) {
			return new WP_Error( 'doers_site_error', 'el sitio devolvió HTTP ' . $code );
		}
		return true;
	}

	/**
	 * Revierte una escritura: restaura desde el backup o elimina el archivo nuevo.
	 *
	 * @param string $path       Ruta absoluta del archivo escrito.
	 * @param string $backup_rel Ruta relativa del backup (uploads/...), o '' si era nuevo.
	 * @return void
	 */
	private static function rollback( $path, $backup_rel ) {
		if ( '' === $backup_rel ) {
			// El archivo no existía antes: lo eliminamos.
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
			return;
		}
		$uploads = wp_upload_dir();
		$source  = $uploads['basedir'] . substr( $backup_rel, strlen( 'uploads' ) );
		if ( is_file( $source ) ) {
			@copy( $source, $path ); // phpcs:ignore
		}
	}

	/**
	 * Lista los backups disponibles.
	 *
	 * @return array
	 */
	public static function list_backups() {
		$uploads = wp_upload_dir();
		$base    = $uploads['basedir'] . '/doers-ai-backups';
		$backups = array();

		if ( is_dir( $base ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$rel = ltrim( str_replace( $base, '', $file->getPathname() ), '/\\' );
					// Estructura: <tema>/<timestamp>/<ruta-relativa>.
					$parts = explode( '/', str_replace( '\\', '/', $rel ), 3 );
					if ( 3 === count( $parts ) ) {
						$backups[] = array(
							'backup_id' => $rel,
							'theme'     => $parts[0],
							'timestamp' => $parts[1],
							'path'      => $parts[2],
							'size'      => $file->getSize(),
						);
					}
				}
			}
			usort( $backups, function ( $a, $b ) {
				return strcmp( $b['timestamp'], $a['timestamp'] );
			} );
		}

		return array( 'backups' => $backups );
	}

	/**
	 * Restaura un backup sobre el tema correspondiente.
	 * Antes de restaurar, hace backup del estado actual (para poder deshacer la restauración).
	 *
	 * @param string $backup_id Identificador relativo del backup (tema/timestamp/ruta).
	 * @return array|WP_Error
	 */
	public static function restore_backup( $backup_id ) {
		if ( ! self::editing_allowed() ) {
			return new WP_Error( 'doers_file_edit_disabled', 'La edición de archivos está deshabilitada en este sitio.' );
		}

		$uploads = wp_upload_dir();
		$base    = realpath( $uploads['basedir'] . '/doers-ai-backups' );
		if ( false === $base ) {
			return new WP_Error( 'doers_not_found', 'No hay backups.' );
		}

		$source = realpath( $uploads['basedir'] . '/doers-ai-backups/' . ltrim( $backup_id, '/' ) );
		if ( false === $source || 0 !== strpos( $source, $base . DIRECTORY_SEPARATOR ) || ! is_file( $source ) ) {
			return new WP_Error( 'doers_not_found', 'El backup indicado no existe.' );
		}

		$parts = explode( '/', str_replace( '\\', '/', ltrim( str_replace( $base, '', $source ), '/\\' ) ), 3 );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'doers_invalid_backup', 'Identificador de backup no válido.' );
		}
		list( $theme, , $relative ) = $parts;

		$content = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return new WP_Error( 'doers_read_failed', 'No se pudo leer el backup.' );
		}

		return self::write( $theme, $relative, $content, true );
	}

	/**
	 * Copia de seguridad en uploads/doers-ai-backups.
	 *
	 * @param string $theme    Slug del tema.
	 * @param string $relative Ruta relativa.
	 * @param string $path     Ruta absoluta del archivo original.
	 * @return string|WP_Error Ruta relativa del backup.
	 */
	private static function backup( $theme, $relative, $path ) {
		$uploads = wp_upload_dir();
		$base    = $uploads['basedir'] . '/doers-ai-backups';
		self::protect_dir( $base );

		$dest_dir = $base . '/' . $theme . '/' . gmdate( 'Ymd-His' );
		$dest     = $dest_dir . '/' . $relative;

		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return new WP_Error( 'doers_backup_failed', 'No se pudo crear el directorio de backup.' );
		}
		if ( ! copy( $path, $dest ) ) {
			return new WP_Error( 'doers_backup_failed', 'No se pudo crear el backup; escritura cancelada.' );
		}

		self::prune_backups( $base, $theme, $relative );

		return str_replace( $uploads['basedir'], 'uploads', $dest );
	}

	/**
	 * Bloquea el acceso web a un directorio (Apache .htaccess + index.php centinela).
	 * Evita que un backup .php se ejecute o se exponga su código fuente.
	 *
	 * @param string $dir Directorio a proteger.
	 * @return void
	 */
	private static function protect_dir( $dir ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// "Require all denied" (Apache 2.4) + "Deny from all" (2.2) por compatibilidad.
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Conserva solo los últimos BACKUP_RETENTION backups de un archivo concreto.
	 *
	 * @param string $base     Directorio base de backups.
	 * @param string $theme    Slug del tema.
	 * @param string $relative Ruta relativa del archivo.
	 * @return void
	 */
	private static function prune_backups( $base, $theme, $relative ) {
		$theme_dir = $base . '/' . $theme;
		if ( ! is_dir( $theme_dir ) ) {
			return;
		}
		// Cada backup vive en <theme>/<timestamp>/<relative>.
		$copies = array();
		foreach ( glob( $theme_dir . '/*', GLOB_ONLYDIR ) as $ts_dir ) {
			$candidate = $ts_dir . '/' . $relative;
			if ( is_file( $candidate ) ) {
				$copies[] = $ts_dir;
			}
		}
		if ( count( $copies ) <= self::BACKUP_RETENTION ) {
			return;
		}
		sort( $copies ); // Orden cronológico por timestamp en el nombre.
		$excess = array_slice( $copies, 0, count( $copies ) - self::BACKUP_RETENTION );
		foreach ( $excess as $ts_dir ) {
			$file = $ts_dir . '/' . $relative;
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore
			}
			// Eliminar el directorio de timestamp si queda vacío.
			self::remove_empty_dirs( $ts_dir, $theme_dir );
		}
	}

	/**
	 * Elimina directorios vacíos desde $dir hacia arriba sin pasar de $stop.
	 *
	 * @param string $dir  Directorio inicial.
	 * @param string $stop Límite superior (no se elimina).
	 * @return void
	 */
	private static function remove_empty_dirs( $dir, $stop ) {
		while ( $dir && $dir !== $stop && is_dir( $dir ) ) {
			$entries = array_diff( scandir( $dir ), array( '.', '..' ) );
			if ( ! empty( $entries ) ) {
				return;
			}
			@rmdir( $dir ); // phpcs:ignore
			$dir = dirname( $dir );
		}
	}
}
