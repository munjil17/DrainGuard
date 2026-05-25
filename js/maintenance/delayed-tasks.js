document.addEventListener("DOMContentLoaded", function () {
    const forms = document.querySelectorAll(".delay-form");

    forms.forEach(function (form) {
        const reasonInput = form.querySelector(".delay-reason");
        const dateInput = form.querySelector(".new-deadline-date");
        const submitButton = form.querySelector(".delay-btn");
        const requestTypeInput = form.querySelector('input[name="request_type"]');

        let isSubmitting = false;

        function resetButton() {
            if (!submitButton) return;

            submitButton.disabled = false;
            submitButton.classList.remove("is-loading");

            if (requestTypeInput && requestTypeInput.value === "delay_notification") {
                submitButton.innerHTML = '<i class="bi bi-send"></i> Send Delay Notification to Ward Officer';
            } else {
                submitButton.innerHTML = '<i class="bi bi-chat-square-text"></i> Request Deadline Extension';
            }
        }

        function setLoading() {
            if (!submitButton) return;

            submitButton.disabled = true;
            submitButton.classList.add("is-loading");

            if (requestTypeInput && requestTypeInput.value === "delay_notification") {
                submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending Notification...';
            } else {
                submitButton.innerHTML = '<i class="bi bi-hourglass-split"></i> Sending Extension Request...';
            }
        }

        function validate() {
            const requestType = requestTypeInput ? requestTypeInput.value : "";
            const reasonValue = reasonInput ? reasonInput.value.trim() : "";
            const dateValue = dateInput ? dateInput.value.trim() : "";

            if (reasonValue === "") {
                alert("Delay reason is required.");
                if (reasonInput) reasonInput.focus();
                return false;
            }

            if (requestType === "deadline_extension") {
                if (dateValue === "") {
                    alert("Please select expected new completion deadline.");
                    if (dateInput) dateInput.focus();
                    return false;
                }

                const selectedDate = new Date(dateValue + "T00:00:00");
                const today = new Date();

                today.setHours(0, 0, 0, 0);

                if (Number.isNaN(selectedDate.getTime())) {
                    alert("Invalid new deadline date.");
                    return false;
                }

                if (selectedDate <= today) {
                    alert("New deadline must be a future date.");
                    return false;
                }
            }

            return true;
        }

        form.addEventListener("submit", function (event) {
            event.preventDefault();

            if (isSubmitting) {
                return;
            }

            if (!validate()) {
                return;
            }

            const requestType = requestTypeInput ? requestTypeInput.value : "";

            let confirmMessage = "Send delay notification to Ward Officer?";

            if (requestType === "deadline_extension") {
                confirmMessage = "Submit deadline extension request to Ward Officer?";
            }

            const confirmed = confirm(confirmMessage);

            if (!confirmed) {
                return;
            }

            isSubmitting = true;
            setLoading();

            const formData = new FormData(form);

            fetch("delayed-tasks.php", {
                method: "POST",
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    alert(data.message || "Request processed.");

                    if (data.success) {
                        window.location.href = "delayed-tasks.php";
                    } else {
                        isSubmitting = false;
                        resetButton();
                    }
                })
                .catch(function () {
                    alert("Failed to submit request. Please try again.");

                    isSubmitting = false;
                    resetButton();
                });
        });
    });
});