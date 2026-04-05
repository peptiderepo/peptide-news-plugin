<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Drops custom tables and removes all options.
 *
 * @since 1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

global $wpdb;

// Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}peptide_news_daily_stats" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}peptide_news_clicks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}peptide_news_articles" );

// Remove all options.
$options = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'peptide_news_%'"
);

foreach ( $options as $option ) {
    delete_option( $option );
}
