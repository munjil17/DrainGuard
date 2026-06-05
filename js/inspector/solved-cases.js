document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.getElementById("solvedCasesFilterForm");
    const caseCards = document.querySelectorAll(".case-card");
    const reviewForms = document.querySelectorAll(".review-form");

    caseCards.forEach(function (card, index) {
        card.style.animationDelay = `${index * 70}ms`;
        card.classList.add("solved-case-show");
    });

    if (filterForm) {
        const searchInput = filterForm.querySelector('input[name="search"]');
        const selectInputs = filterForm.querySelectorAll("select");

        let searchTimer = null;

        if (searchInput) {
            searchInput.addEventListener("input", function () {
                clearTimeout(searchTimer);

                searchTimer = setTimeout(function () {
                    filterForm.submit();
                }, 500);
            });

            searchInput.addEventListener("keydown", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    filterForm.submit();
                }
            });
        }

        selectInputs.forEach(function (select) {
            select.addEventListener("change", function () {
                filterForm.submit();
            });
        });
    }

    reviewForms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const button = form.querySelector(".review-btn");

            event.preventDefault();
            showConfirmModal({
                title: "Confirm Action",
                message: "Move this complaint to Before / After Review and mark it as Inspector Verification Pending?",
                confirmText: "Move",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    if (button) {
                        button.innerHTML = "Processing...";
                        button.style.pointerEvents = "none";
                    }
                    form.submit();
                }
            });
        });
    });
});