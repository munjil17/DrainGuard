document.addEventListener("DOMContentLoaded", function () {
    const forms = document.querySelectorAll(".fr-form");

    forms.forEach(function (form) {
        const stars = form.querySelectorAll(".fr-stars button");
        const ratingInput = form.querySelector(".rating-input");
        const actionInput = form.querySelector(".action-type");
        const actionButtons = form.querySelectorAll(".fr-actions button");

        stars.forEach(function (star) {
            star.addEventListener("click", function () {
                const rating = Number(this.dataset.value);

                if (ratingInput) {
                    ratingInput.value = rating;
                }

                stars.forEach(function (item) {
                    const itemValue = Number(item.dataset.value);

                    if (itemValue <= rating) {
                        item.classList.add("active");
                    } else {
                        item.classList.remove("active");
                    }
                });
            });
        });

        actionButtons.forEach(function (button) {
            button.addEventListener("click", function () {
                const action = this.dataset.action || "feedback";

                if (actionInput) {
                    actionInput.value = action;
                }
            });
        });

        form.addEventListener("submit", function (event) {
            const actionType = actionInput?.value || "feedback";
            const ratingValue = Number(ratingInput?.value || 0);

            if (actionType === "feedback" && ratingValue < 1) {
                event.preventDefault();
                alert("Please select a rating before submitting feedback.");
                return;
            }

            if (actionType === "false_completion") {
                const confirmReport = confirm("Are you sure you want to report false completion and reopen this complaint?");

                if (!confirmReport) {
                    event.preventDefault();
                }
            }
        });
    });
});