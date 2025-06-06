<?php
/**
 * University of Uyo Facial Attendance System
 * Header Template
 * 
 * @author Your Name
 * @version 1.0
 */

// Include required files
include_once '../config/database.php';
include_once '../config/session.php';

// Prevent direct access
if (!defined('FACIAL_ATTENDANCE_SYSTEM')) {
    define('FACIAL_ATTENDANCE_SYSTEM', true);
}

// Get page title if not set
$page_title = $page_title ?? (defined('SYSTEM_NAME') ? SYSTEM_NAME : 'University of Uyo Facial Attendance System');

// Define color scheme constants if not already defined
if (!defined('PRIMARY_COLOR')) {
    define('PRIMARY_COLOR', '#dc3545'); // Red
}
if (!defined('SECONDARY_COLOR')) {
    define('SECONDARY_COLOR', '#198754'); // Green
}
if (!defined('ACCENT_COLOR')) {
    define('ACCENT_COLOR', '#ffffff'); // White
}

// Define URLs if not already defined
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['PHP_SELF']);
    define('BASE_URL', $protocol . $host . $path . '/');
}

if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . '../assets/');
}

// Get current user info if logged in
$current_user = null;
$user_role = null;
$user_id = null;

if (class_exists('SessionManager')) {
    // Check if methods exist before calling them
    if (method_exists('SessionManager', 'getCurrentUser')) {
        $current_user = SessionManager::getCurrentUser();
    }
    
    if (method_exists('SessionManager', 'getUserRole')) {
        $user_role = SessionManager::getUserRole();
    } else {
    // Fallback: get user info from session directly (match navbar.php)
    $user_role = $_SESSION['role'] ?? null;  // Changed from 'user_role' to 'role'
    
    // Get user_id based on role
    if ($user_role === 'admin') {
        $user_id = $_SESSION['id'] ?? null;
    } elseif ($user_role === 'lecturer') {
        $user_id = $_SESSION['id'] ?? null;
    } elseif ($user_role === 'student') {
        $user_id = $_SESSION['student_id'] ?? null;
    }
    
    $current_user = $_SESSION['user_data'] ?? null;
}
} else {
    // Fallback: get user info from session directly
    $user_role = $_SESSION['user_role'] ?? null;
    $user_id = $_SESSION['user_id'] ?? null;
    $current_user = $_SESSION['user_data'] ?? null;
}

// Determine current page for navigation highlighting
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="description" content="<?php echo htmlspecialchars($page_description ?? 'University of Uyo Facial Attendance System - Advanced biometric attendance tracking system'); ?>">
    <meta name="keywords" content="facial recognition, attendance system, university, biometric, tracking">
    <meta name="author" content="University of Uyo">
    <meta name="robots" content="noindex, nofollow">
    
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php echo ASSETS_URL; ?>images/logo.png">
    <link rel="apple-touch-icon" href="<?php echo ASSETS_URL; ?>images/logo.png">
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="<?php echo ASSETS_URL; ?>css/style.css" rel="stylesheet">
    
    <!-- Additional CSS if specified -->
    <?php if (isset($additional_css) && is_array($additional_css)): ?>
        <?php foreach ($additional_css as $css): ?>
            <link href="<?php echo htmlspecialchars($css); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <style>
        :root {
            --primary-color: <?php echo PRIMARY_COLOR; ?>;
            --secondary-color: <?php echo SECONDARY_COLOR; ?>;
            --accent-color: <?php echo ACCENT_COLOR; ?>;
            --light-gray: #f8f9fa;
            --dark-gray:rgb(17, 17, 17);
            --shadow: 0 2px 4px rgba(0,0,0,0.1);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-gray);
        }
        
        /* Header Styles */
        .navbar-brand img {
            height: 40px;
            width: auto;
            transition: var(--transition);
        }
        
        .navbar-brand img:hover {
            transform: scale(1.05);
        }
        
        /* Custom Button Styles */
        .btn-primary-custom {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            transition: var(--transition);
        }
        
        .btn-primary-custom:hover,
        .btn-primary-custom:focus {
            background-color: #c82333;
            border-color: #bd2130;
            color: white;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        .btn-secondary-custom {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            color: white;
            transition: var(--transition);
        }
        
        .btn-secondary-custom:hover,
        .btn-secondary-custom:focus {
            background-color: #157347;
            border-color: #146c43;
            color: white;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        /* Text Color Classes */
        .text-primary-custom {
            color: var(--primary-color) !important;
        }
        
        .text-secondary-custom {
            color: var(--secondary-color) !important;
        }
        
        /* Background Classes */
        .bg-primary-custom {
            background-color: var(--primary-color) !important;
        }
        
        .bg-secondary-custom {
            background-color: var(--secondary-color) !important;
        }
        
        /* Card Header Custom */
        .card-header-custom {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }
        
        /* Sidebar Styles */
        .sidebar {
            background: linear-gradient(180deg, var(--primary-color), #a71e2a);
            min-height: 100vh;
            box-shadow: var(--shadow);
        }
        
        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.8);
            transition: var(--transition);
            border-radius: var(--border-radius);
            margin: 2px 0;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: white;
            background-color: rgba(255, 255, 255, 0.15);
            transform: translateX(5px);
        }
        
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            background-color: var(--light-gray);
            min-height: 100vh;
            padding: 20px;
        }
        
        /* Session Timer */
        .session-timer {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: var(--border-radius);
            padding: 8px 12px;
            font-size: 0.9em;
            box-shadow: var(--shadow);
        }
        
        /* Webcam Container */
        .webcam-container {
            border: 3px solid var(--primary-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .face-recognition-overlay {
            position: relative;
        }
        
        .face-box {
            position: absolute;
            border: 2px solid var(--secondary-color);
            border-radius: 5px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        /* Loading Spinner */
        .spinner-custom {
            width: 2rem;
            height: 2rem;
            border: 0.25em solid var(--primary-color);
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border 0.75s linear infinite;
        }
        
        /* Alert Enhancements */
        .alert {
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        
        .alert-success {
            background-color: rgba(25, 135, 84, 0.1);
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100vh;
                z-index: 1000;
                transition: left 0.3s ease;
            }
            
            .sidebar.show {
                left: 0;
            }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
        
            .navbar-brand img {
                height: 35px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 10px;
            }
            
            .btn-primary-custom,
            .btn-secondary-custom {
                font-size: 0.9rem;
                padding: 8px 16px;
            }
        }
        
       
        
        /* Dark mode support (optional) */
        @media (prefers-color-scheme: dark) {
            :root {
                --light-gray:rgb(110, 113, 117);
                --dark-gray:rgb(48, 52, 58);
            }
        }
    </style>
</head>
<body>
    

    <!-- Include Navigation Bar -->
    <?php include_once 'navbar.php'; ?>

    <!-- Session timeout warning modal -->
    <div class="modal fade" id="sessionTimeoutModal" tabindex="-1" aria-labelledby="sessionTimeoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="sessionTimeoutModalLabel">
                        <i class="fas fa-exclamation-triangle me-2"></i>Session Timeout Warning
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-clock text-warning fa-3x mb-3"></i>
                        <p class="lead">Your session will expire soon. Would you like to extend it?</p>
                    </div>
                    <div class="progress mb-3" style="height: 10px;">
                        <div class="progress-bar bg-warning progress-bar-striped progress-bar-animated" 
                             id="sessionProgressBar" 
                             role="progressbar" 
                             style="width: 100%" 
                             aria-valuenow="100" 
                             aria-valuemin="0" 
                             aria-valuemax="100"></div>
                    </div>
                    <p class="text-center mb0">
                        Time remaining: <span id="timeRemaining" class="fw-bold text-danger fs-4"></span>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="logout()">
                        <i class="fas fa-sign-out-alt me-1"></i>Logout
                    </button>
                    <button type="button" class="btn btn-primary-custom" onclick="extendSession()">
                        <i class="fas fa-clock me-1"></i>Extend Session
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading overlay -->
    <div id="loadingOverlay" class="position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-75 d-none" style="z-index: 9999;">
        <div class="d-flex justify-content-center align-items-center h-100">
            <div class="text-center text-white">
                <div class="spinner-custom mb-3"></div>
                <h4>Please wait...</h4>
                <p class="text-light">Processing your request</p>
            </div>
        </div>
    </div>

    <!-- Flash messages container -->
    <div id="flashMessagesContainer" class="position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 1050;">
        <?php
        // Check if SessionManager class and method exist
        if (class_exists('SessionManager') && method_exists('SessionManager', 'getFlashMessage')) {
            $flash = SessionManager::getFlashMessage();
        } else {
            // Fallback: check session directly for flash messages
            $flash = null;
            if (isset($_SESSION['flash_message'])) {
                $flash = [
                    'message' => $_SESSION['flash_message'],
                    'type' => $_SESSION['flash_type'] ?? 'info'
                ];
                unset($_SESSION['flash_message']);
                unset($_SESSION['flash_type']);
            }
        }
        
        if ($flash):
        ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show shadow" role="alert">
            <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'exclamation-circle' : 'info-circle'); ?> me-2"></i>
            <strong><?php echo $flash['type'] === 'success' ? 'Success!' : ($flash['type'] === 'danger' ? 'Error!' : 'Info!'); ?></strong>
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php 
            endif;
        ?>
    </div>

    <!-- Global JavaScript variables and functions -->

    
    <script>
        // Global JavaScript variables
        const BASE_URL = '<?php echo BASE_URL; ?>';
        const ASSETS_URL = '<?php echo ASSETS_URL; ?>';
        const USER_ROLE = '<?php echo $user_role ?? ''; ?>';
        const USER_ID = <?php echo $user_id ?? 'null'; ?>;
        const CURRENT_PAGE = '<?php echo $current_page; ?>';
        
        // Session management variables
        let sessionCheckInterval;
        let sessionTimeoutWarningShown = false;
        let sessionCountdownInterval;
        
        
        
        
        
        // Session check function
        function startSessionCheck() {
            if (USER_ID && USER_ID !== null) {
                sessionCheckInterval = setInterval(checkSession, 60000); // Check every minute
            }
        }
        
        function checkSession() {
            fetch(BASE_URL + 'api/auth.php?action=check_session')
                .then(response => response.json())
                .then(data => {
                    if (!data.valid) {
                        clearInterval(sessionCheckInterval);
                        showAlert('Your session has expired. You will be redirected to login.', 'warning');
                        setTimeout(() => {
                            window.location.href = BASE_URL + 'public/login.php';
                        }, 3000);
                    } else if (data.remaining_time < 300 && !sessionTimeoutWarningShown) { // 5 minutes
                        showSessionTimeoutWarning(data.remaining_time);
                    }
                })
                .catch(error => {
                    console.error('Session check error:', error);
                });
        }
        
        function showSessionTimeoutWarning(remainingTime) {
            sessionTimeoutWarningShown = true;
            const modal = new bootstrap.Modal(document.getElementById('sessionTimeoutModal'));
            modal.show();
            
            // Update countdown
            let timeLeft = remainingTime;
            sessionCountdownInterval = setInterval(() => {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                const timeDisplay = `${minutes}:${seconds.toString().padStart(2, '0')}`;
                
                const timeElement = document.getElementById('timeRemaining');
                if (timeElement) {
                    timeElement.textContent = timeDisplay;
                }
                
                const progressPercent = (timeLeft / remainingTime) * 100;
                const progressBar = document.getElementById('sessionProgressBar');
                if (progressBar) {
                    progressBar.style.width = progressPercent + '%';
                    progressBar.setAttribute('aria-valuenow', progressPercent);
                }
                
                timeLeft--;
                
                if (timeLeft <= 0) {
                    clearInterval(sessionCountdownInterval);
                    logout();
                }
            }, 1000);
        }
        
        function extendSession() {
            showLoading();
            fetch(BASE_URL + 'api/auth.php?action=extend_session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    sessionTimeoutWarningShown = false;
                    clearInterval(sessionCountdownInterval);
                    const modal = bootstrap.Modal.getInstance(document.getElementById('sessionTimeoutModal'));
                    if (modal) {
                        modal.hide();
                    }
                    showAlert('Session extended successfully!', 'success');
                } else {
                    showAlert('Failed to extend session. You will be logged out.', 'danger');
                    setTimeout(logout, 2000);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Session extend error:', error);
                showAlert('Network error. You will be logged out.', 'danger');
                setTimeout(logout, 2000);
            });
        }
        
        function logout() {
            showLoading();
            window.location.href = BASE_URL + 'public/logout.php';
        }
        
        // Loading overlay functions
        function showLoading() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.classList.remove('d-none');
            }
        }
        
        function hideLoading() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.classList.add('d-none');
            }
        }
        
        // Alert function
        function showAlert(message, type = 'info', duration = 5000) {
            const container = document.getElementById('flashMessagesContainer');
            if (!container) return;
            
            const alertId = 'alert-' + Date.now();
            const iconClass = type === 'success' ? 'check-circle' : 
                             type === 'danger' ? 'exclamation-circle' : 
                             type === 'warning' ? 'exclamation-triangle' : 'info-circle';
            
            const alertHTML = `
                <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show shadow mb-2" role="alert">
                    <i class="fas fa-${iconClass} me-2"></i>
                    <strong>${type.charAt(0).toUpperCase() + type.slice(1)}!</strong>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', alertHTML);
            
            // Auto-remove after duration
            setTimeout(() => {
                const alert = document.getElementById(alertId);
                if (alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, duration);
        }
        
        // Initialize when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Start session checking
            startSessionCheck();
            
            // Auto-hide existing alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    if (bsAlert) {
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Initialize tooltips if Bootstrap tooltips are available
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
            
            // Initialize popovers if Bootstrap popovers are available
            if (typeof bootstrap !== 'undefined' && bootstrap.Popover) {
                const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
                popoverTriggerList.map(function (popoverTriggerEl) {
                    return new bootstrap.Popover(popoverTriggerEl);
                });
            }
        });
        
        // Handle browser back/forward buttons
        window.addEventListener('popstate', function(event) {
            // You can add custom logic here if needed
        });
        
        // Handle page visibility changes
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                // Page is hidden
                console.log('Page hidden');
            } else {
                // Page is visible
                console.log('Page visible');
                // Optionally refresh session when page becomes visible
                if (USER_ID && USER_ID !== null) {
                    checkSession();
                }
            }
        });
    </script>