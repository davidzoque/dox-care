<?php
/**
 * La conexión con el CRM de Dox Studio (portal.doxstudio.com, módulo Dox Care).
 *
 * Desde la 0.4.0 el número que vale es el del CRM, no el de la web: el plugin manda
 * las solicitudes y enseña el plan, el cupo del mes y el estado de cada solicitud
 * que el CRM le devuelve. Un administrador del sitio puede leer todo lo de aquí, y no
 * pasa nada: con el secreto solo se pueden crear solicitudes de esta web y leer su
 * estado (ver el README del módulo dox_care).
 *
 * Cómo se conecta, sin copiar claves a mano:
 *  1. La web genera un "claim" (32 bytes al azar) que nunca sale de aquí y pide su
 *     alta mandando su dirección y el SHA-256 del claim (register).
 *  2. Dox Studio aprueba la web en su panel. Antes, el CRM le pregunta a esta misma
 *     web su hash en https://<dominio>/?dox_care_claim=...: así solo quien controla
 *     el dominio puede conectarlo.
 *  3. Aprobada, la web recoge su secreto una vez con su claim (claim) y desde ahí
 *     firma cada petición con HMAC-SHA256.
 *
 * El estado se pide por el cron dos veces al día y justo después de enviar una
 * solicitud, y se guarda en una opción: el escritorio pinta siempre lo guardado y
 * nunca espera al CRM mientras el cliente mira. Si el CRM no contesta al enviar, la
 * solicitud sale por correo como antes, marcada "[Sin CRM]", y el cron la reintenta
 * (su clave evita duplicarla).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Crm {

	const OPTION  = 'dox_care_crm';         // estado, id público, secreto, claim: sin autoload.
	const STATUS  = 'dox_care_crm_status';  // lo último que dijo el CRM.
	const OUTBOX  = 'dox_care_crm_outbox';  // solicitudes que no pudieron llegar.
	const HOOK    = 'dox_care_crm_sync';
	const VERSION = 'dox_care_crm_version'; // versión del plugin en la última pasada.
	const WAITING = [ 'none', 'pending', 'approved', 'rejected' ]; // estados sin secreto con salida.

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'sync' ] );
		add_action( 'init', [ __CLASS__, 'claim_endpoint' ], 0 );
		add_action( 'admin_post_dox_care_crm_sync', [ __CLASS__, 'sync_now' ] );

		// Se programa aquí y no al activar: las actualizaciones no pasan por la activación.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'twicedaily', self::HOOK );
		}
		// Recién actualizado: pedir el alta en un minuto, sin esperar a la pasada de turno.
		if ( get_option( self::VERSION ) !== DOX_CARE_VERSION ) {
			update_option( self::VERSION, DOX_CARE_VERSION, false );
			// Una versión nueva puede traer lo que hacía falta para conectarse: que vuelva
			// a pedir el alta ya, sin esperar las 6 horas.
			$s = self::state();
			if ( $s['state'] !== 'connected' ) {
				$s['registered_at'] = 0;
				self::save( $s );
			}
			if ( ! wp_next_scheduled( self::HOOK . '_once' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK . '_once' );
			}
		}
		add_action( self::HOOK . '_once', [ __CLASS__, 'sync' ] );
	}

	/** La dirección de la API. Se puede cambiar en wp-config.php (pruebas). */
	public static function base() {
		return defined( 'DOX_CARE_CRM' ) ? trailingslashit( DOX_CARE_CRM ) : 'https://portal.doxstudio.com/dox_care/intake/v1/';
	}

	public static function state() {
		$s = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [
			'state'         => 'none', // none, pending, approved, connected, rejected
			'site'          => '',
			'secret'        => '',
			'claim'         => '',
			'registered_at' => 0,
			'last_sync'     => 0,
			'last_error'    => '',
			'imported'      => '',
		] );
	}

	private static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	public static function connected() {
		$s = self::state();
		return $s['state'] === 'connected' && strlen( $s['secret'] ) === 64 && $s['site'] !== '';
	}

	/** Lo último que dijo el CRM (plan, cupo, solicitudes), o null. */
	public static function status() {
		if ( ! self::connected() ) {
			return null;
		}
		$st = get_option( self::STATUS );
		return is_array( $st ) && isset( $st['plan'] ) ? $st : null;
	}

	/**
	 * https://<web>/?dox_care_claim=... : contesta el hash del claim para que el CRM
	 * compruebe que esta web es quien pidió el alta. Solo el hash (no sirve para nada
	 * más) y sin caché: cada pregunta del CRM lleva un número distinto.
	 */
	public static function claim_endpoint() {
		if ( ! isset( $_GET['dox_care_claim'] ) || is_admin() ) {
			return;
		}
		$s    = self::state();
		$hash = ( $s['state'] === 'pending' && $s['claim'] !== '' ) ? hash( 'sha256', $s['claim'] ) : '';
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'dox care claim' );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo wp_json_encode( [ 'dox_care_claim' => $hash ] );
		exit;
	}

	/**
	 * Una petición al CRM: multipart con el JSON en `payload` y los adjuntos en
	 * files[]. Las firmadas llevan la firma de la hora, el método, la ruta y el
	 * resumen del cuerpo (el JSON y el SHA-256 de cada adjunto).
	 *
	 * @param array $files [ nombre => ruta ]
	 * @return array [ 'code' => int, 'body' => array ] (code 0 si no hubo respuesta)
	 */
	public static function post( $action, array $payload, array $files = [], $signed = true ) {
		$payload['home_url'] = home_url( '/' );
		$json     = wp_json_encode( $payload );
		$boundary = 'doxcare' . wp_generate_password( 24, false );
		$body     = '--' . $boundary . "\r\n"
			. 'Content-Disposition: form-data; name="payload"' . "\r\n\r\n" . $json . "\r\n";
		$hashes = [];
		foreach ( $files as $name => $path ) {
			$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $data === false ) {
				continue;
			}
			$hashes[] = hash( 'sha256', $data );
			$body    .= '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="files[]"; filename="' . str_replace( [ '"', "\r", "\n" ], '', (string) $name ) . '"' . "\r\n"
				. 'Content-Type: application/octet-stream' . "\r\n\r\n" . $data . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";

		$headers = [
			'Content-Type'       => 'multipart/form-data; boundary=' . $boundary,
			'X-Dox-Care-Version' => DOX_CARE_VERSION,
		];
		if ( $signed ) {
			$s    = self::state();
			$time = time();
			$path = '/dox_care/intake/v1/' . $action;
			$sum  = hash( 'sha256', $json . "\n" . implode( "\n", $hashes ) );
			$headers['X-Dox-Care-Site']      = $s['site'];
			$headers['X-Dox-Care-Time']      = (string) $time;
			$headers['X-Dox-Care-Signature'] = hash_hmac( 'sha256', $time . "\nPOST\n" . $path . "\n" . $sum, $s['secret'] );
		}

		$res = wp_remote_post( self::base() . $action, [
			'timeout'     => $files ? 45 : 15,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => $body,
		] );
		if ( is_wp_error( $res ) ) {
			return [ 'code' => 0, 'body' => [ 'error' => $res->get_error_message() ] ];
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : [];

		// El CRM ya no reconoce esta web (la desconectaron o se perdió el secreto):
		// se vuelve a empezar y la siguiente pasada pide el alta otra vez.
		if ( $signed && $code === 401 && in_array( $data['error'] ?? '', [ 'not_connected', 'bad_signature' ], true ) ) {
			self::reset( 'disconnected' );
		}
		return [ 'code' => $code, 'body' => $data ];
	}

	/** Un estado del CRM sin secreto: solo los de WAITING; cualquier otro, volver a empezar. */
	private static function waiting_state( $state ) {
		$state = sanitize_key( $state );
		return in_array( $state, self::WAITING, true ) ? $state : 'none';
	}

	private static function reset( $why ) {
		$s = self::state();
		$s['state']         = 'none';
		$s['site']          = '';
		$s['secret']        = '';
		$s['claim']         = '';
		$s['registered_at'] = 0;
		$s['last_error']    = $why;
		self::save( $s );
		delete_option( self::STATUS );
	}

	private static function keep_status( $status ) {
		if ( is_array( $status ) && isset( $status['plan'] ) ) {
			update_option( self::STATUS, $status, false );
		}
	}

	/** La pasada: pedir el alta, recoger el secreto, reintentar lo pendiente y leer el estado. */
	public static function sync() {
		if ( get_transient( 'dox_care_crm_lock' ) ) {
			return;
		}
		set_transient( 'dox_care_crm_lock', 1, 2 * MINUTE_IN_SECONDS );

		$s = self::state();

		// Sin secreto, solo valen los estados que llevan a algún sitio. Un CRM
		// anterior a la 1.2.1 podía contestar "connected" sin secreto (o
		// "revoked"), y la 0.4.1 se quedaba ahí parada para siempre: se vuelve a
		// empezar y se pide el alta ya (0.4.2).
		if ( ! self::connected() && ! in_array( $s['state'], self::WAITING, true ) ) {
			$s['state']         = 'none';
			$s['registered_at'] = 0;
			self::save( $s );
		}

		if ( ! self::connected() ) {
			if ( $s['claim'] === '' ) {
				$s['claim'] = bin2hex( random_bytes( 32 ) );
			}
			// El alta se repite como mucho cada 6 horas (y siempre la primera vez).
			if ( in_array( $s['state'], [ 'none', 'pending', 'rejected' ], true ) && $s['registered_at'] < time() - 6 * HOUR_IN_SECONDS ) {
				$r = self::post( 'register', [ 'claim_hash' => hash( 'sha256', $s['claim'] ), 'plugin_version' => DOX_CARE_VERSION, 'account' => self::account() ], [], false );
				$s['registered_at'] = time();
				if ( $r['code'] === 200 && ! empty( $r['body']['state'] ) ) {
					$s['state']      = self::waiting_state( $r['body']['state'] );
					$s['last_error'] = '';
				} else {
					$s['last_error'] = sanitize_key( $r['body']['error'] ?? 'no_answer' );
				}
				self::save( $s );
			}
			// Aprobada: recoger el secreto con el claim.
			if ( in_array( $s['state'], [ 'pending', 'approved' ], true ) ) {
				$r = self::post( 'claim', [ 'claim' => $s['claim'] ], [], false );
				if ( $r['code'] === 200 && ( $r['body']['state'] ?? '' ) === 'connected' && ! empty( $r['body']['secret'] ) ) {
					$s['state']  = 'connected';
					$s['site']   = preg_replace( '/[^a-f0-9]/', '', (string) $r['body']['site'] );
					$s['secret'] = preg_replace( '/[^a-f0-9]/', '', (string) $r['body']['secret'] );
					$s['claim']  = '';
					self::save( $s );
				} elseif ( $r['code'] === 200 && ! empty( $r['body']['state'] ) ) {
					$s['state'] = self::waiting_state( $r['body']['state'] );
					self::save( $s );
				}
			}
		}

		if ( self::connected() ) {
			self::flush_outbox();
			if ( self::state()['imported'] === '' ) {
				self::import();
			}
			$r = self::post( 'status', [] );
			if ( $r['code'] === 200 ) {
				self::keep_status( $r['body'] );
			}
			$s = self::state();
			$s['last_sync']  = $r['code'] === 200 ? time() : $s['last_sync'];
			$s['last_error'] = $r['code'] === 200 ? '' : sanitize_key( $r['body']['error'] ?? 'no_answer' );
			if ( self::connected() ) {
				self::save( $s );
			}
		}

		delete_transient( 'dox_care_crm_lock' );
	}

	/**
	 * La primera vez que se conecta, el CRM recibe lo que el contador viejo de la web
	 * tiene de este mes, para cuadrarlo. Entra allí como "importado" y no cuenta hasta
	 * que Dox Studio lo revisa. Se manda una sola vez.
	 */
	private static function import() {
		$entries = [];
		foreach ( Dox_Care_Usage::entries() as $e ) {
			$entries[] = [
				'id'      => (string) ( $e['id'] ?? '' ),
				'time'    => (int) ( $e['time'] ?? 0 ),
				'page'    => (string) ( $e['page'] ?? '' ),
				'message' => (string) ( $e['message'] ?? '' ),
				'units'   => (int) ( $e['units'] ?? 1 ),
				'source'  => (string) ( $e['source'] ?? 'form' ),
			];
		}
		$r = $entries ? self::post( 'import', [ 'entries' => $entries ] ) : [ 'code' => 200, 'body' => [] ];
		if ( $r['code'] === 200 ) {
			$s             = self::state();
			$s['imported'] = Dox_Care_Usage::month_key();
			self::save( $s );
			self::keep_status( $r['body']['status'] ?? null );
		}
	}

	/**
	 * Manda una solicitud al CRM.
	 *
	 * @param array $data  key, page, message, requester_name, requester_email, wp_user
	 * @param array $files [ nombre => ruta temporal ]
	 * @return array [ 'ok' => bool, 'ref' => 'DC-1042', 'limited' => bool ]
	 */
	public static function send_request( array $data, array $files ) {
		$r = self::post( 'request', $data, $files );
		if ( $r['code'] === 200 && ! empty( $r['body']['ref'] ) ) {
			self::keep_status( $r['body']['status'] ?? null );
			return [ 'ok' => true, 'ref' => sanitize_text_field( $r['body']['ref'] ), 'limited' => false ];
		}
		return [ 'ok' => false, 'ref' => '', 'limited' => $r['code'] === 429 ];
	}

	/** Guarda una solicitud que no llegó, para que el cron la reintente (sin adjuntos: esos ya salieron por correo). */
	public static function queue( array $data ) {
		$box   = get_option( self::OUTBOX, [] );
		$box   = is_array( $box ) ? $box : [];
		$box[] = $data;
		update_option( self::OUTBOX, array_slice( $box, -20 ), false );
	}

	private static function flush_outbox() {
		$box = get_option( self::OUTBOX, [] );
		if ( ! is_array( $box ) || ! $box ) {
			return;
		}
		$left = [];
		foreach ( $box as $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$data['message'] = (string) ( $data['message'] ?? '' );
			$r               = self::post( 'request', $data );
			// 422 es un dato que el CRM no acepta: reintentarlo no lo arregla.
			if ( $r['code'] !== 200 && $r['code'] !== 422 ) {
				$left[] = $data;
			}
		}
		update_option( self::OUTBOX, $left, false );
	}

	/** "Sincronizar ahora" en los ajustes (solo el equipo de Dox). */
	public static function sync_now() {
		if ( ! Dox_Care_Settings::is_staff() ) {
			wp_die( esc_html__( 'Only Dox Studio can change this.', 'dox-care' ) );
		}
		check_admin_referer( 'dox_care_crm_sync' );
		$s                  = self::state();
		$s['registered_at'] = 0; // Que pida el alta ya si no está conectada.
		self::save( $s );
		delete_transient( 'dox_care_crm_lock' );
		self::sync();
		wp_safe_redirect( add_query_arg( 'synced', '1', wp_get_referer() ) . '#dxc-crm' );
		exit;
	}

	/**
	 * La cuenta de hosting en la que vive la web (el usuario de cPanel de /home/<cuenta>/).
	 * Con ella el CRM sabe que una web como oqizen.com, dentro del hosting de
	 * victoriachaux.com, comparte su plan y su cupo. Solo sirve para pedir el alta: el
	 * CRM igual comprueba el dominio antes de aprobarla.
	 */
	public static function account() {
		return preg_match( '#^/home\d*/([a-z0-9_]+)/#i', (string) ABSPATH, $m ) ? strtolower( $m[1] ) : '';
	}

	/** Cuántas webs comparten el cupo (las del mismo hosting), según el CRM. */
	public static function group_sites() {
		$st = self::status();
		return $st ? max( 1, (int) ( $st['sites'] ?? 1 ) ) : 1;
	}

	/** El plan según el CRM, si está conectada y es uno de los tres. */
	public static function plan() {
		$st = self::status();
		return $st && in_array( $st['plan'], [ 'essentials', 'pro', 'elite' ], true ) ? $st['plan'] : null;
	}

	/** Lo usado este mes según el CRM; 0 si el estado guardado es de otro mes. */
	public static function used() {
		$st = self::status();
		if ( ! $st ) {
			return null;
		}
		return ( $st['month'] ?? '' ) === wp_date( 'Y-m' ) ? (int) $st['used'] : 0;
	}

	/** Las solicitudes del usuario que mira, como las devolvió el CRM. */
	public static function user_requests( $user_id = 0 ) {
		$st      = self::status();
		$user_id = (string) ( $user_id ?: get_current_user_id() );
		if ( ! $st ) {
			return [];
		}
		return array_values( array_filter( (array) ( $st['requests'] ?? [] ), function ( $r ) use ( $user_id ) {
			return is_array( $r ) && (string) ( $r['wp_user'] ?? '' ) === $user_id;
		} ) );
	}

	/** Cómo se dice cada estado al cliente. */
	public static function status_label( array $r ) {
		switch ( $r['status'] ?? '' ) {
			case 'received':
				return __( 'Received', 'dox-care' );
			case 'needs_info':
				return __( 'We need something from you', 'dox-care' );
			case 'quoted':
				/* translators: %s: price */
				return ! empty( $r['price'] ) ? sprintf( __( 'Outside your plan: %s to approve', 'dox-care' ), '$' . number_format_i18n( (float) $r['price'], floor( $r['price'] ) == $r['price'] ? 0 : 2 ) ) : __( 'Outside your plan: to approve', 'dox-care' );
			case 'queued':
				/* translators: %s: date */
				return ! empty( $r['due'] ) ? sprintf( __( 'In queue, ready by %s', 'dox-care' ), Dox_Care_Settings::date( strtotime( $r['due'] . ' 12:00:00' ) ) ) : __( 'In queue', 'dox-care' );
			case 'in_progress':
				return __( 'In progress', 'dox-care' );
			case 'done':
				return __( 'Done', 'dox-care' );
			case 'closed':
				return __( 'Closed', 'dox-care' );
		}
		return '';
	}
}
