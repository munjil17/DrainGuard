<?php
$activePage = "verification-queue";
$pageTitle = "Verification Queue";

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "ward_officer") {
    header("Location: ../../index.php");
    exit();
}

$wardOfficerUserId = (int)($_SESSION["user_id"] ?? 0);
$successMessage = "";
$errorMessage = "";

function safeText($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function urgencyClass($urgency)
{
    $urgency = strtolower((string)$urgency);

    if ($urgency === "low") return "priority-low";
    if ($urgency === "medium") return "priority-medium";
    if ($urgency === "high") return "priority-high";
    if ($urgency === "critical") return "priority-critical";

    return "priority-low";
}

function wardDisplayName($wardNo, $wardName)
{
    $wardName = trim((string)$wardName);

    if ($wardName !== "") {
        return $wardName;
    }

    return "Ward " . $wardNo;
}

function makeMediaPublicPath($path)
{
    $path = trim((string)$path);

    if ($path === "") {
        return "";
    }

    $path = str_replace("\\", "/", $path);
    $path = preg_replace("#^\.\./\.\./#", "", $path);
    $path = preg_replace("#^\./#", "", $path);
    $path = ltrim($path, "/");

    return "../../" . $path;
}

/*
|--------------------------------------------------------------------------
| GET LOGGED-IN WARD OFFICER'S ASSIGNED WARD
|--------------------------------------------------------------------------
*/

$assignedWardId = 0;
$assignedWardDisplay = "N/A";

$officerSql = "
    SELECT
        wo.assigned_ward_id,
        w.ward_no,
        w.ward_name,
        cc.city_cor_name
    FROM ward_officers wo
    INNER JOIN wards w
        ON wo.assigned_ward_id = w.ward_id
    INNER JOIN city_corporations cc
        ON wo.city_cor_id = cc.city_cor_id
    WHERE wo.user_id = ?
    LIMIT 1
";

$officerStmt = mysqli_prepare($conn, $officerSql);

if ($officerStmt) {
    mysqli_stmt_bind_param($officerStmt, "i", $wardOfficerUserId);
    mysqli_stmt_execute($officerStmt);

    $officerResult = mysqli_stmt_get_result($officerStmt);
    $officerData = $officerResult ? mysqli_fetch_assoc($officerResult) : null;

    if ($officerData) {
        $assignedWardId = (int)$officerData["assigned_ward_id"];
        $assignedWardDisplay = wardDisplayName($officerData["ward_no"], $officerData["ward_name"]);
    }

    mysqli_stmt_close($officerStmt);
}

if ($assignedWardId <= 0) {
    $errorMessage = "No valid assigned ward found for this Ward Officer.";
}

/*
|--------------------------------------------------------------------------
| ACTION: VERIFY / REJECT / DUPLICATE
|--------------------------------------------------------------------------
| No assignment_status enum dependency.
| Only complaints.complaint_status is updated.
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && $assignedWardId > 0) {
    $complaintId = (int)($_POST["complaint_id"] ?? 0);
    $action = trim($_POST["action"] ?? "");

    $allowedActions = ["verify", "reject", "duplicate"];

    if ($complaintId <= 0 || !in_array($action, $allowedActions, true)) {
        $errorMessage = "Invalid request.";
    } else {
        $newStatus = "";

        if ($action === "verify") {
            $newStatus = "verified";
        }

        if ($action === "reject") {
            $newStatus = "rejected";
        }

        if ($action === "duplicate") {
            /*
             * Keeping DB-safe behavior because you did not change enum.
             * Duplicate is treated as rejected/removed from queue.
             */
            $newStatus = "rejected";
        }

        mysqli_begin_transaction($conn);

        try {
            $checkSql = "
                SELECT
                    c.complaint_id,
                    c.complaint_status,
                    ca.ward_id
                FROM complaints c
                INNER JOIN complaint_assignments ca
                    ON c.complaint_id = ca.complaint_id
                WHERE c.complaint_id = ?
                AND c.complaint_status = 'pending_verification'
                AND ca.ward_id = ?
                LIMIT 1
            ";

            $checkStmt = mysqli_prepare($conn, $checkSql);

            if (!$checkStmt) {
                throw new Exception("Complaint check failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($checkStmt, "ii", $complaintId, $assignedWardId);
            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result($checkStmt);
            $complaintRow = $checkResult ? mysqli_fetch_assoc($checkResult) : null;

            mysqli_stmt_close($checkStmt);

            if (!$complaintRow) {
                throw new Exception("This complaint is not available for your ward verification queue.");
            }

            $updateSql = "
                UPDATE complaints
                SET complaint_status = ?,
                    updated_at = NOW()
                WHERE complaint_id = ?
            ";

            $updateStmt = mysqli_prepare($conn, $updateSql);

            if (!$updateStmt) {
                throw new Exception("Complaint update failed: " . mysqli_error($conn));
            }

            mysqli_stmt_bind_param($updateStmt, "si", $newStatus, $complaintId);

            if (!mysqli_stmt_execute($updateStmt)) {
                throw new Exception("Complaint status update failed: " . mysqli_stmt_error($updateStmt));
            }

            mysqli_stmt_close($updateStmt);

            mysqli_commit($conn);

            if ($action === "verify") {
                $successMessage = "Complaint accepted and verified successfully.";
            } elseif ($action === "duplicate") {
                $successMessage = "Complaint marked as duplicate and removed from queue.";
            } else {
                $successMessage = "Complaint rejected successfully.";
            }

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errorMessage = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH PENDING VERIFICATION COMPLAINTS
|--------------------------------------------------------------------------
*/

$verificationComplaints = [];

if ($assignedWardId > 0) {
    $sql = "
        SELECT
            c.complaint_id,
            c.complaint_code,
            c.issue_type,
            c.address_description,
            c.problem_description,
            c.urgency_level,
            c.complaint_status,
            c.submitted_at,
            c.updated_at,

            u.user_name,
            u.user_mail,

            w.ward_id,
            w.ward_no,
            w.ward_name,
            a.area_name,
            t.thana_name,
            cc.city_cor_name

        FROM complaints c

        INNER JOIN users u
            ON c.user_id = u.user_id

        INNER JOIN complaint_assignments ca
            ON c.complaint_id = ca.complaint_id

        INNER JOIN wards w
            ON ca.ward_id = w.ward_id

        INNER JOIN locations l
            ON c.loc_id = l.loc_id

        INNER JOIN areas a
            ON l.area_id = a.area_id

        INNER JOIN thanas t
            ON l.thana_id = t.thana_id

        INNER JOIN city_corporations cc
            ON l.city_cor_id = cc.city_cor_id

        WHERE c.complaint_status = 'pending_verification'
        AND ca.ward_id = ?

        ORDER BY
            CASE
                WHEN c.urgency_level = 'Critical' THEN 1
                WHEN c.urgency_level = 'High' THEN 2
                WHEN c.urgency_level = 'Medium' THEN 3
                ELSE 4
            END,
            c.submitted_at DESC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $assignedWardId);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $row["media"] = [];
                $verificationComplaints[(int)$row["complaint_id"]] = $row;
            }
        }

        mysqli_stmt_close($stmt);
    } else {
        $errorMessage = "Verification queue query failed: " . mysqli_error($conn);
    }
}

/*
|--------------------------------------------------------------------------
| FETCH COMPLAINT MEDIA
|--------------------------------------------------------------------------
*/

if (count($verificationComplaints) > 0) {
    $complaintIds = array_keys($verificationComplaints);
    $safeIds = array_map("intval", $complaintIds);
    $idList = implode(",", $safeIds);

    $mediaSql = "
        SELECT
            media_id,
            complaint_id,
            media_type,
            media_path,
            original_name,
            file_size,
            mime_type,
            uploaded_at
        FROM complaint_media
        WHERE complaint_id IN ($idList)
        ORDER BY complaint_id ASC, media_id ASC
    ";

    $mediaResult = mysqli_query($conn, $mediaSql);

    if ($mediaResult) {
        while ($media = mysqli_fetch_assoc($mediaResult)) {
            $complaintId = (int)$media["complaint_id"];

            if (isset($verificationComplaints[$complaintId])) {
                $verificationComplaints[$complaintId]["media"][] = [
                    "media_id" => (int)$media["media_id"],
                    "type" => (string)$media["media_type"],
                    "path" => makeMediaPublicPath($media["media_path"]),
                    "original_name" => (string)($media["original_name"] ?? ""),
                    "file_size" => (int)($media["file_size"] ?? 0),
                    "mime_type" => (string)($media["mime_type"] ?? "")
                ];
            }
        }
    }
}

$verificationComplaints = array_values($verificationComplaints);

$totalPending = count($verificationComplaints);
$criticalCount = 0;

foreach ($verificationComplaints as $item) {
    if (strtolower((string)$item["urgency_level"]) === "critical") {
        $criticalCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verification Queue | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/verification-queue.css">
</head>

<body>

<div class="ward-layout">

    <?php include "../../includes/ward/sidebar.php"; ?>

    <main class="ward-main">

        <?php include "../../includes/ward/topbar.php"; ?>

        <section class="vq-page">

            <div class="vq-header">
                <div>
                    <h1>Verification Queue</h1>
                    <p>Verify complaints routed from Central Control for your assigned ward.</p>
                </div>

                <div class="vq-count-card">
                    <span><?php echo $totalPending; ?></span>
                    <small>Pending Verification</small>
                </div>
            </div>

            <?php if ($successMessage !== ""): ?>
                <div class="vq-alert vq-success">
                    <i class="bi bi-check-circle"></i>
                    <?php echo safeText($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ""): ?>
                <div class="vq-alert vq-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo safeText($errorMessage); ?>
                </div>
            <?php endif; ?>

            <div class="vq-summary-grid">
                <div class="vq-summary-card">
                    <div class="vq-summary-icon pending">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div>
                        <h2><?php echo $totalPending; ?></h2>
                        <p>Total Pending</p>
                    </div>
                </div>

                <div class="vq-summary-card">
                    <div class="vq-summary-icon critical">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h2><?php echo $criticalCount; ?></h2>
                        <p>Critical Issues</p>
                    </div>
                </div>

                <div class="vq-summary-card">
                    <div class="vq-summary-icon ward">
                        <i class="bi bi-geo-alt"></i>
                    </div>
                    <div>
                        <h2><?php echo safeText($assignedWardDisplay); ?></h2>
                        <p>Assigned Ward</p>
                    </div>
                </div>
            </div>

            <div class="vq-toolbar">
                <div class="vq-search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="vqSearch" placeholder="Search by complaint ID, issue, area, citizen...">
                </div>

                <select id="vqPriorityFilter">
                    <option value="all">All Priority</option>
                    <option value="Critical">Critical</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                </select>
            </div>

            <div class="vq-list">

                <?php if (count($verificationComplaints) > 0): ?>

                    <?php foreach ($verificationComplaints as $complaint): ?>
                        <?php
                            $complaintId = (int)$complaint["complaint_id"];
                            $complaintCode = safeText($complaint["complaint_code"]);
                            $issueType = safeText($complaint["issue_type"]);
                            $rawProblem = (string)$complaint["problem_description"];
                            $problem = safeText($rawProblem);

                            $shortProblemRaw = mb_strlen($rawProblem) > 95
                                ? mb_substr($rawProblem, 0, 95) . "..."
                                : $rawProblem;

                            $shortProblem = safeText($shortProblemRaw);

                            $priority = safeText($complaint["urgency_level"]);
                            $wardText = wardDisplayName($complaint["ward_no"], $complaint["ward_name"]);
                            $areaText = safeText($complaint["area_name"]);
                            $thanaText = safeText($complaint["thana_name"]);
                            $citizenName = safeText($complaint["user_name"]);
                            $citizenEmail = safeText($complaint["user_mail"]);
                            $submittedAt = date("M d, Y h:i A", strtotime($complaint["submitted_at"]));

                            $mediaItems = $complaint["media"] ?? [];
                            $mediaCount = count($mediaItems);
                            $mediaJson = json_encode($mediaItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

                            $firstImagePath = "";
                            foreach ($mediaItems as $media) {
                                if (strtolower($media["type"]) === "image") {
                                    $firstImagePath = $media["path"];
                                    break;
                                }
                            }
                        ?>

                        <article
                            class="vq-card"
                            data-code="<?php echo strtolower($complaintCode); ?>"
                            data-issue="<?php echo strtolower($issueType); ?>"
                            data-area="<?php echo strtolower($areaText); ?>"
                            data-citizen="<?php echo strtolower($citizenName); ?>"
                            data-priority="<?php echo $priority; ?>"
                        >

                            <div class="vq-card-top">
                                <div>
                                    <span class="vq-code"><?php echo $complaintCode; ?></span>

                                    <span class="vq-priority <?php echo urgencyClass($priority); ?>">
                                        <?php echo $priority; ?>
                                    </span>

                                    <?php if ($mediaCount > 0): ?>
                                        <span class="vq-photo-badge">
                                            <i class="bi bi-paperclip"></i>
                                            <?php echo $mediaCount; ?> Evidence
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <span class="vq-status">Pending Verification</span>
                            </div>

                            <h2><?php echo $issueType; ?></h2>
                            <p class="vq-problem-preview"><?php echo $shortProblem; ?></p>

                            <div class="vq-meta">
                                <span><i class="bi bi-person"></i> <?php echo $citizenName; ?></span>
                                <span><i class="bi bi-geo-alt"></i> <?php echo safeText($wardText); ?>, <?php echo $areaText; ?></span>
                                <span><i class="bi bi-clock"></i> <?php echo safeText($submittedAt); ?></span>
                            </div>

                            <div class="vq-evidence-preview">
                                <h3>Evidence Preview</h3>

                                <div class="vq-evidence-box">
                                    <div class="vq-evidence-thumb">
                                        <?php if ($firstImagePath !== ""): ?>
                                            <img src="<?php echo safeText($firstImagePath); ?>" alt="Complaint Evidence">
                                        <?php else: ?>
                                            <i class="bi bi-image"></i>
                                        <?php endif; ?>
                                    </div>

                                    <div class="vq-evidence-info">
                                        <p>
                                            <strong>Location:</strong>
                                            <?php echo safeText($wardText); ?>, <?php echo $areaText; ?>, <?php echo $thanaText; ?>
                                        </p>

                                        <p>
                                            <strong>Description:</strong>
                                            <?php echo $shortProblem; ?>
                                        </p>

                                        <?php if ($mediaCount > 0): ?>
                                            <span class="vq-no-photo-text">
                                                <?php echo $mediaCount; ?> uploaded evidence file(s). Open details to view.
                                            </span>
                                        <?php else: ?>
                                            <span class="vq-no-photo-text">
                                                No evidence uploaded by citizen.
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="vq-actions">

                                <form method="POST" action="verification-queue.php" class="vq-action-form">
                                    <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                    <input type="hidden" name="action" value="verify">

                                    <button type="submit" class="vq-btn vq-verify-btn">
                                        <i class="bi bi-check2-circle"></i>
                                        Accept & Verify
                                    </button>
                                </form>

                                <form method="POST" action="verification-queue.php" class="vq-action-form">
                                    <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                    <input type="hidden" name="action" value="reject">

                                    <button type="submit" class="vq-btn vq-reject-btn">
                                        <i class="bi bi-x-circle"></i>
                                        Reject
                                    </button>
                                </form>

                                <form method="POST" action="verification-queue.php" class="vq-action-form">
                                    <input type="hidden" name="complaint_id" value="<?php echo $complaintId; ?>">
                                    <input type="hidden" name="action" value="duplicate">

                                    <button type="submit" class="vq-btn vq-duplicate-btn">
                                        <i class="bi bi-files"></i>
                                        Duplicate
                                    </button>
                                </form>

                                <button
                                    type="button"
                                    class="vq-btn vq-info-btn"
                                    data-code="<?php echo $complaintCode; ?>"
                                    data-citizen="<?php echo $citizenName; ?>"
                                    data-email="<?php echo $citizenEmail; ?>"
                                    data-issue="<?php echo $issueType; ?>"
                                    data-priority="<?php echo $priority; ?>"
                                    data-corporation="<?php echo safeText($complaint["city_cor_name"]); ?>"
                                    data-thana="<?php echo $thanaText; ?>"
                                    data-ward="<?php echo safeText($wardText); ?>"
                                    data-area="<?php echo $areaText; ?>"
                                    data-address="<?php echo safeText($complaint["address_description"]); ?>"
                                    data-problem="<?php echo $problem; ?>"
                                    data-submitted="<?php echo safeText($submittedAt); ?>"
                                    data-media="<?php echo safeText($mediaJson ?: "[]"); ?>"
                                >
                                    <i class="bi bi-info-circle"></i>
                                    Need More Info
                                </button>

                            </div>

                        </article>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="vq-empty">
                        <i class="bi bi-check-circle"></i>
                        <h2>No complaints pending verification</h2>
                        <p>Complaints sent from Central Ward Verification will appear here.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

        <?php include "../../includes/ward/footer.php"; ?>

    </main>

</div>

<div class="vq-modal-overlay" id="vqDetailsModal">
    <div class="vq-modal">

        <div class="vq-modal-header">
            <div>
                <h2>Complaint Details</h2>
                <p id="modalCode"></p>
            </div>

            <button type="button" class="vq-modal-close" id="vqModalClose">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div class="vq-modal-body">

            <div class="vq-detail-grid">
                <div><span>Citizen</span><strong id="modalCitizen"></strong></div>
                <div><span>Email</span><strong id="modalEmail"></strong></div>
                <div><span>Issue Type</span><strong id="modalIssue"></strong></div>
                <div><span>Priority</span><strong id="modalPriority"></strong></div>
                <div><span>City Corporation</span><strong id="modalCorporation"></strong></div>
                <div><span>Thana</span><strong id="modalThana"></strong></div>
                <div><span>Ward</span><strong id="modalWard"></strong></div>
                <div><span>Area</span><strong id="modalArea"></strong></div>
                <div><span>Submitted</span><strong id="modalSubmitted"></strong></div>
            </div>

            <div class="vq-modal-section">
                <h3>Address Description</h3>
                <p id="modalAddress"></p>
            </div>

            <div class="vq-modal-section">
                <h3>Problem Description</h3>
                <p id="modalProblem"></p>
            </div>

            <div class="vq-modal-section" id="modalMediaWrap">
                <h3>Uploaded Evidence</h3>
                <div class="vq-media-gallery" id="modalMediaGallery"></div>
            </div>

        </div>

    </div>
</div>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/verification-queue.js"></script>

</body>
</html>