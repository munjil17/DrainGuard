document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.getElementById("inspectionQueueFilterForm");
    const queueCards = document.querySelectorAll(".queue-card");
    const actionForms = document.querySelectorAll(".inspection-actions");

    queueCards.forEach(function (card, index) {
        card.style.animationDelay = `${index * 70}ms`;
        card.classList.add("queue-card-show");
    });

    if (filterForm) {
        const searchInput = filterForm.querySelector('input[name="search"]');
        const selectInputs = filterForm.querySelectorAll("select");

        let searchTimer = null;

        function resetPageAndSubmit() {
            const pageInput = filterForm.querySelector('input[name="page"]');

            if (pageInput) {
                pageInput.remove();
            }

            filterForm.submit();
        }

        if (searchInput) {
            searchInput.addEventListener("input", function () {
                clearTimeout(searchTimer);

                searchTimer = setTimeout(function () {
                    resetPageAndSubmit();
                }, 500);
            });

            searchInput.addEventListener("keydown", function (event) {
                if (event.key === "Enter") {
                    event.preventDefault();
                    resetPageAndSubmit();
                }
            });
        }

        selectInputs.forEach(function (select) {
            select.addEventListener("change", function () {
                resetPageAndSubmit();
            });
        });
    }

    actionForms.forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const clickedButton = event.submitter;

            if (!clickedButton) {
                return;
            }

            const action = clickedButton.value;
            const note = form.querySelector(".inspection-note");
            let message = "Are you sure you want to continue?";

            if (action === "approve") {
                message = "Approve this work and mark the complaint as Solved?";
            }

            if (action === "false_completion") {
                if (!note || note.value.trim().length < 10) {
                    event.preventDefault();
                    showWarningModal("Please write a clear inspector note before confirming false completion.");
                    return;
                }

                message = "Confirm false completion? This will send the case back to Ward Officer for local team assignment and apply accountability penalties.";
            }

            event.preventDefault();

            showConfirmModal({
                title: "Confirm Inspection Action",
                message: message,
                confirmText: "Confirm",
                cancelText: "Cancel",
                type: "confirm",
                onConfirm: function() {
                    let hiddenAction = form.querySelector('input[name="inspection_action"][type="hidden"]');

                    if (!hiddenAction) {
                        hiddenAction = document.createElement("input");
                        hiddenAction.type = "hidden";
                        hiddenAction.name = "inspection_action";
                        form.appendChild(hiddenAction);
                    }

                    hiddenAction.value = action;
                    clickedButton.innerHTML = "Processing...";
                    clickedButton.style.pointerEvents = "none";
                    form.submit();
                }
            });

            hiddenAction.value = action;

            clickedButton.innerHTML = "Processing...";
            clickedButton.style.pointerEvents = "none";
        });
    });
});