<?php
// Prevent direct access
if (!defined('FACIAL_ATTENDANCE_SYSTEM')) {
    die('Direct access not permitted');
}


// Initialize variables
$is_logged_in = false;
$user_firstname = '';
$user_fullname = '';
$user_role = '';
$user_avatar = ASSETS_URL . 'images/default-avatar.png';
$dashboard_url = BASE_URL . 'public/';

// Check if any user is logged in
if (isset($_SESSION['role'])) {
    $is_logged_in = true;
    $user_role = $_SESSION['role'];
    
    // Get user details based on role
    if ($_SESSION['role'] === 'admin' && isset($_SESSION['admin_id'])) {
        $user_firstname = isset($_SESSION['admin_firstname']) ? $_SESSION['admin_firstname'] : 
                         (isset($_SESSION['admin_name']) ? explode(' ', $_SESSION['admin_name'])[0] : 'Admin');
        $user_fullname = isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 
                        (isset($_SESSION['admin_firstname']) && isset($_SESSION['admin_lastname']) ? 
                         $_SESSION['admin_firstname'] . ' ' . $_SESSION['admin_lastname'] : 'Administrator');
        $user_role = 'Admin';
        $dashboard_url = BASE_URL . 'admin/';
        if (isset($_SESSION['admin_avatar'])) {
            $user_avatar = $_SESSION['admin_avatar'];
        }
    } 
    elseif ($_SESSION['role'] === 'lecturer' && isset($_SESSION['lecturer_id'])) {
        // Use the actual session structure
        $user_fullname = isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Lecturer';
        
        // Extract first name from full name
        $name_parts = explode(' ', $user_fullname);
        $user_firstname = $name_parts[0];
        
        $user_role = 'Lecturer';
        $dashboard_url = BASE_URL . 'lecturer/';
        if (isset($_SESSION['lecturer_avatar'])) {
            $user_avatar = $_SESSION['lecturer_avatar'];
        }
    } 
    elseif ($_SESSION['role'] === 'student' && isset($_SESSION['student_id'])) {
        // Use the actual session structure  
        $user_fullname = isset($_SESSION['user']['name']) ? $_SESSION['user']['name'] : 'Student';
        
        // Extract first name from full name
        $name_parts = explode(' ', $user_fullname);
        $user_firstname = $name_parts[0];
        
        $user_role = 'Student';
        $dashboard_url = BASE_URL . 'student/';
        if (isset($_SESSION['student_avatar'])) {
            $user_avatar = $_SESSION['student_avatar'];
        }
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark bg-secondary-custom">
    <div class="container-fluid">
        <!-- Brand -->
        <a class="navbar-brand d-flex align-items-center" href="<?php echo $dashboard_url; ?>">
            <img src="<?php echo ASSETS_URL; ?>images/logo.png" alt="Logo" class="me-2" style="height: 30px;">
            <span class="d-none d-md-inline"><?php echo defined('UNIVERSITY_SHORT') ? UNIVERSITY_SHORT : 'University'; ?> Attendance</span>
        </a>

        <!-- Toggler -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar items -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <?php if ($is_logged_in): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>admin/"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="manageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-cog"></i> Manage
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="manageDropdown">
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/faculties.php">Faculties</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/departments.php">Departments</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/courses.php">Courses</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/students.php">Students</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/lecturers.php">Lecturers</a></li>
                            </ul>
                        </li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>admin/attendance.php"><i class="fas fa-chart-bar"></i> Attendance</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>admin/reports.php"><i class="fas fa-file-alt"></i> Reports</a></li>

                    <?php elseif ($_SESSION['role'] === 'lecturer'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>lecturer/"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>lecturer/courses.php"><i class="fas fa-book"></i> My Courses</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>lecturer/mark-attendance.php"><i class="fas fa-users"></i> Attendance </a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>lecturer/reports.php"><i class="fas fa-chart-line"></i> Reports</a></li>

                    <?php elseif ($_SESSION['role'] === 'student'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>student/"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>student/courses.php"><i class="fas fa-book"></i> My Courses</a></li>
                         <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>student/attendance.php"><i class="fas fa-calendar-check"></i> My Attendance</a></li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>

            <!-- Right side -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php if ($is_logged_in): ?>
                    <!-- User Info and Logout -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($user_avatar); ?>" alt="Avatar" class="rounded-circle me-2" width="30" height="30" onerror="this.src='<?php echo ASSETS_URL; ?>images/default-avatar.png';">
                            <span class="text-white"><?php echo htmlspecialchars($user_firstname); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><h6 class="dropdown-header"><?php echo htmlspecialchars($user_fullname); ?></h6></li>
                            <li><span class="dropdown-item-text small text-muted"><?php echo htmlspecialchars(ucfirst($user_role)); ?></span></li>
                            <li><hr class="dropdown-divider"></li>
                            
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/settings.php"><i class="fas fa-cog"></i> System Settings</a></li>
                            <?php elseif ($_SESSION['role'] === 'lecturer'): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>lecturer/profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>lecturer/settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                            <?php elseif ($_SESSION['role'] === 'student'): ?>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>student/profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>student/settings.php"><i class="fas fa-cog"></i> Settings</a></li>
                            <?php endif; ?>
                            
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>student/logout.php" onclick="return confirm('Are you sure you want to logout?')">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <!-- Login Options for Non-logged in users -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="loginDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="loginDropdown">
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>public/login.php?role=student">
                                <i class="fas fa-user-graduate"></i> Student Login
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>public/login.php?role=lecturer">
                                <i class="fas fa-chalkboard-teacher"></i> Lecturer Login
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>public/login.php?role=admin">
                                <i class="fas fa-user-shield"></i> Admin Login
                            </a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<script>
    // Logout confirmation
    function confirmLogout() {
        return confirm('Are you sure you want to logout?');
    }
</script>