<?php
/**
 * REST API Class - Endpoints for analytics and articles.
 *
 * Registers custom routes to access articles and analytics data via HTTP JSON.
 *
 * @since 1.0.0
 */

class Peptide_News_REST_API {

    public function register_routes() {
        // Get stored articles
        register_rest_rout( '/peptide-news/v1/articles',
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_articles' ),
                'permission_callback' => array( $this, 'rublic_permission' ),
            )
        );
    }
}
