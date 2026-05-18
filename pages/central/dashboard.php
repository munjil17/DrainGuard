<?php
require_once "../../config.php";

$allowed_role = "central_officer";
require_once "../../auth/session_check.php";

$activePage = "dashboard";
$pageTitle = "Central Command Dashboard";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/central/sidebar.css">
    <link rel="stylesheet" href="../../css/central/topbar.css">
    <link rel="stylesheet" href="../../css/central/footer.css">
    <link rel="stylesheet" href="../../css/central/dashboard.css">
</head>

<body class="central">

<div class="dg-central-layout">

    <?php include "../../includes/central/sidebar.php"; ?>

    <main class="dg-central-main">

        <?php include "../../includes/central/topbar.php"; ?>

        <section class="central-dashboard-page">

            <div class="cd-header-card">
                <h1>Central Command Dashboard</h1>
                <p>City-wide drainage system oversight and control</p>
            </div>

            <div class="cd-kpi-grid">

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon cyan">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <h2>248</h2>
                    <p>Total Complaints</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon blue">
                        <i class="bi bi-clock"></i>
                    </div>
                    <h2>32</h2>
                    <p>Pending Verification</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon red">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <h2>12</h2>
                    <p>Emergency Cases</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon orange">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                    <h2>86</h2>
                    <p>In Progress</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon green">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <h2>118</h2>
                    <p>Solved</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon red">
                        <i class="bi bi-geo-alt"></i>
                    </div>
                    <h2>8</h2>
                    <p>High Risk Zones</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon red">
                        <i class="bi bi-bell"></i>
                    </div>
                    <h2>5</h2>
                    <p>Red Alert Cases</p>
                </div>

                <div class="cd-kpi-card">
                    <div class="cd-kpi-icon yellow">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    <h2>14</h2>
                    <p>Team Delay Summary</p>
                </div>

            </div>

            <div class="cd-red-alert-panel">

                <div class="cd-alert-header">
                    <div class="cd-alert-left">
                        <div class="cd-alert-icon">
                            <i class="bi bi-bell"></i>
                        </div>

                        <div>
                            <h2>Red Alert: Emergency Cases</h2>
                            <p>5 emergency complaints not handled within SLA</p>
                        </div>
                    </div>

                    <a href="complaints.php" class="cd-review-btn">Review Now</a>
                </div>

                <div class="cd-alert-list">

                    <div class="cd-alert-item">
                        <div>
                            <h3>DG-2026-089</h3>
                            <p>Sector 15, Ward 3</p>
                        </div>

                        <span>8 hrs overdue</span>
                    </div>

                    <div class="cd-alert-item">
                        <div>
                            <h3>DG-2026-092</h3>
                            <p>Main Road, Ward 7</p>
                        </div>

                        <span>5 hrs overdue</span>
                    </div>

                </div>

            </div>

            <div class="cd-panel">

                <div class="cd-panel-header">
                    <h2>Ward-wise Overview</h2>

                    <a href="reports.php">
                        View Detailed Reports <i class="bi bi-chevron-right"></i>
                    </a>
                </div>

                <div class="cd-ward-grid">

                    <div class="cd-ward-card stable">
                        <h3>Ward 3</h3>
                        <p>42 complaints</p>
                        <span>72% resolved</span>
                    </div>

                    <div class="cd-ward-card warning">
                        <h3>Ward 7</h3>
                        <p>36 complaints</p>
                        <span>High pending load</span>
                    </div>

                    <div class="cd-ward-card danger">
                        <h3>Ward 11</h3>
                        <p>29 complaints</p>
                        <span>Emergency attention</span>
                    </div>

                </div>

            </div>

        </section>

        <?php include "../../includes/central/footer.php"; ?>

    </main>

</div>

<script src="../../js/central/sidebar.js"></script>
<script src="../../js/central/dashboard.js"></script>

</body>
</html>