<?php
/**
 * Ajustes de Dox Care: qué plan tiene el cliente, en qué idioma se le muestra y a
 * qué correo llegan sus solicitudes. Una sola opción, `dox_care_settings`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Settings {

	const OPTION = 'dox_care_settings';

	public static function defaults() {
		return [
			'plan'          => 'essentials',
			'language'      => 'auto',
			'support_email' => 'support@doxstudio.com',
			'client_name'   => '',
		];
	}

	public static function all() {
		$saved = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** 'es' o 'en'. En automático, el del usuario que está viendo el panel. */
	public static function language() {
		$lang = self::get( 'language' );
		if ( in_array( $lang, [ 'es', 'en' ], true ) ) {
			return $lang;
		}
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return strpos( (string) $locale, 'es' ) === 0 ? 'es' : 'en';
	}

	/** Fecha corta en el idioma del panel (la web puede estar en otro idioma). */
	public static function date( $timestamp ) {
		if ( self::language() !== 'es' ) {
			return date_i18n( 'F j, Y', $timestamp );
		}
		$months = [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ];
		$t      = (int) $timestamp + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		return gmdate( 'j', $t ) . ' de ' . $months[ (int) gmdate( 'n', $t ) - 1 ] . ' de ' . gmdate( 'Y', $t );
	}

	/**
	 * Solo el equipo de Dox Studio cambia el plan y el contador: el usuario "support"
	 * o un correo de doxstudio.com / paradoxstudio.co. Muchos clientes tienen su propia
	 * cuenta de administrador y no deben poder poner su contador a cero.
	 */
	public static function is_staff() {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() || ! user_can( $user, 'manage_options' ) ) {
			return false;
		}
		$email = strtolower( (string) $user->user_email );
		$staff = $user->user_login === 'support'
			|| substr( $email, -strlen( '@doxstudio.com' ) ) === '@doxstudio.com'
			|| substr( $email, -strlen( '@paradoxstudio.co' ) ) === '@paradoxstudio.co';
		return (bool) apply_filters( 'dox_care_is_staff', $staff, $user );
	}

	public static function init() {
		add_action( 'admin_post_dox_care_save', [ __CLASS__, 'save' ] );
		add_action( 'admin_post_dox_care_usage_save', [ __CLASS__, 'usage_save' ] );
		add_action( 'admin_post_dox_care_usage_add', [ __CLASS__, 'usage_add' ] );
	}

	private static function guard( $nonce ) {
		if ( ! self::is_staff() ) {
			wp_die( esc_html__( 'Only Dox Studio can change this.', 'dox-care' ) );
		}
		check_admin_referer( $nonce );
	}

	public static function usage_save() {
		self::guard( 'dox_care_usage_save' );
		$month = preg_replace( '/[^0-9-]/', '', (string) ( $_POST['month'] ?? '' ) );
		$units = array_map( 'intval', (array) ( $_POST['units'] ?? [] ) );
		$notes = array_map( 'sanitize_text_field', wp_unslash( (array) ( $_POST['note'] ?? [] ) ) );
		$del   = array_map( 'sanitize_key', (array) ( $_POST['delete'] ?? [] ) );
		Dox_Care_Usage::update( $month, $units, $notes, $del );
		wp_safe_redirect( add_query_arg( [ 'updated' => '1', 'month' => $month ], wp_get_referer() ) . '#dxc-usage' );
		exit;
	}

	public static function usage_add() {
		self::guard( 'dox_care_usage_add' );
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		$time = $date ? strtotime( $date . ' 12:00:00' ) : time();
		Dox_Care_Usage::add( [
			'source'  => 'manual',
			'time'    => $time ?: time(),
			'page'    => sanitize_text_field( wp_unslash( $_POST['page'] ?? '' ) ),
			'message' => sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) ),
			'units'   => (int) ( $_POST['units'] ?? 1 ),
			'note'    => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
		] );
		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) . '#dxc-usage' );
		exit;
	}

	public static function save() {
		self::guard( 'dox_care_save' );

		$plans = array_keys( Dox_Care_Plans::all() );
		$plan  = sanitize_key( $_POST['plan'] ?? 'essentials' );
		$lang  = sanitize_key( $_POST['language'] ?? 'auto' );
		$mail  = sanitize_email( wp_unslash( $_POST['support_email'] ?? '' ) );

		update_option( self::OPTION, [
			'plan'          => in_array( $plan, $plans, true ) ? $plan : 'essentials',
			'language'      => in_array( $lang, [ 'auto', 'es', 'en' ], true ) ? $lang : 'auto',
			'support_email' => is_email( $mail ) ? $mail : 'support@doxstudio.com',
			'client_name'   => sanitize_text_field( wp_unslash( $_POST['client_name'] ?? '' ) ),
		] );

		wp_safe_redirect( add_query_arg( 'updated', '1', wp_get_referer() ) );
		exit;
	}

	public static function render() {
		$s     = self::all();
		$plans = Dox_Care_Plans::all();
		if ( ! self::is_staff() ) {
			?>
			<div class="wrap dxc-settings">
				<h1>Dox Care</h1>
				<p><?php
					/* translators: %s: plan name */
					printf( esc_html__( 'Your plan: %s.', 'dox-care' ), esc_html( Dox_Care_Plans::current()['name'] ) );
				?> <?php echo esc_html( Dox_Care_Usage::summary() ); ?>.</p>
				<p><a class="button button-primary" href="<?php echo esc_url( Dox_Care_Dashboard::url() ); ?>"><?php esc_html_e( 'See the dashboard', 'dox-care' ); ?></a></p>
			</div>
			<?php
			return;
		}
		?>
		<div class="wrap dxc-settings">
			<h1>Dox Care</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'dox-care' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dox_care_save">
				<?php wp_nonce_field( 'dox_care_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dxc-plan"><?php esc_html_e( 'Care plan', 'dox-care' ); ?></label></th>
						<td>
							<select id="dxc-plan" name="plan">
								<?php foreach ( $plans as $key => $plan ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['plan'], $key ); ?>><?php echo esc_html( $plan['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The plan this client has. It decides what the dashboard says is included.', 'dox-care' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dxc-name"><?php esc_html_e( 'Client name', 'dox-care' ); ?></label></th>
						<td>
							<input id="dxc-name" type="text" class="regular-text" name="client_name" value="<?php echo esc_attr( $s['client_name'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Used in the greeting; if empty, the name of the logged-in user is used.', 'dox-care' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dxc-lang"><?php esc_html_e( 'Language', 'dox-care' ); ?></label></th>
						<td>
							<select id="dxc-lang" name="language">
								<option value="auto" <?php selected( $s['language'], 'auto' ); ?>><?php esc_html_e( 'Automatic (each user’s language)', 'dox-care' ); ?></option>
								<option value="es" <?php selected( $s['language'], 'es' ); ?>>Español</option>
								<option value="en" <?php selected( $s['language'], 'en' ); ?>>English</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dxc-mail"><?php esc_html_e( 'Requests go to', 'dox-care' ); ?></label></th>
						<td><input id="dxc-mail" type="email" class="regular-text" name="support_email" value="<?php echo esc_attr( $s['support_email'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save changes', 'dox-care' ) ); ?>
			</form>
			<?php self::render_usage(); ?>
			<p><a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( 'See the dashboard', 'dox-care' ); ?></a></p>
		</div>
		<?php
	}

	/** Tabla de actualizaciones de un mes y formulario para añadir una a mano. */
	private static function render_usage() {
		$months = Dox_Care_Usage::months();
		$month  = preg_replace( '/[^0-9-]/', '', (string) ( $_GET['month'] ?? '' ) );
		$month  = in_array( $month, $months, true ) ? $month : Dox_Care_Usage::month_key();
		$rows   = Dox_Care_Usage::entries( $month );
		$limit  = Dox_Care_Usage::limit();
		$used   = Dox_Care_Usage::used( $month );
		$base   = menu_page_url( 'dox-care', false ) ?: admin_url( 'admin.php?page=dox-care' );
		?>
		<hr>
		<h2 id="dxc-usage"><?php esc_html_e( 'Updates', 'dox-care' ); ?></h2>
		<p>
			<?php foreach ( $months as $m ) : ?>
				<?php if ( $m === $month ) : ?><strong><?php echo esc_html( $m ); ?></strong><?php else : ?><a href="<?php echo esc_url( add_query_arg( 'month', $m, $base ) . '#dxc-usage' ); ?>"><?php echo esc_html( $m ); ?></a><?php endif; ?>
				&nbsp;
			<?php endforeach; ?>
		</p>
		<p><strong><?php
			echo $limit === null
				/* translators: %d: requests */
				? esc_html( sprintf( __( '%d requests (unlimited plan)', 'dox-care' ), $used ) )
				/* translators: 1: used, 2: included */
				: esc_html( sprintf( __( '%1$d of %2$d updates used', 'dox-care' ), $used, $limit ) );
		?></strong> <?php esc_html_e( 'Each request from the form counts 1. Set 0 if it was a fix on our side, or 2 if it covered two pages.', 'dox-care' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dox_care_usage_save">
			<input type="hidden" name="month" value="<?php echo esc_attr( $month ); ?>">
			<?php wp_nonce_field( 'dox_care_usage_save' ); ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Date', 'dox-care' ); ?></th>
					<th><?php esc_html_e( 'Page', 'dox-care' ); ?></th>
					<th><?php esc_html_e( 'Request', 'dox-care' ); ?></th>
					<th><?php esc_html_e( 'Source', 'dox-care' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Counts', 'dox-care' ); ?></th>
					<th><?php esc_html_e( 'Note', 'dox-care' ); ?></th>
					<th style="width:70px"><?php esc_html_e( 'Delete', 'dox-care' ); ?></th>
				</tr></thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No updates this month.', 'dox-care' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( self::date( $r['time'] ) ); ?></td>
							<td><?php echo esc_html( $r['page'] ); ?></td>
							<td><?php echo esc_html( $r['message'] ); ?></td>
							<td><?php echo $r['source'] === 'manual' ? esc_html__( 'Added by hand', 'dox-care' ) : esc_html__( 'Form', 'dox-care' ); ?></td>
							<td><input type="number" min="0" max="9" name="units[<?php echo esc_attr( $r['id'] ); ?>]" value="<?php echo esc_attr( $r['units'] ); ?>" style="width:60px"></td>
							<td><input type="text" class="regular-text" name="note[<?php echo esc_attr( $r['id'] ); ?>]" value="<?php echo esc_attr( $r['note'] ); ?>"></td>
							<td><input type="checkbox" name="delete[]" value="<?php echo esc_attr( $r['id'] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $rows ) { submit_button( __( 'Save changes', 'dox-care' ), 'secondary' ); } ?>
		</form>
		<h3><?php esc_html_e( 'Add an update by hand', 'dox-care' ); ?></h3>
		<p><?php esc_html_e( 'For changes that came by email or ticket.', 'dox-care' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="dox_care_usage_add">
			<?php wp_nonce_field( 'dox_care_usage_add' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="dxc-u-date"><?php esc_html_e( 'Date', 'dox-care' ); ?></label></th><td><input id="dxc-u-date" type="date" name="date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="dxc-u-page"><?php esc_html_e( 'Page', 'dox-care' ); ?></label></th><td><input id="dxc-u-page" type="text" class="regular-text" name="page"></td></tr>
				<tr><th scope="row"><label for="dxc-u-msg"><?php esc_html_e( 'What was changed', 'dox-care' ); ?></label></th><td><input id="dxc-u-msg" type="text" class="large-text" name="message" required></td></tr>
				<tr><th scope="row"><label for="dxc-u-units"><?php esc_html_e( 'Counts', 'dox-care' ); ?></label></th><td><input id="dxc-u-units" type="number" min="0" max="9" name="units" value="1" style="width:60px"></td></tr>
				<tr><th scope="row"><label for="dxc-u-note"><?php esc_html_e( 'Note', 'dox-care' ); ?></label></th><td><input id="dxc-u-note" type="text" class="regular-text" name="note"></td></tr>
			</table>
			<?php submit_button( __( 'Add update', 'dox-care' ), 'secondary' ); ?>
		</form>
		<?php
	}
}
