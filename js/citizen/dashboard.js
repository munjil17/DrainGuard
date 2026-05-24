document.addEventListener("DOMContentLoaded", function () {
    const trackForm = document.getElementById("trackComplaintForm");
    const trackInput = document.getElementById("trackComplaintInput");
    const trackError = document.getElementById("trackComplaintError");

    function showTrackError(message) {
        if (!trackError) {
            return;
        }

        trackError.textContent = message;
        trackError.classList.add("show");
    }

    function clearTrackError() {
        if (!trackError) {
            return;
        }

        trackError.textContent = "";
        trackError.classList.remove("show");
    }

    if (trackInput) {
        trackInput.addEventListener("input", clearTrackError);
    }

    if (trackForm && trackInput) {
        trackForm.addEventListener("submit", function (event) {
            const value = trackInput.value.trim();

            clearTrackError();

            if (value === "") {
                event.preventDefault();
                showTrackError("Please enter a complaint ID before tracking.");
                trackInput.focus();
            }
        });
    }
});