<?php
// C:\xampp\htdocs\DrainGuard\pages\citizen\submit-complaint.php

require_once "../../config.php";
require_login(["citizen"]);

$activePage = "submit-complaint";
$pageTitle = "Submit Complaint";
$pageParent = "Citizen";
$pageChild = "Submit Complaint";

function sc_safe($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| Location mapping data load:
| cities → city_corporations → thanas → wards → areas → locations
|--------------------------------------------------------------------------
*/

$locationMap = [];

$locationSql = "
    SELECT
        l.loc_id,

        c.city_id,
        c.city_name,

        cc.city_cor_id,
        cc.city_cor_name,

        t.thana_id,
        t.thana_name,

        w.ward_id,
        w.ward_no,
        w.ward_name,

        a.area_id,
        a.area_name

    FROM locations l
    INNER JOIN cities c
        ON l.city_id = c.city_id
    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id
    INNER JOIN thanas t
        ON l.thana_id = t.thana_id
    INNER JOIN wards w
        ON l.ward_id = w.ward_id
    INNER JOIN areas a
        ON l.area_id = a.area_id
    ORDER BY c.city_name, cc.city_cor_name, t.thana_name, w.ward_no, a.area_name
";

$locationResult = mysqli_query($conn, $locationSql);

if ($locationResult) {
    while ($row = mysqli_fetch_assoc($locationResult)) {
        $locationMap[] = $row;
    }
}

$issueTypes = [
    "Blocked Drain",
    "Waterlogging",
    "Broken Drain Cover",
    "Bad Odor",
    "Overflowing Drain",
    "Other"
];

$successMessage = $_SESSION["complaint_success"] ?? "";
$errorMessage = $_SESSION["complaint_error"] ?? "";

unset($_SESSION["complaint_success"], $_SESSION["complaint_error"]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title><?php echo sc_safe($pageTitle); ?> | DrainGuard</title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/submit-complaint.css">
    <link rel="stylesheet" href="../../css/citizen/citizenTextFix.css">
</head>

<body class="citizen">

<div class="citizen-layout">

    <?php require_once "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php require_once "../../includes/citizen/topbar.php"; ?>

        <section class="sc-page">

            <div class="sc-header">
                <h1>Submit New Complaint</h1>
                <p>Report a drainage issue in your area</p>
            </div>

            <form
                class="sc-card"
                id="submitComplaintForm"
                action="../../auth/submit_complaint_process.php"
                method="POST"
                enctype="multipart/form-data"
            >

                <?php if ($successMessage !== ""): ?>
                    <div class="sc-alert sc-success">
                        <?php echo sc_safe($successMessage); ?>
                    </div>
                <?php endif; ?>

                <?php if ($errorMessage !== ""): ?>
                    <div class="sc-alert sc-error">
                        <?php echo sc_safe($errorMessage); ?>
                    </div>
                <?php endif; ?>

                <div class="sc-section-title">
                    <i class="bi bi-clipboard-plus"></i>
                    <span>Complaint Information</span>
                </div>

                <div class="sc-group sc-full">
                    <label for="issueType">Issue Type</label>

                    <select name="issue_type" id="issueType" required>
                        <option value="">Select issue type</option>

                        <?php foreach ($issueTypes as $issueType): ?>
                            <option value="<?php echo sc_safe($issueType); ?>">
                                <?php echo sc_safe($issueType); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sc-section-title sc-location-title">
                    <i class="bi bi-geo-alt"></i>
                    <span>Location Mapping</span>
                </div>

                <div class="sc-grid-2">
                    <div class="sc-group">
                        <label for="citySelect">City</label>

                        <select name="city_id" id="citySelect" required>
                            <option value="">Select city</option>
                        </select>
                    </div>

                    <div class="sc-group">
                        <label for="cityCorporationSelect">City Corporation</label>

                        <select name="city_cor_id" id="cityCorporationSelect" required disabled>
                            <option value="">Select city corporation</option>
                        </select>
                    </div>
                </div>

                <div class="sc-grid-2">
                    <div class="sc-group">
                        <label for="thanaSelect">Thana</label>

                        <select name="thana_id" id="thanaSelect" required disabled>
                            <option value="">Select thana</option>
                        </select>
                    </div>

                    <div class="sc-group">
                        <label for="wardSelect">Ward</label>

                        <select name="ward_id" id="wardSelect" required disabled>
                            <option value="">Select ward</option>
                        </select>
                    </div>
                </div>

                <div class="sc-grid-2">
                    <div class="sc-group">
                        <label for="areaSelect">Area</label>

                        <select name="area_id" id="areaSelect" required disabled>
                            <option value="">Select area</option>
                        </select>
                    </div>

                    <div class="sc-group">
                        <label for="addressDescription">Address Description</label>

                        <textarea
                            name="address_description"
                            id="addressDescription"
                            class="sc-small-textarea"
                            placeholder="House, road, block, nearby landmark..."
                            required
                        ></textarea>

                        <small class="sc-helper-text" id="addressCounter">0 characters</small>
                    </div>
                </div>

                <input type="hidden" name="loc_id" id="locationId">

                <div class="sc-group sc-full">
                    <label for="problemDescription">Problem Description</label>

                    <textarea
                        name="problem_description"
                        id="problemDescription"
                        class="sc-big-textarea"
                        placeholder="Describe the drainage issue in detail..."
                        required
                    ></textarea>

                    <small class="sc-helper-text" id="problemCounter">0 characters</small>
                </div>

                <div class="sc-bottom-grid">
                    <div class="sc-group">
                        <label for="complaintMedia">Upload Evidence</label>

                        <div class="sc-upload-wrapper" id="uploadWrapper">
                            <input
                                type="file"
                                name="complaint_media[]"
                                id="complaintMedia"
                                accept="image/jpeg,image/png,image/webp,video/mp4,video/webm"
                                multiple
                                hidden
                            >

                            <button type="button" class="sc-upload-box" id="uploadTrigger">
                                <i class="bi bi-cloud-arrow-up"></i>

                                <span id="uploadText">
                                    Click to upload images/video
                                </span>

                                <small id="uploadHint">
                                    Max 5 images, 5MB each. Optional 1 video, max 150MB.
                                </small>
                            </button>
                        </div>

                        <div class="sc-file-list" id="selectedFileList"></div>
                        <small class="sc-file-error" id="fileErrorText"></small>
                    </div>

                    <div class="sc-group">
                        <label>Urgency Level</label>

                        <div class="sc-urgency">
                            <label>
                                <input type="radio" name="urgency_level" value="Low" required>
                                <span class="urgency-low">
                                    <i class="bi bi-circle"></i>
                                    Low
                                </span>
                            </label>

                            <label>
                                <input type="radio" name="urgency_level" value="Medium">
                                <span class="urgency-medium">
                                    <i class="bi bi-circle"></i>
                                    Medium
                                </span>
                            </label>

                            <label>
                                <input type="radio" name="urgency_level" value="High">
                                <span class="urgency-high">
                                    <i class="bi bi-circle"></i>
                                    High
                                </span>
                            </label>

                            <label>
                                <input type="radio" name="urgency_level" value="Critical">
                                <span class="urgency-critical">
                                    <i class="bi bi-circle"></i>
                                    Critical
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="sc-submit-btn" id="submitComplaintBtn">
                    <i class="bi bi-send-fill"></i>
                    <span>Submit Complaint</span>
                </button>

            </form>

        </section>

    </main>

</div>

<script>
    const locationRows = <?php echo json_encode($locationMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/submit-complaint.js"></script>

</body>
</html>