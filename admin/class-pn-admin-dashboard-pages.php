<?php
declare( strict_types=1 );
/**
 * Dashboard, articles, and cost dashboard page rendering.
 *
 * Handles the main analytics dashboard, article list management,
 * and LLM cost tracking dashboard. Includes CSV export for analytics.
 *
 * @since 2.5.0
 * @see Peptide_News_Admin — Main admin orchestrator
 * @see admin/partials/dashboard.php — Analytics dashboard partial
 * @see admin/partials/articles-list.php — Article list partial
 * @see admin/partials/cost-dashboard.php — Cost dashboard partial
 */
class Peptide_News_Admin_Dashboard_Pages {

	/**
	 * Render the analytics dashboard page.
	 *
	 * Includes date filtering and calls the dashboard partial.
	 * Handles CSV export before rendering.
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle CSV export before any HTML output.
		if ( isset( $_GET['export'] ) && 'csv' === $_GET['export'] ) {
			check_admin_referer( 'peptide_news_export_csv' );
			$this->export_csv();
			return;
		}

		include PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/dashboard.php';
	}

	/**
	 * Stream a CSV export of click analytics data.
	 *
	 * Respects date range filters from GET parameters.
	 * Defaults to last 30 days if not specified.
	 *
	 * @return void
	 */
	private function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'peptide-news' ) );
		}

		$raw_start  = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
		$raw_end    = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
		$start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_start ) ? $raw_start : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end_date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_end ) ? $raw_end : gmdate( 'Y-m-d' );

		$data = Peptide_News_Analytics::export_clicks_csv( $start_date, $end_date );

		$filename = 'peptide-news-clicks-' . $start_date . '-to-' . $end_date . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		if ( ! empty( $data ) ) {
			fputcsv( $output, array_keys( $data[0] ) );
			foreach ( $data as $row ) {
				fputcsv( $output, $row );
			}
		} else {
			fputcsv( $output, array( 'No data for selected period' ) );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Render the articles management page.
	 *
	 * @return void
	 */
	public function render_articles_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/articles-list.php';
	}

	/**
	 * Render the LLM cost tracking dashboard page.
	 *
	 * Shows monthly budget status, daily cost chart, per-model breakdown,
	 * and recent API call log. Data is loaded via AJAX for responsiveness.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function render_cost_dashboard_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		include PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/cost-dashboard.php';
	}
}
