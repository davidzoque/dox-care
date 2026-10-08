<?php
/**
 * Que los enlaces de la pantalla de entrada se lean, sea cual sea el fondo.
 *
 * WordPress pinta sus enlaces del login en azul (o gris) pensando en su fondo claro.
 * Cuando el tema o Hide My WP ponen un fondo de color o una tarjeta oscura, algunos
 * dejan de leerse: "Privacy Policy" azul sobre rojo en mavastore o sobre azul en
 * optica-suiza, el selector de idioma, o nuestro "Entrar con un código" en la tarjeta
 * negra de emybeautybar.
 *
 * Un JS al final de la página mide cada uno contra su fondo y solo toca los que no
 * llegan a un contraste de 4,5 a 1 (el mínimo de accesibilidad para texto). Les pone
 * el color del botón de entrar, que es el de la marca en un login personalizado, si se
 * lee bien; si no, blanco o casi negro, el que más contraste. Lo que ya se lee no se
 * toca, y si el fondo es una imagen que se ve no se puede medir y tampoco se toca.
 *
 * Nuestro enlace va aparte: lleva el color del botón siempre que se lea, para que
 * combine con la marca, y si no, el del texto del formulario (ver Dox_Care_Login).
 * Va en todas las pantallas del login, esté o no encendido el código por correo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Login_Colors {

	/** Textos de WordPress fuera de la tarjeta que se arreglan si no se leen. */
	const TARGETS = '.privacy-policy-page-link a,#nav a,#backtoblog a,.language-switcher label,.language-switcher .button';

	public static function init() {
		if ( ! apply_filters( 'dox_care_login_colors', true ) ) {
			return;
		}
		add_action( 'login_footer', [ __CLASS__, 'script' ], 99 );
	}

	public static function script() {
		echo '<script>(function(){function run(){'
			// Cualquier formato de color (rgb, oklab, color()...) a números de 0 a 255, vía canvas.
			. 'var x=document.createElement("canvas").getContext("2d");if(!x){return;}'
			. 'function rgba(c){x.clearRect(0,0,1,1);x.fillStyle="#000";x.fillStyle=c;x.fillRect(0,0,1,1);return x.getImageData(0,0,1,1).data;}'
			. 'function lum(d){var r=[0,1,2].map(function(i){var v=d[i]/255;return v<=.04045?v/12.92:Math.pow((v+.055)/1.055,2.4);});return .2126*r[0]+.7152*r[1]+.0722*r[2];}'
			. 'function ratio(a,b){a=lum(a);b=lum(b);return(Math.max(a,b)+.05)/(Math.min(a,b)+.05);}'
			// El fondo es el del primer contenedor que no sea transparente, con las imágenes que
			// haya por el camino; null si hay un degradado o varias capas, que no se pueden medir.
			. 'function bg(e){var u=[];while(e&&e.nodeType===1){var s=getComputedStyle(e),i=s.backgroundImage;if(i!=="none"){var m=i.match(/^url\\("?(.*?)"?\\)$/);if(!m){return null;}u.push(m[1]);}var d=rgba(s.backgroundColor);if(d[3]>127){return{c:d,u:u};}e=e.parentNode;}return{c:[255,255,255,255],u:u};}'
			// Una imagen que carga tapa el color y no se puede medir; una que falla (Hide My WP
			// bloquea las de White Label CMS, por ejemplo) no se ve y no cuenta.
			. 'function seen(u,cb){var n=u.length,img=false;if(!n){return cb(false);}u.forEach(function(s){var i=new Image();i.onload=function(){img=true;end();};i.onerror=end;i.src=s;});function end(){if(--n===0){cb(img);}}}'
			. 'function when(e,cb){var g=bg(e);if(g){seen(g.u,function(img){if(!img){cb(g.c);}});}}'
			. 'var b=document.querySelector("#wp-submit,#loginform .button-primary"),bc=b?getComputedStyle(b).backgroundColor:"",bd=bc?rgba(bc):null;if(bd&&bd[3]<230){bd=null;}'
			. 'function pick(g){if(bd&&ratio(bd,g)>=4.5){return bc;}return ratio([255,255,255],g)>=ratio([29,35,39],g)?"#fff":"#1d2327";}'
			// Nuestro enlace: el color del botón si se lee; si no, se queda el del CSS.
			. 'var l=document.querySelector("#dxc-code-link a");if(l&&bd){when(l.parentNode,function(g){if(ratio(bd,g)>=4.5){l.style.setProperty("--dxc-link",bc);}});}'
			. 'document.querySelectorAll(' . wp_json_encode( self::TARGETS ) . ').forEach(function(e){'
			. 'if(!e.getClientRects().length){return;}var s=getComputedStyle(e),t=rgba(s.color);'
			// Un botón con fondo propio ya trae su contraste; solo se arregla si es transparente.
			. 'var o=e.classList.contains("button")?rgba(s.backgroundColor):null;if(o&&o[3]>127){return;}'
			. 'when(o?e.parentNode:e,function(g){if(ratio(t,g)>=4.5){return;}'
			. 'var c=pick(g);e.style.setProperty("color",c,"important");if(o){e.style.setProperty("border-color",c,"important");}});'
			. '});'
			. '}'
			// Se mide con la página ya cargada: White Label CMS y otros imprimen sus estilos del
			// login más abajo que este script, y antes el fondo aún es el gris de WordPress.
			. 'if(document.readyState==="complete"){run();}else{window.addEventListener("load",run);}'
			. '})();</script>';
	}
}
