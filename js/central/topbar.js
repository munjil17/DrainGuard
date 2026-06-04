document.addEventListener("DOMContentLoaded", function () {
    const centralTopbarDetails = document.querySelector('.central-topbar .topbar-notification');
    const centralTopbarDropdown = document.querySelector('.central-topbar .notification-dropdown');
    const notificationSummary = centralTopbarDetails ? centralTopbarDetails.querySelector('summary') : null;

    if (centralTopbarDetails && centralTopbarDropdown && notificationSummary) {
        
        notificationSummary.addEventListener('click', function (event) {
            event.preventDefault();
            
            if (centralTopbarDetails.hasAttribute('open')) {
                centralTopbarDetails.removeAttribute('open');
            } else {
                centralTopbarDetails.setAttribute('open', '');
            }
        });
        
        notificationSummary.addEventListener('dblclick', function (event) {
            event.preventDefault();
            centralTopbarDetails.removeAttribute('open');
        });

        centralTopbarDropdown.addEventListener('click', function (event) {
            event.stopPropagation();
        });

        document.addEventListener('click', function (event) {
            if (centralTopbarDetails.hasAttribute('open') && !centralTopbarDetails.contains(event.target)) {
                centralTopbarDetails.removeAttribute('open');
            }
        });
    }
});
