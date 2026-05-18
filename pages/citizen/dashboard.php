<?php
require_once "../../config.php";
require_once "../../auth/session_check.php";

$activePage = 'dashboard';
$pageTitle = 'Citizen Dashboard';
$pageParent = 'Citizen';
$pageChild = 'Dashboard';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'citizen') {
    header("Location: ../../index.php");
    exit();
}

$_SESSION['user_name'] = $_SESSION['user_name'] ?? 'Citizen User';
$_SESSION['user_role_label'] = $_SESSION['user_role_label'] ?? 'Public Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Dashboard | DrainGuard</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global/global.css">
    <link rel="stylesheet" href="../../css/citizen/sidebar.css">
    <link rel="stylesheet" href="../../css/citizen/topbar.css">
    <link rel="stylesheet" href="../../css/citizen/footer.css">
    <link rel="stylesheet" href="../../css/citizen/dashboard.css">
</head>
<body class="citizen">

<div class="citizen-layout">

    <?php include "../../includes/citizen/sidebar.php"; ?>

    <main class="citizen-main main-content">

        <?php include "../../includes/citizen/topbar.php"; ?>

        <section class="dashboard-content">

            <div class="welcome-card">
                <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Track and manage your drainage complaints easily</p>
            </div>

            <div class="kpi-grid">

                <div class="kpi-card">
                    <div class="kpi-icon cyan">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <h2>12</h2>
                    <p>Total Complaints</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon blue">
                        <i class="bi bi-clock"></i>
                    </div>
                    <h2>3</h2>
                    <p>Pending Verification</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon orange">
                        <i class="bi bi-exclamation-triangle"></i>
                    </div>
                    <h2>4</h2>
                    <p>In Progress</p>
                </div>

                <div class="kpi-card">
                    <div class="kpi-icon green">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <h2>5</h2>
                    <p>Solved / Closed</p>
                </div>

            </div>

            <div class="panel complaints-panel">
                <div class="panel-header">
                    <h2>My Complaints</h2>
                    <a href="my-complaints.php">View All <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Issue</th>
                                <th>Area</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr>
                                <td>Blocked drain causing overflow</td>
                                <td>Sector 15</td>
                                <td><span class="status-badge progress">In Progress</span></td>
                                <td>Apr 28</td>
                            </tr>

                            <tr>
                                <td>Sewage leakage near main road</td>
                                <td>Ward 3</td>
                                <td><span class="status-badge verified">Verified</span></td>
                                <td>Apr 27</td>
                            </tr>

                            <tr>
                                <td>Broken drain cover</td>
                                <td>Park Avenue</td>
                                <td><span class="status-badge solved">Solved</span></td>
                                <td>Apr 25</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-row">

                <div class="panel track-panel">
                    <div class="mini-heading">
                        <div class="mini-icon">
                            <i class="bi bi-search"></i>
                        </div>
                        <h2>Track Complaint</h2>
                    </div>

                    <form class="track-form" action="track-complaint.php" method="GET">
                        <input type="text" name="code" placeholder="Enter Complaint ID">
                        <button type="submit">Track</button>
                    </form>
                </div>

                <div class="feedback-card">
                    <div class="feedback-icon">
                        <i class="bi bi-chat-left"></i>
                    </div>

                    <div>
                        <h2>Feedback Reminder</h2>
                        <p>You have 2 solved complaints waiting for your feedback</p>
                        <a href="feedback-reopen.php">Give Feedback <i class="bi bi-chevron-right"></i></a>
                    </div>
                </div>

            </div>

            <div class="panel risk-panel">
                <div class="panel-header">
                    <h2>High Risk Areas</h2>
                    <a href="high-risk-areas.php">View More <i class="bi bi-chevron-right"></i></a>
                </div>

                <div class="risk-grid">
                    <div class="risk-item critical">
                        <h3>Sector 15</h3>
                        <p>Repeated waterlogging complaints</p>
                    </div>

                    <div class="risk-item warning">
                        <h3>Ward 3</h3>
                        <p>Drainage overflow reported frequently</p>
                    </div>

                    <div class="risk-item stable">
                        <h3>Park Avenue</h3>
                        <p>Recently resolved maintenance zone</p>
                    </div>
                </div>
            </div>

        </section>

        <?php include "../../includes/citizen/footer.php"; ?>

    </main>

</div>

<script src="../../js/citizen/sidebar.js"></script>
<script src="../../js/citizen/dashboard.js"></script>
</body>
</html>