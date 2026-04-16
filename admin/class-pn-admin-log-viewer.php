<?php
declare( strict_types=1 );
/**
 * Plugin log viewer component.
 *
 * Renders the real-time plugin log viewer with filtering, pagination,
 * and AJAX handlers. Extracted from the main settings page for modularity.
 *
 * @since 2.5.0
 * @see Peptide_News_Admin_Settings_Page — Uses this for log viewer rendering
 */
class Peptide_News_Admin_Log_Viewer {

	/**
	 * Render the plugin log viewer UI with filters, table, and pagination.
	 *
	 * Includes inline JavaScript for AJAX log loading, filtering, and clearing.
	 *
	 * @return void
	 */
	public static function render_log_viewer(): void {
		echo '<h2>' . esc_html__( 'Plugin Log', 'peptide-news' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Recent plugin activity — fetches, AI processing, errors, and admin actions.', 'peptide-news' ) . '</p>';

		// Filters row.
		echo '<div style="margin:10px 0;display:flex;gap:8px;align-items:center;">';
		echo '<select id="peptide-log-level">';
		echo '<option value="">' . esc_html__( 'All Levels', 'peptide-news' ) . '</option>';
		echo '<option value="info">Info</option>';
		echo '<option value="warning">Warning</option>';
		echo '<option value="error">Error</option>';
		echo '<option value="debug">Debug</option>';
		echo '</select>';
		echo '<select id="peptide-log-context">';
		echo '<option value="">' . esc_html__( 'All Contexts', 'peptide-news' ) . '</option>';
		echo '<option value="fetch">Fetch</option>';
		echo '<option value="llm">LLM / AI</option>';
		echo '<option value="cron">Cron</option>';
		echo '<option value="admin">Admin</option>';
		echo '<option value="general">General</option>';
		echo '</select>';
		echo '<button type="button" id="peptide-log-refresh" class="button">' . esc_html__( 'Refresh', 'peptide-news' ) . '</button>';
		echo '<button type="button" id="peptide-log-clear" class="button" style="color:#a00;">' . esc_html__( 'Clear Log', 'peptide-news' ) . '</button>';
		echo '<span id="peptide-log-status" style="margin-left:8px;color:#666;"></span>';
		echo '</div>';

		// Log table.
		echo '<div id="peptide-log-container" style="max-height:400px;overflow-y:auto;border:1px solid #c3c4c7;background:#fff;">';
		echo '<table class="widefat striped" style="margin:0;">';
		echo '<thead><tr>';
		echo '<th style="width:140px;">' . esc_html__( 'Time', 'peptide-news' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Level', 'peptide-news' ) . '</th>';
		echo '<th style="width:70px;">' . esc_html__( 'Context', 'peptide-news' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'peptide-news' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody id="peptide-log-body"><tr><td colspan="4" style="text-align:center;color:#999;">' . esc_html__( 'Loading...', 'peptide-news' ) . '</td></tr></tbody>';
		echo '</table>';
		echo '</div>';

		// Pagination.
		echo '<div id="peptide-log-pagination" style="margin:8px 0;display:flex;gap:8px;align-items:center;"></div>';

		self::render_log_viewer_script();
	}

	/**
	 * Render the inline JavaScript for log viewer functionality.
	 *
	 * @return void
	 */
	private static function render_log_viewer_script(): void {
		?>
		<script>
		jQuery(document).ready(function($) {
			var currentPage = 1;

			function levelBadge(level) {
				var colors = { info: '#2271b1', warning: '#dba617', error: '#d63638', debug: '#8c8f94' };
				var bg     = { info: '#f0f6fc', warning: '#fcf9e8', error: '#fcf0f1', debug: '#f0f0f1' };
				return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:500;color:' +
					(colors[level] || '#666') + ';background:' + (bg[level] || '#f0f0f1') + ';">' + level + '</span>';
			}

			function loadLogs(page) {
				currentPage = page || 1;
				var $body = $('#peptide-log-body');
				$body.html('<tr><td colspan="4" style="text-align:center;color:#999;">Loading...</td></tr>');

				$.post(peptideNewsAdmin.ajax_url, {
					action:  'peptide_news_get_logs',
					nonce:   peptideNewsAdmin.admin_nonce,
					page:    currentPage,
					level:   $('#peptide-log-level').val(),
					context: $('#peptide-log-context').val()
				}, function(response) {
					if (!response.success) {
						$body.html('<tr><td colspan="4" style="color:#d63638;">Error loading logs.</td></tr>');
						return;
					}

					var rows = response.data.rows;
					if (rows.length === 0) {
						$body.html('<tr><td colspan="4" style="text-align:center;color:#999;">No log entries.</td></tr>');
						$('#peptide-log-pagination').empty();
						$('#peptide-log-status').text('0 entries');
						return;
					}

					var html = '';
					$.each(rows, function(i, row) {
						html += '<tr>';
						html += '<td style="font-size:12px;white-space:nowrap;">' + $('<span>').text(row.created_at).html() + '</td>';
						html += '<td>' + levelBadge(row.level) + '</td>';
						html += '<td style="font-size:12px;">' + $('<span>').text(row.context).html() + '</td>';
						html += '<td style="font-size:13px;word-break:break-word;">' + $('<span>').text(row.message).html() + '</td>';
						html += '</tr>';
					});
					$body.html(html);

					// Pagination.
					var totalPages = response.data.total_pages;
					var pagHtml = '<span style="color:#666;font-size:12px;">Page ' + currentPage + ' of ' + totalPages + ' (' + response.data.total + ' entries)</span>';
					if (currentPage > 1) {
						pagHtml += ' <button type="button" class="button button-small peptide-log-page" data-page="' + (currentPage - 1) + '">&laquo; Prev</button>';
					}
					if (currentPage < totalPages) {
						pagHtml += ' <button type="button" class="button button-small peptide-log-page" data-page="' + (currentPage + 1) + '">Next &raquo;</button>';
					}
					$('#peptide-log-pagination').html(pagHtml);
					$('#peptide-log-status').text(response.data.total + ' entries');
				});
			}

			// Bindings.
			$('#peptide-log-refresh').on('click', function() { loadLogs(1); });
			$('#peptide-log-level, #peptide-log-context').on('change', function() { loadLogs(1); });
			$(document).on('click', '.peptide-log-page', function() { loadLogs($(this).data('page')); });

			$('#peptide-log-clear').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Clear all log entries?', 'peptide-news' ) ); ?>')) return;
				$.post(peptideNewsAdmin.ajax_url, {
					action: 'peptide_news_clear_logs',
					nonce:  peptideNewsAdmin.admin_nonce
				}, function(response) {
					if (response.success) loadLogs(1);
				});
			});

			// Initial load.
			loadLogs(1);
		});
		</script>
		<?php
	}
}
