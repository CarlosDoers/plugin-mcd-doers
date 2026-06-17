<?php
/**
 * Registro declarativo de todas las abilities del conector.
 *
 * Cada ability declara: grupo, si escribe, capability mínima y si requiere
 * confirmación. El gating (grupo activo, solo-lectura, rate limit, confirm)
 * se aplica de forma centralizada.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define y registra las abilities de Doers.
 */
class Doers_Abilities {

	/**
	 * Definición de todas las abilities.
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		$obj = array( 'type' => 'object' );

		return array(
			// --- Grupo: context ---
			'doers/get-project-context' => array(
				'group'       => 'context',
				'write'       => false,
				'cap'         => 'edit_posts',
				'label'       => 'Obtener contexto del proyecto',
				'description' => 'Devuelve el contexto de esta web: identidad y voz de marca, sistema de diseño (tokens del theme.json y de Figma, colores y tipografías) y documentación del proyecto. Conviene leerlo al empezar a crear o modificar contenido o temas, para ajustarse a la identidad del sitio.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Context', 'build_context' ),
			),
			'doers/get-figma-node' => array(
				'group'       => 'context',
				'write'       => false,
				'cap'         => 'edit_posts',
				'label'       => 'Leer un bloque de Figma por enlace',
				'description' => 'Dado un enlace de Figma a un elemento concreto (botón «Copy link», que incluye node-id), devuelve su estructura de diseño (textos, fuentes, colores, auto-layout, espaciados, radios y medidas) y la URL de una imagen renderizada del elemento, para poder replicarlo como bloque o sección del tema.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'link'     => array( 'type' => 'string', 'description' => 'URL de Figma con node-id (Copy link sobre el elemento).' ),
						'node_id'  => array( 'type' => 'string', 'description' => 'Alternativa: id del nodo, p. ej. 15:2462.' ),
						'file_key' => array( 'type' => 'string', 'description' => 'Alternativa: file key; si se omite, usa el del último sync.' ),
					),
				),
				'callback'    => array( 'Doers_Context', 'get_figma_node' ),
			),

			// --- Grupo: settings ---
			'doers/site-info' => array(
				'group'       => 'settings',
				'write'       => false,
				'cap'         => 'manage_options',
				'label'       => 'Información del sitio',
				'description' => 'Devuelve información general del sitio: nombre, URL, versiones, tema activo y configuración de portada.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Manage', 'site_info' ),
			),
			'doers/update-site-settings' => array(
				'group'       => 'settings',
				'write'       => true,
				'cap'         => 'manage_options',
				'label'       => 'Actualizar ajustes del sitio',
				'description' => 'Actualiza ajustes de una lista blanca: título, descripción, portada estática, página de entradas y estructura de enlaces permanentes.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'blogname'            => array( 'type' => 'string' ),
						'blogdescription'     => array( 'type' => 'string' ),
						'show_on_front'       => array( 'type' => 'string', 'enum' => array( 'posts', 'page' ) ),
						'page_on_front'       => array( 'type' => 'integer' ),
						'page_for_posts'      => array( 'type' => 'integer' ),
						'permalink_structure' => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( 'Doers_Manage', 'update_site_settings' ),
			),

			// --- Grupo: content ---
			'doers/list-content' => array(
				'group'       => 'content',
				'write'       => false,
				'cap'         => 'edit_posts',
				'label'       => 'Listar contenido',
				'description' => 'Lista entradas, páginas u otros tipos de contenido, con filtro por tipo, estado y búsqueda.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string' ),
						'search'    => array( 'type' => 'string' ),
						'per_page'  => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
						'page'      => array( 'type' => 'integer', 'default' => 1 ),
					),
				),
				'callback'    => array( 'Doers_Content', 'list_content' ),
			),
			'doers/get-content' => array(
				'group'       => 'content',
				'write'       => false,
				'cap'         => 'edit_posts',
				'label'       => 'Obtener contenido',
				'description' => 'Devuelve un contenido completo por ID, incluido su markup de bloques.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'callback'    => array( 'Doers_Content', 'get_content' ),
			),
			'doers/save-content' => array(
				'group'       => 'content',
				'write'       => true,
				'cap'         => 'publish_pages',
				'label'       => 'Crear o actualizar contenido',
				'description' => 'Crea o actualiza una página o entrada. El campo content acepta markup de bloques. Permite fijar la página como portada.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'                => array( 'type' => 'integer', 'description' => 'ID existente para actualizar. Omitir para crear.' ),
						'post_type'         => array( 'type' => 'string', 'default' => 'page' ),
						'title'             => array( 'type' => 'string' ),
						'content'           => array( 'type' => 'string', 'description' => 'Markup de bloques (<!-- wp:... -->).' ),
						'status'            => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'private' ) ),
						'slug'              => array( 'type' => 'string' ),
						'set_as_front_page' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				'callback'    => array( 'Doers_Content', 'save_content' ),
			),

			// --- Grupo: files ---
			'doers/list-theme-files' => array(
				'group'       => 'files',
				'write'       => false,
				'cap'         => 'edit_themes',
				'label'       => 'Listar archivos de tema',
				'description' => 'Lista los archivos de un tema instalado (por defecto, el activo).',
				'input'       => array(
					'type'       => 'object',
					'properties' => array( 'theme' => array( 'type' => 'string' ) ),
				),
				'callback'    => function ( $input ) {
					return Doers_Files::list_files( isset( $input['theme'] ) ? $input['theme'] : '' );
				},
			),
			'doers/read-theme-file' => array(
				'group'       => 'files',
				'write'       => false,
				'cap'         => 'edit_themes',
				'label'       => 'Leer archivo de tema',
				'description' => 'Devuelve el contenido de un archivo de un tema.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'theme' => array( 'type' => 'string' ),
						'path'  => array( 'type' => 'string', 'description' => 'Ruta relativa, p. ej. templates/front-page.html' ),
					),
					'required'   => array( 'path' ),
				),
				'callback'    => function ( $input ) {
					return Doers_Files::read( isset( $input['theme'] ) ? $input['theme'] : '', $input['path'] );
				},
			),
			'doers/write-theme-file' => array(
				'group'       => 'files',
				'write'       => true,
				'cap'         => 'edit_themes',
				'files'       => true,
				'label'       => 'Escribir archivo de tema',
				'description' => 'Crea o sobrescribe un archivo dentro de un tema. Si existía, se guarda backup automático. Los archivos PHP se validan (sintaxis) antes de guardar y se revierten solos si rompen el sitio. Sobrescribir functions.php o style.css exige confirm=true.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'theme'   => array( 'type' => 'string' ),
						'path'    => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'confirm' => array( 'type' => 'boolean', 'description' => 'Obligatorio (true) al escribir archivos críticos como functions.php o style.css.' ),
					),
					'required'   => array( 'path', 'content' ),
				),
				'callback'    => function ( $input ) {
					return Doers_Files::write(
						isset( $input['theme'] ) ? $input['theme'] : '',
						$input['path'],
						$input['content'],
						! empty( $input['confirm'] )
					);
				},
			),
			'doers/list-backups' => array(
				'group'       => 'files',
				'write'       => false,
				'cap'         => 'edit_themes',
				'label'       => 'Listar backups',
				'description' => 'Lista las copias de seguridad creadas antes de cada escritura de archivos.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Files', 'list_backups' ),
			),
			'doers/restore-backup' => array(
				'group'       => 'files',
				'write'       => true,
				'cap'         => 'edit_themes',
				'files'       => true,
				'confirm'     => true,
				'label'       => 'Restaurar backup',
				'description' => 'Restaura un archivo desde un backup (sobrescribe el archivo actual del tema; el estado actual también se respalda).',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'backup_id' => array( 'type' => 'string', 'description' => 'Identificador devuelto por list-backups (tema/timestamp/ruta).' ),
						'confirm'   => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'backup_id', 'confirm' ),
				),
				'callback'    => function ( $input ) {
					return Doers_Files::restore_backup( $input['backup_id'] );
				},
			),

			// --- Grupo: themes ---
			'doers/list-themes' => array(
				'group'       => 'themes',
				'write'       => false,
				'cap'         => 'switch_themes',
				'label'       => 'Listar temas',
				'description' => 'Lista los temas instalados e indica cuál está activo.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Manage', 'list_themes' ),
			),
			'doers/activate-theme' => array(
				'group'       => 'themes',
				'write'       => true,
				'cap'         => 'switch_themes',
				'confirm'     => true,
				'label'       => 'Activar tema',
				'description' => 'Activa un tema instalado por su slug. Cambia la apariencia de todo el sitio.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'stylesheet' => array( 'type' => 'string' ),
						'confirm'    => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'stylesheet', 'confirm' ),
				),
				'callback'    => array( 'Doers_Manage', 'activate_theme' ),
			),
			'doers/list-templates' => array(
				'group'       => 'themes',
				'write'       => false,
				'cap'         => 'edit_theme_options',
				'label'       => 'Listar plantillas FSE',
				'description' => 'Lista plantillas y partes del tema activo, indicando si están personalizadas en base de datos (editor del sitio) o usan el archivo del tema.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Templates', 'list_templates' ),
			),
			'doers/reset-template' => array(
				'group'       => 'themes',
				'write'       => true,
				'cap'         => 'edit_theme_options',
				'label'       => 'Resetear plantilla FSE',
				'description' => 'Elimina la personalización en BD de una plantilla para volver al archivo del tema. Requiere confirm=true.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'slug'    => array( 'type' => 'string' ),
						'type'    => array( 'type' => 'string', 'enum' => array( 'wp_template', 'wp_template_part' ), 'default' => 'wp_template' ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'slug' ),
				),
				'callback'    => array( 'Doers_Templates', 'reset_template' ),
			),
			'doers/list-navigation' => array(
				'group'       => 'themes',
				'write'       => false,
				'cap'         => 'edit_theme_options',
				'label'       => 'Listar menús de navegación',
				'description' => 'Lista los menús de navegación (wp_navigation) con su markup de bloques.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Templates', 'list_navigation' ),
			),
			'doers/save-navigation' => array(
				'group'       => 'themes',
				'write'       => true,
				'cap'         => 'edit_theme_options',
				'label'       => 'Guardar menú de navegación',
				'description' => 'Crea o actualiza un menú de navegación con markup de bloques navigation-link.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'      => array( 'type' => 'integer' ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( 'Doers_Templates', 'save_navigation' ),
			),

			// --- Grupo: plugins ---
			'doers/list-plugins' => array(
				'group'       => 'plugins',
				'write'       => false,
				'cap'         => 'activate_plugins',
				'label'       => 'Listar plugins',
				'description' => 'Lista los plugins instalados y su estado.',
				'input'       => $obj,
				'callback'    => array( 'Doers_Manage', 'list_plugins' ),
			),
			'doers/toggle-plugin' => array(
				'group'       => 'plugins',
				'write'       => true,
				'cap'         => 'activate_plugins',
				'label'       => 'Activar/desactivar plugin',
				'description' => 'Activa o desactiva un plugin instalado. Desactivar requiere confirm=true.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'plugin'  => array( 'type' => 'string', 'description' => 'p. ej. mi-plugin/mi-plugin.php' ),
						'action'  => array( 'type' => 'string', 'enum' => array( 'activate', 'deactivate' ) ),
						'confirm' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'plugin', 'action' ),
				),
				'callback'    => array( 'Doers_Manage', 'toggle_plugin' ),
			),
			'doers/install-plugin' => array(
				'group'       => 'plugins',
				'write'       => true,
				'cap'         => 'install_plugins',
				'label'       => 'Instalar plugin',
				'description' => 'Instala un plugin desde el repositorio oficial de WordPress.org por su slug, y opcionalmente lo activa.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'slug'     => array( 'type' => 'string', 'description' => 'Slug en wordpress.org, p. ej. contact-form-7' ),
						'activate' => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'slug' ),
				),
				'callback'    => array( 'Doers_Manage', 'install_plugin' ),
			),

			// --- Grupo: media ---
			'doers/list-media' => array(
				'group'       => 'media',
				'write'       => false,
				'cap'         => 'upload_files',
				'label'       => 'Listar medios',
				'description' => 'Lista los archivos de la biblioteca de medios.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'default' => 20, 'maximum' => 100 ),
					),
				),
				'callback'    => array( 'Doers_Media', 'list_media' ),
			),
			'doers/upload-media' => array(
				'group'       => 'media',
				'write'       => true,
				'cap'         => 'upload_files',
				'label'       => 'Subir medio',
				'description' => 'Sube una imagen u otro archivo a la biblioteca, desde una URL o desde contenido en base64.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'            => array( 'type' => 'string', 'description' => 'URL pública del archivo a importar.' ),
						'filename'       => array( 'type' => 'string' ),
						'content_base64' => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'alt'            => array( 'type' => 'string' ),
					),
				),
				'callback'    => array( 'Doers_Media', 'upload_media' ),
			),
		);
	}

	/**
	 * Nombres de las abilities habilitadas (según grupos activos en ajustes).
	 *
	 * @return string[]
	 */
	public static function names() {
		$names = array();
		foreach ( self::definitions() as $name => $def ) {
			if ( Doers_Settings::group_enabled( $def['group'] ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Registra todas las abilities.
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::definitions() as $name => $def ) {
			wp_register_ability(
				$name,
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => 'site',
					'input_schema'        => $def['input'],
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => self::wrap( $name, $def ),
					'permission_callback' => self::permission( $def ),
				)
			);
		}
	}

	/**
	 * Construye el permission_callback con el gating centralizado.
	 *
	 * @param array $def Definición.
	 * @return callable
	 */
	private static function permission( $def ) {
		return function () use ( $def ) {
			if ( ! Doers_Settings::group_enabled( $def['group'] ) ) {
				return false;
			}
			if ( ! empty( $def['write'] ) && Doers_Settings::read_only() ) {
				return false;
			}
			if ( ! empty( $def['files'] ) && ! Doers_Files::editing_allowed() ) {
				return false;
			}
			return current_user_can( $def['cap'] );
		};
	}

	/**
	 * Envuelve el callback con confirmación, rate limit y auditoría.
	 *
	 * @param string $name Nombre de la ability.
	 * @param array  $def  Definición.
	 * @return callable
	 */
	private static function wrap( $name, $def ) {
		return function ( $input = array() ) use ( $name, $def ) {
			$input = is_array( $input ) ? $input : array();

			if ( ! empty( $def['confirm'] ) && empty( $input['confirm'] ) ) {
				return new WP_Error(
					'doers_confirm_required',
					'Esta operación modifica el sitio de forma significativa. Repite la llamada con confirm=true si el usuario lo ha aprobado.'
				);
			}

			if ( ! empty( $def['write'] ) ) {
				$quota = Doers_Settings::consume_rate_limit();
				if ( is_wp_error( $quota ) ) {
					Doers_Audit::log( $name, $input, false );
					return $quota;
				}
			}

			$result = call_user_func( $def['callback'], $input );
			Doers_Audit::log( $name, $input, ! is_wp_error( $result ) );
			return $result;
		};
	}
}
