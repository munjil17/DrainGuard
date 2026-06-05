/**
 * DRAINGUARD GLOBAL CONFIRM MODAL
 * Dynamically injects a beautiful confirmation dialog.
 */

window.showConfirmModal = function(options) {
    const config = Object.assign({
        title: "Confirm Action",
        message: "Are you sure you want to proceed?",
        confirmText: "Confirm",
        cancelText: "Cancel",
        showCancel: true,
        type: "info", // success, danger, warning, info
        onConfirm: null,
        onCancel: null
    }, options);

    // Map types to Bootstrap Icons
    const icons = {
        success: "bi-check-circle-fill",
        danger: "bi-exclamation-triangle-fill",
        warning: "bi-exclamation-circle-fill",
        info: "bi-info-circle-fill"
    };

    const iconClass = icons[config.type] || icons.info;

    // Create Overlay
    const overlay = document.createElement("div");
    overlay.className = "dg-global-modal-overlay dg-modal-" + config.type;
    
    const cancelBtnHTML = config.showCancel ? `<button type="button" class="dg-global-modal-btn dg-btn-cancel">${config.cancelText}</button>` : '';

    // Build HTML
    overlay.innerHTML = `
        <div class="dg-global-modal">
            <div class="dg-global-modal-body">
                <div class="dg-global-modal-icon">
                    <i class="bi ${iconClass}"></i>
                </div>
                <h3 class="dg-global-modal-title">${config.title}</h3>
                <p class="dg-global-modal-message">${config.message}</p>
            </div>
            <div class="dg-global-modal-footer">
                ${cancelBtnHTML}
                <button type="button" class="dg-global-modal-btn dg-btn-confirm">${config.confirmText}</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    const btnCancel = overlay.querySelector(".dg-btn-cancel");
    const btnConfirm = overlay.querySelector(".dg-btn-confirm");

    function closeModal() {
        overlay.classList.remove("dg-show");
        // Wait for animation to finish before removing from DOM
        setTimeout(() => {
            if (document.body.contains(overlay)) {
                document.body.removeChild(overlay);
            }
        }, 300);
    }

    if (btnCancel) {
        btnCancel.addEventListener("click", function() {
            closeModal();
            if (typeof config.onCancel === "function") {
                config.onCancel();
            }
        });
    }

    btnConfirm.addEventListener("click", function() {
        closeModal();
        if (typeof config.onConfirm === "function") {
            config.onConfirm();
        }
    });

    // Close on overlay click
    overlay.addEventListener("click", function(e) {
        if (e.target === overlay) {
            if (btnCancel) {
                btnCancel.click();
            } else {
                closeModal();
            }
        }
    });

    // Trigger animation
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            overlay.classList.add("dg-show");
        });
    });
};

window.showWarningModal = function(message) {
    showConfirmModal({
        title: "Information",
        message: message,
        confirmText: "OK",
        showCancel: false,
        type: "warning"
    });
};
