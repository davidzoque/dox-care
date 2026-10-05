<?php
/**
 * Aviso central: un JSON público en doxstudio.com que leen todas las webs con Dox
 * Care, para publicar un aviso a todos los clientes a la vez (mantenimiento del
 * servidor, una novedad) o cambiar los precios de los planes sin tocar cada web.
 *
 * Formato:
 * {
 *   "notices": [
 *     { "id": "2026-10-servidor", "type": "info|warning|success",
 *       "title": { "es": "...", "en": "..." }, "text": { "es": "...", "en": "..." },
 *       "url": "https://...", "until": "2026-10-12" }
 *   ],
 *   "prices": { "pro": 97, "elite": 137 }
 * }
 *
 * Lo pide el cron de WordPress dos veces al día y se guarda en una opción: el
 * escritorio pinta siempre lo guardado y nunca espera a doxstudio.com. Si no se puede
 * leer, se queda lo último que se leyó bien (o nada, y el escritorio sale sin avisos).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Feed {

	const OPTION = 'dox_care_feed';
	const HOOK   = 'dox_care_feed_refresh';

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'refresh' ] );
		// Se programa aquí y no al activar: las actualizaciones no pasan por la activación.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK );
			delete_transient( 'dox_care_feed' ); // Lo que guardaba la 0.2.1.
		}
	}

	public static function refresh() {
		$res = wp_remote_get( DOX_CARE_FEED, [ 'timeout' => 10 ] );
		if ( is_wp_error( $res ) ) {
			return;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code === 200 ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) ) {
				update_option( self::OPTION, $json, false );
			}
		} elseif ( $code === 404 ) {
			// El archivo ya no está: no hay avisos que mostrar.
			update_option( self::OPTION, [], false );
		}
	}

	public static function get() {
		$data = get_option( self::OPTION, [] );
		return is_array( $data ) ? $data : [];
	}

	/** Avisos vigentes, ya en el idioma del panel. */
	public static function notices() {
		$feed = self::get();
		$out  = [];
		$lang = Dox_Care_Settings::language();
		foreach ( (array) ( $feed['notices'] ?? [] ) as $n ) {
			if ( ! is_array( $n ) ) {
				continue;
			}
			if ( ! empty( $n['until'] ) && strtotime( $n['until'] . ' 23:59:59' ) < time() ) {
				continue;
			}
			$pick = function ( $v ) use ( $lang ) {
				if ( is_array( $v ) ) {
					return (string) ( $v[ $lang ] ?? $v['en'] ?? reset( $v ) );
				}
				return (string) $v;
			};
			$title = $pick( $n['title'] ?? '' );
			if ( $title === '' ) {
				continue;
			}
			$out[] = [
				'type'  => in_array( $n['type'] ?? '', [ 'info', 'warning', 'success' ], true ) ? $n['type'] : 'info',
				'title' => $title,
				'text'  => $pick( $n['text'] ?? '' ),
				'url'   => esc_url_raw( $n['url'] ?? '' ),
			];
		}
		return $out;
	}
}
