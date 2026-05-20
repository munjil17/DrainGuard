<?php
$pageTitle = "DrainGuard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <!-- Important for responsive layout -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo htmlspecialchars($pageTitle); ?> | Smart Urban Drainage Management System</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link rel="stylesheet" href="css/global/landing.css">
</head>
<body>

<main class="landing-page">
    <section class="hero-section">

        <div class="container-fluid px-3 px-sm-4 px-lg-5">
            <div class="hero-content mx-auto">

                <div class="brand-icon">
                    <i class="bi bi-droplet"></i>
                </div>

                <h1>DrainGuard</h1>

                <h2>Smart Urban Drainage Management System</h2>

                <p>
                    A transparent public drainage accountability system for better urban infrastructure
                </p>

                <button type="button" class="login-btn" id="loginRegisterBtn">
                    Login / Register
                    <i class="bi bi-arrow-right"></i>
                </button>

                <!-- Responsive feature cards -->
                <div class="feature-grid row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-3 g-lg-4">

                    <div class="col">
                        <div class="feature-card h-100">
                            <div class="feature-icon tracking-icon">
                                <i class="bi bi-eye"></i>
                            </div>

                            <h3>Real-time Tracking</h3>

                            <p>Monitor complaint progress publicly</p>
                        </div>
                    </div>

                    <div class="col">
                        <div class="feature-card h-100">
                            <div class="feature-icon proof-icon">
                                <i class="bi bi-check-circle"></i>
                            </div>

                            <h3>Verified Work Proof</h3>

                            <p>Photo evidence for all tasks</p>
                        </div>
                    </div>

                    <div class="col">
                        <div class="feature-card h-100">
                            <div class="feature-icon risk-icon">
                                <i class="bi bi-geo-alt"></i>
                            </div>

                            <h3>High Risk Detection</h3>

                            <p>AI-powered zone analysis</p>
                        </div>
                    </div>

                    <div class="col">
                        <div class="feature-card h-100">
                            <div class="feature-icon accountability-icon">
                                <i class="bi bi-shield-check"></i>
                            </div>

                            <h3>Public Accountability</h3>

                            <p>Transparent team tracking</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </section>
</main>

<script src="js/global/landing.js"></script>
</body>
</html>