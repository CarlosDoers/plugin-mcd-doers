<?php
/**
 * Limpieza al desinstalar.
 *
 * @package DoersAI
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'doers_ai_settings' );
delete_option( 'doers_ai_audit_log' );
delete_option( 'doers_ai_oauth_clients' );
delete_option( 'doers_ai_oauth_tokens' );

// Nota: no se eliminan los backups de uploads/doers-ai-backups (son del usuario),
// ni el usuario "doers-ai" (podría tener contenido atribuido). El rol sí se retira.
remove_role( 'doers_ai_agent' );
