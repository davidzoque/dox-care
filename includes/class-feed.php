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
 * Se guarda 6 horas. Si no se puede leer, el escritorio sigue igual sin avisos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Feed {

	const CACHE = 'dox_care_feed';

	public static function get() {
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = [];
		$res  = wp_remote_get( DOX_CARE_FEED, [ 'timeout' => 4 ] );
		if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) ) {
				$data = $json;
			}
		}
		// Aunque falle, se guarda vacío un rato para no preguntar en cada carga.
		set_transient( self::CACHE, $data, is_array( $json ?? null ) ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
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
