<?php
/**
 * wp_mail() con la versión en texto plano junto al HTML. wp_mail solo manda el HTML, y a
 * Gmail no le gusta un correo sin la parte de texto: se la pone PHPMailer en
 * phpmailer_init, solo durante este envío (también sale así por GoSMTP y similares).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Mailer {

	/** @return bool Si salió. */
	public static function send( $to, $subject, $html, array $headers, array $attachments = [] ) {
		$alt = static function ( $phpmailer ) use ( $html ) {
			$phpmailer->AltBody = self::to_text( $html );
		};
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		remove_action( 'phpmailer_init', $alt );
		return $sent;
	}

	/**
	 * El texto de un correo HTML: párrafos separados y los enlaces con su dirección, que en
	 * texto plano es lo único que se puede copiar.
	 */
	public static function to_text( $html ) {
		$text = preg_replace( '#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html );
		$text = preg_replace_callback(
			'#<a\b[^>]*?href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
			static function ( $m ) {
				$url   = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				$label = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) );
				if ( $label === '' ) {
					return ''; // Un logo enlazado no aporta nada en texto.
				}
				return ( $label === $url || 0 === strpos( $url, 'mailto:' ) ) ? $label : $label . ' (' . $url . ')';
			},
			$text
		);
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = preg_replace( '#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $text );
		$text = preg_replace( '#<hr\b[^>]*>#i', "\n----\n", $text );
		$text = preg_replace( '#</t[dh]>#i', ' ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
