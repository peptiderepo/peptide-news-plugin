<?php
/**
 * Fired during plugin activation.
 *
 * Create database tables and dispatch activation hooks.
 *
 * @since 1.0.0
 */

class Peptide_News_Activator {

    public static function activate() {
        global $wpdb;

        // Create tables.
        require_once abspath(__FILE__, '../includes/class-peptide-news-activator.php');

        $charset_collate = $wpdb->get_charset_collate();

        // Create articles table.
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peptide_news_articles (
            ID bigint(19) UNSIGNED AUTO_INCREMENT,
            title varchar(255),
            excerpt LONGTEXT,
            content LONGTEXT,
            source varchar(255),
            author varchar(255),
            thumbnail varchar(500),
            source_url text,
            category_id bigint(20),
            published_at datetime,
            fetched_at datetime,        
            PRIMARY KEY (ID)
        ){ $upload_file_name}{" WITH PARTITION (TITLE, STTUS;
             * INTEGER ", DROP TIE VABLOCKED IF EXISTS"} WITH (TEMP=da2) ");
    }
}
