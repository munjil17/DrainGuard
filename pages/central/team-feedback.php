<?php
require_once "../../config.php";
require_login(["central_officer"]);

$activePage = 'team-feedback';
$pageTitle = 'Team Feedback Summary';
$pageParent = 'Central';
$pageChild = 'Team Feedback';

// Filters
$filterSearch = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterSort = isset($_GET['sort']) ? trim($_GET['sort']) : 'avg_desc';

$whereParts = ["1=1"];
$types = "";
$params = [];

if ($filterSearch !== '') {
    $whereParts[] = "t.team_name LIKE ?";
    $types .= "s";
    $params[] = "%$filterSearch%";
}

$whereSql = "WHERE " . implode(" AND ", $whereParts);

$orderSql = "ORDER BY avg_rating DESC, total_reviews DESC";
if ($filterSort === 'avg_asc') {
    $orderSql = "ORDER BY avg_rating ASC, total_reviews DESC";
} elseif ($filterSort === 'reviews_desc') {
    $orderSql = "ORDER BY total_reviews DESC, avg_rating DESC";
} elseif ($filterSort === 'name_asc') {
    $orderSql = "ORDER BY t.team_name ASC";
}

$sql = "
    SELECT 
        t.maintenance_team_id,
        t.team_name,
        t.availability_status,
        COUNT(mtr.review_id) AS total_reviews,
        IFNULL(AVG(mtr.rating), 0) AS avg_rating
    FROM maintenance_teams t
    LEFT JOIN maintenance_team_reviews mtr ON t.maintenance_team_id = mtr.maintenance_team_id
    $whereSql
    GROUP BY t.maintenance_team_id, t.team_name, t.availability_status
    $orderSql
";

$stmt = mysqli_prepare($conn, $sql);
$teams = [];
if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $teams[] = $r;
        }
    }
    mysqli_stmt_close($stmt);
}

function renderStarsAvg($ratingAvg) {
    $html = '';
    $rounded = round($ratingAvg * 2) / 2; // round to nearest 0.5
    for($i=1; $i<=5; $i++) {
        if($i <= $rounded) {
            $html .= '<i class="bi bi-star-fill" style="color:#fbbf24;"></i> ';
        } elseif ($i - 0.5 == $rounded) {
            $html .= '<i class="bi bi-star-half" style="color:#fbbf24;"></i> ';
        } else {
            $html .= '<i class="bi bi-star" style="color:#cbd5e1;"></i> ';
        }
    }
    return $html;
}

function central_tf_safe($val) {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Feedback | DrainGuard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/team-feedback.css">
    <link rel="stylesheet" href="../../css/global/confirm-modal.css">
</head>
<body class="dg-central-body">

<div class="dg-central-layout">
    <?php require_once "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">
        <?php require_once "../../includes/central/topbar.php"; ?>

        <div class="dg-central-content" style="padding: 24px;">
            
            <div style="margin-bottom: 24px;">
                <h1 style="font-size: 1.5rem; color: #0f172a; margin-bottom: 8px;">Team Feedback Summary</h1>
                <p style="color: #64748b;">Monitor citizen satisfaction and reviews across all maintenance teams.</p>
            </div>

            <form method="GET" action="team-feedback.php" class="tf-filters">
                <div class="tf-form-group">
                    <label>Search Team</label>
                    <input type="text" name="search" placeholder="Enter team name..." value="<?php echo central_tf_safe($filterSearch); ?>">
                </div>
                
                <div class="tf-form-group">
                    <label>Sort By</label>
                    <select name="sort">
                        <option value="avg_desc" <?php echo $filterSort==='avg_desc'?'selected':''; ?>>Highest Rating First</option>
                        <option value="avg_asc" <?php echo $filterSort==='avg_asc'?'selected':''; ?>>Lowest Rating First</option>
                        <option value="reviews_desc" <?php echo $filterSort==='reviews_desc'?'selected':''; ?>>Most Reviews First</option>
                        <option value="name_asc" <?php echo $filterSort==='name_asc'?'selected':''; ?>>Team Name (A-Z)</option>
                    </select>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="tf-btn">Apply Filters</button>
                    <a href="team-feedback.php" class="tf-btn" style="background: #f1f5f9; color: #475569; text-decoration: none;">Clear</a>
                </div>
            </form>

            <div class="tf-summary-grid">
                <?php if(empty($teams)): ?>
                    <div class="tf-empty-state">
                        <i class="bi bi-people" style="font-size: 2.5rem; margin-bottom: 10px; display: block;"></i>
                        No teams found matching your criteria.
                    </div>
                <?php else: ?>
                    <?php foreach($teams as $team): 
                        $avg = number_format($team['avg_rating'], 1);
                        $total = (int)$team['total_reviews'];
                        $statusClass = $team['availability_status'] === 'busy' ? 'busy' : 'available';
                        $statusText = ucwords($team['availability_status']);
                    ?>
                        <div class="tf-card">
                            <div class="tf-card-header">
                                <h3 class="tf-team-name">
                                    <i class="bi bi-tools"></i>
                                    <?php echo central_tf_safe($team['team_name']); ?>
                                </h3>
                                <span class="tf-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </div>

                            <div class="tf-metrics">
                                <div class="tf-metric-box" style="flex: 1;">
                                    <span class="tf-metric-label">Average Rating</span>
                                    <div class="tf-metric-value">
                                        <?php echo $avg; ?>
                                        <span class="tf-stars" style="font-size: 1rem; margin-left: 5px;">
                                            <i class="bi bi-star-fill"></i>
                                        </span>
                                    </div>
                                    <div style="margin-top: 5px;">
                                        <?php echo renderStarsAvg($team['avg_rating']); ?>
                                    </div>
                                </div>
                                <div class="tf-metric-box" style="flex: 1; border-left: 1px solid #e2e8f0; padding-left: 20px;">
                                    <span class="tf-metric-label">Total Reviews</span>
                                    <div class="tf-metric-value" style="font-size: 2rem;">
                                        <?php echo $total; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/global/confirm-modal.js"></script>
</body>
</html>
