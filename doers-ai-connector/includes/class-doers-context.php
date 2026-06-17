<?php
/**
 * Contexto del proyecto: sistema de diseño, marca y documentación de la web,
 * que se entrega a la IA al empezar a trabajar para personalizar el conector
 * a este sitio concreto.
 *
 * Fuentes: campos manuales de marca/diseño, tokens del theme.json del tema
 * activo, documentos en Markdown y sincronización con Figma.
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Almacén y ensamblado del contexto del proyecto.
 */
class Doers_Context {

	const OPTION       = 'doers_ai_context';
	const TOKEN_OPTION = 'doers_ai_figma_token';

	/**
	 * Estructura por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'brand'  => array(
				'name'     => '',
				'tagline'  => '',
				'audience' => '',
				'voice'    => '',
				'notes'    => '',
			),
			'design' => array(
				'logo_url'      => '',
				'primary'       => '',
				'secondary'     => '',
				'accent'        => '',
				'heading_font'  => '',
				'body_font'     => '',
				'notes'         => '',
			),
			'docs'   => array(),
			'figma'  => array(
				'file_key'  => '',
				'last_sync' => '',
				'summary'   => '',
				'colors'    => array(),
				'type'      => array(),
			),
		);
	}

	/**
	 * Lee el contexto guardado (sin el token de Figma).
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$defaults = self::defaults();
		$out      = $defaults;
		foreach ( $defaults as $key => $val ) {
			if ( isset( $saved[ $key ] ) ) {
				$out[ $key ] = is_array( $val ) ? array_merge( $val, (array) $saved[ $key ] ) : $saved[ $key ];
			}
		}
		$out['docs'] = isset( $saved['docs'] ) && is_array( $saved['docs'] ) ? array_values( $saved['docs'] ) : array();
		return $out;
	}

	/**
	 * Guarda el contexto (los campos editables, no el sync de Figma ni el token).
	 *
	 * @param array $context Contexto saneado.
	 */
	public static function save( $context ) {
		update_option( self::OPTION, $context, false );
	}

	/**
	 * Token de Figma (solo uso interno; nunca se devuelve por MCP).
	 *
	 * @return string
	 */
	public static function figma_token() {
		return (string) get_option( self::TOKEN_OPTION, '' );
	}

	/**
	 * Guarda (o borra) el token de Figma.
	 *
	 * @param string $token Token.
	 */
	public static function set_figma_token( $token ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			delete_option( self::TOKEN_OPTION );
		} else {
			update_option( self::TOKEN_OPTION, $token, false );
		}
	}

	/**
	 * Tokens de diseño del tema activo, leídos de su theme.json.
	 *
	 * @return array
	 */
	public static function theme_tokens() {
		$out  = array( 'palette' => array(), 'font_sizes' => array(), 'font_families' => array(), 'spacing' => array() );
		$file = get_stylesheet_directory() . '/theme.json';
		$data = array();

		// Solo leemos el theme.json del propio tema: son los tokens que el tema
		// declara como sistema de diseño. Si no hay theme.json (tema clásico),
		// no inventamos tokens — la paleta por defecto de WordPress NO es el
		// sistema de diseño del proyecto (ese vive en el CSS o en Figma).
		if ( file_exists( $file ) ) {
			$decoded = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $decoded ) && isset( $decoded['settings'] ) ) {
				$data = $decoded['settings'];
			}
		}

		if ( isset( $data['color']['palette'] ) ) {
			foreach ( self::flatten_origins( $data['color']['palette'] ) as $c ) {
				if ( isset( $c['slug'], $c['color'] ) ) {
					$out['palette'][] = array(
						'name'  => isset( $c['name'] ) ? $c['name'] : $c['slug'],
						'slug'  => $c['slug'],
						'color' => $c['color'],
					);
				}
			}
		}
		if ( isset( $data['typography']['fontSizes'] ) ) {
			foreach ( self::flatten_origins( $data['typography']['fontSizes'] ) as $f ) {
				if ( isset( $f['slug'], $f['size'] ) ) {
					$out['font_sizes'][] = array( 'slug' => $f['slug'], 'size' => $f['size'] );
				}
			}
		}
		if ( isset( $data['typography']['fontFamilies'] ) ) {
			foreach ( self::flatten_origins( $data['typography']['fontFamilies'] ) as $f ) {
				if ( isset( $f['fontFamily'] ) ) {
					$out['font_families'][] = array(
						'name'       => isset( $f['name'] ) ? $f['name'] : ( isset( $f['slug'] ) ? $f['slug'] : '' ),
						'fontFamily' => $f['fontFamily'],
					);
				}
			}
		}
		if ( isset( $data['spacing']['spacingSizes'] ) ) {
			foreach ( self::flatten_origins( $data['spacing']['spacingSizes'] ) as $s ) {
				if ( isset( $s['slug'], $s['size'] ) ) {
					$out['spacing'][] = array( 'slug' => $s['slug'], 'size' => $s['size'] );
				}
			}
		}

		return $out;
	}

	/**
	 * Las listas de theme.json pueden venir agrupadas por origen (default/theme/custom).
	 * Devuelve una lista plana.
	 *
	 * @param array $value Valor.
	 * @return array
	 */
	private static function flatten_origins( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		// Lista simple de items asociativos.
		if ( isset( $value[0] ) ) {
			return $value;
		}
		// Agrupado por origen: { default:[], theme:[], custom:[] }.
		$flat = array();
		foreach ( array( 'theme', 'custom', 'default' ) as $origin ) {
			if ( isset( $value[ $origin ] ) && is_array( $value[ $origin ] ) ) {
				$flat = array_merge( $flat, $value[ $origin ] );
			}
		}
		return $flat;
	}

	/**
	 * Ensambla el contexto completo para entregar a la IA.
	 * No incluye secretos (token de Figma).
	 *
	 * @return array
	 */
	public static function build_context() {
		$ctx          = self::get();
		$theme_tokens = self::theme_tokens();

		$has_content = array_filter(
			array(
				implode( '', $ctx['brand'] ),
				implode( '', $ctx['design'] ),
				count( $ctx['docs'] ),
				count( $theme_tokens['palette'] ),
				count( $ctx['figma']['colors'] ),
			)
		);

		return array(
			'configured'    => ! empty( $has_content ),
			'site'          => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url(),
				'active_theme' => get_stylesheet(),
			),
			'brand'         => $ctx['brand'],
			'design_system' => array(
				'manual'        => $ctx['design'],
				'theme_json'    => $theme_tokens,
				'figma'         => array(
					'file_key'  => $ctx['figma']['file_key'],
					'last_sync' => $ctx['figma']['last_sync'],
					'summary'   => $ctx['figma']['summary'],
					'colors'    => $ctx['figma']['colors'],
					'type'      => $ctx['figma']['type'],
				),
			),
			'docs'          => $ctx['docs'],
			'guidance'      => 'Usa este contexto como fuente de verdad sobre la identidad, el sistema de diseño y el funcionamiento de esta web al crear o modificar contenido y temas. Prioriza los tokens del theme.json y de Figma para colores y tipografías; respeta la voz de marca y las convenciones descritas en los documentos.',
		);
	}

	/**
	 * Sincroniza el sistema de diseño desde Figma.
	 * Intenta la API de variables (Enterprise) y, si no, cae a estilos + nodos.
	 *
	 * @param string $file_key File key del archivo de Figma.
	 * @return array|WP_Error Resumen del resultado.
	 */
	public static function figma_sync( $file_key ) {
		$file_key = trim( (string) $file_key );
		$token    = self::figma_token();
		if ( '' === $token ) {
			return new WP_Error( 'doers_figma_no_token', 'Falta el token de acceso de Figma.' );
		}
		if ( '' === $file_key ) {
			return new WP_Error( 'doers_figma_no_key', 'Falta el file key del archivo de Figma.' );
		}

		// Margen para archivos grandes (evita pantalla blanca por memoria/tiempo).
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore
		}

		// Diagnóstico: si un error fatal mata la petición (memoria, etc.), lo
		// capturamos en una opción para mostrarlo al recargar (no pantalla blanca).
		delete_option( 'doers_ai_figma_last_error' );
		delete_option( 'doers_ai_figma_progress' );
		register_shutdown_function( array( __CLASS__, 'figma_shutdown_check' ) );

		$colors = array();
		$type   = array();
		$method = '';

		// 1) Intento con la API de variables (plan Enterprise).
		$vars = self::figma_get( 'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '/variables/local', $token );
		if ( ! is_wp_error( $vars ) && isset( $vars['meta']['variables'] ) ) {
			$method = 'variables';
			foreach ( $vars['meta']['variables'] as $var ) {
				$name = isset( $var['name'] ) ? $var['name'] : '';
				$vals = isset( $var['valuesByMode'] ) ? array_values( $var['valuesByMode'] ) : array();
				$val  = isset( $vals[0] ) ? $vals[0] : null;
				if ( 'COLOR' === ( isset( $var['resolvedType'] ) ? $var['resolvedType'] : '' ) && is_array( $val ) ) {
					$colors[] = array( 'name' => $name, 'value' => self::rgba_to_hex( $val ) );
				}
			}
		}

		// 2) Fallback: estilos publicados + nodos (cualquier plan).
		if ( empty( $colors ) ) {
			$styles = self::figma_get( 'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '/styles', $token );
			if ( is_wp_error( $styles ) ) {
				return $styles;
			}
			$method   = 'styles';
			$by_node  = array();
			$node_ids = array();
			if ( isset( $styles['meta']['styles'] ) ) {
				foreach ( $styles['meta']['styles'] as $style ) {
					if ( empty( $style['node_id'] ) ) {
						continue;
					}
					$by_node[ $style['node_id'] ] = array(
						'name' => isset( $style['name'] ) ? $style['name'] : '',
						'type' => isset( $style['style_type'] ) ? $style['style_type'] : '',
					);
					$node_ids[] = $style['node_id'];
				}
			}
			// IMPORTANTE: pedir los nodos con depth=1. El nodo de un estilo ya trae
			// su color/tipografía; sin depth, Figma devuelve subárboles enormes (los
			// estilos pueden colgar de mockups de página completa) y agota la memoria.
			foreach ( array_chunk( array_slice( $node_ids, 0, 400 ), 50 ) as $batch ) {
				$nodes = self::figma_get(
					'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '/nodes?depth=1&ids=' . rawurlencode( implode( ',', $batch ) ),
					$token,
					40
				);
				if ( is_wp_error( $nodes ) || ! isset( $nodes['nodes'] ) ) {
					continue;
				}
				foreach ( $nodes['nodes'] as $node_id => $payload ) {
					$doc  = isset( $payload['document'] ) ? $payload['document'] : array();
					$meta = isset( $by_node[ $node_id ] ) ? $by_node[ $node_id ] : array( 'name' => '', 'type' => '' );
					if ( 'FILL' === $meta['type'] && isset( $doc['fills'][0]['color'] ) ) {
						$colors[] = array( 'name' => $meta['name'], 'value' => self::rgba_to_hex( $doc['fills'][0]['color'] ) );
					} elseif ( 'TEXT' === $meta['type'] && isset( $doc['style'] ) ) {
						$s      = $doc['style'];
						$type[] = array(
							'name'   => $meta['name'],
							'family' => isset( $s['fontFamily'] ) ? $s['fontFamily'] : '',
							'weight' => isset( $s['fontWeight'] ) ? $s['fontWeight'] : '',
							'size'   => isset( $s['fontSize'] ) ? $s['fontSize'] : '',
						);
					}
				}
			}
		}

		// 3) Último recurso: escanear el documento (sistema de diseño dibujado como capas).
		if ( empty( $colors ) && empty( $type ) ) {
			$scan = self::figma_scan_document( $file_key, $token );
			if ( is_wp_error( $scan ) ) {
				return $scan;
			}
			$colors = $scan['colors'];
			$type   = $scan['type'];
			$method = 'documento';
		}

		if ( empty( $colors ) && empty( $type ) ) {
			return new WP_Error( 'doers_figma_empty', 'Conexión correcta, pero no se encontraron colores ni textos en ese archivo.' );
		}

		$ctx                       = self::get();
		$ctx['figma']['file_key']  = $file_key;
		$ctx['figma']['last_sync'] = gmdate( 'Y-m-d H:i' ) . ' UTC';
		$ctx['figma']['colors']    = array_slice( $colors, 0, 200 );
		$ctx['figma']['type']      = array_slice( $type, 0, 100 );
		$ctx['figma']['summary']   = sprintf( '%d colores y %d estilos de texto (vía %s)', count( $ctx['figma']['colors'] ), count( $ctx['figma']['type'] ), $method );
		self::save( $ctx );
		delete_option( 'doers_ai_figma_progress' );

		return array(
			'method' => $method,
			'colors' => count( $ctx['figma']['colors'] ),
			'type'   => count( $ctx['figma']['type'] ),
		);
	}

	/**
	 * Capturador de errores fatales durante la sincronización con Figma.
	 * Guarda el último fatal para mostrarlo en el panel (evita la pantalla blanca muda).
	 *
	 * @return void
	 */
	public static function figma_shutdown_check() {
		$e = error_get_last();
		$fatals = array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		if ( is_array( $e ) && in_array( $e['type'], $fatals, true ) ) {
			$msg = substr( (string) $e['message'], 0, 300 ) . ' (' . basename( (string) $e['file'] ) . ':' . (int) $e['line'] . ')';
			update_option( 'doers_ai_figma_last_error', $msg, false );
		}
	}

	/**
	 * Escanea el documento de Figma y extrae colores y tipografías de las capas.
	 * Para sistemas de diseño dibujados como capas (no publicados como estilos).
	 *
	 * @param string $file_key File key.
	 * @param string $token    Token.
	 * @return array|WP_Error { colors:[], type:[] } o error.
	 */
	private static function figma_scan_document( $file_key, $token ) {
		// NO descargamos el archivo completo (puede ser enorme y agotar la memoria).
		// 1) Pedimos solo la estructura: páginas y sus marcos de primer nivel (depth=2).
		$tree = self::figma_get( 'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '?depth=2', $token, 30 );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		if ( ! isset( $tree['document']['children'] ) || ! is_array( $tree['document']['children'] ) ) {
			return array( 'colors' => array(), 'type' => array() );
		}

		// 2) Elegir la página del sistema de diseño por nombre; si no, todas.
		$pages  = $tree['document']['children'];
		$target = null;
		foreach ( $pages as $page ) {
			$name = isset( $page['name'] ) ? strtolower( $page['name'] ) : '';
			if ( preg_match( '/(ui|design|dise|sistema|style|estilo|brand|marca|token)/u', $name ) ) {
				$target = $page;
				break;
			}
		}
		// Sin página de diseño identificable, NO recorremos todo el archivo
		// (en sitios grandes agota la memoria). Pedimos al usuario que la nombre.
		if ( null === $target ) {
			return new WP_Error(
				'doers_figma_no_ds_page',
				'Conexión correcta, pero no hay variables ni estilos publicados, y no encontré una página de sistema de diseño en Figma. Nombra una página como "UI", "Design system", "Estilos" o "Marca" (donde estén los colores y tipografías) y reintenta, o publica esos colores/textos como estilos en Figma.'
			);
		}
		$src_pages = array( $target );

		// 3) Seleccionar marcos por NOMBRE: solo los que suenan a tokens (paletas,
		//    tipografías, logos, estilos) y descartar los mockups pesados
		//    (componentes, breakpoints, animaciones…), que son los que disparan
		//    la memoria (un marco de mockups puede pesar decenas de MB).
		$want = '/(colou?r|palet|paleta|tipograf|typo|font|fuente|texto|text|estilo|style|guide|marca|brand|logo|sello|hex|swatch|token)/u';
		$skip = '/(component|breakpoint|mockup|prototip|pantalla|screen|captura|animaci|interacc|landing|p[aá]gina)/u';
		$wanted = array();
		$rest   = array();
		foreach ( $src_pages as $page ) {
			$children = ( isset( $page['children'] ) && is_array( $page['children'] ) ) ? $page['children'] : array( $page );
			foreach ( $children as $frame ) {
				if ( ! isset( $frame['id'] ) ) {
					continue;
				}
				$fname = isset( $frame['name'] ) ? strtolower( $frame['name'] ) : '';
				if ( preg_match( $skip, $fname ) ) {
					continue;
				}
				if ( preg_match( $want, $fname ) ) {
					$wanted[] = $frame['id'];
				} else {
					$rest[] = $frame['id'];
				}
			}
		}
		$ids = $wanted ? $wanted : $rest;
		$ids = array_slice( array_values( array_unique( $ids ) ), 0, 30 );
		if ( empty( $ids ) ) {
			return array( 'colors' => array(), 'type' => array() );
		}

		// 4) Descargar cada marco POR SEPARADO con profundidad baja (depth=4) y
		//    recorrerlo. Uno a uno para que un marco grande no se sume a otros.
		$colors = array();
		$type   = array();
		foreach ( $ids as $fid ) {
			$nodes = self::figma_get(
				'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '/nodes?depth=4&ids=' . rawurlencode( $fid ),
				$token,
				30
			);
			if ( is_wp_error( $nodes ) || ! isset( $nodes['nodes'] ) ) {
				continue;
			}
			$stack = array();
			foreach ( $nodes['nodes'] as $payload ) {
				if ( isset( $payload['document'] ) ) {
					$stack[] = $payload['document'];
				}
			}
			$visited = 0;
			$cap     = 80000; // Tope de nodos por lote.
			while ( $stack && $visited < $cap ) {
				$node = array_pop( $stack );
				$visited++;
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
					foreach ( $node['fills'] as $fill ) {
						if ( isset( $fill['type'], $fill['color'] ) && 'SOLID' === $fill['type'] && ( ! isset( $fill['visible'] ) || $fill['visible'] ) ) {
							$colors[] = array(
								'name'  => isset( $node['name'] ) ? $node['name'] : '',
								'value' => self::rgba_to_hex( $fill['color'] ),
							);
						}
					}
				}
				if ( isset( $node['type'], $node['style'] ) && 'TEXT' === $node['type'] ) {
					$s      = $node['style'];
					$type[] = array(
						'name'   => isset( $node['name'] ) ? $node['name'] : '',
						'family' => isset( $s['fontFamily'] ) ? $s['fontFamily'] : '',
						'weight' => isset( $s['fontWeight'] ) ? $s['fontWeight'] : '',
						'size'   => isset( $s['fontSize'] ) ? (int) round( $s['fontSize'] ) : '',
					);
				}
				if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
					foreach ( $node['children'] as $child ) {
						$stack[] = $child;
					}
				}
			}
		}

		// Dedupe de colores por hex.
		$seen = array();
		$uc   = array();
		foreach ( $colors as $c ) {
			if ( ! isset( $seen[ $c['value'] ] ) ) {
				$seen[ $c['value'] ] = true;
				$uc[]                = $c;
			}
		}
		// Dedupe de tipografías por familia+peso+tamaño.
		$seen = array();
		$ut   = array();
		foreach ( $type as $t ) {
			$key = $t['family'] . '|' . $t['weight'] . '|' . $t['size'];
			if ( '||' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$ut[]         = $t;
			}
		}

		return array(
			'colors' => array_slice( $uc, 0, 60 ),
			'type'   => array_slice( $ut, 0, 40 ),
		);
	}

	/**
	 * GET autenticado a la API de Figma con manejo de errores.
	 *
	 * @param string $url     URL.
	 * @param string $token   Token.
	 * @param int    $timeout Timeout en segundos.
	 * @return array|WP_Error JSON decodificado o error.
	 */
	private static function figma_get( $url, $token, $timeout = 20 ) {
		// Rastro de progreso: si la petición mata el proceso, esto queda guardado
		// y se ve en el panel (indica en qué llamada se quedó).
		$short = preg_replace( '#https://api\.figma\.com/v1/files/[^/]+#', '/files/…', $url );
		update_option( 'doers_ai_figma_progress', substr( (string) $short, 0, 140 ) . ' @ ' . gmdate( 'H:i:s' ) . ' UTC', false );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'X-Figma-Token' => $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$len  = strlen( (string) wp_remote_retrieve_body( $response ) );
		update_option( 'doers_ai_figma_progress', substr( (string) $short, 0, 100 ) . ' → HTTP ' . $code . ', ' . size_format( $len ) . ' @ ' . gmdate( 'H:i:s' ) . ' UTC', false );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 403 === $code ) {
			return new WP_Error( 'doers_figma_403', 'Figma rechazó la petición (403): token sin permiso o endpoint no disponible en tu plan.' );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'doers_figma_404', 'Archivo de Figma no encontrado (404): revisa el file key.' );
		}
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $body['err'] ) ? $body['err'] : ( isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $code );
			return new WP_Error( 'doers_figma_http', 'Figma devolvió un error: ' . $msg );
		}
		return is_array( $body ) ? $body : new WP_Error( 'doers_figma_parse', 'Respuesta de Figma no válida.' );
	}

	/**
	 * Convierte un color {r,g,b,a} (0..1) de Figma a hex.
	 *
	 * @param array $c Color.
	 * @return string
	 */
	private static function rgba_to_hex( $c ) {
		$r = isset( $c['r'] ) ? (int) round( $c['r'] * 255 ) : 0;
		$g = isset( $c['g'] ) ? (int) round( $c['g'] * 255 ) : 0;
		$b = isset( $c['b'] ) ? (int) round( $c['b'] * 255 ) : 0;
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Ability: lee un nodo concreto de Figma (un bloque/sección) desde su enlace
	 * "Copy link" y devuelve su estructura de diseño + una imagen renderizada,
	 * para poder replicarlo como bloque del tema.
	 *
	 * @param array $input { link?:string, node_id?:string, file_key?:string }
	 * @return array|WP_Error
	 */
	public static function get_figma_node( $input = array() ) {
		$input = is_array( $input ) ? $input : array();
		$link  = isset( $input['link'] ) ? (string) $input['link'] : '';

		$file_key = isset( $input['file_key'] ) ? trim( (string) $input['file_key'] ) : '';
		$node     = isset( $input['node_id'] ) ? trim( (string) $input['node_id'] ) : '';

		// Extraer file_key y node-id del enlace si vienen ahí.
		if ( '' !== $link ) {
			if ( '' === $file_key && preg_match( '#figma\.com/(?:design|file|proto|board)/([A-Za-z0-9]+)#', $link, $m ) ) {
				$file_key = $m[1];
			}
			if ( '' === $node && preg_match( '#[?&]node-id=([^&]+)#', $link, $m ) ) {
				$node = rawurldecode( $m[1] );
			}
		}
		// El enlace usa guion (15-2462); la API usa dos puntos (15:2462).
		if ( '' !== $node && false === strpos( $node, ':' ) ) {
			$node = str_replace( '-', ':', $node );
		}

		// Sin file_key explícito, usar el del último sync.
		if ( '' === $file_key ) {
			$ctx      = self::get();
			$file_key = $ctx['figma']['file_key'];
		}

		$token = self::figma_token();
		if ( '' === $token ) {
			return new WP_Error( 'doers_figma_no_token', 'Falta el token de acceso de Figma (configúralo en Ajustes → Doers AI).' );
		}
		if ( '' === $file_key ) {
			return new WP_Error( 'doers_figma_no_key', 'No pude determinar el file key. Pega el enlace completo de Figma o sincroniza primero.' );
		}
		if ( '' === $node ) {
			return new WP_Error( 'doers_figma_no_node', 'El enlace no contiene un node-id. En Figma usa «Copy link» sobre el elemento concreto.' );
		}

		// Estructura del nodo (es un solo bloque: tamaño acotado).
		$resp = self::figma_get( 'https://api.figma.com/v1/files/' . rawurlencode( $file_key ) . '/nodes?ids=' . rawurlencode( $node ), $token, 30 );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$doc = null;
		if ( isset( $resp['nodes'][ $node ]['document'] ) ) {
			$doc = $resp['nodes'][ $node ]['document'];
		} elseif ( ! empty( $resp['nodes'] ) ) {
			$first = reset( $resp['nodes'] );
			$doc   = isset( $first['document'] ) ? $first['document'] : null;
		}
		if ( ! is_array( $doc ) ) {
			return new WP_Error( 'doers_figma_node_missing', 'No encontré ese nodo en el archivo. Revisa que el enlace apunte a un elemento de ESTE archivo de Figma.' );
		}

		// Imagen renderizada (URL pública temporal de Figma).
		$image_url = '';
		$img       = self::figma_get( 'https://api.figma.com/v1/images/' . rawurlencode( $file_key ) . '?format=png&scale=2&ids=' . rawurlencode( $node ), $token, 30 );
		if ( ! is_wp_error( $img ) && isset( $img['images'][ $node ] ) ) {
			$image_url = (string) $img['images'][ $node ];
		}

		return array(
			'file_key'  => $file_key,
			'node_id'   => $node,
			'name'      => isset( $doc['name'] ) ? $doc['name'] : '',
			'image_url' => $image_url,
			'spec'      => self::trim_figma_node( $doc, 0 ),
			'guidance'  => 'Replica este elemento como bloque/sección del tema siguiendo las convenciones del proyecto (revisa CLAUDE.md y docs/crear-nuevo-bloque.md). Usa los tokens de color y tipografía del contexto. La imagen (image_url) es el render real del bloque.',
		);
	}

	/**
	 * Reduce un nodo de Figma a sus propiedades de diseño relevantes (recursivo,
	 * con tope de profundidad para no recorrer estructuras patológicas).
	 *
	 * @param array $node  Nodo.
	 * @param int   $depth Profundidad actual.
	 * @return array
	 */
	private static function trim_figma_node( $node, $depth ) {
		$out = array(
			'type' => isset( $node['type'] ) ? $node['type'] : '',
			'name' => isset( $node['name'] ) ? $node['name'] : '',
		);

		if ( isset( $node['type'] ) && 'TEXT' === $node['type'] ) {
			if ( isset( $node['characters'] ) ) {
				$out['text'] = mb_substr( (string) $node['characters'], 0, 400 );
			}
			if ( isset( $node['style'] ) ) {
				$s          = $node['style'];
				$out['font'] = array_filter(
					array(
						'family'      => isset( $s['fontFamily'] ) ? $s['fontFamily'] : '',
						'weight'      => isset( $s['fontWeight'] ) ? $s['fontWeight'] : '',
						'size'        => isset( $s['fontSize'] ) ? (int) round( $s['fontSize'] ) : '',
						'line_height' => isset( $s['lineHeightPx'] ) ? (int) round( $s['lineHeightPx'] ) : '',
						'align'       => isset( $s['textAlignHorizontal'] ) ? $s['textAlignHorizontal'] : '',
						'transform'   => isset( $s['textCase'] ) ? $s['textCase'] : '',
					),
					function ( $v ) {
						return '' !== $v && null !== $v;
					}
				);
			}
		}

		// Colores de relleno sólidos.
		if ( isset( $node['fills'] ) && is_array( $node['fills'] ) ) {
			$colors = array();
			foreach ( $node['fills'] as $fill ) {
				if ( isset( $fill['type'], $fill['color'] ) && 'SOLID' === $fill['type'] && ( ! isset( $fill['visible'] ) || $fill['visible'] ) ) {
					$colors[] = self::rgba_to_hex( $fill['color'] );
				} elseif ( isset( $fill['type'] ) && 'IMAGE' === $fill['type'] ) {
					$colors[] = 'image';
				}
			}
			if ( $colors ) {
				$out['fill'] = implode( ', ', array_unique( $colors ) );
			}
		}

		// Auto-layout, espaciado, padding, radio, tamaño.
		if ( isset( $node['layoutMode'] ) && 'NONE' !== $node['layoutMode'] ) {
			$out['layout'] = strtolower( $node['layoutMode'] ); // horizontal | vertical.
			if ( isset( $node['itemSpacing'] ) ) {
				$out['gap'] = (int) round( $node['itemSpacing'] );
			}
			$pads = array();
			foreach ( array( 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ) as $p ) {
				$pads[] = isset( $node[ $p ] ) ? (int) round( $node[ $p ] ) : 0;
			}
			if ( array_filter( $pads ) ) {
				$out['padding'] = implode( ' ', $pads );
			}
		}
		if ( isset( $node['cornerRadius'] ) && $node['cornerRadius'] ) {
			$out['radius'] = (int) round( $node['cornerRadius'] );
		}
		if ( isset( $node['absoluteBoundingBox']['width'], $node['absoluteBoundingBox']['height'] ) ) {
			$out['size'] = (int) round( $node['absoluteBoundingBox']['width'] ) . '×' . (int) round( $node['absoluteBoundingBox']['height'] );
		}

		// Hijos (con tope de profundidad y de cantidad).
		if ( $depth < 22 && isset( $node['children'] ) && is_array( $node['children'] ) ) {
			$children = array();
			$count    = 0;
			foreach ( $node['children'] as $child ) {
				if ( $count++ >= 120 ) {
					break;
				}
				if ( is_array( $child ) ) {
					$children[] = self::trim_figma_node( $child, $depth + 1 );
				}
			}
			if ( $children ) {
				$out['children'] = $children;
			}
		}

		return $out;
	}
}
