<?php
/**
 * Ajustes del conector: grupos de abilities, modo solo-lectura, rate limit.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestión de ajustes.
 */
class Doers_Settings {

	const OPTION = 'doers_ai_settings';

	/**
	 * Grupos disponibles y su etiqueta.
	 *
	 * @return array<string,string>
	 */
	public static function groups() {
		return array(
			'content' => 'Contenido y bloques (páginas y entradas)',
			'media'   => 'Medios',
		);
	}

	/**
	 * Ajustes con valores por defecto.
	 *
	 * @return array
	 */
	public static function get() {
		$defaults = array(
			'groups'        => array_fill_keys( array_keys( self::groups() ), true ),
			'read_only'     => false,
			'rate_limit'    => 100,
			'audit_max'     => 200,
			'oauth_enabled' => true,
		);
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings           = array_merge( $defaults, $saved );
		$settings['groups'] = array_merge( $defaults['groups'], isset( $saved['groups'] ) && is_array( $saved['groups'] ) ? $saved['groups'] : array() );
		return $settings;
	}

	/**
	 * Guarda ajustes.
	 *
	 * @param array $settings Ajustes ya saneados.
	 */
	public static function save( $settings ) {
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * ¿Está habilitado un grupo?
	 *
	 * @param string $group Grupo.
	 * @return bool
	 */
	public static function group_enabled( $group ) {
		$settings = self::get();
		return ! empty( $settings['groups'][ $group ] );
	}

	/**
	 * ¿Modo solo lectura?
	 *
	 * @return bool
	 */
	public static function read_only() {
		$settings = self::get();
		return ! empty( $settings['read_only'] );
	}

	/**
	 * Comprueba y consume cuota del rate limit para acciones de escritura.
	 *
	 * @return true|WP_Error
	 */
	public static function consume_rate_limit() {
		$settings = self::get();
		$limit    = (int) $settings['rate_limit'];
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'doers_ai_rate_' . gmdate( 'YmdH' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error(
				'doers_rate_limited',
				sprintf( 'Límite de %d acciones de escritura por hora alcanzado. Ajustable en Ajustes → Doers AI.', $limit )
			);
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}
