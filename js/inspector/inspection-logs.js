document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.getElementById("inspectionLogsFilterForm");
    const modal = document.getElementById("logModal");
    const modalTaskId = document.getElementById("modalTaskId");
    const modalDecision = document.getElementById("modalDecision");
    const modalDate = document.getElementById("modalDate");
    const modalNote = document.getElementById("modalNote");

    const viewButtons = document.querySelectorAll(".view-note-btn");
    const closeButtons = document.querySelectorAll("[data-close-modal]");

    if (filterForm) {
        const searchInput = filterForm.querySelector('input[name="search"]');
        const selects = filterForm.querySelectorAll("select");

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

        selects.forEach(function (select) {
            select.addEventListener("change", function () {
                resetPageAndSubmit();
            });
        });
    }

    function openModal(button) {
        if (!modal) {
            return;
        }

        const task = button.dataset.task || "Task";
        const decision = button.dataset.decision || "Decision";
        const date = button.dataset.date || "Date";
        const note = button.dataset.note || "No note recorded.";

        if (modalTaskId) {
            modalTaskId.textContent = task;
        }

        if (modalDecision) {
            modalDecision.textContent = decision;
        }

        if (modalDate) {
            modalDate.textContent = date;
        }

        if (modalNote) {
            modalNote.textContent = note;
        }

        modal.classList.add("open");
        document.body.style.overflow = "hidden";
    }

    function closeModal() {
        if (!modal) {
            return;
        }

        modal.classList.remove("open");
        document.body.style.overflow = "";
    }

    viewButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            openModal(button);
        });
    });

    closeButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            closeModal();
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeModal();
        }
    });
});