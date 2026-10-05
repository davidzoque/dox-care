<?php
/**
 * Los planes de mantenimiento de Dox Studio, tal como están en
 * doxstudio.com/es/mantenimiento-web/ (octubre de 2026). Los precios se pueden
 * actualizar desde el aviso central sin publicar una versión nueva del plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Plans {

	public static function all() {
		$plans = [
			'essentials' => [
				'name'     => 'Essentials Care',
				'price'    => 47,
				'included' => __( 'Included in your Dox Studio hosting', 'dox-care' ),
				'changes'  => __( 'up to 3 content or design updates per month', 'dox-care' ),
				'pitch'    => '',
				'features' => [
					__( 'Up to 3 content or design updates per month', 'dox-care' ),
					__( 'WordPress core updates', 'dox-care' ),
					__( 'Theme and plugin updates', 'dox-care' ),
					__( 'Real-time security and firewall', 'dox-care' ),
					'Dox Security',
					__( 'Daily backups', 'dox-care' ),
					__( 'Malware and virus removal', 'dox-care' ),
					__( 'Content delivery network (CDN)', 'dox-care' ),
				],
			],
			'pro' => [
				'name'     => 'Pro Care',
				'price'    => 97,
				'included' => '',
				'changes'  => __( 'unlimited design and content updates', 'dox-care' ),
				'pitch'    => __( 'Unlimited changes, speed optimization, analytics and monthly reports.', 'dox-care' ),
				'features' => [
					__( 'Everything in Essentials Care', 'dox-care' ),
					__( 'Unlimited design and content updates', 'dox-care' ),
					__( 'Website speed optimization', 'dox-care' ),
					__( 'Unlimited image optimization', 'dox-care' ),
					'Elementor Pro',
					__( '2 developer hours per month', 'dox-care' ),
					__( 'Daily cloud backups, 30-day archive', 'dox-care' ),
					__( '24/7 uptime monitoring', 'dox-care' ),
					__( 'Website analytics and monthly reports', 'dox-care' ),
				],
			],
			'elite' => [
				'name'     => 'Elite Care',
				'price'    => 137,
				'included' => '',
				'changes'  => __( 'unlimited design and content updates', 'dox-care' ),
				'pitch'    => __( 'Proactive optimization, SEO audit, a monthly call and priority support.', 'dox-care' ),
				'features' => [
					__( 'Everything in Pro Care', 'dox-care' ),
					__( '5 developer hours per month', 'dox-care' ),
					__( 'Speed and Core Web Vitals optimization', 'dox-care' ),
					__( 'Automatic PNG/JPG to WebP conversion', 'dox-care' ),
					__( 'Monthly strategy call (30 min)', 'dox-care' ),
					__( 'Technical SEO audit', 'dox-care' ),
					__( 'Priority chat support', 'dox-care' ),
					__( 'Weekly reports', 'dox-care' ),
				],
			],
		];

		// Precios vigentes desde el aviso central, si los trae.
		$feed = Dox_Care_Feed::get();
		if ( ! empty( $feed['prices'] ) && is_array( $feed['prices'] ) ) {
			foreach ( $feed['prices'] as $key => $price ) {
				if ( isset( $plans[ $key ] ) && is_numeric( $price ) ) {
					$plans[ $key ]['price'] = $price + 0;
				}
			}
		}
		return $plans;
	}

	public static function current() {
		$plans = self::all();
		$key   = Dox_Care_Settings::get( 'plan' );
		return $plans[ $key ] ?? $plans['essentials'];
	}

	/** Los planes por encima del actual, para la tarjeta "¿Quieres más?". */
	public static function upgrades() {
		$order = array_keys( self::all() );
		$pos   = array_search( Dox_Care_Settings::get( 'plan' ), $order, true );
		$pos   = $pos === false ? 0 : $pos;
		return array_slice( self::all(), $pos + 1, null, true );
	}
}
