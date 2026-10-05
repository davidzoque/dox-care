<?php
/**
 * Al borrar Dox Care desde Plugins se quita lo del acceso con código: la tabla de los
 * topes, su versión y los códigos pendientes. Lo demás (ajustes, contador y solicitudes)
 * se queda, como hasta ahora: es el registro del cliente.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dox_care_limits" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'dox_care_db' );
delete_metadata( 'user', 0, 'dox_care_login_code', '', true );
