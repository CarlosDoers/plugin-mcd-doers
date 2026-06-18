<?php
/**
 * Registro declarativo de las abilities del conector.
 *
 * Enfoque: crear y editar contenido (páginas y entradas) reutilizando los
 * bloques que ya existen en el sitio. El gating (grupo activo, solo-lectura,
 * rate limit, confirmación, auditoría) se aplica de forma centralizada.
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
			// --- Grupo: content ---
			'doers/list-blocks' => array(
				'group'       => 'content',
				'write'       => false,
				'cap'         => 'edit_posts',
				'label'       => 'Listar bloques disponibles',
				'description' => 'Devuelve los bloques disponibles para componer páginas: bloques a medida (ACF) con sus campos, block patterns y bloques sincronizados. Úsalo antes de crear o ampliar contenido para reutilizar los bloques existentes del sitio.',
				'input'       => array(
					'type'       => 'object',
					'properties' => array(
						'include_core' => array( 'type' => 'boolean', 'description' => 'Incluir también los bloques por defecto de WordPress (core/*). Por defecto false.' ),
					),
				),
				'callback'    => array( 'Doers_Blocks', 'list_blocks' ),
			),
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
				'description' => 'Devuelve un contenido completo por ID, incluido su markup de bloques. Útil para ampliar o modificar una página existente.',
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
				'description' => 'Crea o actualiza una página o entrada. El campo content acepta markup de bloques (incluidos bloques a medida del sitio, ver list-blocks). Permite fijar la página como portada.',
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
