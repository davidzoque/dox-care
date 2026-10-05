<?php
/**
 * Botón "Pedir un cambio aquí" en la barra superior de la web, para quien tiene
 * sesión iniciada. Abre una ventana con el formulario y la página que está viendo
 * ya elegida, así sabemos exactamente de qué página habla.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Admin_Bar {

	public static function init() {
		add_action( 'admin_bar_menu', [ __CLASS__, 'node' ], 80 );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'modal' ] );
	}

	private static function active() {
		return ! is_admin() && is_admin_bar_showing() && current_user_can( Dox_Care_Requests::CAP );
	}

	public static function node( $bar ) {
		if ( ! self::active() ) {
			return;
		}
		$bar->add_node( [
			'id'    => 'dox-care-request',
			'title' => '<span class="ab-icon dashicons dashicons-edit-page" style="top:2px"></span><span class="ab-label">' . esc_html__( 'Request a change here', 'dox-care' ) . '</span>',
			'href'  => '#dox-care',
			'meta'  => [ 'class' => 'dox-care-ab' ],
		] );
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'dox-care-fonts', 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Poppins:wght@500;600&display=swap', [], null );
		wp_enqueue_style( 'dox-care', DOX_CARE_URL . 'assets/dox-care.css', [], DOX_CARE_VERSION );
		wp_enqueue_script( 'dox-care', DOX_CARE_URL . 'assets/dox-care.js', [], DOX_CARE_VERSION, true );
		wp_localize_script( 'dox-care', 'DoxCare', [ 'ajax' => admin_url( 'admin-ajax.php' ), 'sending' => __( 'Sending…', 'dox-care' ), 'error' => __( 'We could not send it. Please write to us by email.', 'dox-care' ) ] );
	}

	/** La página que se está viendo, sin parámetros de seguimiento. */
	private static function current_url() {
		if ( is_singular() ) {
			return get_permalink();
		}
		if ( is_front_page() || is_home() ) {
			return home_url( '/' );
		}
		$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ), PHP_URL_PATH );
		return home_url( $path ?: '/' );
	}

	public static function modal() {
		if ( ! self::active() ) {
			return;
		}
		?>
		<div class="dxc-modal" id="dox-care" hidden>
			<div class="dxc-modal-bg" data-dxc-close></div>
			<div class="dxc-modal-box" role="dialog" aria-modal="true" aria-labelledby="dxc-modal-title">
				<button type="button" class="dxc-modal-x" data-dxc-close aria-label="<?php esc_attr_e( 'Close', 'dox-care' ); ?>">×</button>
				<p class="dxc-ey">Dox Care</p>
				<h2 id="dxc-modal-title"><?php esc_html_e( 'Request a change', 'dox-care' ); ?></h2>
				<p class="dxc-modal-sub"><?php esc_html_e( 'Tell us what to change on this page and we take care of it.', 'dox-care' ); ?></p>
				<?php echo Dox_Care_Requests::form( self::current_url() ); // phpcs:ignore ?>
			</div>
		</div>
		<?php
	}
}
