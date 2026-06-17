<?php
/**
 * Operaciones de contenido: listar, leer y guardar entradas/páginas.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de contenido para las abilities.
 */
class Doers_Content {

	/**
	 * Lista contenido.
	 *
	 * @param array $input Input validado por el schema.
	 * @return array
	 */
	public static function list_content( $input ) {
		$query = new WP_Query(
			array(
				'post_type'      => isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'any',
				'post_status'    => isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'any',
				's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
				'posts_per_page' => isset( $input['per_page'] ) ? min( 100, absint( $input['per_page'] ) ) : 20,
				'paged'          => isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'slug'     => $post->post_name,
				'link'     => get_permalink( $post ),
				'modified' => $post->post_modified_gmt,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/**
	 * Devuelve un contenido completo (incluido el markup de bloques).
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function get_content( $input ) {
		$post = get_post( absint( $input['id'] ) );
		if ( ! $post ) {
			return new WP_Error( 'doers_not_found', 'No existe contenido con ese ID.' );
		}
		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'type'    => $post->post_type,
			'status'  => $post->post_status,
			'slug'    => $post->post_name,
			'link'    => get_permalink( $post ),
		);
	}

	/**
	 * Crea o actualiza contenido. El contenido se espera como markup de bloques.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function save_content( $input ) {
		$data = array();

		if ( ! empty( $input['id'] ) ) {
			$existing = get_post( absint( $input['id'] ) );
			if ( ! $existing ) {
				return new WP_Error( 'doers_not_found', 'No existe contenido con ese ID.' );
			}
			$data['ID'] = $existing->ID;
		} else {
			$data['post_type'] = isset( $input['post_type'] ) ? sanitize_key( $input['post_type'] ) : 'page';
		}

		if ( isset( $input['title'] ) ) {
			$data['post_title'] = sanitize_text_field( $input['title'] );
		}
		if ( isset( $input['content'] ) ) {
			// Markup de bloques. Se respeta tal cual para usuarios con unfiltered_html.
			$data['post_content'] = current_user_can( 'unfiltered_html' ) ? $input['content'] : wp_kses_post( $input['content'] );
		}
		if ( isset( $input['status'] ) ) {
			$data['post_status'] = sanitize_key( $input['status'] );
		}
		if ( isset( $input['slug'] ) ) {
			$data['post_name'] = sanitize_title( $input['slug'] );
		}

		$result = empty( $data['ID'] ) ? wp_insert_post( $data, true ) : wp_update_post( $data, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $result );

		// Opcional: establecer como portada estática.
		if ( ! empty( $input['set_as_front_page'] ) && 'page' === $post->post_type ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $post->ID );
		}

		return array(
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'link'   => get_permalink( $post ),
		);
	}
}
