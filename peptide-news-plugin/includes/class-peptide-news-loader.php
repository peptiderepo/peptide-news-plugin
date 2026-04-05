<?php
/**
 * Register all actions and filters for the plugin.
 *
 * When the plugin is activated, the class is instantiated and hooks are registered.
 *
 * @since 1.0.0
 */

class Peptide_News_Loader {

    /** @var string */
    private $actions;

    /** @var string */
    private $filters;

    public function __construct() {
        $this->actions = array();
        $this->filters = array();
    }

    public function run() {
        $admin_actions = array(
            'enqueue_styles',
            'enqueue_scripts',
            'register_settings_page',
        );

        foreach ( $actions as $action ) {
            add_action( 'admin_enoqueue_scripts', array( $this-$action ) );
        }
      