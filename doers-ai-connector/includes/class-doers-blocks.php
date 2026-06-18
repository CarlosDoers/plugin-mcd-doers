<?php
/**
 * Descubrimiento de bloques disponibles en el sitio, para que la IA pueda
 * componer páginas reutilizando los bloques que ya existen: bloques a medida
 * (ACF) con sus campos, block patterns y bloques sincronizados (reusables).
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Catálogo de bloques.
 */
class Doers_Blocks {

	/**
	 * Devuelve el catálogo de bloques disponibles.
	 *
	 * @param array $input { include_core?:bool } Por defecto se omiten los bloques core de WordPress.
	 * @return array
	 */
	public static function list_blocks( $input = array() ) {
		$include_core = ! empty( $input['include_core'] );

		return array(
			'custom_blocks'   => self::custom_blocks( $include_core ),
			'patterns'        => self::patterns(),
			'synced_patterns' => self::synced_patterns(),
			'guidance'        => 'Para componer una página, usa el campo content de save-content con markup de bloques. '
				. 'Bloques ACF: <!-- wp:acf/SLUG {"name":"acf/SLUG","mode":"preview","data":{"FIELD_KEY":"valor","_FIELD_KEY":"FIELD_KEY"}} /--> usando las field_key indicadas. '
				. 'Bloques sincronizados: <!-- wp:block {"ref":ID} /-->. '
				. 'Patterns: copia su markup (campo content) y sustituye los textos. Prioriza estos bloques antes que los de WordPress por defecto.',
		);
	}

	/**
	 * Bloques registrados; marca los ACF y adjunta sus campos.
	 *
	 * @param bool $include_core Incluir bloques del core (core/*).
	 * @return array
	 */
	private static function custom_blocks( $include_core ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return array();
		}
		$all = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$out = array();

		foreach ( $all as $name => $type ) {
			$is_core = ( 0 === strpos( $name, 'core/' ) );
			if ( $is_core && ! $include_core ) {
				continue;
			}
			$entry = array(
				'name'        => $name,
				'title'       => isset( $type->title ) ? $type->title : $name,
				'category'    => isset( $type->category ) ? $type->category : '',
				'description' => isset( $type->description ) ? $type->description : '',
			);
			if ( 0 === strpos( $name, 'acf/' ) ) {
				$entry['is_acf'] = true;
				$entry['fields'] = self::acf_fields_for_block( $name );
			}
			$out[] = $entry;
		}

		// Primero los a medida (no core), por relevancia.
		usort(
			$out,
			function ( $a, $b ) {
				$ca = ( 0 === strpos( $a['name'], 'core/' ) ) ? 1 : 0;
				$cb = ( 0 === strpos( $b['name'], 'core/' ) ) ? 1 : 0;
				if ( $ca !== $cb ) {
					return $ca - $cb;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * Campos ACF asociados a un bloque ACF.
	 *
	 * @param string $block_name Nombre del bloque (acf/slug).
	 * @return array
	 */
	private static function acf_fields_for_block( $block_name ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}
		$groups = acf_get_field_groups( array( 'block' => $block_name ) );
		$fields = array();
		foreach ( (array) $groups as $group ) {
			$gfields = acf_get_fields( $group );
			foreach ( (array) $gfields as $f ) {
				$fields[] = array(
					'name'  => isset( $f['name'] ) ? $f['name'] : '',
					'key'   => isset( $f['key'] ) ? $f['key'] : '',
					'label' => isset( $f['label'] ) ? $f['label'] : '',
					'type'  => isset( $f['type'] ) ? $f['type'] : '',
				);
			}
		}
		return $fields;
	}

	/**
	 * Block patterns registrados (con su markup, recortado).
	 *
	 * @return array
	 */
	private static function patterns() {
		if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
			return array();
		}
		$out = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			// Omitir los patterns remotos del directorio core de WordPress.
			if ( isset( $p['source'] ) && 'core' === $p['source'] ) {
				continue;
			}
			$out[] = array(
				'name'       => isset( $p['name'] ) ? $p['name'] : '',
				'title'      => isset( $p['title'] ) ? $p['title'] : '',
				'categories' => isset( $p['categories'] ) ? $p['categories'] : array(),
				'content'    => isset( $p['content'] ) ? self::trim( $p['content'], 4000 ) : '',
			);
		}
		return $out;
	}

	/**
	 * Bloques sincronizados / reusables (wp_block).
	 *
	 * @return array
	 */
	private static function synced_patterns() {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'ref'     => $post->ID,
				'title'   => $post->post_title,
				'insert'  => '<!-- wp:block {"ref":' . $post->ID . '} /-->',
				'preview' => self::trim( $post->post_content, 1000 ),
			);
		}
		return $out;
	}

	/**
	 * Recorta texto largo.
	 *
	 * @param string $text Texto.
	 * @param int    $max  Máximo.
	 * @return string
	 */
	private static function trim( $text, $max ) {
		$text = (string) $text;
		return strlen( $text ) > $max ? substr( $text, 0, $max ) . '…' : $text;
	}
}
