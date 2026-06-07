document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".co-details-toggle").forEach(function (button) {
        button.addEventListener("click", function () {
            const panelId = button.getAttribute("aria-controls");
            const panel = panelId ? document.getElementById(panelId) : null;

            if (!panel) {
                return;
            }

            const isOpen = button.getAttribute("aria-expanded") === "true";
            button.setAttribute("aria-expanded", isOpen ? "false" : "true");
            panel.hidden = isOpen;

            const label = button.querySelector("span");
            if (label) {
                label.textContent = isOpen ? "View Details" : "Hide Details";
            }
        });
    });
});
