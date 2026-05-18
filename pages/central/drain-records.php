<?php
$activePage = 'drain-records';

require_once "../../config.php";
require_once "../../auth/session_check.php";

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'central_officer') {
    header("Location: ../../index.php");
    exit();
}

$drains = [];

$sql = "
    SELECT
        d.drain_id,
        d.drain_code,
        d.drain_type,
        d.drain_condition,
        d.risk_level,
        d.last_cleaned,
        d.next_due,
        d.drain_status,

        cc.city_cor_name,
        t.thana_name,
        w.ward_no,
        w.ward_name,
        a.area_name

    FROM drains d

    INNER JOIN locations l
        ON d.loc_id = l.loc_id

    INNER JOIN city_corporations cc
        ON l.city_cor_id = cc.city_cor_id

    INNER JOIN thanas t
        ON l.thana_id = t.thana_id

    INNER JOIN wards w
        ON l.ward_id = w.ward_id

    INNER JOIN areas a
        ON l.area_id = a.area_id

    ORDER BY d.drain_id DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $drains[] = $row;
    }
}

function safeText($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function conditionClass($condition) {
    if ($condition === 'Good') return 'condition-good';
    if ($condition === 'Fair') return 'condition-fair';
    if ($condition === 'Poor') return 'condition-poor';

    return 'condition-good';
}

function riskClass($risk) {
    if ($risk === 'Low') return 'risk-low';
    if ($risk === 'Medium') return 'risk-medium';
    if ($risk === 'High') return 'risk-high';

    return 'risk-low';
}

function dueText($nextDue) {
    if (empty($nextDue)) {
        return 'Not Set';
    }

    $today = date('Y-m-d');

    if ($nextDue < $today) {
        return 'Overdue';
    }

    return date("M d, Y", strtotime($nextDue));
}

function dueClass($nextDue) {
    if (empty($nextDue)) {
        return 'due-neutral';
    }

    $today = date('Y-m-d');

    if ($nextDue < $today) {
        return 'due-overdue';
    }

    return 'due-normal';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drain Records | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <!-- Reusable Central Layout CSS -->
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">

    <!-- Body CSS only -->
    <link rel="stylesheet" href="../../css/central/drain-records.css">
</head>
<body>

<div class="central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="dr-page">

            <div class="dr-header">
                <div>
                    <h1>Drain Records Management</h1>
                    <p>Track drain conditions, cleaning history, and risk levels</p>
                </div>

                <div class="dr-count-card">
                    <span><?php echo count($drains); ?></span>
                    <small>Total Drains</small>
                </div>
            </div>

            <div class="dr-toolbar">
                <div class="dr-search-box">
                    <i class="bi bi-search"></i>
                    <input
                        type="text"
                        id="drainSearch"
                        placeholder="Search drains by ID, location, ward, thana..."
                    >
                </div>

                <button type="button" class="dr-filter-btn" id="filterToggleBtn">
                    <i class="bi bi-funnel"></i>
                    Filter
                </button>
            </div>

            <div class="dr-filter-panel" id="filterPanel">
                <div class="dr-filter-group">
                    <label>Condition</label>
                    <select id="conditionFilter">
                        <option value="all">All Condition</option>
                        <option value="Good">Good</option>
                        <option value="Fair">Fair</option>
                        <option value="Poor">Poor</option>
                    </select>
                </div>

                <div class="dr-filter-group">
                    <label>Risk Level</label>
                    <select id="riskFilter">
                        <option value="all">All Risk</option>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High">High</option>
                    </select>
                </div>

                <button type="button" class="dr-clear-btn" id="clearFilterBtn">
                    Clear
                </button>
            </div>

            <div class="dr-table-card">

                <?php if (count($drains) > 0): ?>

                    <div class="dr-table-wrap">
                        <table class="dr-table">
                            <thead>
                                <tr>
                                    <th>Drain ID</th>
                                    <th>Location</th>
                                    <th>Ward</th>
                                    <th>Condition</th>
                                    <th>Risk Level</th>
                                    <th>Last Cleaned</th>
                                    <th>Next Due</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($drains as $drain): ?>
                                    <?php
                                        $drainCode = safeText($drain['drain_code']);
                                        $location = safeText($drain['area_name'] . ", " . $drain['thana_name']);
                                        $wardText = safeText("Ward " . $drain['ward_no']);
                                        $condition = safeText($drain['drain_condition']);
                                        $risk = safeText($drain['risk_level']);

                                        $lastCleaned = !empty($drain['last_cleaned'])
                                            ? date("M d, Y", strtotime($drain['last_cleaned']))
                                            : "Not Set";

                                        $nextDueText = dueText($drain['next_due']);
                                        $nextDueClass = dueClass($drain['next_due']);
                                    ?>

                                    <tr
                                        class="dr-row"
                                        data-code="<?php echo strtolower($drainCode); ?>"
                                        data-location="<?php echo strtolower($location); ?>"
                                        data-ward="<?php echo strtolower($wardText); ?>"
                                        data-corporation="<?php echo strtolower($drain['city_cor_name']); ?>"
                                        data-condition="<?php echo $condition; ?>"
                                        data-risk="<?php echo $risk; ?>"
                                    >
                                        <td>
                                            <span class="dr-code"><?php echo $drainCode; ?></span>
                                        </td>

                                        <td><?php echo $location; ?></td>

                                        <td><?php echo $wardText; ?></td>

                                        <td>
                                            <span class="dr-badge <?php echo conditionClass($condition); ?>">
                                                <?php echo $condition; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="dr-badge <?php echo riskClass($risk); ?>">
                                                <?php echo $risk; ?>
                                            </span>
                                        </td>

                                        <td><?php echo $lastCleaned; ?></td>

                                        <td>
                                            <span class="<?php echo $nextDueClass; ?>">
                                                <?php echo $nextDueText; ?>
                                            </span>
                                        </td>

                                        <td>
                                            <a href="drain-history.php?drain_id=<?php echo (int)$drain['drain_id']; ?>" class="dr-action-link">
                                                View History
                                                <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php else: ?>

                    <div class="dr-empty">
                        <i class="bi bi-inbox"></i>
                        <h2>No drain records found</h2>
                        <p>Add drain records first to monitor cleaning history and risk levels.</p>
                    </div>

                <?php endif; ?>

            </div>

        </section>

    </main>

</div>

<script src="../../js/central/drain-records.js"></script>

</body>
</html>