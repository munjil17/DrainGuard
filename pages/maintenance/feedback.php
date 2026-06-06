<?php
require_once "../../config.php";
require_login(["team_leader", "assistant_team_leader", "worker"]);

$activePage = 'feedback';
$pageTitle = 'Citizen Feedback';
$pageParent = 'Maintenance';
$pageChild = 'Feedback';

$userId = (int)$_SESSION['user_id'];

// Get team id
$teamSql = "SELECT maintenance_team_id FROM maintenance_team_members WHERE user_id = ? AND status = 'active' LIMIT 1";
$teamStmt = mysqli_prepare($conn, $teamSql);
$teamId = 0;
if ($teamStmt) {
    mysqli_stmt_bind_param($teamStmt, "i", $userId);
    mysqli_stmt_execute($teamStmt);
    $teamRes = mysqli_stmt_get_result($teamStmt);
    if ($teamRes && $row = mysqli_fetch_assoc($teamRes)) {
        $teamId = (int)$row['maintenance_team_id'];
    }
    mysqli_stmt_close($teamStmt);
}

if ($teamId === 0) {
    die("You are not assigned to an active maintenance team.");
}

// Filters
$filterRating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';

$whereParts = ["mtr.maintenance_team_id = ?"];
$types = "i";
$params = [$teamId];

if ($filterRating > 0 && $filterRating <= 5) {
    $whereParts[] = "mtr.rating = ?";
    $types .= "i";
    $params[] = $filterRating;
}

if ($filterSearch !== '') {
    $whereParts[] = "(u.user_name LIKE ? OR c.complaint_code LIKE ?)";
    $types .= "ss";
    $searchWildcard = "%$filterSearch%";
    $params[] = $searchWildcard;
    $params[] = $searchWildcard;
}

$whereSql = "WHERE " . implode(" AND ", $whereParts);

$sql = "
    SELECT 
        mtr.complaint_id,
        mtr.rating,
        mtr.review_text,
        mtr.created_at,
        u.user_name AS citizen_name,
        c.complaint_code,
        i.issue_name AS issue_title,
        a.area_name
    FROM maintenance_team_reviews mtr
    LEFT JOIN users u ON mtr.citizen_user_id = u.user_id
    LEFT JOIN complaints c ON mtr.complaint_id = c.complaint_id
    LEFT JOIN issues i ON c.issue_id = i.issue_id
    LEFT JOIN locations l ON c.loc_id = l.loc_id
    LEFT JOIN areas a ON l.area_id = a.area_id
    $whereSql
    ORDER BY mtr.created_at DESC
";

$stmt = mysqli_prepare($conn, $sql);
$reviews = [];
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $reviews[] = $r;
        }
    }
    mysqli_stmt_close($stmt);
}

function renderStars($rating) {
    $html = '';
    for($i=1; $i<=5; $i++) {
        if($i <= $rating) {
            $html .= '<i class="bi bi-star-fill"></i> ';
        } else {
            $html .= '<i class="bi bi-star"></i> ';
        }
    }
    return $html;
}

function dash_safe($val) {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback | DrainGuard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/feedback.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>
<body class="maintenance">

<div class="maintenance-layout">
    <?php require_once "../../includes/maintenance/sidebar.php"; ?>

    <main class="maintenance-main">
        <?php require_once "../../includes/maintenance/topbar.php"; ?>

        <div class="content-wrapper" style="padding: 20px;">
            <h2 style="margin-bottom: 20px; color: #1e293b;">Citizen Reviews</h2>

            <form method="GET" action="feedback.php" class="filter-section" id="feedbackFilterForm">
                <div class="filter-group">
                    <label style="font-size: 0.9rem; color: #64748b;">Rating:</label>
                    <select name="rating" id="filterRating">
                        <option value="0">All Ratings</option>
                        <option value="5" <?php echo $filterRating===5?'selected':''; ?>>5 Stars</option>
                        <option value="4" <?php echo $filterRating===4?'selected':''; ?>>4 Stars</option>
                        <option value="3" <?php echo $filterRating===3?'selected':''; ?>>3 Stars</option>
                        <option value="2" <?php echo $filterRating===2?'selected':''; ?>>2 Stars</option>
                        <option value="1" <?php echo $filterRating===1?'selected':''; ?>>1 Star</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label style="font-size: 0.9rem; color: #64748b;">Search:</label>
                    <input type="text" name="search" id="filterSearch" placeholder="Citizen name or Complaint Code" value="<?php echo dash_safe($filterSearch); ?>">
                </div>
                
                <a href="feedback.php" style="text-decoration: none; color: #3b82f6; font-size: 0.9rem; margin-left: 10px;">Clear</a>
            </form>

            <?php if (empty($reviews)): ?>
                <div style="background: #fff; padding: 40px; border-radius: 12px; text-align: center; border: 1px solid #e2e8f0; color: #64748b;">
                    <i class="bi bi-star-half" style="font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: 10px;"></i>
                    <p>No feedback found for your team.</p>
                </div>
            <?php else: ?>
                <div class="feedback-grid">
                    <?php foreach ($reviews as $rev): 
                        $dateFormatted = date('M d, Y', strtotime($rev['created_at']));
                    ?>
                        <div class="review-card" 
                             data-complaint-id="<?php echo (int)$rev['complaint_id']; ?>"
                             data-complaint-code="<?php echo dash_safe($rev['complaint_code']); ?>"
                             data-notification-target="<?php echo (int)$rev['complaint_id']; ?>"
                             data-name="<?php echo dash_safe($rev['citizen_name']); ?>"
                             data-rating="<?php echo (int)$rev['rating']; ?>"
                             data-date="<?php echo $dateFormatted; ?>"
                             data-code="<?php echo dash_safe($rev['complaint_code']); ?>"
                             data-issue="<?php echo dash_safe($rev['issue_title']); ?>"
                             data-area="<?php echo dash_safe($rev['area_name']); ?>"
                             data-text="<?php echo dash_safe($rev['review_text']); ?>">
                            
                            <div class="review-header">
                                <div class="reviewer-name">
                                    <i class="bi bi-person-circle"></i>
                                    <?php echo dash_safe($rev['citizen_name'] ?: 'Unknown Citizen'); ?>
                                </div>
                                <div class="rating-stars">
                                    <?php echo renderStars($rev['rating']); ?>
                                </div>
                            </div>
                            
                            <div class="review-meta">
                                <span><i class="bi bi-calendar3"></i> <?php echo $dateFormatted; ?></span>
                                <span><i class="bi bi-hash"></i> <?php echo dash_safe($rev['complaint_code']); ?></span>
                            </div>

                            <div class="review-preview">
                                "<?php echo dash_safe($rev['review_text'] ?: 'No additional comments provided.'); ?>"
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal -->
<div class="review-modal-overlay" id="reviewModalOverlay">
    <div class="review-modal">
        <div class="modal-header">
            <h3>Review Details</h3>
            <button class="modal-close" id="modalCloseBtn">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-detail-row">
                <div class="modal-label">Citizen</div>
                <div class="modal-value" id="m-name" style="font-weight: 600;"></div>
            </div>
            <div class="modal-detail-row" style="display: flex; gap: 40px;">
                <div>
                    <div class="modal-label">Rating</div>
                    <div class="modal-value rating-stars" id="m-rating"></div>
                </div>
                <div>
                    <div class="modal-label">Date</div>
                    <div class="modal-value" id="m-date"></div>
                </div>
            </div>
            <div class="modal-detail-row" style="display: flex; gap: 40px;">
                <div>
                    <div class="modal-label">Complaint Code</div>
                    <div class="modal-value" id="m-code"></div>
                </div>
                <div>
                    <div class="modal-label">Issue Type</div>
                    <div class="modal-value" id="m-issue"></div>
                </div>
            </div>
            <div class="modal-detail-row">
                <div class="modal-label">Area / Location</div>
                <div class="modal-value" id="m-area"></div>
            </div>
            <div class="modal-detail-row">
                <div class="modal-label">Review Text</div>
                <div class="modal-value" id="m-text" style="font-style: italic; color: #475569;"></div>
            </div>
        </div>
    </div>
</div>

<script src="../../js/maintenance/sidebar.js"></script>
<script src="../../js/maintenance/feedback.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
