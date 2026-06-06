document.addEventListener("DOMContentLoaded", function () {
    const filterForm = document.getElementById("beforeAfterFilterForm");
    const caseLinks = document.querySelectorAll(".review-case-link");
    const mediaBoxes = document.querySelectorAll(".media-box");

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

    caseLinks.forEach(function (link, index) {
        link.style.animationDelay = `${index * 45}ms`;

        link.addEventListener("click", function (event) {
            const isActive = link.classList.contains("active");

            if (!isActive) {
                return;
            }

            event.preventDefault();

            const currentUrl = new URL(window.location.href);

            currentUrl.searchParams.delete("complaint_id");

            window.location.href = currentUrl.toString();
        });
    });

    mediaBoxes.forEach(function (box) {
        box.addEventListener("click", function () {
            const img = box.querySelector("img");

            if (!img) {
                return;
            }

            const overlay = document.createElement("div");
            overlay.className = "media-preview-overlay";
            overlay.innerHTML = `
                <div class="media-preview-box">
                    <button type="button" class="media-preview-close">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <img src="${img.src}" alt="Preview">
                </div>
            `;

            document.body.appendChild(overlay);

            const closeBtn = overlay.querySelector(".media-preview-close");

            closeBtn.addEventListener("click", function () {
                overlay.remove();
            });

            overlay.addEventListener("click", function (event) {
                if (event.target === overlay) {
                    overlay.remove();
                }
            });
        });
    });
});
