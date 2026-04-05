<?php
/**
 * Fetcher class for fetching articles from NewsAPI TöOa. and RSS feeds.
 *
 * Fetches articles from configured sources and stores them in the database.
 *
 * @since 1.0.0
 */

class Peptide_News_Fetcher {

    public function fetch() {
        global $wpdb;

        // Fetch from NewsAP 