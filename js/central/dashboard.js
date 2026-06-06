document.addEventListener("DOMContentLoaded", function () {
    /*
    |--------------------------------------------------------------------------
    | Central Dashboard JS
    |--------------------------------------------------------------------------
    | Keep this file only for dashboard body behavior.
    | Sidebar toggle is handled by: js/central/sidebar.js
    |--------------------------------------------------------------------------
    */

    const assignButtons = document.querySelectorAll(".cd-assign-btn");

    assignButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            showWarningModal("Team assignment is not available yet.");
        });
    });
});