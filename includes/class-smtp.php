<?php
/**
 * SMTP Configuration Handler
 *
 * @package EasyMultiStepForm
 */

namespace EasyMultiStepForm\Includes;

class SMTP {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'phpmailer_init', array( $this, 'configure_from_settings' ) );
	}

	/**
	 * Configure PHPMailer from plugin settings
	 *
	 * @param PHPMailer $phpmailer The PHPMailer instance.
	 */
	public function configure_from_settings( $phpmailer ) {
		// Check if SMTP is enabled
		$enabled = get_option( 'emsf_smtp_enabled', 0 );
		if ( ! $enabled ) {
			return;
		}

		$host       = get_option( 'emsf_smtp_host', '' );
		$port       = get_option( 'emsf_smtp_port', '587' );
		$encryption = get_option( 'emsf_smtp_encryption', 'tls' );
		$username   = get_option( 'emsf_smtp_username', '' );
		$password   = get_option( 'emsf_smtp_password', '' );

		if ( empty( $host ) ) {
			return;
		}

		// Set Mailer to use SMTP
		$phpmailer->isSMTP();
		$phpmailer->Host = $host;
		$phpmailer->Port = intval( $port );

		// Encryption
		if ( 'none' === $encryption ) {
			$phpmailer->SMTPSecure = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $encryption;
		}

		// Authentication
		if ( ! empty( $username ) && ! empty( $password ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $username;
			$phpmailer->Password = $password;
		}

		// Auto-set From to the SMTP username and site name
		if ( ! empty( $username ) && is_email( $username ) ) {
			$phpmailer->From = $username;
		}
		$phpmailer->FromName = get_bloginfo( 'name' );
	}
}
