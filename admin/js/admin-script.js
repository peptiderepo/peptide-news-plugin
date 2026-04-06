/**
 * Admin JS for Peptide News Analytics Dashboard
 *
 * Initialises Chart.js charts using the data exported by dashboard.php
 * via the global `peptideNewsDashboardData` object.
 *
 * @since 1.1.0
 */

(function () {
    'use strict';

    /* ── Guard: only run when dashboard data is available ── */
    if (typeof peptideNewsDashboardData === 'undefined') {
        return;
    }

    var data = peptideNewsDashboardData;

    /* ── Shared colour palette ── */
    var colours = {
        blue:       'rgba(59, 130, 246, 1)',
        blueFill:   'rgba(59, 130, 246, 0.08)',
        green:      'rgba(16, 185, 129, 1)',
        greenFill:  'rgba(16, 185, 129, 0.08)',
        palette: [
            'rgba(59, 130, 246, 0.85)',
            'rgba(16, 185, 129, 0.85)',
            'rgba(245, 158, 11, 0.85)',
            'rgba(239, 68, 68, 0.85)',
            'rgba(139, 92, 246, 0.85)',
            'rgba(236, 72, 153, 0.85)',
            'rgba(20, 184, 166, 0.85)',
            'rgba(249, 115, 22, 0.85)'
        ]
    };

    /* ── 1. Click Trends — Line Chart ── */
    var trendsCanvas = document.getElementById('pn-trends-chart');
    if (trendsCanvas && data.trends) {
        var trendsLabels = data.trends.map(function (row) { return row.click_date; });
        var trendsClicks = data.trends.map(function (row) { return parseInt(row.total_clicks, 10) || 0; });
        var trendsUnique = data.trends.map(function (row) { return parseInt(row.total_unique, 10) || 0; });

        new Chart(trendsCanvas, {
            type: 'line',
            data: {
                labels: trendsLabels,
                datasets: [
                    {
                        label: 'Total Clicks',
                        data: trendsClicks,
                        borderColor: colours.blue,
                        backgroundColor: colours.blueFill,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Unique Visitors',
                        data: trendsUnique,
                        borderColor: colours.green,
                        backgroundColor: colours.greenFill,
                        borderWidth: 2,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        fill: true,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, padding: 16 }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { maxTicksLimit: 12 }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }

    /* ── 2. Device Breakdown — Doughnut Chart ── */
    var devicesCanvas = document.getElementById('pn-devices-chart');
    if (devicesCanvas && data.devices) {
        var deviceLabels = data.devices.map(function (row) { return row.device_type || 'Unknown'; });
        var deviceCounts = data.devices.map(function (row) { return parseInt(row.click_count, 10) || 0; });

        new Chart(devicesCanvas, {
            type: 'doughnut',
            data: {
                labels: deviceLabels,
                datasets: [{
                    data: deviceCounts,
                    backgroundColor: colours.palette.slice(0, deviceLabels.length),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, padding: 12 }
                    }
                },
                cutout: '55%'
            }
        });
    }

    /* ── 3. Source Performance — Bar Chart ── */
    var sourcesCanvas = document.getElementById('pn-sources-chart');
    if (sourcesCanvas && data.sources) {
        var sourceLabels = data.sources.map(function (row) { return row.source || 'Unknown'; });
        var sourceCounts = data.sources.map(function (row) { return parseInt(row.click_count, 10) || 0; });

        new Chart(sourcesCanvas, {
            type: 'bar',
            data: {
                labels: sourceLabels,
                datasets: [{
                    label: 'Clicks',
                    data: sourceCounts,
                    backgroundColor: colours.palette.slice(0, sourceLabels.length),
                    borderRadius: 4,
                    maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                }
            }
        });
    }
})();
