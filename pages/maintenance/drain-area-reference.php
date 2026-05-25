<?php
$pageTitle = "Drain / Area Reference";
$activePage = "drain-area-reference";

require_once "../../config.php";
require_once "../../auth/session_check.php";

$userId = $_SESSION['user_id'] ?? 0;

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateOnly($date)
{
    if (empty($date)) {
        return 'Not available';
    }

    return date("M d, Y", strtotime($date));
}

function conditionLabel($condition)
{
    $condition = strtolower((string)$condition);

    if ($condition === 'good') return 'Good';
    if ($condition === 'moderate') return 'Moderate';
    if ($condition === 'blocked') return 'Blocked';
    if ($condition === 'damaged') return 'Damaged';
    if ($condition === 'overflow') return 'Overflow';

    return 'Unknown';
}

function conditionClass($condition)
{
    $condition = strtolower((string)$condition);

    if ($condition === 'good') return 'condition-good';
    if ($condition === 'moderate') return 'condition-moderate';
    if ($condition === 'blocked') return 'condition-blocked';
    if ($condition === 'damaged') return 'condition-damaged';
    if ($condition === 'overflow') return 'condition-overflow';

    return 'condition-moderate';
}

function riskLabel($condition)
{
    $condition = strtolower((string)$condition);

    if (in_array($condition, ['blocked', 'damaged', 'overflow'], true)) {
        return 'High Risk';
    }

    if ($condition === 'moderate') {
        return 'Medium Risk';
    }

    if ($condition === 'good') {
        return 'Low Risk';
    }

    return 'Medium Risk';
}

function riskClass($condition)
{
    $condition = strtolower((string)$condition);

    if (in_array($condition, ['blocked', 'damaged', 'overflow'], true)) {
        return 'risk-high';
    }

    if ($condition === 'moderate') {
        return 'risk-medium';
    }

    return 'risk-low';
}

$teamInfo = [
    'team_id' => 0,
    'team_name' => 'Maintenance Team',
    'member_name' => $_SESSION['user_name'] ?? 'Maintenance User'
];

if ($userId > 0) {
    $teamSql = "
        SELECT
            mt.maintenance_team_id,
            mt.team_name,
            mtm.full_name
        FROM maintenance_team_members mtm
        INNER JOIN maintenance_teams mt
            ON mt.maintenance_team_id = mtm.maintenance_team_id
        WHERE mtm.user_id = ?
        LIMIT 1
    ";

    $teamStmt = mysqli_prepare($conn, $teamSql);

    if ($teamStmt) {
        mysqli_stmt_bind_param($teamStmt, "i", $userId);
        mysqli_stmt_execute($teamStmt);
        $teamResult = mysqli_stmt_get_result($teamStmt);

        if ($teamResult && mysqli_num_rows($teamResult) > 0) {
            $teamRow = mysqli_fetch_assoc($teamResult);

            $teamInfo['team_id'] = (int)$teamRow['maintenance_team_id'];
            $teamInfo['team_name'] = $teamRow['team_name'] ?? $teamInfo['team_name'];
            $teamInfo['member_name'] = $teamRow['full_name'] ?? $teamInfo['member_name'];
        }

        mysqli_stmt_close($teamStmt);
    }
}

$teamId = (int)$teamInfo['team_id'];

$drainRecords = [];

if ($teamId > 0) {
    $drainSql = "
        SELECT
            d.drain_id,
            d.loc_id,
            d.drain_code,
            d.drain_name,
            d.drain_address_description,
            d.drain_condition,
            d.condition_updated_at,
            d.created_at,
            d.updated_at,

            a.area_id,
            a.area_name,

            w.ward_id,
            w.ward_no,
            w.ward_name,

            COUNT(DISTINCT ca.assignment_id) AS total_assigned_tasks,

            COUNT(DISTINCT CASE
                WHEN ca.assignment_status = 'completed'
                     OR c.complaint_status IN ('solved_by_team', 'closed')
                THEN ca.assignment_id
            END) AS total_maintenance,

            MAX(mp.uploaded_at) AS last_cleaned_at
        FROM complaint_assignments ca

        INNER JOIN complaints c
            ON c.complaint_id = ca.complaint_id

        INNER JOIN drains d
            ON d.drain_id = c.drain_id

        INNER JOIN locations l
            ON l.loc_id = d.loc_id

        INNER JOIN areas a
            ON a.area_id = l.area_id

        INNER JOIN wards w
            ON w.ward_id = l.ward_id

        LEFT JOIN maintenance_proofs mp
            ON mp.assignment_id = ca.assignment_id
            AND mp.proof_stage = 'after'

        WHERE ca.maintenance_team_id = ?

        GROUP BY
            d.drain_id,
            d.loc_id,
            d.drain_code,
            d.drain_name,
            d.drain_address_description,
            d.drain_condition,
            d.condition_updated_at,
            d.created_at,
            d.updated_at,
            a.area_id,
            a.area_name,
            w.ward_id,
            w.ward_no,
            w.ward_name

        ORDER BY
            CASE d.drain_condition
                WHEN 'overflow' THEN 1
                WHEN 'blocked' THEN 2
                WHEN 'damaged' THEN 3
                WHEN 'moderate' THEN 4
                WHEN 'good' THEN 5
                ELSE 6
            END,
            d.updated_at DESC
    ";

    $drainStmt = mysqli_prepare($conn, $drainSql);

    if ($drainStmt) {
        mysqli_stmt_bind_param($drainStmt, "i", $teamId);
        mysqli_stmt_execute($drainStmt);
        $drainResult = mysqli_stmt_get_result($drainStmt);

        while ($drainResult && $row = mysqli_fetch_assoc($drainResult)) {
            $row['history'] = [];

            $historySql = "
                SELECT
                    ca.assignment_id,
                    ca.assignment_status,
                    ca.assigned_at,
                    ca.deadline_at,
                    c.complaint_code,
                    c.complaint_status,
                    c.problem_description,
                    mt.team_name,
                    mp.proof_status,
                    MAX(mp.uploaded_at) AS proof_uploaded_at
                FROM complaint_assignments ca

                INNER JOIN complaints c
                    ON c.complaint_id = ca.complaint_id

                INNER JOIN maintenance_teams mt
                    ON mt.maintenance_team_id = ca.maintenance_team_id

                LEFT JOIN maintenance_proofs mp
                    ON mp.assignment_id = ca.assignment_id
                    AND mp.proof_stage = 'after'

                WHERE c.drain_id = ?
                AND ca.maintenance_team_id = ?

                GROUP BY
                    ca.assignment_id,
                    ca.assignment_status,
                    ca.assigned_at,
                    ca.deadline_at,
                    c.complaint_code,
                    c.complaint_status,
                    c.problem_description,
                    mt.team_name,
                    mp.proof_status

                ORDER BY
                    COALESCE(MAX(mp.uploaded_at), ca.assigned_at) DESC

                LIMIT 5
            ";

            $historyStmt = mysqli_prepare($conn, $historySql);

            if ($historyStmt) {
                $drainId = (int)$row['drain_id'];
                mysqli_stmt_bind_param($historyStmt, "ii", $drainId, $teamId);
                mysqli_stmt_execute($historyStmt);
                $historyResult = mysqli_stmt_get_result($historyStmt);

                while ($historyResult && $historyRow = mysqli_fetch_assoc($historyResult)) {
                    $row['history'][] = $historyRow;
                }

                mysqli_stmt_close($historyStmt);
            }

            $drainRecords[] = $row;
        }

        mysqli_stmt_close($drainStmt);
    }
}

$totalDrains = count($drainRecords);
$highRiskCount = 0;
$blockedCount = 0;
$goodCount = 0;

foreach ($drainRecords as $record) {
    $condition = strtolower((string)$record['drain_condition']);

    if (in_array($condition, ['blocked', 'damaged', 'overflow'], true)) {
        $highRiskCount++;
    }

    if ($condition === 'blocked') {
        $blockedCount++;
    }

    if ($condition === 'good') {
        $goodCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Drain / Area Reference | DrainGuard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/drain-area-reference.css">
</head>

<body class="maintenance">
    <div class="maintenance-layout">
        <?php include "../../includes/maintenance/sidebar.php"; ?>

        <main class="maintenance-main">
            <?php include "../../includes/maintenance/topbar.php"; ?>

            <section class="reference-page">
                <div class="page-heading">
                    <h1>Drain / Area Reference</h1>
                    <p>Drain records and maintenance reference for <?php echo e($teamInfo['team_name']); ?></p>
                </div>

                <div class="reference-kpi-grid">
                    <article class="reference-kpi-card">
                        <div class="kpi-icon blue-icon">
                            <i class="bi bi-water"></i>
                        </div>
                        <strong><?php echo e($totalDrains); ?></strong>
                        <span>Total Reference Drains</span>
                    </article>

                    <article class="reference-kpi-card">
                        <div class="kpi-icon red-icon">
                            <i class="bi bi-exclamation-triangle"></i>
                        </div>
                        <strong><?php echo e($highRiskCount); ?></strong>
                        <span>High Risk Drains</span>
                    </article>

                    <article class="reference-kpi-card">
                        <div class="kpi-icon amber-icon">
                            <i class="bi bi-slash-circle"></i>
                        </div>
                        <strong><?php echo e($blockedCount); ?></strong>
                        <span>Blocked Drains</span>
                    </article>

                    <article class="reference-kpi-card">
                        <div class="kpi-icon green-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <strong><?php echo e($goodCount); ?></strong>
                        <span>Good Condition</span>
                    </article>
                </div>

                <div class="reference-toolbar">
                    <div class="reference-search">
                        <i class="bi bi-search"></i>
                        <input type="text" id="referenceSearchInput" placeholder="Search drain code, area, ward, address...">
                    </div>

                    <select id="conditionFilter" class="reference-filter">
                        <option value="all">All Conditions</option>
                        <option value="good">Good</option>
                        <option value="moderate">Moderate</option>
                        <option value="blocked">Blocked</option>
                        <option value="damaged">Damaged</option>
                        <option value="overflow">Overflow</option>
                    </select>

                    <select id="riskFilter" class="reference-filter">
                        <option value="all">All Risk</option>
                        <option value="high">High Risk</option>
                        <option value="medium">Medium Risk</option>
                        <option value="low">Low Risk</option>
                    </select>
                </div>

                <h2 class="section-title">Drain Information Records</h2>

                <div class="reference-list" id="referenceList">
                    <?php if (count($drainRecords) > 0): ?>
                        <?php foreach ($drainRecords as $record): ?>
                            <?php
                            $condition = strtolower((string)$record['drain_condition']);
                            $risk = strtolower(str_replace(' Risk', '', riskLabel($condition)));

                            $wardText = 'Ward not found';

                            if (!empty($record['ward_no'])) {
                                $wardText = 'Ward ' . $record['ward_no'];
                            } elseif (!empty($record['ward_name'])) {
                                $wardText = $record['ward_name'];
                            }

                            $lastCleaned = !empty($record['last_cleaned_at'])
                                ? formatDateOnly($record['last_cleaned_at'])
                                : 'No record yet';
                            ?>

                            <article
                                class="drain-card reference-item"
                                data-search="<?php echo e(strtolower(
                                    ($record['drain_code'] ?? '') . ' ' .
                                    ($record['drain_name'] ?? '') . ' ' .
                                    ($record['drain_address_description'] ?? '') . ' ' .
                                    ($record['area_name'] ?? '') . ' ' .
                                    ($wardText ?? '') . ' ' .
                                    ($record['drain_condition'] ?? '')
                                )); ?>"
                                data-condition="<?php echo e($condition); ?>"
                                data-risk="<?php echo e($risk); ?>"
                            >
                                <div class="drain-head">
                                    <div>
                                        <div class="drain-badges">
                                            <span class="drain-code"><?php echo e($record['drain_code']); ?></span>
                                            <span class="risk-badge <?php echo e(riskClass($condition)); ?>">
                                                <?php echo e(riskLabel($condition)); ?>
                                            </span>
                                            <span class="condition-badge <?php echo e(conditionClass($condition)); ?>">
                                                <?php echo e(conditionLabel($condition)); ?>
                                            </span>
                                        </div>

                                        <h3><?php echo e($record['drain_name']); ?></h3>
                                        <p><?php echo e($record['drain_address_description']); ?></p>
                                    </div>
                                </div>

                                <div class="info-grid">
                                    <div class="info-box">
                                        <h4><i class="bi bi-info-circle"></i> Basic Info</h4>
                                        <p>Drain Type: <strong>Main Drainage Line</strong></p>
                                        <p>Area: <strong><?php echo e($record['area_name']); ?></strong></p>
                                        <p>Ward: <strong><?php echo e($wardText); ?></strong></p>
                                    </div>

                                    <div class="info-box">
                                        <h4><i class="bi bi-clock-history"></i> Maintenance History</h4>
                                        <p>Last Cleaned: <strong><?php echo e($lastCleaned); ?></strong></p>
                                        <p>Total Maintenance: <strong><?php echo e((int)$record['total_maintenance']); ?> time<?php echo ((int)$record['total_maintenance'] === 1 ? '' : 's'); ?></strong></p>
                                        <p>Total Assigned: <strong><?php echo e((int)$record['total_assigned_tasks']); ?> task<?php echo ((int)$record['total_assigned_tasks'] === 1 ? '' : 's'); ?></strong></p>
                                    </div>

                                    <div class="info-box">
                                        <h4><i class="bi bi-activity"></i> Current Condition</h4>
                                        <p>Condition: <strong><?php echo e(conditionLabel($condition)); ?></strong></p>
                                        <p>Risk Level: <strong><?php echo e(riskLabel($condition)); ?></strong></p>
                                        <p>Updated: <strong><?php echo e(formatDateOnly($record['condition_updated_at'] ?: $record['updated_at'])); ?></strong></p>
                                    </div>
                                </div>

                                <div class="history-box">
                                    <div class="history-head">
                                        <h4><i class="bi bi-clipboard-data"></i> Previous Maintenance Records</h4>
                                    </div>

                                    <?php if (count($record['history']) > 0): ?>
                                        <div class="history-table">
                                            <?php foreach ($record['history'] as $history): ?>
                                                <?php
                                                $dateSource = !empty($history['proof_uploaded_at'])
                                                    ? $history['proof_uploaded_at']
                                                    : $history['assigned_at'];

                                                $statusLabel = 'Pending';

                                                if (($history['proof_status'] ?? '') === 'accepted') {
                                                    $statusLabel = 'Verified';
                                                } elseif (($history['proof_status'] ?? '') === 'rejected') {
                                                    $statusLabel = 'Rejected';
                                                } elseif (($history['proof_status'] ?? '') === 'submitted') {
                                                    $statusLabel = 'Submitted';
                                                } elseif (($history['assignment_status'] ?? '') === 'in_progress') {
                                                    $statusLabel = 'In Progress';
                                                } elseif (($history['assignment_status'] ?? '') === 'team_assigned') {
                                                    $statusLabel = 'Assigned';
                                                }
                                                ?>

                                                <div class="history-row">
                                                    <span><?php echo e(formatDateOnly($dateSource)); ?></span>
                                                    <strong><?php echo e($history['team_name']); ?></strong>
                                                    <span><?php echo e($history['complaint_code']); ?></span>
                                                    <span class="history-status"><?php echo e($statusLabel); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="no-history">No maintenance history found for this drain.</p>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="bi bi-water"></i>
                            <h2>No drain reference found</h2>
                            <p>No drain record is linked with this maintenance team's assigned complaints yet.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="empty-state filter-empty-state" id="filterEmptyState">
                    <i class="bi bi-search"></i>
                    <h2>No matching drain found</h2>
                    <p>No drain reference matches your selected filter.</p>
                </div>
            </section>
        </main>
    </div>

    <script src="../../js/maintenance/sidebar.js"></script>
    <script src="../../js/maintenance/drain-area-reference.js"></script>
</body>
</html>