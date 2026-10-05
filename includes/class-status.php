<?php
/**
 * Estado de la web, leído de la propia web (sin conectar con nada de Dox):
 * certificado SSL, si WordPress, temas y plugins están al día y el último cambio
 * publicado. Pensado para el cliente: frases claras, sin listas técnicas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Status {

	/** Días que le quedan al certificado SSL, o null si no se pudo leer. */
	public static function ssl_days() {
		$cached = get_transient( 'dox_care_ssl' );
		if ( $cached !== false ) {
			return $cached === 'none' ? null : (int) $cached;
		}

		$days = null;
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host && function_exists( 'stream_socket_client' ) && function_exists( 'openssl_x509_parse' ) ) {
			$ctx = stream_context_create( [ 'ssl' => [ 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host ] ] );
			$fp  = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx );
			if ( $fp ) {
				$params = stream_context_get_params( $fp );
				$cert   = $params['options']['ssl']['peer_certificate'] ?? null;
				if ( $cert ) {
					$info = openssl_x509_parse( $cert );
					if ( ! empty( $info['validTo_time_t'] ) ) {
						$days = (int) floor( ( $info['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
					}
				}
				fclose( $fp );
			}
		}
		set_transient( 'dox_care_ssl', $days === null ? 'none' : $days, 12 * HOUR_IN_SECONDS );
		return $days;
	}

	/** Cuántas actualizaciones hay pendientes (núcleo, temas y plugins). */
	public static function pending_updates() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		$count = 0;
		$core  = get_core_updates();
		if ( is_array( $core ) ) {
			foreach ( $core as $u ) {
				if ( isset( $u->response ) && $u->response === 'upgrade' ) {
					$count++;
					break;
				}
			}
		}
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$count  += ! empty( $plugins->response ) ? count( $plugins->response ) : 0;
		$count  += ! empty( $themes->response ) ? count( $themes->response ) : 0;
		return $count;
	}

	/** Fecha del último cambio en páginas o entradas publicadas. */
	public static function last_change() {
		global $wpdb;
		$date = $wpdb->get_var( "SELECT MAX(post_modified) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post','product')" );
		return $date ? strtotime( $date ) : null;
	}

	/**
	 * Las tres líneas del estado, listas para pintar.
	 * Cada una: ok (bool), title, detail.
	 */
	public static function items() {
		$items = [];

		$days = self::ssl_days();
		if ( $days === null ) {
			$items[] = [ 'ok' => is_ssl(), 'title' => __( 'Secure connection (SSL)', 'dox-care' ), 'detail' => is_ssl() ? __( 'Active', 'dox-care' ) : __( 'We are checking it', 'dox-care' ) ];
		} else {
			$items[] = [
				'ok'     => $days > 7,
				'title'  => __( 'Secure connection (SSL)', 'dox-care' ),
				/* translators: %s: date the SSL certificate renews */
				'detail' => $days > 7 ? sprintf( __( 'Active, renews automatically before %s', 'dox-care' ), Dox_Care_Settings::date( time() + $days * DAY_IN_SECONDS ) ) : __( 'Renewing in the next few days', 'dox-care' ),
			];
		}

		$pending = self::pending_updates();
		$items[] = [
			'ok'     => true,
			'title'  => __( 'WordPress, theme and plugins', 'dox-care' ),
			'detail' => $pending === 0 ? __( 'Everything is up to date', 'dox-care' ) : __( 'We apply the pending updates in your next maintenance', 'dox-care' ),
		];

		$last    = self::last_change();
		$items[] = [
			'ok'     => true,
			'title'  => __( 'Last published change', 'dox-care' ),
			'detail' => $last ? Dox_Care_Settings::date( $last ) : __( 'No changes yet', 'dox-care' ),
		];

		return $items;
	}
}
