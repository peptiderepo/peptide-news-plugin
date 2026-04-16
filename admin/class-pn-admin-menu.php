<?php
declare( strict_types=1 );
/**
 * Admin menu registration.
 *
 * Registers the main Peptide News admin menu and subpages
 * (Dashboard, Settings, Articles, Cost Tracking).
 *
 * Callbacks are provided externally (via the main Admin class).
 *
 * @since 2.5.0
 * @see Peptide_News_Admin — Main admin orchestrator
 */
class Peptide_News_Admin_Menu {

	/** @var callable Dashboard page renderer */
	private $dashboard_callback;

	/** @var callable Settings page renderer */
	private $settings_callback;

	/** @var callable Articles page renderer */
	private $articles_callback;

	/** @var callable Cost dashboard page renderer */
	private $cost_callback;

	/**
	 * Constructor.
	 *
	 * @param callable $dashboard_callback Renderer for dashboard page.
	 * @param callable $settings_callback Renderer for settings page.
	 * @param callable $articles_callback Renderer for articles page.
	 * @param callable $cost_callback Renderer for cost dashboard page.
	 */
	public function __construct(
		callable $dashboard_callback,
		callable $settings_callback,
		callable $articles_callback,
		callable $cost_callback
	) {
		$this->dashboard_callback = $dashboard_callback;
		$this->settings_callback  = $settings_callback;
		$this->articles_callback  = $articles_callback;
		$this->cost_callback      = $cost_callback;
	}

	/**
	 * Register all admin menu pages.
	 *
	 * Creates the main menu (Analytics Dashboard) and three submenus
	 * (Settings, Articles, Cost Dashboard).
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {
		// Main menu — Analytics Dashboard.
		add_menu_page(
			__( 'Peptide News', 'peptide-news' ),
			__( 'Peptide News', 'peptide-news' ),
			'manage_options',
			'peptide-news-dashboard',
			$this->dashboard_callback,
			'dashicons-rss',
			30
		);

		// Sub-menu — Settings.
		add_submenu_page(
			'peptide-news-dashboard',
			__( 'Settings', 'peptide-news' ),
			__( 'Settings', 'peptide-news' ),
			'manage_options',
			'peptide-news-settings',
			$this->settings_callback
		);

		// Sub-menu — Articles.
		add_submenu_page(
			'peptide-news-dashboard',
			__( 'Articles', 'peptide-news' ),
			__( 'Articles', 'peptide-news' ),
			'manage_options',
			'peptide-news-articles',
			$this->articles_callback
		);

		// Sub-menu — Cost Dashboard.
		add_submenu_page(
			'peptide-news-dashboard',
			__( 'LLM Costs', 'peptide-news' ),
			__( 'LLM Costs', 'peptide-news' ),
			'manage_options',
			'peptide-news-costs',
			$this->cost_callback
		);
	}
}
