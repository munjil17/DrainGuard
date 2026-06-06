/* js/global/notification-target.js */

document.addEventListener("DOMContentLoaded", function () {
    const urlParams = new URLSearchParams(window.location.search);

    const focusRequested = urlParams.get("focus") === "1" || urlParams.has("highlight");
    const targets = [
        urlParams.get("complaint_id"),
        urlParams.get("complaint_code"),
        urlParams.get("target_id"),
        urlParams.get("assignment_id"),
        urlParams.get("highlight"),
        urlParams.get("id"),
        urlParams.get("code")
    ].filter(function (value) {
        return value && value !== "1";
    });

    if (!focusRequested && targets.length === 0) {
        return;
    }

    const targetElement = findTargetElement(targets);

    if (!targetElement) {
        showFocusMessage("The related complaint could not be found.");
        return;
    }

    revealHiddenTarget(targetElement);

    targetElement.classList.add("notification-focus", "notification-selected", "dg-notification-target");

    setTimeout(function () {
        targetElement.scrollIntoView({ behavior: "smooth", block: "center" });
    }, 300);
});

function findTargetElement(targets) {
    const selectorNames = [
        "data-complaint-id",
        "data-complaint-code",
        "data-target-id",
        "data-assignment-id",
        "data-notification-target",
        "data-id",
        "data-code"
    ];

    for (const target of targets) {
        for (const name of selectorNames) {
            const escapedTarget = cssEscape(target);
            const exactSelector = `[${name}="${escapedTarget}" i]`;
            const element = document.querySelector(exactSelector);

            if (element) {
                return element.closest("tr, article, .card, .cm-row, .task-card, .complaint-card, .report-card, .review-card, .queue-card, .case-card, .vq-card, .lta-card, .rd-card, .objection-card") || element;
            }
        }
    }

    return null;
}

function revealHiddenTarget(targetElement) {
    const filters = document.querySelectorAll('select, input[type="search"], input[name="search"]');

    filters.forEach(function (filter) {
        if (filter.tagName === "SELECT") {
            if (filter.querySelector('option[value="all"]')) {
                filter.value = "all";
            } else if (filter.querySelector('option[value=""]')) {
                filter.value = "";
            }
        } else {
            filter.value = "";
        }

        filter.dispatchEvent(new Event("input", { bubbles: true }));
        filter.dispatchEvent(new Event("change", { bubbles: true }));
    });

    targetElement.hidden = false;
    targetElement.classList.remove("d-none", "hidden");
    targetElement.style.display = "";
}

function showFocusMessage(message) {
    const notice = document.createElement("div");
    notice.className = "notification-focus-message";
    notice.setAttribute("role", "status");
    notice.textContent = message;
    document.body.appendChild(notice);
}

function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(value);
    }

    return String(value).replace(/["\\]/g, "\\$&");
}
