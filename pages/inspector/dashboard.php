<?php
session_start();

$activePage = "dashboard";

$_SESSION['user_name'] = "Inspector Karim";
$_SESSION['user_role_label'] = "Inspector Verification";
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Inspector Dashboard | DrainGuard</title>

    <link rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <link rel="stylesheet" href="../../css/global/global.css">

    <link rel="stylesheet" href="../../css/inspector/sidebar.css">
    <link rel="stylesheet" href="../../css/inspector/topbar.css">
    <link rel="stylesheet" href="../../css/inspector/footer.css">
    <link rel="stylesheet" href="../../css/inspector/dashboard.css">

</head>

<body class="inspector">

<div class="inspector-layout">

    <?php require_once "../../includes/inspector/sidebar.php"; ?>

    <main class="inspector-main">

        <?php require_once "../../includes/inspector/topbar.php"; ?>

        <section class="dashboard-content">

            <div class="inspector-hero">

                <div>

                    <span class="hero-badge">
                        INSPECTOR VERIFICATION ACCESS
                    </span>

                    <h1>
                        Inspector Final Judgment Authority
                    </h1>

                    <p>
                        Review maintenance work, verify completion proof,
                        approve or reopen complaints
                    </p>

                </div>

                <div class="hero-count">

                    <small>Pending Inspections</small>
                    <h2>12</h2>

                </div>

            </div>

            <div class="authority-note">

                <div class="authority-icon">
                    <i class="bi bi-shield-check"></i>
                </div>

                <div>

                    <h3>
                        Inspector Final Judgment Authority
                    </h3>

                    <p>
                        You have the authority to verify maintenance team work.
                        You can approve work if proof is satisfactory,
                        or reopen complaints if work is incomplete or suspicious.
                    </p>

                </div>

            </div>

            <div class="stats-grid">

                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="bi bi-eye"></i>
                    </div>

                    <h2>12</h2>
                    <p>Pending Inspections</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                    <h2>45</h2>
                    <p>Approved This Week</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon red">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>

                    <h2>5</h2>
                    <p>Reopened Cases</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon yellow">
                        <i class="bi bi-flag"></i>
                    </div>

                    <h2>3</h2>
                    <p>Citizen Objections</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="bi bi-clock-history"></i>
                    </div>

                    <h2>8</h2>
                    <p>Awaiting Review</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon blue">
                        <i class="bi bi-file-earmark-check"></i>
                    </div>

                    <h2>124</h2>
                    <p>Total Inspections</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>

                    <h2>89%</h2>
                    <p>Approval Rate</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon orange">
                        <i class="bi bi-flag"></i>
                    </div>

                    <h2>2</h2>
                    <p>False Completion</p>
                </div>

            </div>

        </section>

        <?php require_once "../../includes/inspector/footer.php"; ?>

    </main>

</div>

<script src="../../js/inspector/sidebar.js"></script>
<script src="../../js/inspector/dashboard.js"></script>

</body>
</html>