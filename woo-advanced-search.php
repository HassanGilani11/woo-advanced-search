<?php
/**
 * Plugin Name: WooCommerce Advanced Search
 * Plugin URI:  https://syntexdev.com/woo-advanced-search
 * Description: Advanced search bar for WooCommerce with live Algolia-powered product results, category suggestions, recent searches, and a no-results state. Use shortcode [woo_advanced_search].
 * Version:     1.4.8
 * Author:      SyntexDev
 * Author URI:  https://syntexdev.com
 * Text Domain: woo-advanced-search
 * Requires WooCommerce: 5.0
 */

defined( 'ABSPATH' ) || exit;

define( 'WAS_VERSION',     '1.4.8' );
define( 'WAS_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'WAS_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Asset version = plugin version + file modification timestamp.
 * Forces browsers and server-side caches to fetch the new file whenever
 * the JS or CSS is saved, without a manual cache-purge step.
 */
function was_asset_version( string $file ): string {
    $counter = (int) get_option( 'was_cache_counter', 0 );
    if ( file_exists( $file ) ) {
        return WAS_VERSION . '.' . filemtime( $file ) . ( $counter ? '.' . $counter : '' );
    }
    return WAS_VERSION . ( $counter ? '.' . $counter : '' );
}

/* -----------------------------------------------------------------------
   Load sub-files
----------------------------------------------------------------------- */
require_once WAS_PLUGIN_DIR . 'includes/class-was-settings.php';
require_once WAS_PLUGIN_DIR . 'includes/class-was-ajax.php';
require_once WAS_PLUGIN_DIR . 'includes/class-was-shortcode.php';

/* -----------------------------------------------------------------------
   Boot
----------------------------------------------------------------------- */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'WooCommerce Advanced Search requires WooCommerce to be active.', 'woo-advanced-search' )
                . '</p></div>';
        } );
        return;
    }
    WAS_Settings::init();
    WAS_Ajax::init();
    WAS_Shortcode::init();
} );
