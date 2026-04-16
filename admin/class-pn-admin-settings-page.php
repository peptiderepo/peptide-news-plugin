<?php
declare( strict_types=1 );
/**
 * Settings page rendering.
 *
 * Renders the main settings form with:
 * - Manual fetch/summary generation buttons with AJAX handlers
 * - Settings form (sections/fields via WordPress)
 * - Plugin usage documentation
 * - Real-time plugin log viewer with filtering and pagination
 *
 * @since 2.5.0
 * @see Peptide_News_Admin — Main admin orchestrator
 * @see Peptide_News_Admin_Settings — Field renderers
 */
class Peptide_News_Admin_Settings_Page {

	/** @var Peptide_News_Admin_Settings Settings instance for field rendering */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Peptide_News_Admin_Settings $settings Settings instance.
	 */
	public function __construct( Peptide_News_Admin_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Render the settings page with form, buttons, and log viewer.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle manual fetch trigger.
		if ( isset( $_POST['peptide_news_fetch_now'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized', 'peptide-news' ), 403 );
			}
			check_admin_referer( 'peptide_news_fetch_now_action' );
			$fetcher = new Peptide_News_Fetcher();
			$fetcher->fetch_all_sources();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Fetch completed!', 'peptide-news' ) . '</p></div>';
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		// Fetch Now button.
		echo '<form method="post" style="display:inline-block;margin-right:12px;">';
		wp_nonce_field( 'peptide_news_fetch_now_action' );
		submit_button( __( 'Fetch Articles Now', 'peptide-news' ), 'secondary', 'peptide_news_fetch_now', false );
		echo '</form>';

		// Backfill Sources button (fixes "news.google.com" source labels).
		echo '<button type="button" id="peptide-backfill-sources" class="button button-secondary">';
		echo esc_html__( 'Fix Article Sources', 'peptide-news' );
		echo '</button>';
		echo '<span id="peptide-backfill-result" style="margin-left:10px;"></span>';

		// Generate AI Summaries button.
		echo '<br style="margin-bottom:8px;">';
		echo '<button type="button" id="peptide-generate-summaries" class="button button-primary" style="margin-top:8px;">';
		echo esc_html__( 'Generate AI Summaries', 'peptide-news' );
		echo '</button>';
		echo '<span id="peptide-summary-result" style="margin-left:10px;"></span>';

		?>
		<script>
		(function($) {
			$('#peptide-backfill-sources').on('click', function() {
				var $btn = $(this), $result = $('#peptide-backfill-result');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Fixing...', 'peptide-news' ) ); ?>');
				$result.text('');
				$.post(peptideNewsAdmin.ajax_url, {
					action: 'peptide_news_backfill_sources',
					nonce: peptideNewsAdmin.admin_nonce
				}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Fix Article Sources', 'peptide-news' ) ); ?>');
					if (response.success) {
						$result.text(response.data.updated + ' article(s) updated.');
					} else {
						$result.text('Error: ' + (response.data || 'Unknown error'));
					}
				}).fail(function() {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Fix Article Sources', 'peptide-news' ) ); ?>');
					$result.text('Request failed.');
				});
			});

			// Generate AI Summaries — loops in batches until all articles are done.
			// Uses a 3-second delay between batches to respect free-tier rate limits,
			// and retries up to 3 times when a batch fails before giving up.
			$('#peptide-generate-summaries').on('click', function() {
				var $btn = $(this), $result = $('#peptide-summary-result');
				var totalProcessed = 0;
				var retries = 0;
				var maxRetries = 3;

				$btn.prop('disabled', true);
				$result.text('<?php echo esc_js( __( 'Starting...', 'peptide-news' ) ); ?>');

				function runBatch() {
					$btn.text('<?php echo esc_js( __( 'Generating...', 'peptide-news' ) ); ?>');
					$.post(peptideNewsAdmin.ajax_url, {
						action: 'peptide_news_generate_summaries',
						nonce: peptideNewsAdmin.admin_nonce
					}, function(response) {
						if (response.success) {
							totalProcessed += response.data.processed;
							var remaining = response.data.remaining;
							$result.text(totalProcessed + ' summarized, ' + remaining + ' remaining...');

							if (remaining > 0 && response.data.processed > 0) {
								// Success — reset retry counter and continue after a delay
								// to respect the free-tier rate limit (20 req/min).
								retries = 0;
								setTimeout(runBatch, 3000);
							} else if (remaining > 0 && retries < maxRetries) {
								// No progress — likely rate limited. Wait longer and retry.
								retries++;
								$result.text(totalProcessed + ' summarized, ' + remaining + ' remaining... (rate limited, retrying in 10s, attempt ' + retries + '/' + maxRetries + ')');
								setTimeout(runBatch, 10000);
							} else {
								// Done — either all processed or exhausted retries.
								$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Generate AI Summaries', 'peptide-news' ) ); ?>');
								if (remaining === 0) {
									$result.text('Done! ' + totalProcessed + ' article(s) summarized.');
								} else {
									var errMsg = totalProcessed + ' summarized. ' + remaining + ' could not be processed.';
									if (response.data.errors && response.data.errors.length > 0) {
										errMsg += ' Error: ' + response.data.errors[0];
									}
									$result.text(errMsg);
								}
							}
						} else {
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Generate AI Summaries', 'peptide-news' ) ); ?>');
							$result.text('Error: ' + (response.data || 'Unknown error'));
						}
					}).fail(function() {
						if (retries < maxRetries) {
							retries++;
							$result.text(totalProcessed + ' completed. Request failed, retrying in 10s... (attempt ' + retries + '/' + maxRetries + ')');
							setTimeout(runBatch, 10000);
						} else {
							$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Generate AI Summaries', 'peptide-news' ) ); ?>');
							$result.text('Request failed. ' + totalProcessed + ' completed before failure.');
						}
					});
				}

				runBatch();
			});

		})(jQuery);
		</script>
		<?php

		// Last fetch info.
		$last_fetch = get_option( 'peptide_news_last_fetch' );
		if ( $last_fetch ) {
			$extra_info = '';
			if ( isset( $last_fetch['filtered_out'] ) && $last_fetch['filtered_out'] > 0 ) {
				$extra_info .= sprintf(
					' | %s: %d',
					esc_html__( 'Filtered out', 'peptide-news' ),
					$last_fetch['filtered_out']
				);
			}
			if ( isset( $last_fetch['ai_processed'] ) ) {
				$extra_info .= sprintf(
					' | %s: %d',
					esc_html__( 'AI analyzed', 'peptide-news' ),
					$last_fetch['ai_processed']
				);
			}
			printf(
				'<p class="description">%s: %s | %s: %d | %s: %d%s</p>',
				esc_html__( 'Last fetch', 'peptide-news' ),
				esc_html( $last_fetch['time'] ),
				esc_html__( 'Found', 'peptide-news' ),
				intval( $last_fetch['found'] ),
				esc_html__( 'New stored', 'peptide-news' ),
				intval( $last_fetch['new_stored'] ),
				esc_html( $extra_info )
			);
		}

		// Settings form.
		echo '<form method="post" action="options.php">';
		settings_fields( 'peptide_news_settings_group' );
		do_settings_sections( 'peptide-news-settings' );
		submit_button();
		echo '</form>';

		// Shortcode usage info.
		echo '<h2>' . esc_html__( 'Usage', 'peptide-news' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Shortcode:', 'peptide-news' ) . '</strong> <code>[peptide_news]</code> ' .
			esc_html__( 'or', 'peptide-news' ) . ' <code>[peptide_news count="5"]</code></p>';
		echo '<p><strong>' . esc_html__( 'Widget:', 'peptide-news' ) . '</strong> ' .
			esc_html__( 'Add "Peptide News" from Appearance > Widgets.', 'peptide-news' ) . '</p>';

		// ── Plugin Log Viewer ──────────────────────────────────────────
		Peptide_News_Admin_Log_Viewer::render_log_viewer();

		echo '</div>';
	}
}
