<?php
/**
 * Uninstall Agent Token: remove every option and transient this plugin
 * created, leaving nothing behind in the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'agent_token_settings' );

// Remove any one-time plain-token transients that may still be pending.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_agent_token_plain_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_agent_token_plain_' ) . '%'
	)
);
