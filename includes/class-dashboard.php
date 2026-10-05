<?php
/**
 * El escritorio de Dox Care. Sustituye al escritorio de WordPress: quien entra a
 * /wp-admin/ llega aquí. El escritorio de siempre sigue a un clic ("escritorio
 * clásico"), por si hace falta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Dashboard {

	const SLUG = 'dox-care-home';
	const CAP  = 'edit_posts';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ], 5 );
		add_action( 'load-index.php', [ __CLASS__, 'redirect' ], 1 );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_filter( 'admin_body_class', function ( $classes ) {
			return self::is_screen() ? $classes . ' dxc-screen' : $classes;
		} );
	}

	public static function url() {
		return admin_url( 'index.php?page=' . self::SLUG );
	}

	private static function is_screen() {
		return is_admin() && ( $_GET['page'] ?? '' ) === self::SLUG;
	}

	public static function menu() {
		add_dashboard_page( 'Dox Care', __( 'Home', 'dox-care' ), self::CAP, self::SLUG, [ __CLASS__, 'render' ], 0 );
		// El "Inicio" de WordPress pasa a llamarse escritorio clásico y va al final.
		global $submenu;
		if ( isset( $submenu['index.php'] ) ) {
			foreach ( $submenu['index.php'] as $i => $item ) {
				if ( ( $item[2] ?? '' ) === 'index.php' ) {
					unset( $submenu['index.php'][ $i ] );
				}
			}
		}
	}

	/** /wp-admin/ y "Escritorio" llevan al de Dox Care, salvo que se pida el clásico. */
	public static function redirect() {
		if ( isset( $_GET['page'] ) || isset( $_GET['dox_classic'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function assets() {
		if ( ! self::is_screen() ) {
			return;
		}
		wp_enqueue_style( 'dox-care-fonts', 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Poppins:wght@500;600&display=swap', [], null );
		wp_enqueue_style( 'dox-care', DOX_CARE_URL . 'assets/dox-care.css', [], DOX_CARE_VERSION );
		wp_enqueue_script( 'dox-care', DOX_CARE_URL . 'assets/dox-care.js', [], DOX_CARE_VERSION, true );
		wp_localize_script( 'dox-care', 'DoxCare', [ 'ajax' => admin_url( 'admin-ajax.php' ), 'sending' => __( 'Sending…', 'dox-care' ), 'error' => __( 'We could not send it. Please write to us by email.', 'dox-care' ) ] );
	}

	private static function arrow() {
		return '<svg viewBox="0 0 448 512" aria-hidden="true"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224H32c-17.7 0-32 14.3-32 32s14.3 32 32 32h306.7L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></svg>';
	}

	public static function logo() {
		return '<svg viewBox="0 0 523.34 517.95" aria-hidden="true"><polygon fill="#141313" points="299.08 0 74.75 129.45 299.08 258.97 299.08 517.95 523.34 388.5 523.34 129.45 299.08 0"/><path fill="#ff8d27" d="M56.1,464.02h0c74.77,43.15,168.22-10.81,168.22-97.14h0c0-40.07-21.38-77.1-56.08-97.13h0C93.47,226.57,0,280.53,0,366.88h0c0,40.08,21.39,77.11,56.1,97.15Z"/></svg>';
	}

	private static function icon( $name ) {
		$paths = [
			'invoice' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
			'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
			'ticket'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 21 12z"/>',
			'pulse'   => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
		];
		return '<span class="dxc-ic"><svg viewBox="0 0 24 24" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg></span>';
	}

	public static function render() {
		$whmcs    = 'https://clients.doxstudio.com';
		$mail     = Dox_Care_Settings::get( 'support_email' );
		$plan     = Dox_Care_Plans::current();
		$upgrades = Dox_Care_Plans::upgrades();
		$lang     = Dox_Care_Settings::language();
		$plans_url = $lang === 'es' ? 'https://doxstudio.com/es/mantenimiento-web/' : 'https://doxstudio.com/web-maintenance/';
		$name     = Dox_Care_Settings::get( 'client_name' );
		if ( ! $name ) {
			$user = wp_get_current_user();
			$name = $user->first_name ?: $user->display_name;
		}
		$log = array_slice( Dox_Care_Requests::log(), 0, 3 );
		?>
		<div class="dxc">
			<div class="dxc-top">
				<a class="dxc-brand" href="<?php echo esc_url( $lang === 'es' ? 'https://doxstudio.com/es/' : 'https://doxstudio.com/' ); ?>" target="_blank" rel="noopener"><?php echo self::logo(); // phpcs:ignore ?><span>Dox Care</span></a>
				<p><?php esc_html_e( 'Support:', 'dox-care' ); ?> <a href="mailto:<?php echo esc_attr( $mail ); ?>"><?php echo esc_html( $mail ); ?></a></p>
			</div>

			<?php foreach ( Dox_Care_Feed::notices() as $n ) : ?>
				<div class="dxc-notice dxc-notice-<?php echo esc_attr( $n['type'] ); ?>">
					<p><strong><?php echo esc_html( $n['title'] ); ?></strong> <?php echo esc_html( $n['text'] ); ?></p>
					<?php if ( $n['url'] ) : ?><a href="<?php echo esc_url( $n['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'More information', 'dox-care' ); ?></a><?php endif; ?>
				</div>
			<?php endforeach; ?>

			<div class="dxc-status">
				<p><span class="dxc-dot"></span><strong><?php
					/* translators: %s: plan name */
					printf( esc_html__( 'Your website is covered by %s.', 'dox-care' ), esc_html( $plan['name'] ) );
				?></strong> <?php echo esc_html( $plan['included'] ? $plan['included'] . '.' : __( 'Updates, security, backups and support.', 'dox-care' ) ); ?></p>
				<a href="#dxc-includes"><?php esc_html_e( 'What’s included', 'dox-care' ); ?> ↓</a>
			</div>

			<div class="dxc-hello">
				<h1><?php
					/* translators: %s: client name */
					printf( esc_html__( 'Hi, %s', 'dox-care' ), esc_html( $name ) );
				?></h1>
				<p><?php esc_html_e( 'From here you can request changes, check your account and find what you need. We take care of the rest.', 'dox-care' ); ?></p>
			</div>

			<div class="dxc-row dxc-row-21">
				<div class="dxc-card dxc-change">
					<p class="dxc-ey"><?php esc_html_e( 'Changes', 'dox-care' ); ?></p>
					<h2><?php esc_html_e( 'Need to change something on your website?', 'dox-care' ); ?></h2>
					<p><?php
						/* translators: %s: what the plan includes, e.g. "up to 3 content or design updates per month" */
						printf( wp_kses( __( 'Your plan includes <strong>%s</strong>. Tell us here and we do it.', 'dox-care' ), [ 'strong' => [] ] ), esc_html( $plan['changes'] ) );
					?></p>
					<?php
					$limit = Dox_Care_Usage::limit();
					$used  = Dox_Care_Usage::used();
					?>
					<div class="dxc-meter<?php echo ( $limit !== null && $used >= $limit ) ? ' is-full' : ''; ?>">
						<?php if ( $limit !== null ) : ?>
							<span class="dxc-meter-dots" aria-hidden="true"><?php for ( $i = 1; $i <= $limit; $i++ ) : ?><i class="<?php echo $i <= $used ? 'on' : ''; ?>"></i><?php endfor; ?></span>
						<?php endif; ?>
						<span><?php echo esc_html( Dox_Care_Usage::summary() ); ?></span>
						<?php if ( $limit !== null && $used >= $limit ) : ?>
							<small><?php
								/* translators: %s: first day of next month */
								printf( esc_html__( 'New requests this month are quoted separately. Your updates renew on %s.', 'dox-care' ), esc_html( Dox_Care_Settings::date( strtotime( 'first day of next month', current_time( 'timestamp' ) ) ) ) );
							?></small>
						<?php endif; ?>
					</div>
					<?php echo Dox_Care_Requests::form(); // phpcs:ignore ?>
					<p class="dxc-alt"><?php esc_html_e( 'You can also', 'dox-care' ); ?> <a href="<?php echo esc_url( $whmcs . '/submitticket.php?step=2&deptid=2' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'open a ticket', 'dox-care' ); ?></a> <?php esc_html_e( 'or write to', 'dox-care' ); ?> <a href="mailto:<?php echo esc_attr( $mail ); ?>"><?php echo esc_html( $mail ); ?></a>.</p>
					<?php if ( $log ) : ?>
						<div class="dxc-log">
							<p class="dxc-ey"><?php esc_html_e( 'Your latest requests', 'dox-care' ); ?></p>
							<ul>
								<?php foreach ( $log as $r ) : ?>
									<li><b><?php echo esc_html( $r['page'] ); ?></b><span><?php echo esc_html( $r['message'] ); ?></span><small><?php echo esc_html( Dox_Care_Settings::date( $r['time'] ) ); ?></small></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
				<div class="dxc-col">
				<div class="dxc-card dxc-dark">
						<p class="dxc-ey"><?php esc_html_e( 'Your account', 'dox-care' ); ?></p>
						<h3><?php esc_html_e( 'Your whole service in one place', 'dox-care' ); ?></h3>
						<ul class="dxc-check">
							<li><?php esc_html_e( 'Invoices and payments', 'dox-care' ); ?></li>
							<li><?php esc_html_e( 'Your plan and its renewal', 'dox-care' ); ?></li>
							<li><?php esc_html_e( 'Domains and email', 'dox-care' ); ?></li>
							<li><?php esc_html_e( 'Your support tickets', 'dox-care' ); ?></li>
						</ul>
						<a class="dxc-btn dxc-btn-w" href="<?php echo esc_url( $whmcs . '/clientarea.php' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Go to my account', 'dox-care' ); ?> <?php echo self::arrow(); // phpcs:ignore ?></a>
					</div>
				<div class="dxc-card dxc-health">
						<p class="dxc-ey"><?php esc_html_e( 'Your website today', 'dox-care' ); ?></p>
						<ul class="dxc-health-list">
							<?php foreach ( Dox_Care_Status::items() as $it ) : ?>
								<li class="<?php echo $it['ok'] ? 'ok' : 'warn'; ?>"><b><?php echo esc_html( $it['title'] ); ?></b><span><?php echo esc_html( $it['detail'] ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>

			<div class="dxc-card dxc-inc" id="dxc-includes">
					<div class="dxc-inc-head">
						<p class="dxc-ey"><?php esc_html_e( 'Your plan', 'dox-care' ); ?></p>
						<h3><?php
							/* translators: %s: plan name */
							printf( esc_html__( 'What your %s includes', 'dox-care' ), esc_html( $plan['name'] ) );
						?></h3>
					</div>
					<ul class="dxc-check">
						<?php foreach ( $plan['features'] as $f ) : ?>
							<li><?php echo esc_html( $f ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			
			<div class="dxc-row <?php echo $upgrades ? 'dxc-row-11' : ''; ?>">
				<div class="dxc-card">
					<p class="dxc-ey"><?php esc_html_e( 'Do it yourself', 'dox-care' ); ?></p>
					<h3><?php esc_html_e( 'What you can solve in a minute', 'dox-care' ); ?></h3>
					<ul class="dxc-links">
						<li><a href="<?php echo esc_url( $whmcs . '/clientarea.php?action=invoices' ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'invoice' ); // phpcs:ignore ?><span class="tx"><b><?php esc_html_e( 'Download your invoices', 'dox-care' ); ?></b><small><?php esc_html_e( 'All your invoices and payments, in PDF.', 'dox-care' ); ?></small></span></a></li>
						<li><a href="<?php echo esc_url( home_url( '/webmail' ) ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'mail' ); // phpcs:ignore ?><span class="tx"><b><?php esc_html_e( 'Open your email', 'dox-care' ); ?></b><small><?php esc_html_e( 'Webmail for your business email accounts.', 'dox-care' ); ?></small></span></a></li>
						<li><a href="<?php echo esc_url( $whmcs . '/supporttickets.php' ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'ticket' ); // phpcs:ignore ?><span class="tx"><b><?php esc_html_e( 'Check your tickets', 'dox-care' ); ?></b><small><?php esc_html_e( 'What you asked us for and how it is going.', 'dox-care' ); ?></small></span></a></li>
						<li><a href="<?php echo esc_url( $whmcs . '/serverstatus.php' ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'pulse' ); // phpcs:ignore ?><span class="tx"><b><?php esc_html_e( 'Server status', 'dox-care' ); ?></b><small><?php esc_html_e( 'Something slow? See if there is an incident.', 'dox-care' ); ?></small></span></a></li>
					</ul>
				</div>
				<?php if ( $upgrades ) : ?>
					<div class="dxc-card dxc-more">
						<p class="dxc-ey"><?php esc_html_e( 'Want more?', 'dox-care' ); ?></p>
						<h3><?php esc_html_e( 'Continuous improvements for your website', 'dox-care' ); ?></h3>
						<div class="dxc-plans">
							<?php foreach ( $upgrades as $up ) : ?>
								<div class="dxc-plan"><b><?php echo esc_html( $up['name'] ); ?></b><span class="pr">$<?php echo esc_html( $up['price'] ); ?><small><?php esc_html_e( '/mo', 'dox-care' ); ?></small></span><span><?php echo esc_html( $up['pitch'] ); ?></span></div>
							<?php endforeach; ?>
						</div>
						<a class="dxc-btn dxc-btn-o" href="<?php echo esc_url( $plans_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See plans', 'dox-care' ); ?> <?php echo self::arrow(); // phpcs:ignore ?></a>
					</div>
				<?php endif; ?>
			</div>

			<p class="dxc-foot">Dox Studio LLC · <a href="https://doxstudio.com" target="_blank" rel="noopener">doxstudio.com</a> · <a href="<?php echo esc_url( admin_url( 'index.php?dox_classic=1' ) ); ?>"><?php esc_html_e( 'Classic WordPress dashboard', 'dox-care' ); ?></a></p>
		</div>
		<?php
	}
}
