<?php
/**
 * Entrar con un código por correo, además de con la contraseña (que sigue igual).
 *
 * El usuario escribe su correo o su usuario y le llega un código de 6 números que vale
 * 10 minutos, se usa una sola vez y admite 5 intentos. Sale en la pantalla de entrada
 * de WordPress (también con Hide My WP, que solo cambia la dirección) y en la de la
 * caja de Dox POS, a través de sus filtros dox_pos_login_code_*.
 *
 * Lo que este camino NO es: igual de fuerte que una contraseña larga. Quien controle el
 * correo de un usuario puede entrar a su cuenta. Por eso:
 * - Cada entrada con código le manda al usuario un aviso ("has entrado a tal web con un
 *   código"), como hacen Google o Netflix, para que una entrada que no hizo no pase
 *   desapercibida.
 * - Se apaga por web en los ajustes, y aparte se puede dejar fuera a las cuentas con
 *   poder sobre la web (administradores, editores, quien gestiona usuarios o plugins).
 * - Se apaga solo si hay un plugin de doble factor conocido.
 * - Todos los topes se cuentan en una tabla propia con sumas atómicas: los transients
 *   viven en Redis en estas webs, se borran con cualquier purga y no son atómicos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Login {

	const META       = 'dox_care_login_code';
	const TABLE      = 'dox_care_limits';
	const DB_VERSION = '1';
	const TTL        = 600; // 10 minutos.
	const TRIES      = 5;   // Intentos por código.
	// Topes por hora. Los de IP son los únicos que se ven; los de cuenta y el de toda la
	// web no, para que una cuenta real y una inventada respondan igual.
	const SEND_IP    = 20;  // Códigos pedidos por IP.
	const SEND_USER  = 5;   // Códigos enviados a una cuenta, la escriba como la escriba.
	const CHECK_IP   = 30;  // Comprobaciones por IP.
	const CHECK_USER = 10;  // Comprobaciones contra el código vivo de una cuenta.

	const SEND_ALL   = 100; // Códigos enviados por web y hora, a todas las cuentas juntas.

	/**
	 * Permisos que hacen de una cuenta una llave de la web: sin código salvo opt-in.
	 * unfiltered_html va aquí porque quien lo tiene (el Editor, en una web normal) puede
	 * dejar un script en una entrada que, al abrirla un administrador, cree otro
	 * administrador. manage_woocommerce, porque cambia adónde llega el dinero.
	 */
	const PRIVILEGED = [
		'manage_options', 'edit_users', 'promote_users', 'create_users', 'delete_users',
		'activate_plugins', 'install_plugins', 'update_plugins', 'edit_plugins',
		'switch_themes', 'edit_themes', 'edit_theme_options', 'update_core', 'import',
		'unfiltered_html', 'manage_woocommerce',
	];

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		if ( get_option( 'dox_care_db' ) !== self::DB_VERSION ) {
			self::install();
		}
		add_action( 'login_form', [ __CLASS__, 'link' ] );
		add_action( 'login_form_dox_code', [ __CLASS__, 'screen' ] );
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'styles' ] );

		// Dox POS: su pantalla de caja pinta el paso a paso y nos pide enviar y comprobar,
		// pasando el permiso que tiene que tener la cuenta (el de usar la caja).
		add_filter( 'dox_pos_login_code_enabled', '__return_true' );
		add_filter( 'dox_pos_login_code_send', function ( $result, $login, $cap = '' ) {
			return self::send( $login, (string) $cap );
		}, 10, 3 );
		add_filter( 'dox_pos_login_code_verify', function ( $result, $login, $code, $cap = '' ) {
			return self::verify( $login, $code, (string) $cap );
		}, 10, 4 );
	}

	public static function enabled() {
		$on = Dox_Care_Settings::get( 'login_code' ) === '1' && ! self::two_factor_plugin();
		return (bool) apply_filters( 'dox_care_login_code', $on );
	}

	/** La tabla de los topes: una fila por contador, con su suma y su caducidad. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . self::TABLE;
		dbDelta( "CREATE TABLE {$table} (
  k char(40) NOT NULL,
  n int unsigned NOT NULL DEFAULT 0,
  expires int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (k),
  KEY expires (expires)
) {$wpdb->get_charset_collate()};" );
		// Solo se da por instalada si la tabla existe de verdad; si no, se reintenta en la
		// próxima carga y mientras tanto el código no está disponible.
		if ( self::table_exists() ) {
			update_option( 'dox_care_db', self::DB_VERSION, false );
		}
	}

	private static function table_exists() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function ready() {
		return get_option( 'dox_care_db' ) === self::DB_VERSION;
	}

	private static function unavailable() {
		return new WP_Error( 'dox_care_unavailable', __( 'Signing in with a code is not available right now. Use your password.', 'dox-care' ) );
	}

	/**
	 * Suma 1 a un contador y dice si sigue dentro del tope. La suma la hace MySQL en una
	 * sola sentencia, así que cien peticiones a la vez dan 1, 2, 3... 100 y solo las
	 * primeras pasan. Si la tabla falla, se cierra: mejor sin código que sin topes.
	 */
	private static function hit( $key, $limit, $window = HOUR_IN_SECONDS ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$now   = time();
		$k     = substr( hash( 'sha256', $key ), 0, 40 );
		// LAST_INSERT_ID(expr) deja en la conexión el valor que esta misma sentencia
		// escribió, así que no hace falta volver a leer la tabla (otra petición podría
		// haberla cambiado entre medias). 1 fila afectada = fila nueva, n = 1;
		// 2 = actualizada, n = insert_id. El orden importa: n se calcula con el expires viejo.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$quiet = $wpdb->suppress_errors( true ); // Un fallo de SQL no se pinta en la pantalla de entrada.
		$rows  = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (k, n, expires) VALUES (%s, 1, %d)
			ON DUPLICATE KEY UPDATE n = LAST_INSERT_ID(IF(expires < %d, 1, n + 1)), expires = IF(expires < %d, %d, expires)",
			$k, $now + $window, $now, $now, $now + $window
		) );
		$id = (int) $wpdb->insert_id; // Antes de la limpieza: otra consulta podría ponerlo a 0.
		if ( $rows !== false && wp_rand( 1, 50 ) === 1 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires < %d", $now ) );
		}
		$wpdb->suppress_errors( $quiet );
		// phpcs:enable
		if ( $rows === 1 ) {
			$n = 1;
		} elseif ( $rows === 2 ) {
			$n = $id;
		} else {
			// La consulta falló (¿la tabla ya no está?): se cierra, y en la próxima carga se
			// vuelve a instalar.
			delete_option( 'dox_care_db' );
			return false;
		}
		return $n >= 1 && $n <= $limit;
	}

	/**
	 * Si la web tiene un plugin de doble factor, el código por correo se apaga solo: sería
	 * una puerta sin segundo factor. La lista no puede estar completa (y un mu-plugin
	 * nunca aparece aquí); por eso las cuentas con poder quedan fuera de todos modos.
	 *
	 * @return string El plugin encontrado, o ''.
	 */
	public static function two_factor_plugin() {
		$known = [
			'two-factor/two-factor.php'                                         => 'Two Factor',
			'wordfence/wordfence.php'                                           => 'Wordfence',
			'wordfence-login-security/wordfence-login-security.php'             => 'Wordfence Login Security',
			'better-wp-security/better-wp-security.php'                         => 'Solid Security',
			'ithemes-security-pro/ithemes-security-pro.php'                     => 'Solid Security Pro',
			'wp-2fa/wp-2fa.php'                                                 => 'WP 2FA',
			'wp-2fa-premium/wp-2fa.php'                                         => 'WP 2FA',
			'two-factor-authentication/two-factor-authentication.php'           => 'Two Factor Authentication',
			'two-factor-authentication-premium/two-factor-authentication.php'   => 'Two Factor Authentication',
			'miniorange-2-factor-authentication/miniorange_2_factor_settings.php' => 'miniOrange 2FA',
			'duo-wordpress/duo_wordpress.php'                                   => 'Duo',
			'google-authenticator/google-authenticator.php'                     => 'Google Authenticator',
			'wp-simple-firewall/icwp-wpsf.php'                                  => 'Shield Security',
			'all-in-one-wp-security-and-firewall/wp-security.php'               => 'All In One WP Security',
			'rublon/rublon2factor.php'                                          => 'Rublon',
			'keyy/keyy.php'                                                     => 'Keyy',
		];
		// Se lee la opción y no is_plugin_active(): esa función solo existe en el panel y
		// esto corre también en wp-login.php.
		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
		}
		// Por carpeta y no por archivo exacto: algunos cambian el nombre del archivo principal.
		$folders = array_map( function ( $f ) {
			return strtok( (string) $f, '/' );
		}, $active );
		foreach ( $known as $file => $name ) {
			if ( in_array( strtok( $file, '/' ), $folders, true ) ) {
				return $name;
			}
		}
		return '';
	}

	/** El usuario a partir de lo que escribió: correo o nombre de usuario. */
	private static function find_user( $login ) {
		$login = trim( (string) $login );
		if ( $login === '' ) {
			return false;
		}
		return is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	}

	/**
	 * La IP para los topes. En us1, mod_remoteip ya pone en REMOTE_ADDR la IP real que
	 * manda Cloudflare. Una IPv6 se agrupa por su /64: quien tiene una tiene 2^64.
	 */
	private static function ip() {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$bin = inet_pton( $ip );
			if ( $bin !== false ) {
				return bin2hex( substr( $bin, 0, 8 ) ) . '::/64';
			}
		}
		return $ip;
	}

	/** ¿Es una cuenta con poder sobre la web? */
	private static function is_privileged( WP_User $user ) {
		foreach ( self::PRIVILEGED as $cap ) {
			if ( user_can( $user, $cap ) ) {
				return true;
			}
		}
		return is_multisite() && is_super_admin( $user->ID );
	}

	/**
	 * Si esta cuenta puede entrar con código: lo que comprueba el login normal (spam o
	 * borrados en multisitio, los plugins que bloquean cuentas por wp_authenticate_user),
	 * que no sea una cuenta con poder (salvo opt-in), el permiso que pida quien llama
	 * (la caja pide el suyo) y un filtro propio.
	 *
	 * @return true|WP_Error
	 */
	private static function eligible( WP_User $user, $cap = '' ) {
		$no = new WP_Error( 'dox_care_blocked', __( 'That account cannot sign in.', 'dox-care' ) );
		if ( ! is_email( $user->user_email ) ) {
			return $no;
		}
		if ( is_multisite() && ( ! empty( $user->spam ) || ! empty( $user->deleted ) || ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) ) {
			return $no;
		}
		if ( self::is_privileged( $user ) && Dox_Care_Settings::get( 'login_code_admins' ) !== '1' ) {
			return $no;
		}
		if ( $cap !== '' && ! user_can( $user, $cap ) ) {
			return $no;
		}
		$checked = apply_filters( 'wp_authenticate_user', $user, '' );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		$allowed = apply_filters( 'dox_care_login_code_user', true, $user );
		return is_wp_error( $allowed ) ? $allowed : ( $allowed ? true : $no );
	}

	private static function hash( $user_id, $code ) {
		return hash_hmac( 'sha256', $user_id . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * Envía un código. Devuelve true también cuando no se envía nada (la cuenta no existe,
	 * no puede entrar con código o ya pidió demasiados), para no revelar cuáles hay;
	 * WP_Error solo con el tope por IP, que se cuenta igual exista la cuenta o no. Los
	 * topes por cuenta van por su id, así que escribirla con acentos ("suppórt" encuentra
	 * a "support") o con su correo no da más intentos.
	 *
	 * $cap: permiso que tiene que tener la cuenta (la caja pide el de usarla).
	 */
	public static function send( $login, $cap = '' ) {
		if ( ! self::ready() ) {
			return self::unavailable();
		}
		$limit = new WP_Error( 'dox_care_limit', __( 'Too many codes requested. Wait a few minutes or sign in with your password.', 'dox-care' ) );
		if ( ! self::hit( 'send-ip|' . self::ip(), self::SEND_IP ) ) {
			return $limit;
		}

		$user = self::find_user( $login );
		if ( ! $user || is_wp_error( self::eligible( $user, $cap ) ) ) {
			return true;
		}
		// El tope de la cuenta, sea cual sea la forma de escribirla. No se ve: si se pasa, no
		// se envía nada y el código que tuviera sigue valiendo.
		if ( ! self::hit( 'send-user|' . $user->ID, self::SEND_USER ) ) {
			return true;
		}
		// Tope de toda la web, para que nadie use la tienda para mandar correo a miles de
		// clientas cambiando de IP. Solo cuenta envíos reales; tampoco se ve.
		if ( ! self::hit( 'send-all', self::SEND_ALL ) ) {
			return true;
		}

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		update_user_meta( $user->ID, self::META, [
			'hash'    => self::hash( $user->ID, $code ),
			'id'      => wp_generate_password( 12, false ),
			'expires' => time() + self::TTL,
		] );

		// El correo sale al final, con la respuesta ya entregada: si se enviara aquí, lo que
		// tarda el SMTP delataría qué cuentas existen.
		add_action( 'shutdown', function () use ( $user, $code ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
			self::email( $user, $code );
		}, PHP_INT_MAX );
		return true;
	}

	/**
	 * Comprueba el código y, si vale, abre la sesión (sin "recordarme": en una tablet de
	 * caja compartida no conviene). Devuelve el usuario con la sesión ya abierta, o WP_Error.
	 *
	 * $cap: permiso que tiene que tener la cuenta, comprobado ANTES de abrir la sesión.
	 */
	public static function verify( $login, $code, $cap = '' ) {
		if ( ! self::ready() ) {
			return self::unavailable();
		}
		$fail  = new WP_Error( 'dox_care_code', __( 'That code is not valid or has expired. Request a new one.', 'dox-care' ) );
		$limit = new WP_Error( 'dox_care_limit', __( 'Too many attempts. Wait a few minutes or sign in with your password.', 'dox-care' ) );
		if ( ! self::hit( 'check-ip|' . self::ip(), self::CHECK_IP ) ) {
			return $limit;
		}

		$code = preg_replace( '/\D/', '', (string) $code );
		$user = self::find_user( $login );
		if ( ! $user || strlen( $code ) !== 6 ) {
			self::failed( $login, $fail );
			return $fail;
		}
		$saved = get_user_meta( $user->ID, self::META, true );
		if ( ! is_array( $saved ) || empty( $saved['hash'] ) || empty( $saved['id'] ) || (int) $saved['expires'] < time() ) {
			self::failed( $login, $fail );
			return $fail;
		}
		// El tope de la cuenta solo cuenta cuando hay un código que adivinar: si no, bastaría
		// mandar basura para dejar a un cajero sin poder usar el suyo.
		if ( ! self::hit( 'check-user|' . $user->ID, self::CHECK_USER ) ) {
			return $fail; // Sin aviso distinto: no se dice que la cuenta existe.
		}
		// El intento se cuenta antes de comparar y en la base de datos: con peticiones en
		// paralelo, solo las 5 primeras llegan a comparar.
		if ( ! self::hit( 'try|' . $user->ID . '|' . $saved['id'], self::TRIES, self::TTL ) ) {
			delete_user_meta( $user->ID, self::META );
			self::failed( $login, $fail );
			return $fail;
		}
		if ( ! hash_equals( $saved['hash'], self::hash( $user->ID, $code ) ) ) {
			self::failed( $login, $fail );
			return $fail;
		}

		delete_user_meta( $user->ID, self::META ); // Un solo uso.

		// Se vuelve a comprobar aquí y no solo al enviar: la cuenta pudo cambiar entre medias.
		$can = self::eligible( $user, $cap );
		if ( is_wp_error( $can ) ) {
			self::failed( $login, $fail );
			return $fail;
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		// El aviso de la entrada sale al final, como el código, para no hacer esperar.
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		add_action( 'shutdown', function () use ( $user, $ip ) {
			self::signed_in_email( $user, $ip );
		}, PHP_INT_MAX );
		return $user;
	}

	/**
	 * Un intento fallido, igual que lo anuncia el login normal, para que los plugins que
	 * cuentan fallos (Wordfence, Limit Login Attempts) también vean estos. Lo frenan los
	 * topes de comprobación: no se puede usar para generar fallos sin límite a nombre de otro.
	 */
	private static function failed( $login, WP_Error $error ) {
		// Como mucho 10 avisos por hora a nombre de la misma cuenta, aunque cambien de IP:
		// si no, servirían para que un plugin que bloquea por usuario cierre la de otro.
		if ( self::hit( 'failed|' . strtolower( trim( (string) $login ) ), self::CHECK_USER ) ) {
			do_action( 'wp_login_failed', (string) $login, $error );
		}
	}

	/** El correo con el código, en el idioma de ese usuario. El código no va en el asunto. */
	private static function email( WP_User $user, $code ) {
		$lang = Dox_Care_Settings::get( 'language' );
		if ( ! in_array( $lang, [ 'es', 'en' ], true ) ) {
			$lang = strpos( get_user_locale( $user ), 'es' ) === 0 ? 'es' : 'en';
		}
		$restore = Dox_Care_Settings::use_language( $lang );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: wp_parse_url( home_url(), PHP_URL_HOST );
		/* translators: %s: site name */
		$subject = sprintf( __( 'Your code to sign in to %s', 'dox-care' ), $site );
		$body  = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#141313;max-width:480px">';
		/* translators: %s: site name */
		$body .= '<p>' . esc_html( sprintf( __( 'Your code to sign in to %s:', 'dox-care' ), $site ) ) . '</p>';
		$body .= '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:18px 0">' . esc_html( $code ) . '</p>';
		$body .= '<p>' . esc_html__( 'It is valid for 10 minutes and can be used once.', 'dox-care' ) . '</p>';
		$body .= '<p style="color:#6B6866;font-size:13px">' . esc_html__( 'If you did not request it, ignore this email: nobody can sign in without the code.', 'dox-care' ) . '</p></div>';

		wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		$restore();
	}

	/** El aviso al usuario de que alguien entró a su cuenta con un código. */
	private static function signed_in_email( WP_User $user, $ip ) {
		$lang = Dox_Care_Settings::get( 'language' );
		if ( ! in_array( $lang, [ 'es', 'en' ], true ) ) {
			$lang = strpos( get_user_locale( $user ), 'es' ) === 0 ? 'es' : 'en';
		}
		$restore = Dox_Care_Settings::use_language( $lang );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: wp_parse_url( home_url(), PHP_URL_HOST );
		$when = Dox_Care_Settings::date( time(), $lang ) . ', ' . wp_date( $lang === 'es' ? 'H:i' : 'g:i a' );
		/* translators: %s: site name */
		$subject = sprintf( __( 'You signed in to %s with a code', 'dox-care' ), $site );
		$body  = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#141313;max-width:480px">';
		/* translators: %s: site name */
		$body .= '<p>' . esc_html( sprintf( __( 'Someone just signed in to your account on %s with a code sent to this email.', 'dox-care' ), $site ) ) . '</p>';
		$body .= '<p style="background:#F4F3F1;border-radius:8px;padding:12px 14px;margin:16px 0">';
		$body .= '<b>' . esc_html__( 'When:', 'dox-care' ) . '</b> ' . esc_html( $when ) . '<br>';
		$body .= '<b>' . esc_html__( 'From the IP address:', 'dox-care' ) . '</b> ' . esc_html( $ip ) . '</p>';
		$body .= '<p>' . esc_html__( 'If it was you, there is nothing to do.', 'dox-care' ) . '</p>';
		$body .= '<p>' . esc_html__( 'If it was not you, change the password of this email right away (someone may have access to it) and then the password of the website:', 'dox-care' ) . ' ';
		$body .= '<a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'change my password', 'dox-care' ) . '</a>.</p></div>';

		wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		$restore();
	}

	private static function requested_redirect() {
		return isset( $_REQUEST['redirect_to'] ) && is_scalar( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Adónde ir al entrar, con el filtro de WordPress (Dox POS lleva a los cajeros a la caja). */
	private static function redirect_to( WP_User $user ) {
		$requested = self::requested_redirect();
		return apply_filters( 'login_redirect', $requested ?: admin_url(), $requested, $user );
	}

	private static function url() {
		$args     = [ 'action' => 'dox_code' ];
		$redirect = self::requested_redirect();
		if ( $redirect ) {
			$args['redirect_to'] = rawurlencode( $redirect ); // add_query_arg no codifica los valores.
		}
		return add_query_arg( $args, wp_login_url() );
	}

	/**
	 * El enlace bajo el formulario de contraseña. WordPress no tiene un gancho después
	 * del botón de entrar, así que se pinta aquí y una línea de JS lo baja debajo; sin
	 * JS se queda donde está y funciona igual. Su color lo pone Dox_Care_Login_Colors.
	 */
	public static function link() {
		echo '<p class="dxc-code-link" id="dxc-code-link"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Sign in with a code by email', 'dox-care' ) . '</a></p>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var l=document.getElementById("dxc-code-link"),s=l&&l.parentNode.querySelector(".submit");if(s){s.parentNode.insertBefore(l,s.nextSibling);}});</script>';
	}

	/**
	 * Sin el color del botón (ver Dox_Care_Login_Colors), el enlace y la línea toman el
	 * color del texto del formulario (currentColor), así que se leen igual en el login
	 * blanco de WordPress que en uno oscuro (UiCore, Hide My WP...). Va subrayado, como
	 * los enlaces de debajo. Los navegadores sin color-mix() se quedan con el color
	 * heredado y la línea gris. Selectores flojos a propósito: el CSS del tema gana.
	 */
	public static function styles() {
		echo '<style>.dxc-code-link{clear:both;margin:0;padding:14px 0 2px;text-align:center}#loginform .submit+.dxc-code-link{border-top:1px solid #dcdcde;border-top-color:color-mix(in srgb,currentColor 20%,transparent);margin-top:52px}.dxc-code-link a{color:inherit;color:var(--dxc-link,color-mix(in srgb,currentColor 85%,transparent));text-decoration:underline;text-underline-offset:2px}.dxc-code-link a:hover,.dxc-code-link a:focus{color:var(--dxc-link,inherit);text-decoration-thickness:2px}#dxc-code{font-size:24px;letter-spacing:6px;text-align:center}</style>';
	}

	/** wp-login.php?action=dox_code: paso 1 (correo) y paso 2 (código). */
	public static function screen() {
		$errors = new WP_Error();
		$step   = 'ask';
		$login  = isset( $_POST['log'] ) && is_scalar( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'dox_care_code' );
		// El paso va en un campo oculto y no en el nombre del botón: con Enter, algunos
		// navegadores no mandan el botón.
		$want = $posted && isset( $_POST['dxc_step'] ) && is_scalar( $_POST['dxc_step'] ) ? sanitize_key( $_POST['dxc_step'] ) : '';

		if ( $want === 'send' ) {
			$sent = self::send( $login );
			if ( is_wp_error( $sent ) ) {
				$errors->add( 'limit', $sent->get_error_message() );
			} else {
				$step = 'code';
			}
		} elseif ( $want === 'verify' ) {
			$code = isset( $_POST['code'] ) && is_scalar( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
			$user = self::verify( $login, $code );
			if ( $user instanceof WP_User ) {
				wp_safe_redirect( self::redirect_to( $user ) );
				exit;
			}
			$errors->add( 'code', $user->get_error_message() );
			$step = 'code';
		}

		$message = $step === 'code'
			? '<p class="message">' . esc_html__( 'If that account can sign in with a code, we sent one to its email. It may take a minute; check spam too.', 'dox-care' ) . '</p>'
			: '<p class="message">' . esc_html__( 'Write your email or username and we will send you a code to sign in, no password needed.', 'dox-care' ) . '</p>';

		login_header( __( 'Sign in with a code', 'dox-care' ), $message, $errors );
		?>
		<form name="dxc-code-form" id="loginform" action="<?php echo esc_url( self::url() ); ?>" method="post">
			<?php wp_nonce_field( 'dox_care_code' ); ?>
			<?php if ( $step === 'ask' ) : ?>
				<input type="hidden" name="dxc_step" value="send">
				<p>
					<label for="user_login"><?php esc_html_e( 'Username or email', 'dox-care' ); ?></label>
					<input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr( $login ); ?>" autocomplete="username" required autofocus>
				</p>
				<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Send me a code', 'dox-care' ); ?>"></p>
			<?php else : ?>
				<input type="hidden" name="dxc_step" value="verify">
				<input type="hidden" name="log" value="<?php echo esc_attr( $login ); ?>">
				<p>
					<label for="dxc-code"><?php esc_html_e( 'Code', 'dox-care' ); ?></label>
					<input type="text" name="code" id="dxc-code" class="input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
				</p>
				<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Sign in', 'dox-care' ); ?>"></p>
			<?php endif; ?>
		</form>
		<p id="nav">
			<?php if ( $step === 'code' ) : ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Request another code', 'dox-care' ); ?></a> |
			<?php endif; ?>
			<a href="<?php echo esc_url( wp_login_url( self::requested_redirect() ) ); ?>"><?php esc_html_e( 'Sign in with your password', 'dox-care' ); ?></a>
		</p>
		<?php
		login_footer( 'user_login' );
		exit;
	}
}
