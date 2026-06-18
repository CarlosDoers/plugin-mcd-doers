<?php
/**
 * Plugin Name: Doers AI Connector
 * Plugin URI: https://doersdf.com
 * Description: Conecta tu WordPress a asistentes de IA (Claude y otros clientes MCP). Registra abilities de desarrollo y gestión de contenido y las expone como servidor MCP propio mediante el MCP Adapter oficial.
 * Version: 0.9.0
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Doers
 * Author URI: https://doersdf.com
 * License: GPL-2.0-or-later
 * Text Domain: doers-ai
 *
 * @package DoersAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOERS_AI_VERSION', '0.9.0' );
define( 'DOERS_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOERS_AI_SERVER_ID', 'doers-ai' );

require_once DOERS_AI_DIR . 'includes/class-doers-settings.php';
require_once DOERS_AI_DIR . 'includes/class-doers-audit.php';
require_once DOERS_AI_DIR . 'includes/class-doers-blocks.php';
require_once DOERS_AI_DIR . 'includes/class-doers-content.php';
require_once DOERS_AI_DIR . 'includes/class-doers-media.php';
require_once DOERS_AI_DIR . 'includes/class-doers-abilities.php';
require_once DOERS_AI_DIR . 'includes/class-doers-oauth.php';
require_once DOERS_AI_DIR . 'includes/class-doers-admin.php';

// Servidor OAuth 2.1 para clientes MCP remotos (claude.ai).
Doers_OAuth::init();

/**
 * ¿Están las dependencias disponibles?
 *
 * @return array{abilities:bool,adapter:bool}
 */
function doers_ai_dependencies() {
	return array(
		'abilities' => function_exists( 'wp_register_ability' ),
		'adapter'   => class_exists( '\WP\MCP\Core\McpAdapter' ),
	);
}

// Aviso en admin si falta alguna dependencia.
add_action( 'admin_notices', function () {
	$deps = doers_ai_dependencies();
	if ( $deps['abilities'] && $deps['adapter'] ) {
		return;
	}

	// En la propia página de ajustes no hace falta el aviso: ahí está el instalador.
	if ( isset( $_GET['page'] ) && 'doers-ai' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	if ( ! $deps['abilities'] ) {
		printf(
			'<div class="notice notice-error"><p><strong>Doers AI Connector:</strong> %s</p></div>',
			esc_html__( 'requiere la Abilities API (WordPress 6.9 o superior). Actualiza WordPress para poder usarlo.', 'doers-ai' )
		);
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>Doers AI Connector:</strong> %s <a href="%s">%s</a> %s</p></div>',
		esc_html__( 'queda un paso para terminar la configuración:', 'doers-ai' ),
		esc_url( admin_url( 'options-general.php?page=doers-ai' ) ),
		esc_html__( 've a Ajustes → Doers AI', 'doers-ai' ),
		esc_html__( 'y pulsa «Instalar automáticamente» para añadir el MCP Adapter con un clic.', 'doers-ai' )
	);
} );

// Registrar abilities cuando la Abilities API esté lista.
add_action( 'wp_abilities_api_init', array( 'Doers_Abilities', 'register' ) );

// Asegurar que el adapter se inicializa (cuando se usa como paquete Composer no se auto-inicializa).
add_action( 'plugins_loaded', function () {
	if ( class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		\WP\MCP\Core\McpAdapter::instance();
	}
}, 20 );

// Crear el servidor MCP propio con las abilities de Doers.
add_action( 'mcp_adapter_init', function ( $adapter ) {
	$adapter->create_server(
		DOERS_AI_SERVER_ID,                 // ID único del servidor.
		'doers-ai',                         // Namespace REST.
		'mcp',                              // Ruta REST: /wp-json/doers-ai/mcp.
		'Doers AI Connector',               // Nombre.
		'Servidor MCP de Doers: desarrollo de temas, contenido y gestión del sitio.', // Descripción.
		'v' . DOERS_AI_VERSION,             // Versión.
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		Doers_Abilities::names(),           // Abilities expuestas como tools.
		array(),                            // Resources.
		array()                             // Prompts.
	);
} );

// Página de ajustes.
add_action( 'admin_menu', array( 'Doers_Admin', 'register_menu' ) );
