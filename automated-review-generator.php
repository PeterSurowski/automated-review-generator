<?php
/**
 * Plugin Name: Automated Review Generator
 * Plugin URI: https://codetemple.net
 * Description: Generate product/service reviews automatically using configurable templates and analysis.
 * Version: 0.1.0
 * Author: Code Temple
 * Author URI: https://codetemple.net
 * Text Domain: automated-review-generator
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'ARG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ARG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Basic autoload / includes
 */
if ( file_exists( ARG_PLUGIN_DIR . 'includes/class-arg-core.php' ) ) {
    require_once ARG_PLUGIN_DIR . 'includes/class-arg-core.php';
}
// Admin UI
if ( file_exists( ARG_PLUGIN_DIR . 'includes/admin/class-arg-admin.php' ) ) {
    require_once ARG_PLUGIN_DIR . 'includes/admin/class-arg-admin.php';
}
// LLM handler (pseudo-code)
if ( file_exists( ARG_PLUGIN_DIR . 'includes/class-arg-llm.php' ) ) {
    require_once ARG_PLUGIN_DIR . 'includes/class-arg-llm.php';
}

/**
 * Activation hook
 */
function arg_activate() {
    // Placeholder: create default options, DB tables, etc.
    add_option( 'arg_version', '0.1.0' );
}
register_activation_hook( __FILE__, 'arg_activate' );

/**
 * Deactivation hook
 */
function arg_deactivate() {
    // Placeholder: cleanup scheduled tasks
}
register_deactivation_hook( __FILE__, 'arg_deactivate' );

// Initialize core
if ( class_exists( 'ARG_Core' ) ) {
    ARG_Core::instance();
}

// Add Settings link on the Plugins page for quick access
function arg_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=arg-admin' ) ) . '">' . esc_html__( 'Settings', 'automated-review-generator' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'arg_plugin_action_links' );
