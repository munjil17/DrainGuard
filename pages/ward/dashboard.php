<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$activePage = "dashboard";

$_SESSION['user_name'] = $_SESSION['user_name'] ?? "Ward Officer";
$_SESSION['user_role_label'] = $_SESSION['user_role_label'] ?? "Ward Operations";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ward Dashboard | DrainGuard</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/ward/sidebar.css">
    <link rel="stylesheet" href="../../css/ward/topbar.css">
    <link rel="stylesheet" href="../../css/ward/footer.css">
    <link rel="stylesheet" href="../../css/ward/dashboard.css">
</head>

<body class="ward">

<div class="ward-layout">

    <?php require_once "../../includes/ward/sidebar.php"; ?>

    <main class="ward-main">

        <?php require_once "../../includes/ward/topbar.php"; ?>

        <section class="dashboard-content">

            <div class="page-header-card">
                <h1>Ward Office Operations Dashboard</h1>
                <p>Monitor and manage drainage complaints for Ward 3</p>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon cyan"><i class="bi bi-file-earmark-text"></i></div>
                    <h2>45</h2>
                    <p>Ward Total Complaints</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="bi bi-clock"></i></div>
                    <h2>8</h2>
                    <p>Pending Verification</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon purple"><i class="bi bi-people"></i></div>
                    <h2>12</h2>
                    <p>Assigned Cases</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon orange"><i class="bi bi-graph-up-arrow"></i></div>
                    <h2>18</h2>
                    <p>In Progress Cases</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon green"><i class="bi bi-check-circle"></i></div>
                    <h2>19</h2>
                    <p>Solved Cases</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon yellow"><i class="bi bi-exclamation-triangle"></i></div>
                    <h2>5</h2>
                    <p>Reopened Cases</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon red"><i class="bi bi-clock-history"></i></div>
                    <h2>3</h2>
                    <p>Delayed Cases</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon red"><i class="bi bi-geo-alt"></i></div>
                    <h2>4</h2>
                    <p>Ward Risk Zones</p>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2>New Complaints Waiting for Verification</h2>
                    <a href="verification-queue.php">View Queue <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="verification-list">
                    <div class="verification-item">
                        <div>
                            <span class="complaint-id">DG-2026-098</span>
                            <span class="priority high">High</span>
                            <h3>Blocked drain near market</h3>
                            <p>Sector 15 · 30 mins ago</p>
                        </div>
                        <button type="button">Verify</button>
                    </div>

                    <div class="verification-item">
                        <div>
                            <span class="complaint-id">DG-2026-097</span>
                            <span class="priority critical">Critical</span>
                            <h3>Water logging in residential area</h3>
                            <p>Sector 16 · 1 hour ago</p>
                        </div>
                        <button type="button">Verify</button>
                    </div>

                    <div class="verification-item">
                        <div>
                            <span class="complaint-id">DG-2026-096</span>
                            <span class="priority medium">Medium</span>
                            <h3>Broken drain cover</h3>
                            <p>Main Road · 2 hours ago</p>
                        </div>
                        <button type="button">Verify</button>
                    </div>
                </div>
            </div>

        </section>

        <?php require_once "../../includes/ward/footer.php"; ?>

    </main>

</div>

<script src="../../js/ward/sidebar.js"></script>
<script src="../../js/ward/dashboard.js"></script>

</body>
</html>