<?php
session_start();

$activePage = "dashboard";

$_SESSION['user_name'] = "Team Alpha";
$_SESSION['user_role_label'] = "Maintenance Team";
?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Maintenance Dashboard | DrainGuard</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">

    <link rel="stylesheet" href="../../css/maintenance/sidebar.css">
    <link rel="stylesheet" href="../../css/maintenance/topbar.css">
    <link rel="stylesheet" href="../../css/maintenance/footer.css">
    <link rel="stylesheet" href="../../css/maintenance/dashboard.css">

</head>

<body class="maintenance">

<div class="maintenance-layout">

    <?php require_once "../../includes/maintenance/sidebar.php"; ?>

    <main class="maintenance-main">

        <?php require_once "../../includes/maintenance/topbar.php"; ?>

        <section class="dashboard-content">

            <div class="maintenance-hero">

                <div>
                    <span class="hero-badge">
                        FIELD MAINTENANCE TEAM
                    </span>

                    <h1>Team Alpha – Daily Work Queue</h1>

                    <p>
                        Complete assigned tasks, upload work photos,
                        mark jobs as solved by team
                    </p>
                </div>

                <div class="hero-task-count">
                    <small>Today's Tasks</small>
                    <h2>7</h2>
                </div>

            </div>

            <div class="important-note">

                <div class="note-icon">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>

                <div>
                    <h3>Important: Team Work Scope</h3>

                    <p>
                        You can mark tasks as "Solved by Team"
                        after completing work. You cannot close
                        complaints. Final closure requires citizen
                        feedback and inspector verification.
                    </p>
                </div>

            </div>

            <div class="stats-grid">

                <div class="stat-card">
                    <div class="stat-icon cyan">
                        <i class="bi bi-list-check"></i>
                    </div>

                    <h2>7</h2>
                    <p>Assigned Jobs Today</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon red">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>

                    <h2>3</h2>
                    <p>Urgent Tasks</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="bi bi-clock-history"></i>
                    </div>

                    <h2>4</h2>
                    <p>Tasks Near Deadline</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="bi bi-check-circle"></i>
                    </div>

                    <h2>45</h2>
                    <p>Solved by Team</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon yellow">
                        <i class="bi bi-eye"></i>
                    </div>

                    <h2>8</h2>
                    <p>Awaiting Inspection</p>
                </div>

            </div>

        </section>

        <?php require_once "../../includes/maintenance/footer.php"; ?>

    </main>

</div>

<script src="../../js/maintenance/sidebar.js"></script>
<script src="../../js/maintenance/dashboard.js"></script>

</body>
</html>