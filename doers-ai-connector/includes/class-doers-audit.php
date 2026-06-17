<?php
/**
 * Registro de auditoría: cada ejecución de una ability queda registrada.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit log sencillo sobre una opción (capado a 200 entradas).
 */
class Doers_Audit {

	const OPTION   = 'doers_ai_audit_log';
	const MAX_ROWS = 200;

	/**
	 * Registra una ejecución.
	 *
	 * @param string $ability Nombre de la ability.
	 * @param array  $input   Input recibido (se trunca para no inflar la opción).
	 * @param bool   $ok      Resultado.
	 */
	public static function log( $ability, $input, $ok = true ) {
		$rows   = get_option( self::OPTION, array() );
		$user   = wp_get_current_user();
		$rows[] = array(
			'time'    => gmdate( 'Y-m-d H:i:s' ),
			'ability' => $ability,
			'user'    => $user && $user->exists() ? $user->user_login : 'anon',
			'input'   => self::truncate( $input ),
			'ok'      => (bool) $ok,
		);
		$max = self::MAX_ROWS;
		if ( class_exists( 'Doers_Settings' ) ) {
			$settings = Doers_Settings::get();
			$max      = max( 10, (int) $settings['audit_max'] );
		}
		if ( count( $rows ) > $max ) {
			$rows = array_slice( $rows, - $max );
		}
		update_option( self::OPTION, $rows, false );
	}

	/**
	 * Devuelve las últimas entradas (más recientes primero).
	 *
	 * @param int $limit Número de entradas.
	 * @return array
	 */
	public static function recent( $limit = 30 ) {
		$rows = get_option( self::OPTION, array() );
		return array_reverse( array_slice( $rows, - $limit ) );
	}

	/**
	 * Resumen seguro del input.
	 *
	 * @param mixed $input Input.
	 * @return string
	 */
	private static function truncate( $input ) {
		$json = wp_json_encode( $input );
		if ( ! is_string( $json ) ) {
			return '';
		}
		return strlen( $json ) > 500 ? substr( $json, 0, 500 ) . '…' : $json;
	}
}
