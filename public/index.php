<?php
/**
 * University of Uyo Facial Attendance System
 * Main Index Page - Role Selection
 * 
 * @author Your Name
 * @version 1.0
 */

// Start session and include required files
include_once '../config/session.php';
include_once '../includes/header.php';

// Set page title and meta description
$page_title = "University of Uyo Facial Attendance System - Role Selection";
$page_description = "Select your role to access the University of Uyo Facial Attendance System";
?>

<main class="main-content">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="welcome-section text-center py-5">
                    <!-- Logo Section -->
                    <div class="logo-container mb-4">
                        <img src="../assets/images/logo.png" 
                             alt="University of Uyo Logo" 
                             class="university-logo img-fluid" 
                             loading="lazy"
                             onerror="this.style.display='none'">
                    </div>

                    <!-- Title Section -->
                    <div class="title-section mb-4">
                        <h1 class="main-title text-success fw-bold mb-3">
                            University of Uyo
                        </h1>
                        <h2 class="sub-title text-dark mb-3">
                            Facial Attendance System
                        </h2>
                        <p class="lead text-muted">
                            Please select your role to proceed with secure authentication
                        </p>
                    </div>

                    <!-- Role Selection Section -->
                    <div class="role-selection mt-5">
                        <h3 class="section-title text-dark mb-4">Choose Your Role</h3>
                        
                        <div class="row g-3 justify-content-center">
                            <!-- Student Role -->
                            <div class="col-md-4 col-sm-6">
                                <a href="login.php?role=student" 
                                   class="btn btn-success btn-role w-100 p-4 text-decoration-none"
                                   role="button"
                                   aria-label="Login as Student">
                                    <i class="fas fa-user-graduate fa-2x mb-2"></i>
                                    <div class="fw-bold fs-5">Student</div>
                                    <small class="d-block mt-1 opacity-75">Access student portal</small>
                                </a>
                            </div>

                            <!-- Lecturer Role -->
                            <div class="col-md-4 col-sm-6">
                                <a href="login.php?role=lecturer" 
                                   class="btn btn-danger btn-role w-100 p-4 text-decoration-none"
                                   role="button"
                                   aria-label="Login as Lecturer">
                                    <i class="fas fa-chalkboard-teacher fa-2x mb-2"></i>
                                    <div class="fw-bold fs-5">Lecturer</div>
                                    <small class="d-block mt-1 opacity-75">Manage classes & attendance</small>
                                </a>
                            </div>

                            <!-- Admin Role -->
                            <div class="col-md-4 col-sm-6">
                                <a href="login.php?role=admin" 
                                   class="btn btn-light btn-role w-100 p-4 text-decoration-none border-2"
                                   role="button"
                                   aria-label="Login as Administrator">
                                    <i class="fas fa-user-shield fa-2x mb-2 text-dark"></i>
                                    <div class="fw-bold fs-5 text-dark">Admin</div>
                                    <small class="d-block mt-1 text-muted">System administration</small>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Features Section -->
                    <div class="features-section mt-5 pt-4">
                        <div class="row text-center">
                            <div class="col-md-4 mb-3">
                                <div class="feature-item">
                                    <i class="fas fa-face-smile text-success fa-2x mb-2"></i>
                                    <h5 class="text-dark">Facial Recognition</h5>
                                    <p class="text-muted small">Advanced AI-powered attendance tracking</p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="feature-item">
                                    <i class="fas fa-shield-alt text-danger fa-2x mb-2"></i>
                                    <h5 class="text-dark">Secure Access</h5>
                                    <p class="text-muted small">Multi-level authentication system</p>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <div class="feature-item">
                                    <i class="fas fa-chart-line text-success fa-2x mb-2"></i>
                                    <h5 class="text-dark">Real-time Tracking</h5>
                                    <p class="text-muted small">Instant attendance monitoring</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<style>
/* Custom styles for the index page */
.main-content {
    min-height: calc(100vh - 160px);
    display: flex;
    align-items: center;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.university-logo {
    max-width: 120px;
    height: auto;
    filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
    transition: transform 0.3s ease;
}

.university-logo:hover {
    transform: scale(1.05);
}

.main-title {
    font-size: 2.5rem;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
}

.sub-title {
    font-size: 1.5rem;
    font-weight: 500;
}

.btn-role {
    border-radius: 15px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    min-height: 120px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.btn-role:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0,0,0,0.2);
}

.btn-success.btn-role:hover {
    background-color: #157347;
    border-color: #146c43;
}

.btn-danger.btn-role:hover {
    background-color: #bb2d3b;
    border-color: #b02a37;
}

.btn-light.btn-role:hover {
    background-color: #f8f9fa;
    border-color: #6c757d;
    color: #495057;
}

.feature-item {
    padding: 1rem;
    border-radius: 10px;
    transition: transform 0.3s ease;
}

.feature-item:hover {
    transform: translateY(-3px);
}

.section-title {
    position: relative;
    display: inline-block;
}

.section-title::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 50%;
    transform: translateX(-50%);
    width: 50px;
    height: 3px;
    background: linear-gradient(to right, #198754, #dc3545);
    border-radius: 2px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .main-title {
        font-size: 2rem;
    }
    
    .sub-title {
        font-size: 1.25rem;
    }
    
    .btn-role {
        min-height: 100px;
        padding: 1.5rem;
    }
    
    .university-logo {
        max-width: 80px;
    }
}

@media (max-width: 576px) {
    .btn-role {
        min-height: 80px;
        padding: 1rem;
    }
    
    .btn-role i {
        font-size: 1.5rem !important;
    }
    
    .btn-role .fs-5 {
        font-size: 1.1rem !important;
    }
}

/* Loading animation for logo */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.welcome-section {
    animation: fadeInUp 0.8s ease-out;
}

/* Focus states for accessibility */
.btn-role:focus {
    outline: 3px solid rgba(13, 110, 253, 0.25);
    outline-offset: 2px;
}
</style>

<?php
include_once '../includes/footer.php';
?>