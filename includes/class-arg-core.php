<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ARG_Core {
    /**
     * Singleton instance
     */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function init() {
        // Hooks, shortcodes, admin init, etc.
        add_action( 'init', array( $this, 'load_textdomain' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'automated-review-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/../languages' );
    }
}
