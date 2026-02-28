<?php
/**
 * Plugin Name: Easy Multi Step Form
 * Plugin URI: https://shakoor-wpdev.vercel.app/easy-multi-step-form
 * Description: A secure contact form plugin that saves submissions and sends admin notifications
 * Version: 1.0.0
 * Author: shakoor
 * Author URI: https://shakoor-wpdev.vercel.app
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easy-multi-step-form
 * Domain Path: /languages
 *
 * @package EasyMultiStepForm
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'EMSF_VERSION', '1.0.0' );
define( 'EMSF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMSF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EMSF_PREFIX', 'emsf' );

// Autoload classes
require_once EMSF_PLUGIN_DIR . 'includes/class-autoloader.php';

/**
 * Main plugin class
 */
class Easy_Multi_Step_Form {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
		add_action( 'plugins_loaded', array( $this, 'check_upgrade' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_public_assets' ) );
		register_activation_hook( __FILE__, array( $this, 'activate_plugin' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate_plugin' ) );
	}

	/**
	 * Check for plugin upgrade and run migrations
	 */
	public function check_upgrade() {
		if ( get_option( 'emsf_db_version' ) !== EMSF_VERSION ) {
			\EasyMultiStepForm\Includes\Database::create_submission_table();
			update_option( 'emsf_db_version', EMSF_VERSION );
		}
	}

	/**
	 * Initialize plugin classes
	 */
	public function init_classes() {
		new \EasyMultiStepForm\Includes\Admin();
		new \EasyMultiStepForm\Includes\Form();
		new \EasyMultiStepForm\Includes\SMTP();
	}

	/**
	 * Load plugin text domain
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'easy-multi-step-form',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets() {
		wp_enqueue_style(
			'emsf-admin-styles',
			EMSF_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			EMSF_VERSION
		);
		wp_enqueue_script(
			'emsf-admin-scripts',
			EMSF_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			EMSF_VERSION,
			true
		);
		wp_localize_script(
			'emsf-admin-scripts',
			'emsf_admin_ajax',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'emsf_form_nonce' ),
			)
		);
	}

	/**
	 * Enqueue public assets
	 */
	public function enqueue_public_assets() {
		wp_enqueue_style(
			'emsf-public-styles',
			EMSF_PLUGIN_URL . 'assets/css/public.css',
			array(),
			EMSF_VERSION
		);

		// External UI Libraries for Professional Controls
		wp_enqueue_style( 'emsf-flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), '4.6.13' );
		wp_enqueue_script( 'emsf-flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '4.6.13', true );
		wp_enqueue_style( 'emsf-choices-css', 'https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css', array(), '10.2.0' );
		wp_enqueue_script( 'emsf-choices-js', 'https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js', array(), '10.2.0', true );

		// Dynamic Styling Injection
		$primary    = get_option( 'emsf_primary_color', '#0BC139' );
		$hover      = get_option( 'emsf_primary_hover', '#0F991F' );
		$bg_input   = get_option( 'emsf_bg_input', '#f8fafc' );
		$form_bg    = get_option( 'emsf_form_bg', '#ffffff' );
		$label_clr  = get_option( 'emsf_label_color', '#1e293b' );
		$placeholder = get_option( 'emsf_placeholder_color', '#94a3b8' );
		$form_rad   = get_option( 'emsf_form_radius', '12px' );
		$form_pad   = get_option( 'emsf_form_padding', '30px' );
		$in_radius  = get_option( 'emsf_input_radius', '8px' );
		$btn_radius = get_option( 'emsf_button_radius', '8px' );
		$in_padding = get_option( 'emsf_input_padding', '12px 16px' );
		$btn_padding = get_option( 'emsf_button_padding', '14px 24px' );
		$override   = get_option( 'emsf_custom_css', '' );

		$custom_css = "
			:root {
				--emsf-custom-primary: $primary;
				--emsf-custom-hover: $hover;
				--emsf-custom-bg-input: $bg_input;
				--emsf-custom-form-bg: $form_bg;
				--emsf-custom-label-color: $label_clr;
				--emsf-custom-placeholder: $placeholder;
				--emsf-custom-form-radius: $form_rad;
				--emsf-custom-form-padding: $form_pad;
				--emsf-custom-input-radius: $in_radius;
				--emsf-custom-btn-radius: $btn_radius;
				--emsf-custom-input-padding: $in_padding;
				--emsf-custom-btn-padding: $btn_padding;
			}
			$override
		";
		wp_add_inline_style( 'emsf-public-styles', $custom_css );

		wp_enqueue_script(
			'emsf-public-scripts',
			EMSF_PLUGIN_URL . 'assets/js/public.js',
			array( 'jquery' ),
			EMSF_VERSION,
			true
		);

		// Localize script with nonce
		wp_localize_script(
			'emsf-public-scripts',
			'emsfData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'emsf_form_nonce' ),
				'recaptcha_enabled' => get_option( 'emsf_recaptcha_enabled', 0 ),
				'recaptcha_type'    => get_option( 'emsf_recaptcha_type', 'v3' ),
				'recaptcha_site_key' => get_option( 'emsf_recaptcha_site_key', '' ),
			)
		);
	}

	/**
	 * Activate plugin - Create database table
	 */
	public static function activate_plugin() {
		\EasyMultiStepForm\Includes\Database::create_submission_table();

		// Set default options if not already set
		add_option( 'emsf_primary_color', '#0BC139' );
		add_option( 'emsf_primary_hover', '#0F991F' );
		
		$default_structure = array(
			array( 
				'id' => 'step_1', 
				'title' => 'Contact Information', 
				'fields' => array(
					'name'  => array('label' => 'Name', 'type' => 'text', 'required' => 1, 'width' => '50', 'system' => false, 'placeholder' => 'e.g., John Doe'),
					'email' => array('label' => 'Email', 'type' => 'email', 'required' => 1, 'width' => '50', 'system' => false, 'placeholder' => 'e.g., john@example.com'),
					'phone' => array('label' => 'Phone', 'type' => 'tel', 'required' => 0, 'width' => '100', 'system' => false, 'placeholder' => 'e.g., 1234567890'),
				)
			),
			array( 
				'id' => 'step_2', 
				'title' => 'Message & Details', 
				'fields' => array(
					'message' => array('label' => 'Your Message', 'type' => 'textarea', 'required' => 1, 'width' => '100', 'system' => false, 'placeholder' => 'How can we help you?')
				)
			),
		);
		add_option( 'emsf_form_structure', $default_structure );

		flush_rewrite_rules();
	}

	/**
	 * Deactivate plugin
	 */
	public static function deactivate_plugin() {
		flush_rewrite_rules();
	}
}

// Initialize plugin
new Easy_Multi_Step_Form();