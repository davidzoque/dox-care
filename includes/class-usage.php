<?php
/**
 * Contador de actualizaciones del mes, según los Términos del Servicio (sección F):
 * cada solicitud del formulario cuenta 1 por defecto; Dox Studio la puede ajustar
 * (0 si era un error, 2 si eran dos páginas) y añadir a mano las que lleguen por
 * correo o ticket. Se cuenta por mes calendario, en la zona horaria de la web, y
 * no se acumula. Se guardan los últimos 12 meses en `dox_care_usage`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Usage {

	const OPTION    = 'dox_care_usage';
	const MONTH_MAX = 300; // Entradas por mes: de sobra para lo legítimo, y la opción no crece sin fin.

	/** Actualizaciones incluidas al mes por plan; null = ilimitadas. */
	public static function limit() {
		$limits = [ 'essentials' => 3, 'pro' => null, 'elite' => null ];
		$plan   = Dox_Care_Settings::get( 'plan' );
		return array_key_exists( $plan, $limits ) ? $limits[ $plan ] : 3;
	}

	public static function month_key( $time = null ) {
		return wp_date( 'Y-m', $time ?: time() );
	}

	private static function all() {
		$data = get_option( self::OPTION, [] );
		return is_array( $data ) ? $data : [];
	}

	private static function save( $data ) {
		krsort( $data );
		update_option( self::OPTION, array_slice( $data, 0, 12, true ), false );
	}

	public static function entries( $month = null ) {
		$data = self::all();
		return $data[ $month ?: self::month_key() ] ?? [];
	}

	public static function used( $month = null ) {
		$sum = 0;
		foreach ( self::entries( $month ) as $e ) {
			$sum += (int) ( $e['units'] ?? 0 );
		}
		return $sum;
	}

	/** Meses con registros, del más nuevo al más viejo. */
	public static function months() {
		$keys = array_keys( self::all() );
		if ( ! in_array( self::month_key(), $keys, true ) ) {
			array_unshift( $keys, self::month_key() );
		}
		rsort( $keys );
		return $keys;
	}

	/**
	 * Añade una entrada. $entry: source (form|manual), page, message, units, time.
	 * Devuelve su id, o '' si el mes ya tiene MONTH_MAX entradas.
	 */
	public static function add( array $entry ) {
		$time  = (int) ( $entry['time'] ?? time() );
		$month = self::month_key( $time );
		$data  = self::all();
		$id    = substr( md5( uniqid( '', true ) ), 0, 10 );
		if ( count( $data[ $month ] ?? [] ) >= self::MONTH_MAX ) {
			return '';
		}
		$data[ $month ][] = [
			'id'      => $id,
			'time'    => $time,
			'source'  => ( $entry['source'] ?? 'form' ) === 'manual' ? 'manual' : 'form',
			'page'    => sanitize_text_field( $entry['page'] ?? '' ),
			'message' => sanitize_text_field( mb_substr( (string) ( $entry['message'] ?? '' ), 0, 160 ) ),
			'units'   => max( 0, min( 9, (int) ( $entry['units'] ?? 1 ) ) ),
			'note'    => sanitize_text_field( $entry['note'] ?? '' ),
		];
		self::save( $data );
		return $id;
	}

	/** Cambia unidades y nota de varias entradas de un mes; borra las marcadas. */
	public static function update( $month, array $units, array $notes, array $delete ) {
		$data = self::all();
		if ( empty( $data[ $month ] ) ) {
			return;
		}
		foreach ( $data[ $month ] as $i => $e ) {
			$id = $e['id'];
			if ( in_array( $id, $delete, true ) ) {
				unset( $data[ $month ][ $i ] );
				continue;
			}
			if ( isset( $units[ $id ] ) ) {
				$data[ $month ][ $i ]['units'] = max( 0, min( 9, (int) $units[ $id ] ) );
			}
			if ( isset( $notes[ $id ] ) ) {
				$data[ $month ][ $i ]['note'] = sanitize_text_field( $notes[ $id ] );
			}
		}
		$data[ $month ] = array_values( $data[ $month ] );
		self::save( $data );
	}

	/** Texto del contador para el cliente y para el correo. */
	public static function summary() {
		$limit = self::limit();
		$used  = self::used();
		if ( $limit === null ) {
			/* translators: %d: number of requests this month */
			return sprintf( _n( 'This month: %d request (your plan has unlimited updates)', 'This month: %d requests (your plan has unlimited updates)', $used, 'dox-care' ), $used );
		}
		/* translators: 1: updates used, 2: updates included per month */
		return sprintf( __( 'This month: %1$d of %2$d updates used', 'dox-care' ), $used, $limit );
	}
}
