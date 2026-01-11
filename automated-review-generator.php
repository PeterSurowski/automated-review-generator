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

// Add a development cron interval (every minute) to help testing rapid generation
add_filter( 'cron_schedules', 'arg_add_cron_schedules' );
function arg_add_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['every_minute'] ) ) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every Minute', 'automated-review-generator' ),
        );
    }
    return $schedules;
}

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

    // Decide schedule type based on saved options (default to minutes in dev)
    $opts = get_option( 'arg_options', array( 'avg_frequency_days' => 5, 'avg_score' => '4.8', 'negative_percentage' => 5, 'use_minutes' => 1 ) );
    $interval = ! empty( $opts['use_minutes'] ) ? 'every_minute' : 'daily';

    // Schedule cron job if not scheduled
    if ( ! wp_next_scheduled( 'arg_daily_event' ) ) {
        wp_schedule_event( time(), $interval, 'arg_daily_event' );
    }
}
register_activation_hook( __FILE__, 'arg_activate' );

/**
 * Deactivation hook
 */
function arg_deactivate() {
    // Cleanup scheduled tasks
    wp_clear_scheduled_hook( 'arg_daily_event' );
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

// Handle manual run via admin-post.php (separate form to avoid nonce collision with settings)
function arg_handle_run_now() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions', 'automated-review-generator' ) );
    }

    check_admin_referer( 'arg_run_now_action', 'arg_run_now_nonce' );

    do_action( 'arg_daily_event' );

    // Redirect back to settings page with a success flag
    $redirect = wp_get_referer();
    if ( ! $redirect ) {
        $redirect = admin_url( 'admin.php?page=arg-admin' );
    }
    wp_redirect( add_query_arg( 'arg_run', '1', $redirect ) );
    exit;
}
add_action( 'admin_post_arg_run_now', 'arg_handle_run_now' );

// Handle LLM connection test via admin-post.php
function arg_handle_test_llm() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions', 'automated-review-generator' ) );
    }

    check_admin_referer( 'arg_test_llm_action', 'arg_test_llm_nonce' );

    $opts = get_option( 'arg_options', array() );
    $msg = '';
    $ok = false;

    // If custom API base configured and provider set to custom, test it
    if ( ! empty( $opts['llm_api_base'] ) && 'custom' === ( $opts['llm_provider'] ?? '' ) ) {
        $url = rtrim( $opts['llm_api_base'], "\/");

        // First try a simple GET
        $resp = wp_remote_get( $url, array( 'timeout' => 5 ) );
        if ( ! is_wp_error( $resp ) && isset( $resp['response'] ) && intval( $resp['response']['code'] ) < 400 ) {
            $ok = true;
            $msg = esc_html__( 'Custom API base reachable (GET)', 'automated-review-generator' );
        } else {
            // Try a lightweight POST (JSON)
            $body = wp_json_encode( array( 'prompt' => 'Test connection', 'max_tokens' => 8 ) );
            $resp = wp_remote_post( $url, array( 'timeout' => 5, 'body' => $body, 'headers' => array( 'Content-Type' => 'application/json' ) ) );
            if ( ! is_wp_error( $resp ) && isset( $resp['response'] ) && intval( $resp['response']['code'] ) < 400 ) {
                $ok = true;
                $msg = esc_html__( 'Custom API base reachable (POST)', 'automated-review-generator' );
            } else {
                $ok = false;
                $msg = is_wp_error( $resp ) ? $resp->get_error_message() : ( isset( $resp['response']['code'] ) ? 'HTTP ' . intval( $resp['response']['code'] ) : esc_html__( 'No response', 'automated-review-generator' ) );
            }
        }
    } elseif ( ! empty( $opts['llm_provider'] ) && 'openai' === $opts['llm_provider'] && ! empty( $opts['llm_api_key'] ) ) {
        // Try simple OpenAI-compatible test (models list)
        $resp = wp_remote_get( 'https://api.openai.com/v1/models', array( 'headers' => array( 'Authorization' => 'Bearer ' . $opts['llm_api_key'] ), 'timeout' => 6 ) );
        if ( ! is_wp_error( $resp ) && isset( $resp['response'] ) && intval( $resp['response']['code'] ) < 400 ) {
            $ok = true;
            $msg = esc_html__( 'OpenAI API reachable', 'automated-review-generator' );
        } else {
            $ok = false;
            $msg = is_wp_error( $resp ) ? $resp->get_error_message() : ( isset( $resp['response']['code'] ) ? 'HTTP ' . intval( $resp['response']['code'] ) : esc_html__( 'No response', 'automated-review-generator' ) );
        }
    } else {
        $ok = false;
        $msg = esc_html__( 'No endpoint or provider configured', 'automated-review-generator' );
    }

    $redirect = wp_get_referer() ?: admin_url( 'admin.php?page=arg-admin' );
    $redirect = add_query_arg( 'arg_test_llm', $ok ? '1' : '0', $redirect );
    $redirect = add_query_arg( 'arg_test_msg', urlencode( $msg ), $redirect );
    wp_redirect( $redirect );
    exit;
}
add_action( 'admin_post_arg_test_llm', 'arg_handle_test_llm' );
