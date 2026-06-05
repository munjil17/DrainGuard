document.addEventListener("DOMContentLoaded", function () {
    const forms = document.querySelectorAll(".fr-form");

    forms.forEach(function (form) {
        const stars = form.querySelectorAll(".fr-stars button");
        const ratingInput = form.querySelector(".rating-input");
        const actionInput = form.querySelector("select.action-type");
        const textarea = form.querySelector('textarea[name="feedback_text"]');

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


        form.addEventListener("submit", function (event) {
            const clickedButton = event.submitter;
            const actionType = actionInput ? actionInput.value : "feedback";
            const ratingValue = Number(ratingInput?.value || 0);
            const textValue = textarea ? textarea.value.trim() : "";

            if (actionType === "feedback") {
                if (ratingValue < 1) {
                    event.preventDefault();
                    showWarningModal("Please select a rating before submitting feedback.");
                    return;
                }

                if (textValue.length < 5) {
                    event.preventDefault();
                    showWarningModal("Please write a short feedback message.");
                    return;
                }
            }

            if (actionType === "citizen_objection") {
                if (ratingValue < 1) {
                    event.preventDefault();
                    showWarningModal("Please select a rating before submitting your objection.");
                    return;
                }

                if (textValue.length < 10) {
                    event.preventDefault();
                    showWarningModal("Please clearly explain why the problem is still not solved.");
                    return;
                }

                event.preventDefault();
                showConfirmModal({
                    title: "Submit Objection",
                    message: "Submit this objection to Ward Officer? The complaint will be marked as disputed until reviewed.",
                    confirmText: "Submit",
                    cancelText: "Cancel",
                    type: "warning",
                    onConfirm: function() {
                        if (clickedButton) {
                            clickedButton.innerHTML = "Processing...";
                            clickedButton.style.pointerEvents = "none";
                        }
                        form.submit();
                    }
                });
                return;
            }

            if (clickedButton) {
                clickedButton.innerHTML = "Processing...";
                clickedButton.style.pointerEvents = "none";
            }
        });
    });
});