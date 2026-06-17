<?php
/**
 * Plantillas FSE y navegación.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plantillas de bloques (wp_template / wp_template_part) y menús wp_navigation.
 */
class Doers_Templates {

	/**
	 * Lista plantillas y partes del tema activo, indicando si están personalizadas en BD.
	 *
	 * @return array
	 */
	public static function list_templates() {
		$result = array();
		foreach ( array( 'wp_template', 'wp_template_part' ) as $type ) {
			foreach ( get_block_templates( array(), $type ) as $template ) {
				$result[] = array(
					'id'         => $template->id,
					'slug'       => $template->slug,
					'type'       => $type,
					'title'      => $template->title,
					'source'     => $template->source, // 'theme' = archivo, 'custom' = personalizada en BD.
					'customized' => 'custom' === $template->source,
				);
			}
		}
		return array( 'templates' => $result );
	}

	/**
	 * Elimina la personalización en BD de una plantilla (vuelve al archivo del tema).
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function reset_template( $input ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error( 'doers_confirm_required', 'Operación destructiva: repite la llamada con confirm=true. Se perderá la personalización hecha en el editor del sitio.' );
		}

		$type = isset( $input['type'] ) && 'wp_template_part' === $input['type'] ? 'wp_template_part' : 'wp_template';
		$slug = sanitize_title( $input['slug'] );

		$posts = get_posts(
			array(
				'post_type'      => $type,
				'name'           => $slug,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);

		if ( ! $posts ) {
			return new WP_Error( 'doers_not_found', 'No hay personalización en BD para esa plantilla (ya usa el archivo del tema).' );
		}

		wp_delete_post( $posts[0]->ID, true );

		return array(
			'reset' => $slug,
			'type'  => $type,
		);
	}

	/**
	 * Lista los menús de navegación (wp_navigation).
	 *
	 * @return array
	 */
	public static function list_navigation() {
		$menus = array();
		foreach ( get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'posts_per_page' => 50 ) ) as $post ) {
			$menus[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => $post->post_content,
			);
		}
		return array( 'menus' => $menus );
	}

	/**
	 * Crea o actualiza un menú de navegación con markup de bloques.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function save_navigation( $input ) {
		$data = array(
			'post_type'   => 'wp_navigation',
			'post_status' => 'publish',
		);
		if ( ! empty( $input['id'] ) ) {
			$existing = get_post( absint( $input['id'] ) );
			if ( ! $existing || 'wp_navigation' !== $existing->post_type ) {
				return new WP_Error( 'doers_not_found', 'No existe un menú con ese ID.' );
			}
			$data['ID'] = $existing->ID;
		}
		if ( isset( $input['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			$data['post_content'] = $input['content']; // Markup de bloques de navigation-link.
		}

		$result = empty( $data['ID'] ) ? wp_insert_post( $data, true ) : wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'id' => (int) $result );
	}
}
