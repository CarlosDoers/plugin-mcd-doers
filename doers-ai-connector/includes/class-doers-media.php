<?php
/**
 * Abilities de medios.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subida y listado de medios.
 */
class Doers_Media {

	/**
	 * Lista medios de la biblioteca.
	 *
	 * @param array $input Input.
	 * @return array
	 */
	public static function list_media( $input ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
				'posts_per_page' => isset( $input['per_page'] ) ? min( 100, absint( $input['per_page'] ) ) : 20,
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'url'   => wp_get_attachment_url( $post->ID ),
				'mime'  => $post->post_mime_type,
				'alt'   => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			);
		}
		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/**
	 * Sube un medio desde una URL o desde contenido en base64.
	 *
	 * @param array $input Input.
	 * @return array|WP_Error
	 */
	public static function upload_media( $input ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( ! empty( $input['url'] ) ) {
			$url = esc_url_raw( $input['url'] );
			$id  = media_sideload_image( $url, 0, isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null, 'id' );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		} elseif ( ! empty( $input['filename'] ) && ! empty( $input['content_base64'] ) ) {
			$filename = sanitize_file_name( $input['filename'] );
			$data     = base64_decode( $input['content_base64'], true ); // phpcs:ignore
			if ( false === $data ) {
				return new WP_Error( 'doers_invalid_base64', 'El contenido base64 no es válido.' );
			}
			$check = wp_check_filetype( $filename );
			if ( empty( $check['type'] ) ) {
				return new WP_Error( 'doers_invalid_filetype', 'Tipo de archivo no permitido: ' . $filename );
			}
			$upload = wp_upload_bits( $filename, null, $data );
			if ( ! empty( $upload['error'] ) ) {
				return new WP_Error( 'doers_upload_failed', $upload['error'] );
			}
			$id = wp_insert_attachment(
				array(
					'post_title'     => isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : $filename,
					'post_mime_type' => $check['type'],
					'post_status'    => 'inherit',
				),
				$upload['file']
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		} else {
			return new WP_Error( 'doers_missing_input', 'Indica "url" o bien "filename" + "content_base64".' );
		}

		if ( ! empty( $input['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
		}

		return array(
			'id'  => (int) $id,
			'url' => wp_get_attachment_url( $id ),
		);
	}
}
