<?php
/**
 * Cost Dashboard admin partial.
 *
 * Displays LLM API cost tracking data: budget status, daily cost chart,
 * per-model and per-operation breakdowns, and a recent API call log.
 *
 * Data is loaded via AJAX (peptide_news_get_cost_data action) and rendered
 * client-side using Chart.js for the daily cost trend chart.
 *
 * @since 2.4.0
 * @see class-peptide-news-cost-tracker.php — Data source for all cost queries
 * @see class-peptide-news-admin.php        — Registers this page via add_submenu_page()
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'LLM Cost Dashboard', 'peptide-news' ); ?></h1>

	<!-- Period selector -->
	<div style="margin: 15px 0;">
		<label for="pn-cost-period"><strong><?php esc_html_e( 'Period:', 'peptide-news' ); ?></strong></label>
		<select id="pn-cost-period" style="margin-left: 5px;">
			<option value="day"><?php esc_html_e( 'Today', 'peptide-news' ); ?></option>
			<option value="week"><?php esc_html_e( 'Last 7 Days', 'peptide-news' ); ?></option>
			<option value="month" selected><?php esc_html_e( 'This Month', 'peptide-news' ); ?></option>
		</select>
		<button type="button" id="pn-cost-refresh" class="button button-secondary" style="margin-left: 8px;">
			<?php esc_html_e( 'Refresh', 'peptide-news' ); ?>
		</button>
	</div>

	<!-- Budget Status Card -->
	<div id="pn-budget-status" class="card" style="max-width: 600px; padding: 15px; margin-bottom: 20px;">
		<h2 style="margin-top: 0;"><?php esc_html_e( 'Monthly Budget', 'peptide-news' ); ?></h2>
		<div id="pn-budget-content">
			<p class="description"><?php esc_html_e( 'Loading...', 'peptide-news' ); ?></p>
		</div>
	</div>

	<!-- Summary Cards Row -->
	<div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
		<div class="card" style="flex: 1; min-width: 150px; padding: 15px;">
			<h3 style="margin: 0 0 5px;"><?php esc_html_e( 'Total Cost', 'peptide-news' ); ?></h3>
			<p id="pn-cost-total" style="font-size: 24px; font-weight: bold; margin: 0;">$0.0000</p>
		</div>
		<div class="card" style="flex: 1; min-width: 150px; padding: 15px;">
			<h3 style="margin: 0 0 5px;"><?php esc_html_e( 'API Requests', 'peptide-news' ); ?></h3>
			<p id="pn-cost-requests" style="font-size: 24px; font-weight: bold; margin: 0;">0</p>
		</div>
		<div class="card" style="flex: 1; min-width: 150px; padding: 15px;">
			<h3 style="margin: 0 0 5px;"><?php esc_html_e( 'Total Tokens', 'peptide-news' ); ?></h3>
			<p id="pn-cost-tokens" style="font-size: 24px; font-weight: bold; margin: 0;">0</p>
		</div>
	</div>

	<!-- Daily Cost Chart -->
	<div class="card" style="max-width: 800px; padding: 15px; margin-bottom: 20px;">
		<h2 style="margin-top: 0;"><?php esc_html_e( 'Daily Cost Trend', 'peptide-news' ); ?></h2>
		<canvas id="pn-cost-chart" height="250"></canvas>
	</div>

	<!-- Breakdowns Row -->
	<div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
		<!-- Per-Model Breakdown -->
		<div class="card" style="flex: 1; min-width: 350px; padding: 15px;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Cost by Model', 'peptide-news' ); ?></h2>
			<table class="widefat striped" id="pn-cost-by-model">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Model', 'peptide-news' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'peptide-news' ); ?></th>
						<th><?php esc_html_e( 'Tokens', 'peptide-news' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'peptide-news' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td colspan="4"><?php esc_html_e( 'Loading...', 'peptide-news' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<!-- Per-Operation Breakdown -->
		<div class="card" style="flex: 1; min-width: 300px; padding: 15px;">
			<h2 style="margin-top: 0;"><?php esc_html_e( 'Cost by Operation', 'peptide-news' ); ?></h2>
			<table class="widefat striped" id="pn-cost-by-operation">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Operation', 'peptide-news' ); ?></th>
						<th><?php esc_html_e( 'Requests', 'peptide-news' ); ?></th>
						<th><?php esc_html_e( 'Cost', 'peptide-news' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td colspan="3"><?php esc_html_e( 'Loading...', 'peptide-news' ); ?></td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<!-- Recent API Calls Log -->
	<div class="card" style="padding: 15px; margin-bottom: 20px;">
		<h2 style="margin-top: 0;"><?php esc_html_e( 'Recent API Calls', 'peptide-news' ); ?></h2>
		<table class="widefat striped" id="pn-cost-recent">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'peptide-news' ); ?></th>
					<th><?php esc_html_e( 'Model', 'peptide-news' ); ?></th>
					<th><?php esc_html_e( 'Operation', 'peptide-news' ); ?></th>
					<th><?php esc_html_e( 'Prompt', 'peptide-news' ); ?></th>
					<th><?php esc_html_e( 'Completion', 'peptide-news' ); ?></th>
					<th><?php esc_html_e( 'Total', 'peptide-news' ); ?></th>
					<th><?php esc_html_e( 'Cost', 'peptide-news' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td colspan="7"><?php esc_html_e( 'Loading...', 'peptide-news' ); ?></td></tr>
			</tbody>
		</table>
	</div>
</div>

<script>
(function($) {
	'use strict';

	var costChart = null;

	function loadCostData() {
		var period = $('#pn-cost-period').val();

		$.ajax({
			url: peptideNewsAdmin.ajax_url,
			method: 'GET',
			data: {
				action: 'peptide_news_get_cost_data',
				nonce: peptideNewsAdmin.admin_nonce,
				period: period
			},
			success: function(response) {
				if (response.success) {
					renderCostData(response.data);
				}
			}
		});
	}

	function renderCostData(data) {
		// Summary cards.
		$('#pn-cost-total').text('$' + parseFloat(data.summary.total_cost).toFixed(4));
		$('#pn-cost-requests').text(data.summary.total_requests.toLocaleString());
		$('#pn-cost-tokens').text(data.summary.total_tokens.toLocaleString());

		// Budget status.
		renderBudget(data.budget);

		// Daily chart.
		renderChart(data.daily_costs);

		// Model breakdown table.
		renderModelTable(data.summary.by_model);

		// Operation breakdown table.
		renderOperationTable(data.summary.by_operation);

		// Recent calls log.
		renderRecentCalls(data.recent_calls);
	}

	function renderBudget(budget) {
		var html = '';
		if (budget.limit <= 0 || budget.mode === 'disabled') {
			html = '<p>' + '<?php echo esc_js( __( 'No budget set. Configure a monthly budget in Settings to track spending limits.', 'peptide-news' ) ); ?>' + '</p>';
		} else {
			var pct = budget.percent_used;
			var barColor = pct >= 100 ? '#dc3232' : (pct >= 80 ? '#dba617' : '#46b450');
			var modeLabel = budget.mode === 'hard_stop' ? '<?php echo esc_js( __( 'Hard Stop', 'peptide-news' ) ); ?>' : '<?php echo esc_js( __( 'Warn Only', 'peptide-news' ) ); ?>';

			html += '<p><strong><?php echo esc_js( __( 'Spent:', 'peptide-news' ) ); ?></strong> $' + budget.month_spend.toFixed(4) + ' / $' + budget.limit.toFixed(2) + ' (' + pct.toFixed(1) + '%)</p>';
			html += '<div style="background:#e0e0e0;border-radius:4px;height:20px;max-width:400px;margin:8px 0;">';
			html += '<div style="background:' + barColor + ';height:100%;border-radius:4px;width:' + Math.min(pct, 100) + '%;transition:width 0.3s;"></div>';
			html += '</div>';
			html += '<p class="description"><strong><?php echo esc_js( __( 'Mode:', 'peptide-news' ) ); ?></strong> ' + modeLabel + '</p>';

			if (pct >= 100 && budget.mode === 'hard_stop') {
				html += '<div class="notice notice-error inline" style="margin:10px 0;"><p><?php echo esc_js( __( 'Budget exceeded! LLM API calls are blocked until next month or budget is increased.', 'peptide-news' ) ); ?></p></div>';
			} else if (pct >= 80) {
				html += '<div class="notice notice-warning inline" style="margin:10px 0;"><p><?php echo esc_js( __( 'Approaching budget limit.', 'peptide-news' ) ); ?></p></div>';
			}
		}
		$('#pn-budget-content').html(html);
	}

	function renderChart(dailyCosts) {
		var ctx = document.getElementById('pn-cost-chart');
		if (!ctx) return;

		var labels = dailyCosts.map(function(d) { return d.date; });
		var costs = dailyCosts.map(function(d) { return parseFloat(d.cost); });
		var tokens = dailyCosts.map(function(d) { return parseInt(d.tokens, 10); });

		if (costChart) {
			costChart.destroy();
		}

		costChart = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					label: '<?php echo esc_js( __( 'Cost (USD)', 'peptide-news' ) ); ?>',
					data: costs,
					backgroundColor: 'rgba(0, 115, 170, 0.7)',
					borderColor: '#0073aa',
					borderWidth: 1,
					yAxisID: 'y'
				}, {
					label: '<?php echo esc_js( __( 'Tokens', 'peptide-news' ) ); ?>',
					data: tokens,
					type: 'line',
					borderColor: '#46b450',
					backgroundColor: 'rgba(70, 180, 80, 0.1)',
					fill: true,
					tension: 0.3,
					yAxisID: 'y1'
				}]
			},
			options: {
				responsive: true,
				interaction: { mode: 'index', intersect: false },
				scales: {
					y: {
						type: 'linear',
						position: 'left',
						title: { display: true, text: '<?php echo esc_js( __( 'Cost (USD)', 'peptide-news' ) ); ?>' },
						ticks: {
							callback: function(v) { return '$' + v.toFixed(4); }
						}
					},
					y1: {
						type: 'linear',
						position: 'right',
						title: { display: true, text: '<?php echo esc_js( __( 'Tokens', 'peptide-news' ) ); ?>' },
						grid: { drawOnChartArea: false }
					}
				}
			}
		});
	}

	function renderModelTable(models) {
		var tbody = $('#pn-cost-by-model tbody');
		if (!models || models.length === 0) {
			tbody.html('<tr><td colspan="4"><?php echo esc_js( __( 'No data for this period.', 'peptide-news' ) ); ?></td></tr>');
			return;
		}
		var html = '';
		models.forEach(function(m) {
			html += '<tr>';
			html += '<td><code>' + escHtml(m.model) + '</code></td>';
			html += '<td>' + parseInt(m.requests, 10).toLocaleString() + '</td>';
			html += '<td>' + parseInt(m.total_tokens, 10).toLocaleString() + '</td>';
			html += '<td>$' + parseFloat(m.total_cost).toFixed(4) + '</td>';
			html += '</tr>';
		});
		tbody.html(html);
	}

	function renderOperationTable(ops) {
		var tbody = $('#pn-cost-by-operation tbody');
		if (!ops || ops.length === 0) {
			tbody.html('<tr><td colspan="3"><?php echo esc_js( __( 'No data for this period.', 'peptide-news' ) ); ?></td></tr>');
			return;
		}
		var html = '';
		ops.forEach(function(o) {
			html += '<tr>';
			html += '<td>' + escHtml(o.operation) + '</td>';
			html += '<td>' + parseInt(o.requests, 10).toLocaleString() + '</td>';
			html += '<td>$' + parseFloat(o.total_cost).toFixed(4) + '</td>';
			html += '</tr>';
		});
		tbody.html(html);
	}

	function renderRecentCalls(calls) {
		var tbody = $('#pn-cost-recent tbody');
		if (!calls || calls.length === 0) {
			tbody.html('<tr><td colspan="7"><?php echo esc_js( __( 'No API calls recorded yet.', 'peptide-news' ) ); ?></td></tr>');
			return;
		}
		var html = '';
		calls.forEach(function(c) {
			html += '<tr>';
			html += '<td>' + escHtml(c.created_at) + '</td>';
			html += '<td><code style="font-size:11px;">' + escHtml(c.model) + '</code></td>';
			html += '<td>' + escHtml(c.operation) + '</td>';
			html += '<td>' + parseInt(c.prompt_tokens, 10).toLocaleString() + '</td>';
			html += '<td>' + parseInt(c.completion_tokens, 10).toLocaleString() + '</td>';
			html += '<td>' + parseInt(c.total_tokens, 10).toLocaleString() + '</td>';
			html += '<td>$' + parseFloat(c.cost_usd).toFixed(6) + '</td>';
			html += '</tr>';
		});
		tbody.html(html);
	}

	function escHtml(str) {
		if (!str) return '';
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	// Event handlers.
	$('#pn-cost-period').on('change', loadCostData);
	$('#pn-cost-refresh').on('click', loadCostData);

	// Initial load.
	$(document).ready(loadCostData);

})(jQuery);
</script>
