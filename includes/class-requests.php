<?php
/**
 * "Pedir un cambio": el formulario del escritorio y de la barra superior manda la
 * solicitud por correo a soporte (support@doxstudio.com por defecto), con la web,
 * la página, quién la pide y los archivos adjuntos. Se guardan las últimas 20 en
 * la propia web para que el cliente vea lo que ha pedido.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dox_Care_Requests {

	const LOG        = 'dox_care_requests';
	const CAP        = 'edit_posts';
	const MAX_FILES  = 5;
	const MAX_BYTES  = 10485760; // 10 MB por archivo.
	const DAILY_MAX  = 15;

	public static function init() {
		add_action( 'wp_ajax_dox_care_request', [ __CLASS__, 'handle' ] );
	}

	public static function allowed_types() {
		return [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'pdf', 'doc', 'docx', 'txt', 'xls', 'xlsx', 'zip' ];
	}

	/** Las páginas que puede elegir en el formulario. */
	public static function page_choices() {
		$out   = [ [ 'url' => home_url( '/' ), 'title' => __( 'Home page', 'dox-care' ) ] ];
		$front = (int) get_option( 'page_on_front' );
		$pages = get_posts( [ 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order title', 'order' => 'ASC' ] );
		foreach ( $pages as $p ) {
			if ( $p->ID === $front ) {
				continue;
			}
			$out[] = [ 'url' => get_permalink( $p ), 'title' => $p->post_title ?: __( '(no title)', 'dox-care' ) ];
		}
		return $out;
	}

	public static function log() {
		$log = get_option( self::LOG, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function handle() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do this.', 'dox-care' ) ], 403 );
		}
		check_ajax_referer( 'dox_care_request', 'nonce' );

		$page    = esc_url_raw( wp_unslash( $_POST['page'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		if ( mb_strlen( trim( $message ) ) < 5 ) {
			wp_send_json_error( [ 'message' => __( 'Tell us what you want to change.', 'dox-care' ) ], 400 );
		}

		// Un tope diario por web, por si alguien envía el formulario en bucle.
		$today = array_filter( self::log(), function ( $r ) {
			return ( $r['time'] ?? 0 ) > time() - DAY_IN_SECONDS;
		} );
		if ( count( $today ) >= self::DAILY_MAX ) {
			wp_send_json_error( [ 'message' => __( 'You have sent many requests today. Write to us by email and we will take care of it.', 'dox-care' ) ], 429 );
		}

		// Adjuntos: se copian a una carpeta temporal con su nombre original.
		$attachments = [];
		$names       = [];
		$tmp_dir     = trailingslashit( get_temp_dir() ) . 'dox-care-' . wp_generate_password( 8, false );
		if ( ! empty( $_FILES['files']['name'] ) && is_array( $_FILES['files']['name'] ) ) {
			wp_mkdir_p( $tmp_dir );
			$count = min( count( $_FILES['files']['name'] ), self::MAX_FILES );
			for ( $i = 0; $i < $count; $i++ ) {
				$err  = $_FILES['files']['error'][ $i ] ?? UPLOAD_ERR_NO_FILE;
				$name = sanitize_file_name( wp_unslash( $_FILES['files']['name'][ $i ] ?? '' ) );
				$tmp  = $_FILES['files']['tmp_name'][ $i ] ?? '';
				$size = (int) ( $_FILES['files']['size'][ $i ] ?? 0 );
				if ( $err !== UPLOAD_ERR_OK || ! is_uploaded_file( $tmp ) || $size > self::MAX_BYTES ) {
					continue;
				}
				$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				if ( ! in_array( $ext, self::allowed_types(), true ) ) {
					continue;
				}
				$dest = trailingslashit( $tmp_dir ) . $name;
				if ( @move_uploaded_file( $tmp, $dest ) ) {
					$attachments[] = $dest;
					$names[]       = $name;
				}
			}
		}

		$user  = wp_get_current_user();
		$site  = wp_parse_url( home_url(), PHP_URL_HOST );
		$title = self::page_title( $page );

		// Cuenta del mes (Términos, sección F): esta solicitud sería la número $n.
		$limit = Dox_Care_Usage::limit();
		$n     = Dox_Care_Usage::used() + 1;
		$over  = $limit !== null && $n > $limit;

		/* translators: 1: site domain, 2: page title */
		$subject = sprintf( __( '[Dox Care] Change on %1$s: %2$s', 'dox-care' ), $site, $title );
		if ( $over ) {
			$subject = __( '[Outside the plan]', 'dox-care' ) . ' ' . $subject;
		}

		$rows = [
			__( 'Website', 'dox-care' )   => '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $site ) . '</a>',
			__( 'Page', 'dox-care' )      => $page ? '<a href="' . esc_url( $page ) . '">' . esc_html( $title ) . '</a><br><small>' . esc_html( $page ) . '</small>' : esc_html( $title ),
			__( 'Requested by', 'dox-care' ) => esc_html( $user->display_name ) . ' &lt;' . esc_html( $user->user_email ) . '&gt;',
			__( 'Plan', 'dox-care' )      => esc_html( Dox_Care_Plans::current()['name'] ),
			__( 'Attachments', 'dox-care' ) => $names ? esc_html( implode( ', ', $names ) ) : __( 'None', 'dox-care' ),
			__( 'This month', 'dox-care' ) => $limit === null
				/* translators: %d: request number this month */
				? esc_html( sprintf( __( 'Request number %d (unlimited plan)', 'dox-care' ), $n ) )
				/* translators: 1: update number, 2: included per month */
				: ( $over ? '<strong style="color:#B42318">' . esc_html( sprintf( __( 'Update %1$d of %2$d: outside the plan, quote it separately', 'dox-care' ), $n, $limit ) ) . '</strong>' : esc_html( sprintf( __( 'Update %1$d of %2$d', 'dox-care' ), $n, $limit ) ) ),
		];
		$body  = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#141313;max-width:620px">';
		$body .= '<h2 style="font-size:18px;margin:0 0 14px">' . esc_html__( 'New change request', 'dox-care' ) . '</h2>';
		$body .= '<table cellpadding="6" style="border-collapse:collapse;margin-bottom:16px">';
		foreach ( $rows as $label => $value ) {
			$body .= '<tr><td style="color:#6B6866;vertical-align:top;white-space:nowrap">' . esc_html( $label ) . '</td><td>' . $value . '</td></tr>';
		}
		$body .= '</table><div style="background:#F4F3F1;border-radius:8px;padding:14px 16px;white-space:pre-wrap">' . esc_html( $message ) . '</div>';
		$body .= '<p style="color:#9C9893;font-size:12px;margin-top:18px">' . esc_html__( 'Sent from the Dox Care dashboard. Reply to this email to answer the client.', 'dox-care' ) . '</p></div>';

		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		if ( is_email( $user->user_email ) ) {
			$headers[] = 'Reply-To: ' . $user->display_name . ' <' . $user->user_email . '>';
		}

		$sent = wp_mail( Dox_Care_Settings::get( 'support_email' ), $subject, $body, $headers, $attachments );

		// Limpieza de la carpeta temporal, se haya enviado o no.
		foreach ( $attachments as $file ) {
			@unlink( $file );
		}
		if ( is_dir( $tmp_dir ) ) {
			@rmdir( $tmp_dir );
		}

		if ( ! $sent ) {
			wp_send_json_error( [ 'message' => __( 'We could not send it. Please write to us by email.', 'dox-care' ) ], 500 );
		}

		$log = self::log();
		array_unshift( $log, [
			'time'    => time(),
			'page'    => $title,
			'url'     => $page,
			'message' => mb_substr( $message, 0, 140 ),
			'files'   => count( $names ),
			'user'    => $user->display_name,
		] );
		update_option( self::LOG, array_slice( $log, 0, 20 ), false );

		Dox_Care_Usage::add( [ 'source' => 'form', 'page' => $title, 'message' => $message, 'units' => 1 ] );

		wp_send_json_success( [ 'message' => __( 'Request sent. We will answer you by email.', 'dox-care' ) ] );
	}

	private static function page_title( $url ) {
		if ( ! $url ) {
			return __( 'The whole website', 'dox-care' );
		}
		if ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			return __( 'Home page', 'dox-care' );
		}
		$id = url_to_postid( $url );
		return $id ? get_the_title( $id ) : $url;
	}

	/**
	 * El formulario. Lo usan el escritorio y la ventana de la barra superior.
	 * $current: la página que ya viene elegida (la que está viendo el cliente).
	 */
	public static function form( $current = '' ) {
		$choices = self::page_choices();
		$known   = wp_list_pluck( $choices, 'url' );
		ob_start();
		?>
		<form class="dxc-form" enctype="multipart/form-data" novalidate>
			<input type="hidden" name="action" value="dox_care_request">
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'dox_care_request' ) ); ?>">
			<label class="dxc-field">
				<span><?php esc_html_e( 'Which page?', 'dox-care' ); ?></span>
				<select name="page">
					<?php if ( $current && ! in_array( $current, $known, true ) ) : ?>
						<option value="<?php echo esc_attr( $current ); ?>" selected><?php echo esc_html( self::page_title( $current ) ); ?></option>
					<?php endif; ?>
					<?php foreach ( $choices as $c ) : ?>
						<option value="<?php echo esc_attr( $c['url'] ); ?>" <?php selected( $current, $c['url'] ); ?>><?php echo esc_html( $c['title'] ); ?></option>
					<?php endforeach; ?>
					<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'The whole website / another one', 'dox-care' ); ?></option>
				</select>
			</label>
			<label class="dxc-field">
				<span><?php esc_html_e( 'What do you want to change?', 'dox-care' ); ?></span>
				<textarea name="message" rows="5" required placeholder="<?php esc_attr_e( 'Write the exact text as it should read, or describe the change.', 'dox-care' ); ?>"></textarea>
			</label>
			<label class="dxc-field dxc-files">
				<span><?php esc_html_e( 'Photos or files (optional)', 'dox-care' ); ?></span>
				<input type="file" name="files[]" multiple accept="image/*,.pdf,.doc,.docx,.txt,.xls,.xlsx,.zip">
				<small><?php esc_html_e( 'Up to 5 files of 10 MB each.', 'dox-care' ); ?></small>
			</label>
			<div class="dxc-form-foot">
				<button type="submit" class="dxc-btn dxc-btn-o"><?php esc_html_e( 'Send request', 'dox-care' ); ?></button>
				<p class="dxc-form-msg" role="status" aria-live="polite"></p>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}
}
