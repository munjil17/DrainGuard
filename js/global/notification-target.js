/* js/global/notification-target.js */

document.addEventListener("DOMContentLoaded", function() {
    const urlParams = new URLSearchParams(window.location.search);
    
    // Check if highlight parameter exists
    let target = urlParams.get('highlight');
    
    // Support ?complaint_id=123&highlight=1 fallback
    if (!target || target === '1') {
        if (urlParams.has('complaint_id')) {
            target = urlParams.get('complaint_id');
        } else if (urlParams.has('id')) {
            target = urlParams.get('id');
        } else if (urlParams.has('code')) {
            target = urlParams.get('code');
        }
    }

    if (target) {
        // Build generic selectors based on requested data attributes
        const selectors = [
            `[data-complaint-code="${target}" i]`,
            `[data-complaint-id="${target}" i]`,
            `[data-target-id="${target}" i]`,
            `[data-notification-target="${target}" i]`,
            `[data-id="${target}" i]`,
            `[data-code="${target}" i]`
        ];

        // Try to find the element
        let targetElement = null;
        for (const selector of selectors) {
            targetElement = document.querySelector(selector);
            if (targetElement) {
                // If the element is a button, we want to highlight its parent row or card
                const rowOrCard = targetElement.closest('tr, .card, .cm-row, .task-card, .complaint-card, .report-card');
                if (rowOrCard) {
                    targetElement = rowOrCard;
                }
                break;
            }
        }

        if (targetElement) {
            // Un-hide the element if it is currently hidden by a filter
            const computedStyle = window.getComputedStyle(targetElement);
            if (computedStyle.display === 'none' || computedStyle.visibility === 'hidden') {
                // Attempt to clear common filters to make the item visible
                const filters = document.querySelectorAll('select.cm-filter-select, select.filter-select, input[type="search"]');
                filters.forEach(f => {
                    f.value = f.options ? (f.querySelector('option[value=""]') ? '' : 'all') : '';
                    f.dispatchEvent(new Event('change', { bubbles: true }));
                });
                
                // If there are tab buttons, try to click the "All" tab
                const allTabs = document.querySelectorAll('.tab-btn[data-tab="all"], .filter-btn[data-filter="all"]');
                allTabs.forEach(tab => tab.click());
            }

            // Apply the highlight class
            targetElement.classList.add('dg-notification-target');

            // Scroll smoothly into view
            setTimeout(() => {
                targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 300);

            // Remove highlight ONLY if another element in the same context gets highlighted manually
            // We just keep it highlighted as requested.
        }
    }
});
