<?php
/**
 * Entrar con un código por correo, además de con la contraseña (que sigue igual).
 *
 * El usuario escribe su correo o su usuario y le llega un código de 6 números que
 * vale 10 minutos, se usa una sola vez y admite 5 intentos. No es más débil que la
 * contraseña: quien controla el correo ya puede cambiarla con "¿Olvidaste tu
 * contraseña?". Sale en la pantalla de entrada de WordPress (también con Hide My WP,
 * que solo cambia la dirección) y en la de la caja de Dox POS, a través de sus filtros
 * dox_pos_login_code_*.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Login {

	const META      = 'dox_care_login_code';
	const SENDS     = 'dox_care_login_sends';
	const TTL       = 600; // 10 minutos.
	const TRIES     = 5;   // Intentos por código.
	const USER_HOUR = 5;   // Códigos por usuario y hora.
	const IP_HOUR   = 20;  // Códigos por IP y hora.

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'login_form', [ __CLASS__, 'link' ] );
		add_action( 'login_form_dox_code', [ __CLASS__, 'screen' ] );
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'styles' ] );

		// Dox POS: su pantalla de caja pinta el paso a paso y nos pide enviar y comprobar.
		add_filter( 'dox_pos_login_code_enabled', '__return_true' );
		add_filter( 'dox_pos_login_code_send', function ( $result, $login ) {
			return self::send( $login );
		}, 10, 2 );
		add_filter( 'dox_pos_login_code_verify', function ( $result, $login, $code ) {
			return self::verify( $login, $code );
		}, 10, 3 );
	}

	public static function enabled() {
		return (bool) apply_filters( 'dox_care_login_code', Dox_Care_Settings::get( 'login_code' ) !== '0' );
	}

	/** El usuario a partir de lo que escribió: correo o nombre de usuario. */
	private static function find_user( $login ) {
		$login = trim( (string) $login );
		if ( $login === '' ) {
			return false;
		}
		return is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	}

	private static function ip() {
		return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	}

	private static function hash( $user_id, $code ) {
		return hash_hmac( 'sha256', $user_id . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * Envía un código. Devuelve true también cuando el usuario no existe, para no
	 * revelar qué correos tienen cuenta; WP_Error solo si se pasó del límite.
	 */
	public static function send( $login ) {
		$ip_key = 'dox_care_lc_' . md5( self::ip() );
		$ip_n   = (int) get_transient( $ip_key );
		if ( $ip_n >= self::IP_HOUR ) {
			return new WP_Error( 'dox_care_limit', __( 'Too many codes requested. Wait a few minutes or sign in with your password.', 'dox-care' ) );
		}
		set_transient( $ip_key, $ip_n + 1, HOUR_IN_SECONDS );

		$user = self::find_user( $login );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return true;
		}

		$sends = array_filter( (array) get_user_meta( $user->ID, self::SENDS, true ), function ( $t ) {
			return (int) $t > time() - HOUR_IN_SECONDS;
		} );
		if ( count( $sends ) >= self::USER_HOUR ) {
			return new WP_Error( 'dox_care_limit', __( 'Too many codes requested. Wait a few minutes or sign in with your password.', 'dox-care' ) );
		}
		$sends[] = time();
		update_user_meta( $user->ID, self::SENDS, array_values( $sends ) );

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		update_user_meta( $user->ID, self::META, [
			'hash'    => self::hash( $user->ID, $code ),
			'expires' => time() + self::TTL,
			'tries'   => 0,
		] );

		self::email( $user, $code );
		return true;
	}

	/** Comprueba el código y, si vale, abre la sesión. Devuelve el usuario o WP_Error. */
	public static function verify( $login, $code ) {
		$fail = new WP_Error( 'dox_care_code', __( 'That code is not valid or has expired. Request a new one.', 'dox-care' ) );
		$code = preg_replace( '/\D/', '', (string) $code );
		$user = self::find_user( $login );
		if ( ! $user || strlen( $code ) !== 6 ) {
			return $fail;
		}
		$saved = get_user_meta( $user->ID, self::META, true );
		if ( ! is_array( $saved ) || empty( $saved['hash'] ) || (int) $saved['expires'] < time() || (int) $saved['tries'] >= self::TRIES ) {
			delete_user_meta( $user->ID, self::META );
			return $fail;
		}
		if ( ! hash_equals( $saved['hash'], self::hash( $user->ID, $code ) ) ) {
			$saved['tries'] = (int) $saved['tries'] + 1;
			update_user_meta( $user->ID, self::META, $saved );
			return $fail;
		}

		delete_user_meta( $user->ID, self::META ); // Un solo uso.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		return $user;
	}

	/** El correo con el código, en el idioma de ese usuario. */
	private static function email( WP_User $user, $code ) {
		$lang = Dox_Care_Settings::get( 'language' );
		if ( ! in_array( $lang, [ 'es', 'en' ], true ) ) {
			$lang = strpos( get_user_locale( $user ), 'es' ) === 0 ? 'es' : 'en';
		}
		$restore = Dox_Care_Settings::use_language( $lang );

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: wp_parse_url( home_url(), PHP_URL_HOST );
		/* translators: 1: the code, 2: site name */
		$subject = sprintf( __( '%1$s is your code to sign in to %2$s', 'dox-care' ), $code, $site );
		$body  = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#141313;max-width:480px">';
		/* translators: %s: site name */
		$body .= '<p>' . esc_html( sprintf( __( 'Your code to sign in to %s:', 'dox-care' ), $site ) ) . '</p>';
		$body .= '<p style="font-size:32px;font-weight:bold;letter-spacing:6px;margin:18px 0">' . esc_html( $code ) . '</p>';
		$body .= '<p>' . esc_html__( 'It is valid for 10 minutes and can be used once.', 'dox-care' ) . '</p>';
		$body .= '<p style="color:#6B6866;font-size:13px">' . esc_html__( 'If you did not request it, ignore this email: nobody can sign in without the code.', 'dox-care' ) . '</p></div>';

		wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		$restore();
	}

	/** Adónde ir al entrar, con el filtro de WordPress (Dox POS lleva a los cajeros a la caja). */
	private static function redirect_to( WP_User $user ) {
		$requested = isset( $_REQUEST['redirect_to'] ) && is_scalar( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
		$to        = $requested ?: admin_url();
		return apply_filters( 'login_redirect', $to, $requested, $user );
	}

	private static function url( $args = [] ) {
		$redirect = isset( $_REQUEST['redirect_to'] ) && is_scalar( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
		if ( $redirect ) {
			$args['redirect_to'] = rawurlencode( $redirect );
		}
		return add_query_arg( array_merge( [ 'action' => 'dox_code' ], $args ), wp_login_url() );
	}

	/**
	 * El enlace bajo el formulario de contraseña. WordPress no tiene un gancho después
	 * del botón de entrar, así que se pinta aquí y una línea de JS lo baja debajo; sin
	 * JS se queda donde está y funciona igual.
	 */
	public static function link() {
		echo '<p class="dxc-code-link" id="dxc-code-link"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Sign in with a code by email', 'dox-care' ) . '</a></p>';
		echo '<script>document.addEventListener("DOMContentLoaded",function(){var l=document.getElementById("dxc-code-link"),s=l&&l.parentNode.querySelector(".submit");if(s){s.parentNode.insertBefore(l,s.nextSibling);}});</script>';
	}

	public static function styles() {
		echo '<style>.dxc-code-link{clear:both;margin:0;padding:14px 0 2px;text-align:center}#loginform .submit+.dxc-code-link{border-top:1px solid #dcdcde;margin-top:52px}.dxc-code-link a{text-decoration:none}#dxc-code{font-size:24px;letter-spacing:6px;text-align:center}</style>';
	}

	/** wp-login.php?action=dox_code: paso 1 (correo) y paso 2 (código). */
	public static function screen() {
		$errors = new WP_Error();
		$step   = 'ask';
		$login  = isset( $_POST['log'] ) && is_scalar( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
		$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'dox_care_code' );

		if ( $posted && ! isset( $_POST['dxc_verify'] ) ) {
			$sent = self::send( $login );
			if ( is_wp_error( $sent ) ) {
				$errors->add( 'limit', $sent->get_error_message() );
			} else {
				$step = 'code';
			}
		} elseif ( $posted ) {
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
			? '<p class="message">' . esc_html__( 'If that account exists, we sent a 6-digit code to its email. It may take a minute; check spam too.', 'dox-care' ) . '</p>'
			: '<p class="message">' . esc_html__( 'Write your email or username and we will send you a code to sign in, no password needed.', 'dox-care' ) . '</p>';

		login_header( __( 'Sign in with a code', 'dox-care' ), $message, $errors );
		?>
		<form name="dxc-code-form" id="loginform" action="<?php echo esc_url( self::url() ); ?>" method="post">
			<?php wp_nonce_field( 'dox_care_code' ); ?>
			<?php if ( $step === 'ask' ) : ?>
				<p>
					<label for="user_login"><?php esc_html_e( 'Username or email', 'dox-care' ); ?></label>
					<input type="text" name="log" id="user_login" class="input" value="<?php echo esc_attr( $login ); ?>" autocomplete="username" required autofocus>
				</p>
				<p class="submit"><input type="submit" name="dxc_send" class="button button-primary button-large" value="<?php esc_attr_e( 'Send me a code', 'dox-care' ); ?>"></p>
			<?php else : ?>
				<input type="hidden" name="log" value="<?php echo esc_attr( $login ); ?>">
				<p>
					<label for="dxc-code"><?php esc_html_e( 'Code', 'dox-care' ); ?></label>
					<input type="text" name="code" id="dxc-code" class="input" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
				</p>
				<p class="submit"><input type="submit" name="dxc_verify" class="button button-primary button-large" value="<?php esc_attr_e( 'Sign in', 'dox-care' ); ?>"></p>
			<?php endif; ?>
		</form>
		<p id="nav">
			<?php if ( $step === 'code' ) : ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Request another code', 'dox-care' ); ?></a> |
			<?php endif; ?>
			<a href="<?php echo esc_url( wp_login_url( isset( $_REQUEST['redirect_to'] ) && is_scalar( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '' ) ); ?>"><?php esc_html_e( 'Sign in with your password', 'dox-care' ); ?></a>
		</p>
		<?php
		login_footer( 'user_login' );
		exit;
	}
}
