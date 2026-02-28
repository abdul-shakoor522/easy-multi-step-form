<?php
/**
 * Autoloader for plugin classes
 *
 * @package EasyMultiStepForm
 */

namespace EasyMultiStepForm\Includes;

class Autoloader {

	/**
	 * Constructor
	 */
	public function __construct() {
		spl_autoload_register( array( $this, 'autoload' ) );
	}

	/**
	 * Autoload classes
	 *
	 * @param string $class_name Class name to load.
	 */
	public function autoload( $class_name ) {
		// Check if class belongs to this plugin
		if ( false === strpos( $class_name, 'EasyMultiStepForm' ) ) {
			return;
		}

		// Remove namespace prefix
		$class_name = str_replace( 'EasyMultiStepForm\\', '', $class_name );

		// Convert namespace to file path
		$file_parts = explode( '\\', strtolower( $class_name ) );
		$filename   = 'class-' . str_replace( '_', '-', array_pop( $file_parts ) ) . '.php';

		// Build the file path
		if ( ! empty( $file_parts ) ) {
			$filepath = EMSF_PLUGIN_DIR . implode( '/', $file_parts ) . '/' . $filename;
		} else {
			$filepath = EMSF_PLUGIN_DIR . $filename;
		}

		// Load the file
		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}
}

new Autoloader();