<?php
declare( strict_types=1 );
/**
 * Admin-specific functionality.
 *
 * Orchestrator class that coordinates admin assets, menu registration,
 * settings management, and dashboard/page rendering. Delegates to
 * specialized admin classes to keep each focused on a single responsibility.
 *
 * Preserves the original public interface for backward compatibility
 * with existing hooks and loader registrations.
 *
 * @since 1.0.0
 * @see Peptide_News_Admin_Assets — Asset enqueuing
 * @see Peptide_News_Admin_Menu — Menu registration
 * @see Peptide_News_Admin_Settings — Settings & field renderers
 * @see Peptide_News_Admin_Settings_Page — Settings page + log viewer
 * @see Peptide_News_Admin_Dashboard_Pages — Dashboard/articles/costs pages
 */
class Peptide_News_Admin {

	/** @var string Plugin name/slug */
	private $plugin_name;

	/** @var string Plugin version */
	private $version;

	/** @var Peptide_News_Admin_Assets */
	private $assets;

	/** @var Peptide_News_Admin_Menu */
	private $menu;

	/** @var Peptide_News_Admin_Settings */
	private $settings;

	/** @var Peptide_News_Admin_Settings_Page */
	private $settings_page;

	/** @var Peptide_News_Admin_Dashboard_Pages */
	private $dashboard_pages;

	/**
	 * Constructor.
	 *
	 * Instantiates all delegate admin classes.
	 *
	 * @param string $plugin_name Plugin name/slug.
	 * @param string $version Plugin version.
	 */
	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;

		// Initialize delegates.
		$this->assets            = new Peptide_News_Admin_Assets( $plugin_name, $version );
		$this->settings          = new Peptide_News_Admin_Settings();
		$this->settings_page     = new Peptide_News_Admin_Settings_Page( $this->settings );
		$this->dashboard_pages   = new Peptide_News_Admin_Dashboard_Pages();

		// Menu callbacks are closures pointing to dashboard pages instance methods.
		$this->menu = new Peptide_News_Admin_Menu(
			fn() => $this->dashboard_pages->render_dashboard_page(),
			fn() => $this->settings_page->render_settings_page(),
			fn() => $this->dashboard_pages->render_articles_page(),
			fn() => $this->dashboard_pages->render_cost_dashboard_page()
		);
	}

	/**
	 * Enqueue admin CSS.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_styles( string $hook ): void {
		$this->assets->enqueue_styles( $hook );
	}

	/**
	 * Enqueue admin JavaScript.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		$this->assets->enqueue_scripts( $hook );
	}

	/**
	 * Register all admin menu pages.
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {
		$this->menu->add_admin_menu();
	}

	/**
	 * Register all WordPress settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$this->settings->register_settings();
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		$this->settings_page->render_settings_page();
	}

	/**
	 * Render the analytics dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		$this->dashboard_pages->render_dashboard_page();
	}

	/**
	 * Render the articles management page.
	 *
	 * @return void
	 */
	public function render_articles_page(): void {
		$this->dashboard_pages->render_articles_page();
	}

	/**
	 * Render the LLM cost tracking dashboard page.
	 *
	 * @return void
	 */
	public function render_cost_dashboard_page(): void {
		$this->dashboard_pages->render_cost_dashboard_page();
	}
}
