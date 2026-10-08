<?php
/**
 * Plugin Name: Dox Care
 * Plugin URI:  https://doxstudio.com/es/mantenimiento-web/
 * Update URI:  https://github.com/davidzoque/dox-care
 * Description: Your website care plan with Dox Studio: a dashboard with your plan, your site status and a one-click way to request changes.
 * Version:     0.3.5
 * Author:      Dox Studio
 * Author URI:  https://doxstudio.com
 * License:     GPL-2.0+
 * Text Domain: dox-care
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DOX_CARE_VERSION', '0.3.5' );
define( 'DOX_CARE_FILE', __FILE__ );
define( 'DOX_CARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOX_CARE_URL', plugin_dir_url( __FILE__ ) );

// Dirección del aviso central: un JSON en my.doxstudio.com con avisos para todos los
// clientes y los precios vigentes de los planes. Se puede cambiar en wp-config.php.
if ( ! defined( 'DOX_CARE_FEED' ) ) {
	define( 'DOX_CARE_FEED', 'https://my.doxstudio.com/dox-care.json' );
}

// A dónde llegan los avisos de cambios en los ajustes y en el contador.
if ( ! defined( 'DOX_CARE_ALERT_TO' ) ) {
	define( 'DOX_CARE_ALERT_TO', 'support@doxstudio.com' );
}

// Actualizaciones automáticas desde las releases de GitHub (Plugin Update Checker),
// con el ZIP limpio que adjunta el workflow. El filtro del nombre evita que coja otro
// adjunto si algún día la release lleva más de uno.
$dox_care_puc = DOX_CARE_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $dox_care_puc ) ) {
	require_once $dox_care_puc;
	// Hide My WP trae su propia copia del actualizador sin Parsedown: si se carga antes
	// la suya, leer una release con notas da un error fatal. Cargamos la nuestra.
	if ( ! class_exists( 'Parsedown', false ) && file_exists( DOX_CARE_DIR . 'vendor/plugin-update-checker/vendor/Parsedown.php' ) ) {
		require_once DOX_CARE_DIR . 'vendor/plugin-update-checker/vendor/Parsedown.php';
	}
	$dox_care_updates = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/davidzoque/dox-care/',
		__FILE__,
		'dox-care'
	);
	$dox_care_updates->setBranch( 'main' );
	$dox_care_updates->getVcsApi()->enableReleaseAssets( '/^dox-care\.zip$/' );
}

// Menú común de los plugins de Dox Studio (se carga solo la copia más nueva).
if ( file_exists( DOX_CARE_DIR . 'dox-core/loader.php' ) && file_exists( DOX_CARE_DIR . 'dox-core/version.php' ) ) {
	require_once DOX_CARE_DIR . 'dox-core/loader.php';
	if ( class_exists( 'Dox_Core_Loader' ) ) {
		Dox_Core_Loader::register( require DOX_CARE_DIR . 'dox-core/version.php', DOX_CARE_DIR . 'dox-core/dox-core.php' );
	}
}

require_once DOX_CARE_DIR . 'includes/class-settings.php';
require_once DOX_CARE_DIR . 'includes/class-plans.php';
require_once DOX_CARE_DIR . 'includes/class-feed.php';
require_once DOX_CARE_DIR . 'includes/class-status.php';
require_once DOX_CARE_DIR . 'includes/class-usage.php';
require_once DOX_CARE_DIR . 'includes/class-requests.php';
require_once DOX_CARE_DIR . 'includes/class-dashboard.php';
require_once DOX_CARE_DIR . 'includes/class-admin-bar.php';
require_once DOX_CARE_DIR . 'includes/class-login.php';
require_once DOX_CARE_DIR . 'includes/class-login-colors.php';

// Idioma: el del usuario, o el que se fije en los ajustes. Se carga nuestro .mo
// para cualquier variante de español (es_ES, es_CO, es_MX...).
add_action( 'init', function () {
	if ( Dox_Care_Settings::language() === 'es' ) {
		unload_textdomain( 'dox-care' );
		load_textdomain( 'dox-care', DOX_CARE_DIR . 'languages/dox-care-es_ES.mo' );
	}
}, 1 );

add_action( 'plugins_loaded', function () {
	Dox_Care_Settings::init();
	Dox_Care_Feed::init();
	Dox_Care_Dashboard::init();
	Dox_Care_Requests::init();
	Dox_Care_Admin_Bar::init();
	Dox_Care_Login::init();
	Dox_Care_Login_Colors::init();
} );

// Entrada en el menú "Dox Plugins".
add_action( 'dox_core_register', function ( $core ) {
	$core->register_plugin( [
		'slug'    => 'dox-care',
		'name'    => 'Dox Care',
		'version' => DOX_CARE_VERSION,
		'summary' => __( 'Your care plan, site status and change requests.', 'dox-care' ),
		'url'     => 'https://doxstudio.com/es/mantenimiento-web/',
		'page'    => [
			'menu_title' => 'Dox Care',
			'page_title' => 'Dox Care',
			'callback'   => [ 'Dox_Care_Settings', 'render' ],
		],
	] );
} );

register_activation_hook( __FILE__, function () {
	// El escritorio de UiCore y el nuestro no pueden convivir: los dos redirigen el
	// escritorio de WordPress. Al activar Dox Care se apaga el de UiCore.
	$ac = get_option( 'uicore_theme_options_admin_customizer' );
	if ( is_array( $ac ) && ( $ac['wp_custom_dash'] ?? '' ) === 'true' ) {
		$ac['wp_custom_dash'] = 'false';
		update_option( 'uicore_theme_options_admin_customizer', $ac );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( Dox_Care_Feed::HOOK );
} );
