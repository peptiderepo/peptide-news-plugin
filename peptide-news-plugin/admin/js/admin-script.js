/**
 * Admin JS for Peptide News Analytics Dashboard
 */

(function() {
    const ctx = document.getElementById('peptide-analytics-chart')?.getContext('2d');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [
                {
                    label: 'Clicks',
                    data: [12, 19, 3, 5, 2, 3],
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }
            ]
            }
        });
})();
