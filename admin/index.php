<?php
session_start();
require_once '../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?role=admin');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$admin_id = $_SESSION['admin_id'];

// Get admin information
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = :admin_id");
$stmt->execute(['admin_id' => $admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header('Location: login.php?role=admin');
    exit;
}

// Get dashboard statistics
// Total counts
$stats = [];

// Total Students
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM students WHERE is_active = 1");
$stmt->execute();
$stats['total_students'] = $stmt->fetch()['total'];

// Total Lecturers
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM lecturers WHERE is_active = 1");
$stmt->execute();
$stats['total_lecturers'] = $stmt->fetch()['total'];

// Total Courses
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM courses WHERE is_active = 1");
$stmt->execute();
$stats['total_courses'] = $stmt->fetch()['total'];

// Total Faculties
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM faculties");
$stmt->execute();
$stats['total_faculties'] = $stmt->fetch()['total'];

// Total Departments
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM departments");
$stmt->execute();
$stats['total_departments'] = $stmt->fetch()['total'];

// Active Attendance Sessions Today
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM attendance_sessions 
    WHERE DATE(session_date) = CURDATE() 
    AND status = 'active'
");
$stmt->execute();
$stats['active_sessions'] = $stmt->fetch()['total'];

// Total Attendance Records Today
$stmt = $conn->prepare("
    SELECT COUNT(*) as total 
    FROM attendance a
    JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
    WHERE DATE(ats.session_date) = CURDATE()
");
$stmt->execute();
$stats['today_attendance'] = $stmt->fetch()['total'];

// Recent System Activities (Last 10)
$stmt = $conn->prepare("
    SELECT 
        'New Student' as activity_type,
        CONCAT(s.firstname, ' ', s.surname) as description,
        s.created_at as activity_time,
        'student' as icon_type
    FROM students s 
    WHERE s.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    
    UNION ALL
    
    SELECT 
        'New Lecturer' as activity_type,
        CONCAT(l.firstname, ' ', l.surname) as description,
        l.created_at as activity_time,
        'lecturer' as icon_type
    FROM lecturers l 
    WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    
    UNION ALL
    
    SELECT 
        'Attendance Session' as activity_type,
        CONCAT(c.code, ' - ', c.name) as description,
        ats.created_at as activity_time,
        'attendance' as icon_type
    FROM attendance_sessions ats
    JOIN courses c ON ats.course_id = c.id
    WHERE ats.created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
    
    ORDER BY activity_time DESC
    LIMIT 10
");
$stmt->execute();
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Faculty Statistics
$stmt = $conn->prepare("
    SELECT 
        f.name as faculty_name,
        f.code as faculty_code,
        COUNT(DISTINCT d.id) as departments_count,
        COUNT(DISTINCT s.id) as students_count,
        COUNT(DISTINCT l.id) as lecturers_count
    FROM faculties f
    LEFT JOIN departments d ON f.id = d.faculty_id
    LEFT JOIN students s ON f.id = s.faculty_id AND s.is_active = 1
    LEFT JOIN lecturers l ON f.id = l.faculty_id AND l.is_active = 1
    GROUP BY f.id, f.name, f.code
    ORDER BY students_count DESC
    LIMIT 5
");
$stmt->execute();
$faculty_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's Attendance Statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_records,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
    FROM attendance a
    JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
    WHERE DATE(ats.session_date) = CURDATE()
");
$stmt->execute();
$today_attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent Course Registrations
$stmt = $conn->prepare("
    SELECT 
        CONCAT(s.firstname, ' ', s.surname) as student_name,
        s.reg_number,
        c.code as course_code,
        c.name as course_name,
        sc.created_at
    FROM student_courses sc
    JOIN students s ON sc.student_id = s.id
    JOIN courses c ON sc.course_id = c.id
    WHERE sc.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY sc.created_at DESC
    LIMIT 5
");
$stmt->execute();
$recent_registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include_once '../includes/header.php'; ?>

<style>
.admin-profile-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    transition: transform 0.3s ease;
    border: none;
    height: 100%;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card.students {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-card.lecturers {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.stat-card.courses {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.stat-card.faculties {
    background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
    color: #333;
}

.stat-card.departments {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
    color: #333;
}

.stat-card.sessions {
    background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
    color: #333;
}

.stat-card h3 {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 10px;
}

.quick-action-card {
    border: none;
    border-radius: 15px;
    transition: all 0.3s ease;
    height: 100%;
}

.quick-action-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.quick-action-icon {
    font-size: 3rem;
    margin-bottom: 15px;
}

.welcome-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
}

.card-header-custom {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
}

.activity-item {
    border-left: 3px solid;
    padding-left: 15px;
    margin-bottom: 15px;
}

.activity-item.student { border-left-color: #007bff; }
.activity-item.lecturer { border-left-color: #28a745; }
.activity-item.attendance { border-left-color: #ffc107; }

.progress-thin {
    height: 8px;
}

.faculty-card {
    border-left: 4px solid #007bff;
    transition: all 0.3s ease;
}

.faculty-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.attendance-summary {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 10px;
    padding: 20px;
}
</style>

<main class="container-fluid py-4">
    <!-- Welcome Header -->
    <div class="welcome-header">
        <div class="row align-items-center">
            <div class="col-md-2">
                <div class="admin-profile-img mx-auto bg-white bg-opacity-20 d-flex align-items-center justify-content-center">
                    <i class="fas fa-user-shield fa-2x text-white"></i>
                </div>
            </div>
            <div class="col-md-10 text-start">
                <h2 class="mb-1">Welcome, <?= htmlspecialchars($admin['full_name']) ?></h2>
                <p class="mb-1">System Administrator | <?= htmlspecialchars($admin['username']) ?></p>
                <p class="mb-0">Last login: <?= $admin['last_login'] ? date('M j, Y g:i A', strtotime($admin['last_login'])) : 'First time login' ?></p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stat-card students">
                <i class="fas fa-user-graduate fa-2x mb-2"></i>
                <h3><?= number_format($stats['total_students']) ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stat-card lecturers">
                <i class="fas fa-chalkboard-teacher fa-2x mb-2"></i>
                <h3><?= number_format($stats['total_lecturers']) ?></h3>
                <p class="mb-0">Total Lecturers</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stat-card courses">
                <i class="fas fa-book fa-2x mb-2"></i>
                <h3><?= number_format($stats['total_courses']) ?></h3>
                <p class="mb-0">Total Courses</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stat-card faculties">
                <i class="fas fa-university fa-2x mb-2"></i>
                <h3><?= number_format($stats['total_faculties']) ?></h3>
                <p class="mb-0">Faculties</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stat-card departments">
                <i class="fas fa-building fa-2x mb-2"></i>
                <h3><?= number_format($stats['total_departments']) ?></h3>
                <p class="mb-0">Departments</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
            <div class="stat-card sessions">
                <i class="fas fa-calendar-check fa-2x mb-2"></i>
                <h3><?= number_format($stats['active_sessions']) ?></h3>
                <p class="mb-0">Active Sessions</p>
                <small>Today</small>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-3"><i class="fas fa-bolt text-warning"></i> Quick Actions</h4>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-primary">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <h6>Add Student</h6>
                    <p class="text-muted small mb-3">Register new student</p>
                    <a href="students.php?action=add" class="btn btn-sm btn-primary">Add Student</a>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-success">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <h6>Add Lecturer</h6>
                    <p class="text-muted small mb-3">Register new lecturer</p>
                    <a href="lecturers.php?action=add" class="btn btn-sm btn-success">Add Lecturer</a>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-info">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h6>Add Course</h6>
                    <p class="text-muted small mb-3">Create new course</p>
                    <a href="courses.php?action=add" class="btn btn-sm btn-info">Add Course</a>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-warning">
                        <i class="fas fa-building"></i>
                    </div>
                    <h6>Add Department</h6>
                    <p class="text-muted small mb-3">Create department</p>
                    <a href="departments.php?action=add" class="btn btn-sm btn-warning">Add Dept</a>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-secondary">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <h6>Reports</h6>
                    <p class="text-muted small mb-3">View system reports</p>
                    <a href="reports.php" class="btn btn-sm btn-secondary">View Reports</a>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-dark">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <h6>Settings</h6>
                    <p class="text-muted small mb-3">System settings</p>
                    <a href="settings.php" class="btn btn-sm btn-dark">Settings</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Activities -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-activity text-primary"></i> Recent Activities</h5>
                </div>
                <div class="card-body">
                    <?php if ($recent_activities): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity-item <?= $activity['icon_type'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($activity['activity_type']) ?></strong><br>
                                        <span class="text-muted"><?= htmlspecialchars($activity['description']) ?></span>
                                    </div>
                                    <small class="text-muted">
                                        <?= date('M j, g:i A', strtotime($activity['activity_time'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No recent activities</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Today's Attendance Summary -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-calendar-day text-success"></i> Today's Attendance Summary</h5>
                </div>
                <div class="card-body">
                    <?php if ($today_attendance_stats['total_records'] > 0): ?>
                        <div class="attendance-summary">
                            <div class="row text-center mb-3">
                                <div class="col-3">
                                    <h4 class="text-success mb-1"><?= $today_attendance_stats['present_count'] ?></h4>
                                    <small class="text-muted">Present</small>
                                </div>
                                <div class="col-3">
                                    <h4 class="text-warning mb-1"><?= $today_attendance_stats['late_count'] ?></h4>
                                    <small class="text-muted">Late</small>
                                </div>
                                <div class="col-3">
                                    <h4 class="text-danger mb-1"><?= $today_attendance_stats['absent_count'] ?></h4>
                                    <small class="text-muted">Absent</small>
                                </div>
                                <div class="col-3">
                                    <h4 class="text-primary mb-1"><?= $today_attendance_stats['total_records'] ?></h4>
                                    <small class="text-muted">Total</small>
                                </div>
                            </div>
                            
                            <?php 
                            $attendance_rate = $today_attendance_stats['total_records'] > 0 
                                ? round(($today_attendance_stats['present_count'] / $today_attendance_stats['total_records']) * 100, 1) 
                                : 0;
                            ?>
                            
                            <div class="mb-2">
                                <div class="d-flex justify-content-between">
                                    <span>Overall Attendance Rate</span>
                                    <span><strong><?= $attendance_rate ?>%</strong></span>
                                </div>
                                <div class="progress progress-thin">
                                    <div class="progress-bar bg-success" style="width: <?= $attendance_rate ?>%"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3">
                            <a href="attendance_reports.php?date=<?= date('Y-m-d') ?>" class="btn btn-outline-primary btn-sm">
                                View Detailed Report
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No attendance records for today</p>
                            <p class="small text-muted">Records will appear as students attend classes</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Faculty Overview -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-university text-info"></i> Faculty Overview</h5>
                </div>
                <div class="card-body">
                    <?php if ($faculty_stats): ?>
                        <div class="row">
                            <?php foreach ($faculty_stats as $faculty): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card faculty-card">
                                        <div class="card-body">
                                            <h6 class="card-title"><?= htmlspecialchars($faculty['faculty_name']) ?></h6>
                                            <p class="card-text text-muted"><?= htmlspecialchars($faculty['faculty_code']) ?></p>
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <strong><?= $faculty['departments_count'] ?></strong><br>
                                                    <small class="text-muted">Departments</small>
                                                </div>
                                                <div class="col-4">
                                                    <strong><?= $faculty['students_count'] ?></strong><br>
                                                    <small class="text-muted">Students</small>
                                                </div>
                                                <div class="col-4">
                                                    <strong><?= $faculty['lecturers_count'] ?></strong><br>
                                                    <small class="text-muted">Lecturers</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center">
                            <a href="faculties.php" class="btn btn-outline-info">Manage All Faculties</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-university fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No faculty data available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Recent Course Registrations -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-user-plus text-warning"></i> Recent Registrations</h5>
                </div>
                <div class="card-body">
                    <?php if ($recent_registrations): ?>
                        <?php foreach ($recent_registrations as $registration): ?>
                            <div class="d-flex align-items-center mb-3 p-2 bg-light rounded">
                                <div class="flex-grow-1">
                                    <strong><?= htmlspecialchars($registration['student_name']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($registration['reg_number']) ?></small><br>
                                    <small class="text-primary"><?= htmlspecialchars($registration['course_code']) ?></small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <?= date('M j', strtotime($registration['created_at'])) ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center">
                            <a href="course_registrations.php" class="btn btn-sm btn-outline-warning">View All</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-plus fa-2x text-muted mb-3"></i>
                            <p class="text-muted">No recent registrations</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- System Info & Logout -->
    <div class="row mt-4">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <i class="fas fa-server text-primary fa-2x mb-2"></i>
                            <h6>System Status</h6>
                            <span class="badge bg-success">Online</span>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-database text-info fa-2x mb-2"></i>
                            <h6>Database</h6>
                            <span class="badge bg-success">Connected</span>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-clock text-warning fa-2x mb-2"></i>
                            <h6>Server Time</h6>
                            <small><?= date('Y-m-d H:i:s') ?></small>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-shield-alt text-success fa-2x mb-2"></i>
                            <h6>Security</h6>
                            <span class="badge bg-success">Active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 d-flex align-items-center justify-content-center">
            <a href="logout.php" class="btn btn-outline-danger btn-lg">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</main>

<?php include_once '../includes/footer.php'; ?>