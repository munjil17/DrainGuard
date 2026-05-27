document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.getElementById("citizenObjectionsFilterForm");
    const objectionCards = document.querySelectorAll(".objection-card");
    const actionForms = document.querySelectorAll(".objection-actions");

    objectionCards.forEach(function (card, index) {
        card.style.animationDelay = `${index * 70}ms`;
        card.classList.add("objection-card-show");
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
            const note = form.querySelector(".inspector-note");

            let message = "Are you sure you want to continue?";

            if (action === "reopen") {
                message = "Accept this objection and send it to Ward Officer for local team assignment?";
            } else if (action === "reject") {
                message = "Reject this objection and keep the complaint as Solved?";
            }

            if (note && note.value.trim().length < 5) {
                alert("Please write a short inspector note before submitting.");
                event.preventDefault();
                return;
            }

            if (!confirm(message)) {
                event.preventDefault();
                return;
            }

            let hiddenAction = form.querySelector('input[name="objection_action"][type="hidden"]');

            if (!hiddenAction) {
                hiddenAction = document.createElement("input");
                hiddenAction.type = "hidden";
                hiddenAction.name = "objection_action";
                form.appendChild(hiddenAction);
            }

            hiddenAction.value = action;

            clickedButton.innerHTML = "Processing...";
            clickedButton.style.pointerEvents = "none";
        });
    });
});