<?php
/**
 * Uninstall handler for Automated Review Generator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Example cleanup: remove plugin options
delete_option( 'arg_version' );
