document.addEventListener("DOMContentLoaded", function () {
    const citySelect = document.getElementById("citySelect");
    const corporationSelect = document.getElementById("cityCorporationSelect");
    const thanaSelect = document.getElementById("thanaSelect");
    const wardSelect = document.getElementById("wardSelect");
    const areaSelect = document.getElementById("areaSelect");
    const locationIdInput = document.getElementById("locationId");

    const form = document.getElementById("submitComplaintForm");

    const mediaInput = document.getElementById("complaintMedia");
    const uploadTrigger = document.getElementById("uploadTrigger");
    const uploadWrapper = document.getElementById("uploadWrapper");
    const uploadText = document.getElementById("uploadText");
    const uploadHint = document.getElementById("uploadHint");
    const selectedFileList = document.getElementById("selectedFileList");
    const fileErrorText = document.getElementById("fileErrorText");

    const addressDescription = document.getElementById("addressDescription");
    const problemDescription = document.getElementById("problemDescription");
    const addressCounter = document.getElementById("addressCounter");
    const problemCounter = document.getElementById("problemCounter");

    const rows = (typeof locationRows !== "undefined" && Array.isArray(locationRows))
        ? locationRows
        : [];

    const MAX_IMAGE_COUNT = 5;
    const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
    const MAX_VIDEO_COUNT = 1;
    const MAX_VIDEO_SIZE = 150 * 1024 * 1024;

    const allowedImageTypes = ["image/jpeg", "image/png", "image/webp"];
    const allowedVideoTypes = ["video/mp4", "video/webm"];

    let selectedFiles = [];

    function resetSelect(select, placeholder) {
        if (!select) {
            return;
        }

        select.innerHTML = `<option value="">${placeholder}</option>`;
        select.disabled = true;
    }

    function enableSelect(select) {
        if (!select) {
            return;
        }

        select.disabled = false;
    }

    function uniqueById(items, idKey) {
        const map = new Map();

        items.forEach(function (item) {
            const idValue = String(item[idKey]);

            if (!map.has(idValue)) {
                map.set(idValue, item);
            }
        });

        return Array.from(map.values());
    }

    function addOption(select, value, text) {
        const option = document.createElement("option");

        option.value = value;
        option.textContent = text;

        select.appendChild(option);
    }

    function resetAfterCity() {
        resetSelect(corporationSelect, "Select city corporation");
        resetSelect(thanaSelect, "Select thana");
        resetSelect(wardSelect, "Select ward");
        resetSelect(areaSelect, "Select area");

        if (locationIdInput) {
            locationIdInput.value = "";
        }
    }

    function resetAfterCorporation() {
        resetSelect(thanaSelect, "Select thana");
        resetSelect(wardSelect, "Select ward");
        resetSelect(areaSelect, "Select area");

        if (locationIdInput) {
            locationIdInput.value = "";
        }
    }

    function resetAfterThana() {
        resetSelect(wardSelect, "Select ward");
        resetSelect(areaSelect, "Select area");

        if (locationIdInput) {
            locationIdInput.value = "";
        }
    }

    function resetAfterWard() {
        resetSelect(areaSelect, "Select area");

        if (locationIdInput) {
            locationIdInput.value = "";
        }
    }

    function showFileError(message) {
        if (!fileErrorText) {
            return;
        }

        fileErrorText.textContent = message;
        fileErrorText.classList.add("show");
    }

    function clearFileError() {
        if (!fileErrorText) {
            return;
        }

        fileErrorText.textContent = "";
        fileErrorText.classList.remove("show");
    }

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) {
            return (bytes / (1024 * 1024)).toFixed(1) + " MB";
        }

        return Math.max(1, Math.round(bytes / 1024)) + " KB";
    }

    function getFileKey(file) {
        return [
            file.name,
            file.size,
            file.type,
            file.lastModified
        ].join("|");
    }

    function getMediaStats(files) {
        let imageCount = 0;
        let videoCount = 0;

        files.forEach(function (file) {
            if (allowedImageTypes.includes(file.type)) {
                imageCount += 1;
            }

            if (allowedVideoTypes.includes(file.type)) {
                videoCount += 1;
            }
        });

        return {
            imageCount: imageCount,
            videoCount: videoCount
        };
    }

    function syncInputFiles() {
        if (!mediaInput) {
            return;
        }

        const dataTransfer = new DataTransfer();

        selectedFiles.forEach(function (file) {
            dataTransfer.items.add(file);
        });

        mediaInput.files = dataTransfer.files;
    }

    function updateUploadState() {
        const stats = getMediaStats(selectedFiles);
        const isMaxSelected = stats.imageCount >= MAX_IMAGE_COUNT && stats.videoCount >= MAX_VIDEO_COUNT;

        if (uploadWrapper) {
            uploadWrapper.classList.toggle("is-max-selected", isMaxSelected);
        }

        if (uploadTrigger) {
            uploadTrigger.disabled = isMaxSelected;
        }

        if (uploadText) {
            if (selectedFiles.length === 0) {
                uploadText.textContent = "Click to upload images/video";
            } else {
                uploadText.textContent = selectedFiles.length + " file(s) selected";
            }
        }

        if (uploadHint) {
            if (isMaxSelected) {
                uploadHint.textContent = "Maximum selected: 5 images and 1 video.";
            } else if (stats.imageCount >= MAX_IMAGE_COUNT) {
                uploadHint.textContent = "5 images selected. You can add only 1 video now.";
            } else if (stats.videoCount >= MAX_VIDEO_COUNT) {
                uploadHint.textContent = "1 video selected. You can add images only.";
            } else {
                uploadHint.textContent = "Max 5 images, 5MB each. Optional 1 video, max 150MB.";
            }
        }
    }

    function renderFileList() {
        if (!selectedFileList) {
            return;
        }

        selectedFileList.innerHTML = "";

        selectedFiles.forEach(function (file, index) {
            const isVideo = allowedVideoTypes.includes(file.type);
            const item = document.createElement("div");

            item.className = "sc-file-item";

            item.innerHTML = `
                <i class="bi ${isVideo ? "bi-camera-video" : "bi-image"}"></i>
                <span title="${file.name}">${file.name}</span>
                <small>${formatSize(file.size)}</small>
                <button type="button" class="sc-file-remove" data-index="${index}" aria-label="Remove file">
                    <i class="bi bi-x-lg"></i>
                </button>
            `;

            selectedFileList.appendChild(item);
        });

        selectedFileList.querySelectorAll(".sc-file-remove").forEach(function (button) {
            button.addEventListener("click", function () {
                const index = Number(this.dataset.index);

                if (!Number.isNaN(index)) {
                    selectedFiles.splice(index, 1);
                    syncInputFiles();
                    renderFileList();
                    updateUploadState();
                    clearFileError();
                }
            });
        });
    }

    function validateSingleFile(file, currentStats) {
        const isImage = allowedImageTypes.includes(file.type);
        const isVideo = allowedVideoTypes.includes(file.type);

        if (!isImage && !isVideo) {
            return "Allowed files: JPG, JPEG, PNG, WEBP, MP4, WEBM.";
        }

        if (isImage) {
            if (currentStats.imageCount >= MAX_IMAGE_COUNT) {
                return "Maximum 5 images are allowed. You can add only video now if no video is selected.";
            }

            if (file.size > MAX_IMAGE_SIZE) {
                return "Each image must be 5MB or less.";
            }
        }

        if (isVideo) {
            if (currentStats.videoCount >= MAX_VIDEO_COUNT) {
                return "Maximum 1 video is allowed. You can add only images now.";
            }

            if (file.size > MAX_VIDEO_SIZE) {
                return "Video must be 150MB or less.";
            }
        }

        return "";
    }

    function addFiles(newFiles) {
        clearFileError();

        const incomingFiles = Array.from(newFiles || []);

        if (incomingFiles.length === 0) {
            return;
        }

        const existingKeys = new Set(selectedFiles.map(getFileKey));
        const acceptedFiles = [];

        for (const file of incomingFiles) {
            const fileKey = getFileKey(file);

            if (existingKeys.has(fileKey)) {
                showFileError("Duplicate file skipped: " + file.name);
                continue;
            }

            const currentStats = getMediaStats(selectedFiles.concat(acceptedFiles));
            const error = validateSingleFile(file, currentStats);

            if (error !== "") {
                showFileError(error);
                continue;
            }

            acceptedFiles.push(file);
            existingKeys.add(fileKey);
        }
selectedFiles = selectedFiles.concat(acceptedFiles);

syncInputFiles();
renderFileList();
updateUploadState();


    }

    function validateFinalMedia() {
        const stats = getMediaStats(selectedFiles);

        if (stats.imageCount > MAX_IMAGE_COUNT) {
            showFileError("You can upload maximum 5 images.");
            return false;
        }

        if (stats.videoCount > MAX_VIDEO_COUNT) {
            showFileError("You can upload maximum 1 video.");
            return false;
        }

        for (const file of selectedFiles) {
            const error = validateSingleFile(file, {
                imageCount: 0,
                videoCount: 0
            });

            if (error !== "" && !error.includes("Maximum")) {
                showFileError(error);
                return false;
            }
        }

        return true;
    }

    function updateCharacterCounter(input, counter) {
        if (!input || !counter) {
            return;
        }

        counter.textContent = input.value.trim().length + " characters";
    }

    function validateDescriptions() {
        const addressLength = addressDescription ? addressDescription.value.trim().length : 0;
        const problemLength = problemDescription ? problemDescription.value.trim().length : 0;

        if (addressLength < 8) {
            alert("Address description must be at least 8 characters. Please write the exact drain location.");
            addressDescription.focus();
            return false;
        }

        if (problemLength < 10) {
            alert("Problem description must be at least 10 characters. Please describe the issue clearly.");
            problemDescription.focus();
            return false;
        }

        return true;
    }

    if (citySelect && corporationSelect && thanaSelect && wardSelect && areaSelect && locationIdInput) {
        const cities = uniqueById(rows, "city_id");

        cities.forEach(function (city) {
            addOption(citySelect, city.city_id, city.city_name);
        });

        citySelect.addEventListener("change", function () {
            const cityId = this.value;

            resetAfterCity();

            if (!cityId) {
                return;
            }

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId);
            });

            const corporations = uniqueById(filteredRows, "city_cor_id");

            corporations.forEach(function (corp) {
                addOption(corporationSelect, corp.city_cor_id, corp.city_cor_name);
            });

            enableSelect(corporationSelect);
        });

        corporationSelect.addEventListener("change", function () {
            const cityId = citySelect.value;
            const cityCorId = this.value;

            resetAfterCorporation();

            if (!cityId || !cityCorId) {
                return;
            }

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId)
                    && String(row.city_cor_id) === String(cityCorId);
            });

            const thanas = uniqueById(filteredRows, "thana_id");

            thanas.forEach(function (thana) {
                addOption(thanaSelect, thana.thana_id, thana.thana_name);
            });

            enableSelect(thanaSelect);
        });

        thanaSelect.addEventListener("change", function () {
            const cityId = citySelect.value;
            const cityCorId = corporationSelect.value;
            const thanaId = this.value;

            resetAfterThana();

            if (!cityId || !cityCorId || !thanaId) {
                return;
            }

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId)
                    && String(row.city_cor_id) === String(cityCorId)
                    && String(row.thana_id) === String(thanaId);
            });

            const wards = uniqueById(filteredRows, "ward_id");

            wards.forEach(function (ward) {
                addOption(
                    wardSelect,
                    ward.ward_id,
                    ward.ward_name || ("Ward " + ward.ward_no)
                );
            });

            enableSelect(wardSelect);
        });

        wardSelect.addEventListener("change", function () {
            const cityId = citySelect.value;
            const cityCorId = corporationSelect.value;
            const thanaId = thanaSelect.value;
            const wardId = this.value;

            resetAfterWard();

            if (!cityId || !cityCorId || !thanaId || !wardId) {
                return;
            }

            const filteredRows = rows.filter(function (row) {
                return String(row.city_id) === String(cityId)
                    && String(row.city_cor_id) === String(cityCorId)
                    && String(row.thana_id) === String(thanaId)
                    && String(row.ward_id) === String(wardId);
            });

            const areas = uniqueById(filteredRows, "area_id");

            areas.forEach(function (area) {
                const option = document.createElement("option");

                option.value = area.area_id;
                option.textContent = area.area_name;
                option.dataset.locId = area.loc_id;

                areaSelect.appendChild(option);
            });

            enableSelect(areaSelect);
        });

        areaSelect.addEventListener("change", function () {
            const selectedOption = this.options[this.selectedIndex];

            if (selectedOption && selectedOption.dataset.locId) {
                locationIdInput.value = selectedOption.dataset.locId;
            } else {
                locationIdInput.value = "";
            }
        });
    }

    if (uploadTrigger && mediaInput) {
        uploadTrigger.addEventListener("click", function () {
            const stats = getMediaStats(selectedFiles);
            const isMaxSelected = stats.imageCount >= MAX_IMAGE_COUNT && stats.videoCount >= MAX_VIDEO_COUNT;

            if (!isMaxSelected) {
                mediaInput.click();
            }
        });
    }

    if (mediaInput) {
        mediaInput.addEventListener("change", function () {
            addFiles(mediaInput.files);
        });
    }

    if (addressDescription) {
        updateCharacterCounter(addressDescription, addressCounter);

        addressDescription.addEventListener("input", function () {
            updateCharacterCounter(addressDescription, addressCounter);
        });
    }

    if (problemDescription) {
        updateCharacterCounter(problemDescription, problemCounter);

        problemDescription.addEventListener("input", function () {
            updateCharacterCounter(problemDescription, problemCounter);
        });
    }

    if (form) {
        form.addEventListener("submit", function (event) {
            if (locationIdInput && locationIdInput.value.trim() === "") {
                event.preventDefault();
                alert("Please select a valid area before submitting.");
                return;
            }

            if (!validateDescriptions()) {
                event.preventDefault();
                return;
            }

            if (!validateFinalMedia()) {
                event.preventDefault();
            }
        });
    }

    updateUploadState();
});