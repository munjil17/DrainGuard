<?php
$activePage = 'submit-complaint';
$pageTitle = 'Submit Complaint';
$pageParent = 'Citizen';
$pageChild = 'Submit Complaint';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$userName = $_SESSION['user_name'] ?? 'Citizen User';

/*
    Location mapping data load:
    cities → city_corporations → thanas → wards → areas → locations
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Submit Complaint | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Global CSS -->
    <link rel="stylesheet" href="../../css/global/global.css">

    <!-- Existing Citizen Layout CSS -->
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">

    <!-- Page CSS -->
    <link rel="stylesheet" href="../../css/citizen/submit-complaint.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="sc-page">

            <div class="sc-header">
                <h1>Submit New Complaint</h1>
                <p>Report a drainage issue in your area</p>
            </div>

            <form class="sc-card" action="../../auth/submit_complaint_process.php" method="POST" enctype="multipart/form-data">

                <?php if (isset($_SESSION['complaint_success'])): ?>
                    <div class="sc-alert sc-success">
                        <?php
                            echo $_SESSION['complaint_success'];
                            unset($_SESSION['complaint_success']);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['complaint_error'])): ?>
                    <div class="sc-alert sc-error">
                        <?php
                            echo $_SESSION['complaint_error'];
                            unset($_SESSION['complaint_error']);
                        ?>
                    </div>
                <?php endif; ?>

                <div class="sc-section-title">
                    <i class="bi bi-clipboard-plus"></i>
                    <span>Complaint Information</span>
                </div>

                <div class="sc-group sc-full">
                    <label>Issue Type</label>
                    <select name="issue_type" required>
                        <option value="">Select issue type</option>
                        <option value="Blocked Drain">Blocked Drain</option>
                        <option value="Waterlogging">Waterlogging</option>
                        <option value="Broken Drain Cover">Broken Drain Cover</option>
                        <option value="Bad Odor">Bad Odor</option>
                        <option value="Overflowing Drain">Overflowing Drain</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="sc-section-title sc-location-title">
                    <i class="bi bi-geo-alt"></i>
                    <span>Location Mapping</span>
                </div>

                <div class="sc-grid-2">
                    <div class="sc-group">
                        <label>City</label>
                        <select name="city_id" id="citySelect" required>
                            <option value="">Select city</option>
                        </select>
                    </div>

                    <div class="sc-group">
                        <label>City Corporation</label>
                        <select name="city_cor_id" id="cityCorporationSelect" required disabled>
                            <option value="">Select city corporation</option>
                        </select>
                    </div>
                </div>

                <div class="sc-grid-2">
                    <div class="sc-group">
                        <label>Thana</label>
                        <select name="thana_id" id="thanaSelect" required disabled>
                            <option value="">Select thana</option>
                        </select>
                    </div>

                    <div class="sc-group">
                        <label>Ward</label>
                        <select name="ward_id" id="wardSelect" required disabled>
                            <option value="">Select ward</option>
                        </select>
                    </div>
                </div>

                <div class="sc-grid-2">
                    <div class="sc-group">
                        <label>Area</label>
                        <select name="area_id" id="areaSelect" required disabled>
                            <option value="">Select area</option>
                        </select>
                    </div>

                    <div class="sc-group">
                        <label>Address Description</label>
                        <textarea
                            name="address_description"
                            class="sc-small-textarea"
                            placeholder="House, road, block, nearby landmark..."
                            required
                        ></textarea>
                    </div>
                </div>

                <input type="hidden" name="loc_id" id="locationId">

                <div class="sc-group sc-full">
                    <label>Problem Description</label>
                    <textarea
                        name="problem_description"
                        class="sc-big-textarea"
                        placeholder="Describe the drainage issue in detail..."
                        required
                    ></textarea>
                </div>

                <div class="sc-bottom-grid">
                    <div class="sc-group">
                        <label>Upload Photo</label>

                        <label class="sc-upload-box">
                            <input
                                type="file"
                                name="complaint_image"
                                id="complaintImage"
                                accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                            >
                            <i class="bi bi-cloud-arrow-up"></i>
                            <span id="uploadText">Click to upload image</span>
                            <small>PNG, JPG, JPEG up to 5MB</small>
                        </label>
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

                <button type="submit" class="sc-submit-btn">
                    <i class="bi bi-send-fill"></i>
                    <span>Submit Complaint</span>
                </button>

            </form>

        </section>

    </main>

</div>

<script>
    const locationRows = <?php echo json_encode($locationMap); ?>;
</script>
<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/submit-complaint.js"></script>

</body>
</html>