<?php
/**
 * Servidor OAuth 2.1 para conexión directa de clientes MCP remotos (claude.ai).
 *
 * Implementa el flujo de autorización requerido por la especificación MCP:
 * - RFC 9728: Protected Resource Metadata (/.well-known/oauth-protected-resource)
 * - RFC 8414: Authorization Server Metadata (/.well-known/oauth-authorization-server)
 * - RFC 7591: Dynamic Client Registration
 * - Authorization Code + PKCE (S256) con rotación de refresh tokens
 * - Validación Bearer en el endpoint MCP (limitada a ese namespace)
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Servidor OAuth.
 */
class Doers_OAuth {

	const CLIENTS_OPTION = 'doers_ai_oauth_clients';
	const TOKENS_OPTION  = 'doers_ai_oauth_tokens';
	const ACCESS_TTL     = 3600;          // 1 hora.
	const REFRESH_TTL    = 2592000;       // 30 días.
	const CODE_TTL       = 120;           // 2 minutos.

	/**
	 * Engancha todo.
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle_well_known' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle_authorize' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer' ), 20 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_www_authenticate_header' ), 10, 3 );
	}

	/**
	 * ¿OAuth habilitado en ajustes?
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = Doers_Settings::get();
		return ! empty( $settings['oauth_enabled'] );
	}

	// ------------------------------------------------------------------
	// Descubrimiento (.well-known)
	// ------------------------------------------------------------------

	/**
	 * Sirve los documentos de metadatos OAuth.
	 */
	public static function maybe_handle_well_known() {
		if ( ! self::enabled() ) {
			return;
		}
		$path = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore
		if ( ! $path || 0 !== strpos( $path, '/.well-known/oauth-' ) ) {
			return;
		}

		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) ) {
			self::send_json( self::authorization_server_metadata() );
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			self::send_json( self::protected_resource_metadata() );
		}
	}

	/**
	 * Metadatos del servidor de autorización (RFC 8414).
	 *
	 * @return array
	 */
	private static function authorization_server_metadata() {
		return array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                => home_url( '/?doers_oauth=authorize' ),
			'token_endpoint'                        => rest_url( 'doers-ai/oauth/token' ),
			'registration_endpoint'                 => rest_url( 'doers-ai/oauth/register' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'scopes_supported'                      => array( 'mcp' ),
		);
	}

	/**
	 * Metadatos del recurso protegido (RFC 9728).
	 *
	 * @return array
	 */
	private static function protected_resource_metadata() {
		return array(
			'resource'                 => rest_url( 'doers-ai/mcp' ),
			'authorization_servers'    => array( home_url( '/' ) ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
		);
	}

	// ------------------------------------------------------------------
	// Registro dinámico de clientes (RFC 7591)
	// ------------------------------------------------------------------

	/**
	 * Rutas REST: registro y token.
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'doers-ai',
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_register' ),
			)
		);
		register_rest_route(
			'doers-ai',
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_token' ),
			)
		);
	}

	/**
	 * Registro dinámico de cliente.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public static function handle_register( $request ) {
		if ( ! self::enabled() ) {
			return new WP_REST_Response( array( 'error' => 'access_denied' ), 403 );
		}

		$body          = $request->get_json_params();
		$redirect_uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : array();

		$valid_uris = array();
		foreach ( $redirect_uris as $uri ) {
			if ( self::redirect_uri_allowed( $uri ) ) {
				$valid_uris[] = esc_url_raw( $uri );
			}
		}
		if ( ! $valid_uris ) {
			return new WP_REST_Response(
				array(
					'error'             => 'invalid_redirect_uri',
					'error_description' => 'Se requiere al menos una redirect_uri https (o localhost).',
				),
				400
			);
		}

		$client_id = 'doers_' . wp_generate_password( 32, false, false );
		$clients   = get_option( self::CLIENTS_OPTION, array() );

		$clients[ $client_id ] = array(
			'name'          => isset( $body['client_name'] ) ? sanitize_text_field( $body['client_name'] ) : 'Cliente MCP',
			'redirect_uris' => $valid_uris,
			'created'       => time(),
		);
		update_option( self::CLIENTS_OPTION, $clients, false );

		return new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_id_issued_at'        => time(),
				'client_name'                => $clients[ $client_id ]['name'],
				'redirect_uris'              => $valid_uris,
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
			),
			201
		);
	}

	/**
	 * ¿redirect_uri permitida? https siempre; http solo loopback (RFC 8252).
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	private static function redirect_uri_allowed( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( 'https' === $parts['scheme'] ) {
			return true;
		}
		return 'http' === $parts['scheme'] && in_array( $parts['host'], array( 'localhost', '127.0.0.1', '[::1]' ), true );
	}

	// ------------------------------------------------------------------
	// Autorización (consentimiento)
	// ------------------------------------------------------------------

	/**
	 * Punto de entrada del endpoint de autorización (?doers_oauth=authorize).
	 */
	public static function maybe_handle_authorize() {
		if ( ! isset( $_GET['doers_oauth'] ) || 'authorize' !== $_GET['doers_oauth'] ) { // phpcs:ignore
			return;
		}
		if ( ! self::enabled() ) {
			wp_die( 'OAuth deshabilitado en este sitio.' );
		}

		// Exigir sesión de WordPress.
		if ( ! is_user_logged_in() ) {
			$current_url = home_url( add_query_arg( null, null ) );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		$client_id     = isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : ''; // phpcs:ignore
		$redirect_uri  = isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : ''; // phpcs:ignore
		$state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore
		$challenge     = isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : ''; // phpcs:ignore
		$method        = isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : ''; // phpcs:ignore
		$response_type = isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : ''; // phpcs:ignore

		$clients = get_option( self::CLIENTS_OPTION, array() );
		if ( ! isset( $clients[ $client_id ] ) ) {
			wp_die( 'OAuth: client_id desconocido.' );
		}
		if ( ! in_array( $redirect_uri, $clients[ $client_id ]['redirect_uris'], true ) ) {
			wp_die( 'OAuth: redirect_uri no registrada para este cliente.' );
		}
		if ( 'code' !== $response_type || 'S256' !== $method || '' === $challenge ) {
			self::redirect_error( $redirect_uri, $state, 'invalid_request', 'Se requiere response_type=code y PKCE S256.' );
		}

		// ¿El usuario ha respondido al formulario?
		if ( isset( $_POST['doers_oauth_decision'] ) ) { // phpcs:ignore
			check_admin_referer( 'doers_oauth_authorize' );

			if ( 'approve' !== $_POST['doers_oauth_decision'] ) { // phpcs:ignore
				self::redirect_error( $redirect_uri, $state, 'access_denied', 'El usuario denegó el acceso.' );
			}

			$code = wp_generate_password( 48, false, false );
			set_transient(
				'doers_oauth_code_' . md5( $code ),
				array(
					'client_id'      => $client_id,
					'redirect_uri'   => $redirect_uri,
					'code_challenge' => $challenge,
					'user_id'        => get_current_user_id(),
				),
				self::CODE_TTL
			);

			$location = add_query_arg(
				array(
					'code'  => rawurlencode( $code ),
					'state' => rawurlencode( $state ),
					'iss'   => rawurlencode( home_url( '/' ) ),
				),
				$redirect_uri
			);
			wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect
			exit;
		}

		self::render_consent_screen( $clients[ $client_id ]['name'] );
	}

	/**
	 * Redirige al cliente con un error OAuth.
	 *
	 * @param string $redirect_uri URI.
	 * @param string $state        Estado.
	 * @param string $error        Código.
	 * @param string $description  Descripción.
	 */
	private static function redirect_error( $redirect_uri, $state, $error, $description ) {
		$location = add_query_arg(
			array(
				'error'             => rawurlencode( $error ),
				'error_description' => rawurlencode( $description ),
				'state'             => rawurlencode( $state ),
			),
			$redirect_uri
		);
		wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Pantalla de consentimiento.
	 *
	 * @param string $client_name Nombre del cliente.
	 */
	private static function render_consent_screen( $client_name ) {
		$user = wp_get_current_user();
		status_header( 200 );
		nocache_headers();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Autorizar acceso — <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f0f0f1; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
		.card { background:#fff; border:1px solid #dcdcde; border-radius:12px; padding:32px; max-width:420px; width:100%; box-shadow:0 1px 3px rgba(0,0,0,.06); }
		h1 { font-size:1.25rem; margin:0 0 8px; }
		p { color:#50575e; line-height:1.5; }
		ul { color:#50575e; padding-left:20px; }
		.buttons { display:flex; gap:10px; margin-top:24px; }
		button { flex:1; padding:10px 16px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; border:1px solid #dcdcde; background:#fff; }
		button.approve { background:#1d2327; color:#fff; border-color:#1d2327; }
	</style>
</head>
<body>
	<form class="card" method="post">
		<?php wp_nonce_field( 'doers_oauth_authorize' ); ?>
		<h1><?php echo esc_html( $client_name ); ?> quiere acceder a <?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
		<p>Conectado como <strong><?php echo esc_html( $user->user_login ); ?></strong>. Si autorizas, este cliente de IA podrá actuar en tu nombre con tus permisos a través del conector Doers AI:</p>
		<ul>
			<li>Leer y modificar contenido, temas y plugins (según los grupos habilitados en Ajustes → Doers AI).</li>
			<li>Toda la actividad quedará registrada en la auditoría.</li>
		</ul>
		<div class="buttons">
			<button type="submit" name="doers_oauth_decision" value="deny">Denegar</button>
			<button type="submit" name="doers_oauth_decision" value="approve" class="approve">Autorizar</button>
		</div>
	</form>
</body>
</html>
		<?php
		exit;
	}

	// ------------------------------------------------------------------
	// Endpoint de token
	// ------------------------------------------------------------------

	/**
	 * Intercambio de código y refresh.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public static function handle_token( $request ) {
		if ( ! self::enabled() ) {
			return self::token_error( 'access_denied', 'OAuth deshabilitado.', 403 );
		}

		$grant = $request->get_param( 'grant_type' );

		if ( 'authorization_code' === $grant ) {
			return self::grant_authorization_code( $request );
		}
		if ( 'refresh_token' === $grant ) {
			return self::grant_refresh_token( $request );
		}
		return self::token_error( 'unsupported_grant_type', 'grant_type no soportado.' );
	}

	/**
	 * Grant: authorization_code + PKCE.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	private static function grant_authorization_code( $request ) {
		$code     = (string) $request->get_param( 'code' );
		$verifier = (string) $request->get_param( 'code_verifier' );
		$key      = 'doers_oauth_code_' . md5( $code );
		$data     = get_transient( $key );

		if ( ! $code || ! is_array( $data ) ) {
			return self::token_error( 'invalid_grant', 'Código no válido o caducado.' );
		}
		delete_transient( $key ); // Un solo uso.

		if ( $request->get_param( 'client_id' ) && $request->get_param( 'client_id' ) !== $data['client_id'] ) {
			return self::token_error( 'invalid_grant', 'client_id no coincide.' );
		}
		if ( $request->get_param( 'redirect_uri' ) && $request->get_param( 'redirect_uri' ) !== $data['redirect_uri'] ) {
			return self::token_error( 'invalid_grant', 'redirect_uri no coincide.' );
		}

		// PKCE S256.
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		if ( ! $verifier || ! hash_equals( $data['code_challenge'], $expected ) ) {
			return self::token_error( 'invalid_grant', 'Verificación PKCE fallida.' );
		}

		return self::issue_tokens( (int) $data['user_id'], $data['client_id'] );
	}

	/**
	 * Grant: refresh_token (con rotación).
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	private static function grant_refresh_token( $request ) {
		$refresh = (string) $request->get_param( 'refresh_token' );
		$tokens  = self::all_tokens();
		$hash    = hash( 'sha256', $refresh );

		if ( ! $refresh || ! isset( $tokens['refresh'][ $hash ] ) ) {
			return self::token_error( 'invalid_grant', 'refresh_token no válido.' );
		}
		$row = $tokens['refresh'][ $hash ];
		if ( $row['expires'] < time() ) {
			unset( $tokens['refresh'][ $hash ] );
			self::save_tokens( $tokens );
			return self::token_error( 'invalid_grant', 'refresh_token caducado.' );
		}

		// Rotación: invalidar el refresh usado.
		unset( $tokens['refresh'][ $hash ] );
		self::save_tokens( $tokens );

		return self::issue_tokens( (int) $row['user_id'], $row['client_id'] );
	}

	/**
	 * Emite access + refresh token para un usuario/cliente.
	 *
	 * @param int    $user_id   Usuario.
	 * @param string $client_id Cliente.
	 * @return WP_REST_Response
	 */
	private static function issue_tokens( $user_id, $client_id ) {
		if ( ! get_user_by( 'id', $user_id ) ) {
			return self::token_error( 'invalid_grant', 'El usuario ya no existe.' );
		}

		$access  = wp_generate_password( 64, false, false );
		$refresh = wp_generate_password( 64, false, false );
		$tokens  = self::all_tokens();

		$tokens['access'][ hash( 'sha256', $access ) ] = array(
			'user_id'   => $user_id,
			'client_id' => $client_id,
			'expires'   => time() + self::ACCESS_TTL,
		);
		$tokens['refresh'][ hash( 'sha256', $refresh ) ] = array(
			'user_id'   => $user_id,
			'client_id' => $client_id,
			'expires'   => time() + self::REFRESH_TTL,
		);
		self::save_tokens( $tokens );

		return new WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => 'mcp',
			),
			200
		);
	}

	/**
	 * Respuesta de error del endpoint de token.
	 *
	 * @param string $error       Código.
	 * @param string $description Descripción.
	 * @param int    $status      HTTP status.
	 * @return WP_REST_Response
	 */
	private static function token_error( $error, $description, $status = 400 ) {
		return new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	// ------------------------------------------------------------------
	// Autenticación Bearer del endpoint MCP
	// ------------------------------------------------------------------

	/**
	 * Autentica peticiones al namespace doers-ai mediante Bearer token.
	 *
	 * @param int|false $user_id Usuario ya determinado.
	 * @return int|false
	 */
	public static function authenticate_bearer( $user_id ) {
		if ( $user_id || ! self::enabled() ) {
			return $user_id;
		}

		// Solo para peticiones REST a nuestro namespace MCP.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		if ( false === strpos( $uri, 'doers-ai/mcp' ) ) {
			return $user_id;
		}

		$header = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore
		}
		if ( 0 !== stripos( $header, 'Bearer ' ) ) {
			return $user_id;
		}

		$token  = trim( substr( $header, 7 ) );
		$tokens = self::all_tokens();
		$hash   = hash( 'sha256', $token );

		if ( ! isset( $tokens['access'][ $hash ] ) ) {
			return $user_id;
		}
		$row = $tokens['access'][ $hash ];
		if ( $row['expires'] < time() ) {
			return $user_id;
		}

		return (int) $row['user_id'];
	}

	/**
	 * Añade WWW-Authenticate a los 401 del endpoint MCP (descubrimiento RFC 9728).
	 *
	 * @param WP_REST_Response $result  Respuesta.
	 * @param WP_REST_Server   $server  Servidor.
	 * @param WP_REST_Request  $request Petición.
	 * @return WP_REST_Response
	 */
	public static function add_www_authenticate_header( $result, $server, $request ) {
		if ( ! self::enabled() || ! $result instanceof WP_REST_Response ) {
			return $result;
		}
		if ( 0 !== strpos( $request->get_route(), '/doers-ai/mcp' ) ) {
			return $result;
		}
		if ( ! in_array( $result->get_status(), array( 401, 403 ), true ) ) {
			return $result;
		}
		$metadata_url = home_url( '/.well-known/oauth-protected-resource/wp-json/doers-ai/mcp' );
		$result->header( 'WWW-Authenticate', 'Bearer resource_metadata="' . $metadata_url . '"' );
		return $result;
	}

	// ------------------------------------------------------------------
	// Almacenamiento y administración
	// ------------------------------------------------------------------

	/**
	 * Devuelve los tokens (con purga de caducados).
	 *
	 * @return array{access:array,refresh:array}
	 */
	private static function all_tokens() {
		$tokens = get_option( self::TOKENS_OPTION, array() );
		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}
		$tokens += array( 'access' => array(), 'refresh' => array() );

		$now     = time();
		$changed = false;
		foreach ( array( 'access', 'refresh' ) as $type ) {
			foreach ( $tokens[ $type ] as $hash => $row ) {
				if ( $row['expires'] < $now ) {
					unset( $tokens[ $type ][ $hash ] );
					$changed = true;
				}
			}
		}
		if ( $changed ) {
			self::save_tokens( $tokens );
		}
		return $tokens;
	}

	/**
	 * Guarda tokens.
	 *
	 * @param array $tokens Tokens.
	 */
	private static function save_tokens( $tokens ) {
		update_option( self::TOKENS_OPTION, $tokens, false );
	}

	/**
	 * Lista de clientes registrados (para el panel).
	 *
	 * @return array
	 */
	public static function clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Nº de tokens de acceso vivos.
	 *
	 * @return int
	 */
	public static function active_token_count() {
		$tokens = self::all_tokens();
		return count( $tokens['access'] );
	}

	/**
	 * Revoca un cliente y todos sus tokens.
	 *
	 * @param string $client_id Cliente.
	 */
	public static function revoke_client( $client_id ) {
		$clients = self::clients();
		unset( $clients[ $client_id ] );
		update_option( self::CLIENTS_OPTION, $clients, false );

		$tokens = self::all_tokens();
		foreach ( array( 'access', 'refresh' ) as $type ) {
			foreach ( $tokens[ $type ] as $hash => $row ) {
				if ( $row['client_id'] === $client_id ) {
					unset( $tokens[ $type ][ $hash ] );
				}
			}
		}
		self::save_tokens( $tokens );
	}

	/**
	 * Revoca todos los tokens.
	 */
	public static function revoke_all_tokens() {
		self::save_tokens( array( 'access' => array(), 'refresh' => array() ) );
	}

	/**
	 * Envía JSON y termina.
	 *
	 * @param array $data Datos.
	 */
	private static function send_json( $data ) {
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data );
		exit;
	}
}
