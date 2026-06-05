document.addEventListener("DOMContentLoaded", function () {
    const data = window.wardAreaData || {};

    const cityCorporations = Array.isArray(data.cityCorporations) ? data.cityCorporations : [];
    const thanas = Array.isArray(data.thanas) ? data.thanas : [];
    const wards = Array.isArray(data.wards) ? data.wards : [];
    const wardDetails = data.wardDetails || {};

    const citySelect = document.getElementById("citySelect");
    const cityCorporationSelect = document.getElementById("cityCorporationSelect");
    const thanaSelect = document.getElementById("thanaSelect");
    const wardSelect = document.getElementById("wardSelect");

    const wardEmptyState = document.getElementById("wardEmptyState");
    const wardCard = document.getElementById("wardCard");

    const wardTitle = document.getElementById("wardTitle");
    const wardOfficer = document.getElementById("wardOfficer");
    const wardInspector = document.getElementById("wardInspector");
    const areaChipList = document.getElementById("areaChipList");
    const totalComplaints = document.getElementById("totalComplaints");
    const totalAreas = document.getElementById("totalAreas");

    const areaEditBtn = document.getElementById("areaEditBtn");
    const areaModal = document.getElementById("areaModal");
    const areaModalClose = document.getElementById("areaModalClose");
    const modalWardLabel = document.getElementById("modalWardLabel");
    const editAreaList = document.getElementById("editAreaList");

    const addAreaForm = document.getElementById("addAreaForm");
    const addCityId = document.getElementById("addCityId");
    const addCityCorId = document.getElementById("addCityCorId");
    const addThanaId = document.getElementById("addThanaId");
    const addWardId = document.getElementById("addWardId");
    const newAreaName = document.getElementById("newAreaName");

    const renameAreaForm = document.getElementById("renameAreaForm");
    const renameAreaId = document.getElementById("renameAreaId");
    const renameWardId = document.getElementById("renameWardId");
    const renameAreaName = document.getElementById("renameAreaName");

    const instructionBtn = document.getElementById("wardInstructionBtn");
    const inspectionBtn = document.getElementById("inspectionRequestBtn");

    const instructionModal = document.getElementById("instructionModal");
    const instructionModalClose = document.getElementById("instructionModalClose");
    const instructionModalTitle = document.getElementById("instructionModalTitle");
    const instructionModalSubtitle = document.getElementById("instructionModalSubtitle");
    const instructionOptions = document.getElementById("instructionOptions");
    const instructionForm = document.getElementById("instructionForm");
    const instructionReceiverUserId = document.getElementById("instructionReceiverUserId");
    const instructionReceiverRole = document.getElementById("instructionReceiverRole");
    const instructionWardId = document.getElementById("instructionWardId");
    const instructionTitleInput = document.getElementById("instructionTitleInput");
    const instructionMessage = document.getElementById("instructionMessage");

    let selectedWard = null;

    const wardInstructionOptions = [
        {
            title: "Verify New Complaint",
            message: "Please verify the newly submitted complaint in this ward and confirm whether the complaint is valid."
        },
        {
            title: "Visit Complaint Location",
            message: "Please visit the complaint location physically and submit the field verification status."
        },
        {
            title: "Assign Maintenance Team",
            message: "Please assign an available maintenance team for the verified drainage issue in this ward."
        },
        {
            title: "Follow Up Pending Complaint",
            message: "Please follow up on pending complaints in this ward and update their current status."
        },
        {
            title: "Review Reopened Complaint",
            message: "Please review the reopened complaint and decide whether further maintenance action is required."
        },
        {
            title: "Monitor High Risk Area",
            message: "Please monitor the high-risk drainage area and report if immediate action is needed."
        },
        {
            title: "Submit Ward Status Report",
            message: "Please submit a ward-level status report including complaints, risky areas, and pending actions."
        }
    ];

    const inspectionOptionsData = [
        {
            title: "Inspect Completed Work",
            message: "Please inspect the completed maintenance work and verify whether the issue has been properly resolved."
        },
        {
            title: "Verify Before/After Proof",
            message: "Please review the before-and-after proof submitted by the maintenance team and verify its accuracy."
        },
        {
            title: "Check False Completion Report",
            message: "Please check whether the reported completion is accurate or if it is a false completion report."
        },
        {
            title: "Review Citizen Objection",
            message: "Please review the citizen objection and inspect whether the complaint needs to be reopened."
        },
        {
            title: "Recheck Reopened Complaint",
            message: "Please recheck the reopened complaint after maintenance follow-up and submit your inspection decision."
        },
        {
            title: "Emergency Field Inspection",
            message: "Please perform an urgent field inspection for the emergency drainage situation in this ward."
        },
        {
            title: "Submit Inspection Report",
            message: "Please submit the final inspection report for this ward's resolved drainage cases."
        }
    ];

    function resetSelect(select, placeholder) {
        if (!select) return;

        select.innerHTML = `<option value="">${placeholder}</option>`;
        select.disabled = true;
    }

    function enableSelect(select) {
        if (!select) return;

        select.disabled = false;
    }

    function addOption(select, value, text) {
        const option = document.createElement("option");
        option.value = value;
        option.textContent = text;
        select.appendChild(option);
    }

    function hideWardCard() {
        selectedWard = null;

        if (wardCard) {
            wardCard.hidden = true;
        }

        if (wardEmptyState) {
            wardEmptyState.hidden = false;
        }
    }

    function showWardCard(ward) {
        selectedWard = ward;

        if (!ward) {
            hideWardCard();
            return;
        }

        if (wardEmptyState) {
            wardEmptyState.hidden = true;
        }

        if (wardCard) {
            wardCard.hidden = false;
        }

        const wardLabel = ward.ward_name || `Ward ${ward.ward_no || ""}`;

        if (wardTitle) {
            wardTitle.textContent = wardLabel;
        }

        if (wardOfficer) {
            wardOfficer.textContent = ward.officer_name || "Not Assigned";
        }

        if (wardInspector) {
            wardInspector.textContent = ward.inspector_name || "Not Assigned";
        }

        if (totalComplaints) {
            totalComplaints.textContent = ward.total_complaints || 0;
        }

        if (totalAreas) {
            totalAreas.textContent = ward.total_areas || 0;
        }

        renderAreaChips(ward.areas || []);
    }

    function renderAreaChips(areas) {
        if (!areaChipList) return;

        areaChipList.innerHTML = "";

        if (!Array.isArray(areas) || areas.length === 0) {
            const empty = document.createElement("span");
            empty.className = "wa-area-empty";
            empty.textContent = "No area mapping found for this ward.";
            areaChipList.appendChild(empty);
            return;
        }

        areas.forEach(function (area) {
            const chip = document.createElement("span");
            chip.className = "wa-area-chip";
            chip.textContent = area.area_name || "Unnamed Area";
            areaChipList.appendChild(chip);
        });
    }

    function renderEditAreaList(areas) {
        if (!editAreaList) return;

        editAreaList.innerHTML = "";

        if (!Array.isArray(areas) || areas.length === 0) {
            const empty = document.createElement("div");
            empty.className = "wa-area-empty";
            empty.textContent = "No existing area found. Add a new area below.";
            editAreaList.appendChild(empty);
            return;
        }

        areas.forEach(function (area) {
            const item = document.createElement("div");
            item.className = "wa-edit-area-item";

            const areaId = Number(area.area_id || 0);
            const areaName = area.area_name || "Unnamed Area";

            item.innerHTML = `
                <strong>${escapeHtml(areaName)}</strong>
                <button
                    type="button"
                    class="wa-rename-btn"
                    data-area-id="${areaId}"
                    data-area-name="${escapeHtml(areaName)}"
                    title="Rename area"
                >
                    <i class="bi bi-pencil-square"></i>
                </button>
            `;

            editAreaList.appendChild(item);
        });

        editAreaList.querySelectorAll(".wa-rename-btn").forEach(function (button) {
            button.addEventListener("click", function () {
                const areaId = Number(button.dataset.areaId || 0);
                const oldName = button.dataset.areaName || "";

                if (!selectedWard || !selectedWard.ward_id) {
                    showWarningModal("Please select a ward first.");
                    return;
                }

                if (areaId <= 0) {
                    showWarningModal("Invalid area selected.");
                    return;
                }

                const newName = prompt("Enter new area name:", oldName);

                if (newName === null) {
                    return;
                }

                const cleanedName = newName.trim();

                if (cleanedName.length < 2) {
                    showWarningModal("Area name must be at least 2 characters.");
                    return;
                }

                if (!renameAreaForm || !renameAreaId || !renameWardId || !renameAreaName) {
                    showWarningModal("Rename form is missing.");
                    return;
                }

                renameAreaId.value = String(areaId);
                renameWardId.value = String(selectedWard.ward_id);
                renameAreaName.value = cleanedName;
                renameAreaForm.submit();
            });
        });
    }

    function openAreaModal() {
        if (!selectedWard || !areaModal) {
            showWarningModal("Please select a ward first.");
            return;
        }

        if (modalWardLabel) {
            modalWardLabel.textContent = selectedWard.ward_name || `Ward ${selectedWard.ward_no || ""}`;
        }

        renderEditAreaList(selectedWard.areas || []);

        if (addCityId) addCityId.value = selectedWard.city_id || "";
        if (addCityCorId) addCityCorId.value = selectedWard.city_cor_id || "";
        if (addThanaId) addThanaId.value = selectedWard.thana_id || "";
        if (addWardId) addWardId.value = selectedWard.ward_id || "";

        if (newAreaName) {
            newAreaName.value = "";
        }

        areaModal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function closeAreaModal() {
        if (!areaModal) return;

        areaModal.classList.remove("show");
        document.body.style.overflow = "";
    }

    function openInstructionModal(type) {
        if (!selectedWard) {
            showWarningModal("Please select a ward first.");
            return;
        }

        const isWard = type === "ward_officer";
        const receiverUserId = isWard ? selectedWard.officer_user_id : selectedWard.inspector_user_id;
        const receiverName = isWard ? selectedWard.officer_name : selectedWard.inspector_name;
        const options = isWard ? wardInstructionOptions : inspectionOptionsData;

        if (!instructionModal) return;

        if (instructionModalTitle) {
            instructionModalTitle.textContent = isWard ? "Send Ward Instruction" : "Send Inspection Request";
        }

        if (instructionModalSubtitle) {
            instructionModalSubtitle.textContent =
                "Ward: " + (selectedWard.ward_name || "Selected Ward") +
                " | Receiver: " + (receiverName || "Not Assigned");
        }

        if (instructionReceiverUserId) instructionReceiverUserId.value = receiverUserId || "";
        if (instructionReceiverRole) instructionReceiverRole.value = type;
        if (instructionWardId) instructionWardId.value = selectedWard.ward_id || "";
        if (instructionTitleInput) instructionTitleInput.value = "";
        if (instructionMessage) instructionMessage.value = "";

        renderInstructionOptions(options);

        instructionModal.classList.add("show");
        document.body.style.overflow = "hidden";
    }

    function renderInstructionOptions(options) {
        if (!instructionOptions) return;

        instructionOptions.innerHTML = "";

        options.forEach(function (item) {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "wa-option-btn";
            button.textContent = item.title;

            button.addEventListener("click", function () {
                document.querySelectorAll(".wa-option-btn").forEach(function (btn) {
                    btn.classList.remove("active");
                });

                button.classList.add("active");

                if (instructionTitleInput) {
                    instructionTitleInput.value = item.title;
                }

                if (instructionMessage) {
                    instructionMessage.value = item.message;
                }
            });

            instructionOptions.appendChild(button);
        });
    }

    function closeInstructionModal() {
        if (!instructionModal) return;

        instructionModal.classList.remove("show");
        document.body.style.overflow = "";
    }

    function escapeHtml(value) {
        return String(value || "")
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll('"', "&quot;")
            .replaceAll("'", "&#039;");
    }

    if (citySelect) {
        citySelect.addEventListener("change", function () {
            const cityId = citySelect.value;

            resetSelect(cityCorporationSelect, "Select City Corporation");
            resetSelect(thanaSelect, "Select Thana");
            resetSelect(wardSelect, "Select Ward");
            hideWardCard();

            if (!cityId) return;

            const filteredCorporations = cityCorporations.filter(function (corp) {
                return String(corp.city_id) === String(cityId);
            });

            filteredCorporations.forEach(function (corp) {
                addOption(cityCorporationSelect, corp.city_cor_id, corp.city_cor_name);
            });

            enableSelect(cityCorporationSelect);
        });
    }

    if (cityCorporationSelect) {
        cityCorporationSelect.addEventListener("change", function () {
            const cityCorId = cityCorporationSelect.value;

            resetSelect(thanaSelect, "Select Thana");
            resetSelect(wardSelect, "Select Ward");
            hideWardCard();

            if (!cityCorId) return;

            const filteredThanas = thanas.filter(function (thana) {
                return String(thana.city_cor_id) === String(cityCorId);
            });

            filteredThanas.forEach(function (thana) {
                addOption(thanaSelect, thana.thana_id, thana.thana_name);
            });

            enableSelect(thanaSelect);
        });
    }

    if (thanaSelect) {
        thanaSelect.addEventListener("change", function () {
            const thanaId = thanaSelect.value;

            resetSelect(wardSelect, "Select Ward");
            hideWardCard();

            if (!thanaId) return;

            const filteredWards = wards.filter(function (ward) {
                return String(ward.thana_id) === String(thanaId);
            });

            filteredWards.forEach(function (ward) {
                const label = ward.ward_name || `Ward ${ward.ward_no || ""}`;
                addOption(wardSelect, ward.ward_id, label);
            });

            enableSelect(wardSelect);
        });
    }

    if (wardSelect) {
        wardSelect.addEventListener("change", function () {
            const wardId = wardSelect.value;

            if (!wardId) {
                hideWardCard();
                return;
            }

            const ward = wardDetails[wardId];

            if (!ward) {
                hideWardCard();
                showWarningModal("Ward details not found.");
                return;
            }

            showWardCard(ward);
        });
    }

    if (areaEditBtn) {
        areaEditBtn.addEventListener("click", openAreaModal);
    }

    if (areaModalClose) {
        areaModalClose.addEventListener("click", closeAreaModal);
    }

    if (areaModal) {
        areaModal.addEventListener("click", function (event) {
            if (event.target === areaModal) {
                closeAreaModal();
            }
        });
    }

    if (instructionBtn) {
        instructionBtn.addEventListener("click", function () {
            openInstructionModal("ward_officer");
        });
    }

    if (inspectionBtn) {
        inspectionBtn.addEventListener("click", function () {
            openInstructionModal("inspector");
        });
    }

    if (instructionModalClose) {
        instructionModalClose.addEventListener("click", closeInstructionModal);
    }

    if (instructionModal) {
        instructionModal.addEventListener("click", function (event) {
            if (event.target === instructionModal) {
                closeInstructionModal();
            }
        });
    }

    if (instructionForm) {
        instructionForm.addEventListener("submit", function (event) {
            const title = instructionTitleInput ? instructionTitleInput.value.trim() : "";
            const message = instructionMessage ? instructionMessage.value.trim() : "";

            if (!title || !message) {
                event.preventDefault();
                showWarningModal("Please select an instruction before sending.");
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape") {
            closeAreaModal();
            closeInstructionModal();
        }
    });

    if (addAreaForm) {
        addAreaForm.addEventListener("submit", function (event) {
            if (!selectedWard) {
                event.preventDefault();
                showWarningModal("Please select a ward first.");
                return;
            }

            const value = newAreaName ? newAreaName.value.trim() : "";

            if (value.length < 2) {
                event.preventDefault();
                showWarningModal("Area name must be at least 2 characters.");
            }
        });
    }

    hideWardCard();
});