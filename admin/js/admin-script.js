/**
 * Admin JS for Peptide News Analytics Dashboard
 *
 * Initialises Chart.js charts using the data exported by dashboard.php
 * via the global `peptideNewsDashboardData` object.
 *
 * Chart.js with responsive:true + maintainAspectRatio:false requires
 * the parent container to have an explicit height. The prepareCanvas()
 * helper wraps each <canvas> in a height-constrained <div> so that
 * charts render at a predictable, bounded size.
 *
 * @since 1.2.1
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

    /**
     * Wrap a canvas element in a height-constrained container.
     *
     * Chart.js sets canvas dimensions via JS, overriding CSS max-height.
     * The only reliable way to constrain chart height is to give the
     * *parent* element an explicit height.
     *
     * @param {string} id     Canvas element ID.
     * @param {number} height Desired chart height in pixels.
     * @return {HTMLCanvasElement|null} The canvas, or null if not found.
     */
    function prepareCanvas(id, height) {
        var canvas = document.getElementById(id);
        if (!canvas) {
            return null;
        }
        var wrapper = document.createElement('div');
        wrapper.style.position = 'relative';
        wrapper.style.height   = height + 'px';
        wrapper.style.width    = '100%';
        canvas.parentNode.insertBefore(wrapper, canvas);
        wrapper.appendChild(canvas);
        return canvas;
    }

    /* ── 1. Click Trends — Line Chart ── */
    var trendsCanvas = prepareCanvas('pn-trends-chart', 300);
    if (trendsCanvas && data.trends) {
        var trendsLabels = data.trends.map(function (row) { return row.click_date || row.stat_date; });
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
    var devicesCanvas = prepareCanvas('pn-devices-chart', 250);
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
    var sourcesCanvas = prepareCanvas('pn-sources-chart', 250);
    if (sourcesCanvas && data.sources) {
        var sourceLabels = data.sources.map(function (row) { return row.source || 'Unknown'; });
        var sourceCounts = data.sources.map(function (row) { return parseInt(row.click_count || row.total_clicks, 10) || 0; });

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
