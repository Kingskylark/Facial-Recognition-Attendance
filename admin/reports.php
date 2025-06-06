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

// Get filter parameters
$report_type = $_GET['report_type'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$faculty_id = $_GET['faculty_id'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$course_id = $_GET['course_id'] ?? '';

// Get faculties for filter dropdown
$stmt = $conn->prepare("SELECT id, name, code FROM faculties ORDER BY name");
$stmt->execute();
$faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter dropdown
$dept_query = "SELECT d.id, d.name, d.code, f.name as faculty_name FROM departments d 
               LEFT JOIN faculties f ON d.faculty_id = f.id ORDER BY f.name, d.name";
$stmt = $conn->prepare($dept_query);
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get courses for filter dropdown
$course_query = "SELECT c.id, c.name, c.code, f.name as faculty_name FROM courses c 
                 LEFT JOIN faculties f ON c.faculty_id = f.id WHERE c.is_active = 1 ORDER BY f.name, c.name";
$stmt = $conn->prepare($course_query);
$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate reports based on type
switch ($report_type) {
    case 'attendance_summary':
        $report_data = generateAttendanceSummaryReport($conn, $date_from, $date_to, $faculty_id, $department_id, $course_id);
        break;
    case 'student_attendance':
        $report_data = generateStudentAttendanceReport($conn, $date_from, $date_to, $faculty_id, $department_id, $course_id);
        break;
    case 'course_statistics':
        $report_data = generateCourseStatisticsReport($conn, $date_from, $date_to, $faculty_id, $department_id);
        break;
    case 'lecturer_performance':
        $report_data = generateLecturerPerformanceReport($conn, $date_from, $date_to, $faculty_id, $department_id);
        break;
    case 'registration_trends':
        $report_data = generateRegistrationTrendsReport($conn, $date_from, $date_to, $faculty_id, $department_id);
        break;
    case 'defaulters':
        $report_data = generateDefaultersReport($conn, $date_from, $date_to, $faculty_id, $department_id, $course_id);
        break;
    default:
        $report_data = generateOverviewReport($conn, $date_from, $date_to);
        break;
}

// Report Generation Functions
function generateOverviewReport($conn, $date_from, $date_to) {
    $data = [];
    
    // Overall Statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT s.id) as total_students,
            COUNT(DISTINCT l.id) as total_lecturers,
            COUNT(DISTINCT c.id) as total_courses,
            COUNT(DISTINCT f.id) as total_faculties,
            COUNT(DISTINCT d.id) as total_departments
        FROM students s
        CROSS JOIN lecturers l
        CROSS JOIN courses c  
        CROSS JOIN faculties f
        CROSS JOIN departments d
        WHERE s.is_active = 1 AND l.is_active = 1 AND c.is_active = 1
    ");
    $stmt->execute();
    $data['overview'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Attendance Statistics for the period
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
        FROM attendance a
        JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
        WHERE DATE(ats.session_date) BETWEEN :date_from AND :date_to
    ");
    $stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
    $data['attendance_stats'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Faculty-wise breakdown
    $stmt = $conn->prepare("
        SELECT 
            f.name as faculty_name,
            COUNT(DISTINCT s.id) as student_count,
            COUNT(DISTINCT l.id) as lecturer_count,
            COUNT(DISTINCT c.id) as course_count,
            COUNT(DISTINCT d.id) as department_count
        FROM faculties f
        LEFT JOIN students s ON f.id = s.faculty_id AND s.is_active = 1
        LEFT JOIN lecturers l ON f.id = l.faculty_id AND l.is_active = 1
        LEFT JOIN courses c ON f.id = c.faculty_id AND c.is_active = 1
        LEFT JOIN departments d ON f.id = d.faculty_id
        GROUP BY f.id, f.name
        ORDER BY student_count DESC
    ");
    $stmt->execute();
    $data['faculty_breakdown'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

function generateAttendanceSummaryReport($conn, $date_from, $date_to, $faculty_id, $department_id, $course_id) {
    $where_conditions = ["DATE(ats.session_date) BETWEEN :date_from AND :date_to"];
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($faculty_id) {
        $where_conditions[] = "c.faculty_id = :faculty_id";
        $params['faculty_id'] = $faculty_id;
    }
    if ($department_id) {
        $where_conditions[] = "c.department_id = :department_id";
        $params['department_id'] = $department_id;
    }
    if ($course_id) {
        $where_conditions[] = "c.id = :course_id";
        $params['course_id'] = $course_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            c.code as course_code,
            c.name as course_name,
            CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
            f.name as faculty_name,
            COUNT(DISTINCT ats.id) as total_sessions,
            COUNT(a.id) as total_attendance_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
        FROM courses c
        JOIN attendance_sessions ats ON c.id = ats.course_id
        LEFT JOIN attendance a ON ats.id = a.attendance_session_id
        LEFT JOIN lecturers l ON c.lecturer_id = l.id
        LEFT JOIN faculties f ON c.faculty_id = f.id
        WHERE $where_clause
        GROUP BY c.id, c.code, c.name, l.firstname, l.surname, f.name
        ORDER BY attendance_rate DESC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateStudentAttendanceReport($conn, $date_from, $date_to, $faculty_id, $department_id, $course_id) {
    $where_conditions = ["DATE(ats.session_date) BETWEEN :date_from AND :date_to"];
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($faculty_id) {
        $where_conditions[] = "s.faculty_id = :faculty_id";
        $params['faculty_id'] = $faculty_id;
    }
    if ($department_id) {
        $where_conditions[] = "s.department_id = :department_id";
        $params['department_id'] = $department_id;
    }
    if ($course_id) {
        $where_conditions[] = "c.id = :course_id";
        $params['course_id'] = $course_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            s.reg_number,
            CONCAT(s.firstname, ' ', s.surname) as student_name,
            f.name as faculty_name,
            d.name as department_name,
            COUNT(a.id) as total_records,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
        FROM students s
        LEFT JOIN attendance a ON s.id = a.student_id
        LEFT JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
        LEFT JOIN courses c ON ats.course_id = c.id
        LEFT JOIN faculties f ON s.faculty_id = f.id
        LEFT JOIN departments d ON s.department_id = d.id
        WHERE s.is_active = 1 AND $where_clause
        GROUP BY s.id, s.reg_number, s.firstname, s.surname, f.name, d.name
        HAVING total_records > 0
        ORDER BY attendance_rate ASC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateCourseStatisticsReport($conn, $date_from, $date_to, $faculty_id, $department_id) {
    $where_conditions = ["c.is_active = 1"];
    $params = [];
    
    if ($faculty_id) {
        $where_conditions[] = "c.faculty_id = :faculty_id";
        $params['faculty_id'] = $faculty_id;
    }
    if ($department_id) {
        $where_conditions[] = "c.department_id = :department_id";
        $params['department_id'] = $department_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            c.code as course_code,
            c.name as course_name,
            CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
            f.name as faculty_name,
            d.name as department_name,
            COUNT(DISTINCT sc.student_id) as enrolled_students,
            COUNT(DISTINCT ats.id) as total_sessions,
            COALESCE(AVG(session_attendance.attendance_rate), 0) as avg_attendance_rate
        FROM courses c
        LEFT JOIN lecturers l ON c.lecturer_id = l.id
        LEFT JOIN faculties f ON c.faculty_id = f.id
        LEFT JOIN departments d ON c.department_id = d.id
        LEFT JOIN student_courses sc ON c.id = sc.course_id
        LEFT JOIN attendance_sessions ats ON c.id = ats.course_id
        LEFT JOIN (
            SELECT 
                ats.id as session_id,
                ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
            FROM attendance_sessions ats
            LEFT JOIN attendance a ON ats.id = a.attendance_session_id
            WHERE DATE(ats.session_date) BETWEEN :date_from AND :date_to
            GROUP BY ats.id
        ) session_attendance ON ats.id = session_attendance.session_id
        WHERE $where_clause
        GROUP BY c.id, c.code, c.name, l.firstname, l.surname, f.name, d.name
        ORDER BY enrolled_students DESC
    ");
    $params['date_from'] = $date_from;
    $params['date_to'] = $date_to;
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateLecturerPerformanceReport($conn, $date_from, $date_to, $faculty_id, $department_id) {
    $where_conditions = ["l.is_active = 1", "DATE(ats.session_date) BETWEEN :date_from AND :date_to"];
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($faculty_id) {
        $where_conditions[] = "l.faculty_id = :faculty_id";
        $params['faculty_id'] = $faculty_id;
    }
    if ($department_id) {
        $where_conditions[] = "l.department_id = :department_id";
        $params['department_id'] = $department_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
            f.name as faculty_name,
            d.name as department_name,
            COUNT(DISTINCT c.id) as courses_taught,
            COUNT(DISTINCT ats.id) as sessions_conducted,
            COUNT(DISTINCT sc.student_id) as total_students_taught,
            COALESCE(AVG(session_stats.attendance_rate), 0) as avg_attendance_rate
        FROM lecturers l
        LEFT JOIN faculties f ON l.faculty_id = f.id
        LEFT JOIN departments d ON l.department_id = d.id
        LEFT JOIN courses c ON l.id = c.lecturer_id
        LEFT JOIN attendance_sessions ats ON c.id = ats.course_id
        LEFT JOIN student_courses sc ON c.id = sc.course_id
        LEFT JOIN (
            SELECT 
                ats.id as session_id,
                ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
            FROM attendance_sessions ats
            LEFT JOIN attendance a ON ats.id = a.attendance_session_id
            GROUP BY ats.id
        ) session_stats ON ats.id = session_stats.session_id
        WHERE $where_clause
        GROUP BY l.id, l.firstname, l.surname, f.name, d.name
        HAVING sessions_conducted > 0
        ORDER BY avg_attendance_rate DESC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateRegistrationTrendsReport($conn, $date_from, $date_to, $faculty_id, $department_id) {
    $where_conditions = ["DATE(sc.created_at) BETWEEN :date_from AND :date_to"];
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($faculty_id) {
        $where_conditions[] = "c.faculty_id = :faculty_id";
        $params['faculty_id'] = $faculty_id;
    }
    if ($department_id) {
        $where_conditions[] = "c.department_id = :department_id";
        $params['department_id'] = $department_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(sc.created_at) as registration_date,
            COUNT(*) as daily_registrations,
            COUNT(DISTINCT sc.student_id) as unique_students,
            COUNT(DISTINCT sc.course_id) as courses_registered
        FROM student_courses sc
        JOIN courses c ON sc.course_id = c.id
        WHERE $where_clause
        GROUP BY DATE(sc.created_at)
        ORDER BY registration_date DESC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function generateDefaultersReport($conn, $date_from, $date_to, $faculty_id, $department_id, $course_id) {
    $where_conditions = ["DATE(ats.session_date) BETWEEN :date_from AND :date_to"];
    $params = ['date_from' => $date_from, 'date_to' => $date_to];
    
    if ($faculty_id) {
        $where_conditions[] = "s.faculty_id = :faculty_id";
        $params['faculty_id'] = $faculty_id;
    }
    if ($department_id) {
        $where_conditions[] = "s.department_id = :department_id";
        $params['department_id'] = $department_id;
    }
    if ($course_id) {
        $where_conditions[] = "c.id = :course_id";
        $params['course_id'] = $course_id;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $stmt = $conn->prepare("
        SELECT 
            s.reg_number,
            CONCAT(s.firstname, ' ', s.surname) as student_name,
            f.name as faculty_name,
            d.name as department_name,
            c.code as course_code,
            c.name as course_name,
            COUNT(a.id) as total_classes,
            SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as classes_attended,
            SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as classes_missed,
            ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 2) as attendance_rate
        FROM students s
        JOIN attendance a ON s.id = a.student_id
        JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
        JOIN courses c ON ats.course_id = c.id
        LEFT JOIN faculties f ON s.faculty_id = f.id
        LEFT JOIN departments d ON s.department_id = d.id
        WHERE s.is_active = 1 AND $where_clause
        GROUP BY s.id, s.reg_number, s.firstname, s.surname, f.name, d.name, c.id, c.code, c.name
        HAVING attendance_rate < 75
        ORDER BY attendance_rate ASC
    ");
    $stmt->execute($params);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php include_once '../includes/header.php'; ?>

<style>
.report-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 25px;
}

.filter-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
}

.report-card {
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 25px;
}

.report-card .card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    border-radius: 10px 10px 0 0 !important;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stat-box {
    background: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-left: 4px solid;
}

.stat-box.primary { border-left-color: #007bff; }
.stat-box.success { border-left-color: #28a745; }
.stat-box.warning { border-left-color: #ffc107; }
.stat-box.danger { border-left-color: #dc3545; }
.stat-box.info { border-left-color: #17a2b8; }

.table-responsive {
    border-radius: 10px;
    overflow: hidden;
}

.btn-group-custom .btn {
    margin-right: 5px;
    margin-bottom: 5px;
}

.attendance-rate-bar {
    width: 100%;
    height: 20px;
    background: #f8f9fa;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.attendance-rate-fill {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

.rate-excellent { background: #28a745; }
.rate-good { background: #17a2b8; }
.rate-average { background: #ffc107; }
.rate-poor { background: #dc3545; }

@media print {
    .filter-card, .btn, .no-print {
        display: none !important;
    }
    .report-card {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}
</style>

<main class="container-fluid py-4">
    <!-- Report Header -->
    <div class="report-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-1"><i class="fas fa-chart-line"></i> System Reports</h2>
                <p class="mb-0">Comprehensive analytics and reporting dashboard</p>
            </div>
            <div class="col-md-4 text-end">
                <button onclick="window.print()" class="btn btn-light no-print">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <button onclick="exportToCSV()" class="btn btn-light no-print">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card no-print">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label"><strong>Report Type</strong></label>
                <select name="report_type" class="form-select" onchange="this.form.submit()">
                    <option value="overview" <?= $report_type === 'overview' ? 'selected' : '' ?>>System Overview</option>
                    <option value="attendance_summary" <?= $report_type === 'attendance_summary' ? 'selected' : '' ?>>Attendance Summary</option>
                    <option value="student_attendance" <?= $report_type === 'student_attendance' ? 'selected' : '' ?>>Student Attendance</option>
                    <option value="course_statistics" <?= $report_type === 'course_statistics' ? 'selected' : '' ?>>Course Statistics</option>
                    <option value="lecturer_performance" <?= $report_type === 'lecturer_performance' ? 'selected' : '' ?>>Lecturer Performance</option>
                    <option value="registration_trends" <?= $report_type === 'registration_trends' ? 'selected' : '' ?>>Registration Trends</option>
                    <option value="defaulters" <?= $report_type === 'defaulters' ? 'selected' : '' ?>>Attendance Defaulters</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><strong>From Date</strong></label>
                <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><strong>To Date</strong></label>
                <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label"><strong>Faculty</strong></label>
                <select name="faculty_id" class="form-select">
                    <option value="">All Faculties</option>
                    <?php foreach ($faculties as $faculty): ?>
                        <option value="<?= $faculty['id'] ?>" <?= $faculty_id == $faculty['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($faculty['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label"><strong>Department</strong></label>
                <select name="department_id" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department_id == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </form>
    </div>

    <!-- Report Content -->
    <?php if ($report_type === 'overview'): ?>
        <!-- System Overview Report -->
        <div class="stats-grid">
            <div class="stat-box primary">
                <h3><?= number_format($report_data['overview']['total_students']) ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
            <div class="stat-box success">
                <h3><?= number_format($report_data['overview']['total_lecturers']) ?></h3>
                <p class="mb-0">Total Lecturers</p>
            </div>
            <div class="stat-box info">
                <h3><?= number_format($report_data['overview']['total_courses']) ?></h3>
                <p class="mb-0">Total Courses</p>
            </div>
            <div class="stat-box warning">
                <h3><?= number_format($report_data['overview']['total_faculties']) ?></h3>
                <p class="mb-0">Faculties</p>
            </div>
            <div class="stat-box danger">
                <h3><?= number_format($report_data['overview']['total_departments']) ?></h3>
                <p class="mb-0">Departments</p>
            </div>
        </div>

        <?php if ($report_data['attendance_stats']['total_records'] > 0): ?>
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Attendance Summary (<?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h4 class="text-success"><?= number_format($report_data['attendance_stats']['present_count']) ?></h4>
                        <p class="text-muted">Present</p>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-warning"><?= number_format($report_data['attendance_stats']['late_count']) ?></h4>
                        <p class="text-muted">Late</p>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-danger"><?= number_format($report_data['attendance_stats']['absent_count']) ?></h4>
                        <p class="text-muted">Absent</p>
                    </div>
                    <div class="col-md-3">
                        <h4 class="text-primary"><?= number_format($report_data['attendance_stats']['total_records']) ?></h4>
                        <p class="text-muted">Total Records</p>
                    </div>
                </div>
                <?php 
                $attendance_rate = $report_data['attendance_stats']['total_records'] > 0 
                    ? round(($report_data['attendance_stats']['present_count'] / $report_data['attendance_stats']['total_records']) * 100, 1) 
                    : 0;
                ?>
                <div class="text-center mt-3">
                    <h5>Overall Attendance Rate: <span class="text-success"><?= $attendance_rate ?>%</span></h5>
                    <div class="attendance-rate-bar">
                        <div class="attendance-rate-fill <?= $attendance_rate >= 85 ? 'rate-excellent' : ($attendance_rate >= 75 ? 'rate-good' : ($attendance_rate >= 65 ? 'rate-average' : 'rate-poor')) ?>" 
                             style="width: <?= $attendance_rate ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Faculty Breakdown -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Faculty Breakdown</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Faculty</th>
                                <th>Students</th>
                                <th>Lecturers</th>
                                <th>Courses</th>
                                <th>Departments</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data['faculty_breakdown'] as $faculty): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($faculty['faculty_name']) ?></strong></td>
                                <td><?= number_format($faculty['student_count']) ?></td>
                                <td><?= number_format($faculty['lecturer_count']) ?></td>
                                <td><?= number_format($faculty['course_count']) ?></td>
                                <td><?= number_format($faculty['department_count']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($report_type === 'attendance_summary'): ?>
        <!-- Attendance Summary Report -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Attendance Summary Report</h5>
                <small class="text-muted">Period: <?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="reportTable">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Lecturer</th>
                                <th>Faculty</th>
                                <th>Sessions</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['course_code']) ?></strong></td>
                                <td><?= htmlspecialchars($row['course_name']) ?></td>
                                <td><?= htmlspecialchars($row['lecturer_name']) ?></td>
                                <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                                <td><?= number_format($row['total_sessions']) ?></td>
                                <td><span class="badge bg-success"><?= number_format($row['present_count']) ?></span></td>
                                <td><span class="badge bg-warning"><?= number_format($row['late_count']) ?></span></td>
                                <td><span class="badge bg-danger"><?= number_format($row['absent_count']) ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2 <?= $row['attendance_rate'] >= 75 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($row['attendance_rate'], 1) ?>%
                                        </span>
                                        <div class="attendance-rate-bar" style="width: 100px;">
                                            <div class="attendance-rate-fill <?= $row['attendance_rate'] >= 85 ? 'rate-excellent' : ($row['attendance_rate'] >= 75 ? 'rate-good' : ($row['attendance_rate'] >= 65 ? 'rate-average' : 'rate-poor')) ?>" 
                                                 style="width: <?= $row['attendance_rate'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($report_type === 'student_attendance'): ?>
        <!-- Student Attendance Report -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Student Attendance Report</h5>
                <small class="text-muted">Period: <?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="reportTable">
                        <thead>
                            <tr>
                                <th>Reg Number</th>
                                <th>Student Name</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Total Classes</th>
                                <th>Present</th>
                                <th>Late</th>
                                <th>Absent</th>
                                <th>Attendance Rate</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['reg_number']) ?></strong></td>
                                <td><?= htmlspecialchars($row['student_name']) ?></td>
                                <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                                <td><?= htmlspecialchars($row['department_name']) ?></td>
                                <td><?= number_format($row['total_records']) ?></td>
                                <td><span class="badge bg-success"><?= number_format($row['present_count']) ?></span></td>
                                <td><span class="badge bg-warning"><?= number_format($row['late_count']) ?></span></td>
                                <td><span class="badge bg-danger"><?= number_format($row['absent_count']) ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2 <?= $row['attendance_rate'] >= 75 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($row['attendance_rate'], 1) ?>%
                                        </span>
                                        <div class="attendance-rate-bar" style="width: 80px;">
                                            <div class="attendance-rate-fill <?= $row['attendance_rate'] >= 85 ? 'rate-excellent' : ($row['attendance_rate'] >= 75 ? 'rate-good' : ($row['attendance_rate'] >= 65 ? 'rate-average' : 'rate-poor')) ?>" 
                                                 style="width: <?= $row['attendance_rate'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['attendance_rate'] >= 85): ?>
                                        <span class="badge bg-success">Excellent</span>
                                    <?php elseif ($row['attendance_rate'] >= 75): ?>
                                        <span class="badge bg-info">Good</span>
                                    <?php elseif ($row['attendance_rate'] >= 65): ?>
                                        <span class="badge bg-warning">Average</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Poor</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($report_type === 'course_statistics'): ?>
        <!-- Course Statistics Report -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Course Statistics Report</h5>
                <small class="text-muted">Period: <?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="reportTable">
                        <thead>
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Lecturer</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Enrolled Students</th>
                                <th>Total Sessions</th>
                                <th>Avg Attendance Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['course_code']) ?></strong></td>
                                <td><?= htmlspecialchars($row['course_name']) ?></td>
                                <td><?= htmlspecialchars($row['lecturer_name']) ?></td>
                                <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                                <td><?= htmlspecialchars($row['department_name']) ?></td>
                                <td>
                                    <span class="badge bg-primary"><?= number_format($row['enrolled_students']) ?></span>
                                </td>
                                <td><?= number_format($row['total_sessions']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2 <?= $row['avg_attendance_rate'] >= 75 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($row['avg_attendance_rate'], 1) ?>%
                                        </span>
                                        <div class="attendance-rate-bar" style="width: 80px;">
                                            <div class="attendance-rate-fill <?= $row['avg_attendance_rate'] >= 85 ? 'rate-excellent' : ($row['avg_attendance_rate'] >= 75 ? 'rate-good' : ($row['avg_attendance_rate'] >= 65 ? 'rate-average' : 'rate-poor')) ?>" 
                                                 style="width: <?= $row['avg_attendance_rate'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($report_type === 'lecturer_performance'): ?>
        <!-- Lecturer Performance Report -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Lecturer Performance Report</h5>
                <small class="text-muted">Period: <?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="reportTable">
                        <thead>
                            <tr>
                                <th>Lecturer Name</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Courses Taught</th>
                                <th>Sessions Conducted</th>
                                <th>Total Students</th>
                                <th>Avg Attendance Rate</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($row['lecturer_name']) ?></strong></td>
                                <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                                <td><?= htmlspecialchars($row['department_name']) ?></td>
                                <td><span class="badge bg-info"><?= number_format($row['courses_taught']) ?></span></td>
                                <td><?= number_format($row['sessions_conducted']) ?></td>
                                <td><span class="badge bg-primary"><?= number_format($row['total_students_taught']) ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2 <?= $row['avg_attendance_rate'] >= 75 ? 'text-success' : 'text-danger' ?>">
                                            <?= number_format($row['avg_attendance_rate'], 1) ?>%
                                        </span>
                                        <div class="attendance-rate-bar" style="width: 80px;">
                                            <div class="attendance-rate-fill <?= $row['avg_attendance_rate'] >= 85 ? 'rate-excellent' : ($row['avg_attendance_rate'] >= 75 ? 'rate-good' : ($row['avg_attendance_rate'] >= 65 ? 'rate-average' : 'rate-poor')) ?>" 
                                                 style="width: <?= $row['avg_attendance_rate'] ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($row['avg_attendance_rate'] >= 85): ?>
                                        <span class="badge bg-success">Excellent</span>
                                    <?php elseif ($row['avg_attendance_rate'] >= 75): ?>
                                        <span class="badge bg-info">Good</span>
                                    <?php elseif ($row['avg_attendance_rate'] >= 65): ?>
                                        <span class="badge bg-warning">Average</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif ($report_type === 'registration_trends'): ?>
        <!-- Registration Trends Report -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Registration Trends Report</h5>
                <small class="text-muted">Period: <?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></small>
            </div>
            <div class="card-body">
                <?php if (empty($report_data)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No registration data found for the selected period.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="reportTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Daily Registrations</th>
                                    <th>Unique Students</th>
                                    <th>Courses Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <td><strong><?= date('M j, Y', strtotime($row['registration_date'])) ?></strong></td>
                                    <td><span class="badge bg-primary"><?= number_format($row['daily_registrations']) ?></span></td>
                                    <td><span class="badge bg-info"><?= number_format($row['unique_students']) ?></span></td>
                                    <td><span class="badge bg-success"><?= number_format($row['courses_registered']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($report_type === 'defaulters'): ?>
        <!-- Attendance Defaulters Report -->
        <div class="report-card">
            <div class="card-header">
                <h5 class="mb-0">Attendance Defaulters Report</h5>
                <small class="text-muted">Students with attendance below 75% | Period: <?= date('M j', strtotime($date_from)) ?> - <?= date('M j, Y', strtotime($date_to)) ?></small>
            </div>
            <div class="card-body">
                <?php if (empty($report_data)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> Great news! No students have attendance below 75% for the selected period.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong><?= count($report_data) ?></strong> students have attendance below the required 75% threshold.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover" id="reportTable">
                            <thead>
                                <tr>
                                    <th>Reg Number</th>
                                    <th>Student Name</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Course</th>
                                    <th>Total Classes</th>
                                    <th>Attended</th>
                                    <th>Missed</th>
                                    <th>Attendance Rate</th>
                                    <th>Action Required</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                <tr class="<?= $row['attendance_rate'] < 50 ? 'table-danger' : 'table-warning' ?>">
                                    <td><strong><?= htmlspecialchars($row['reg_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($row['student_name']) ?></td>
                                    <td><?= htmlspecialchars($row['faculty_name']) ?></td>
                                    <td><?= htmlspecialchars($row['department_name']) ?></td>
                                    <td>
                                        <div>
                                            <strong><?= htmlspecialchars($row['course_code']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($row['course_name']) ?></small>
                                        </div>
                                    </td>
                                    <td><?= number_format($row['total_classes']) ?></td>
                                    <td><span class="badge bg-success"><?= number_format($row['classes_attended']) ?></span></td>
                                    <td><span class="badge bg-danger"><?= number_format($row['classes_missed']) ?></span></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="me-2 text-danger fw-bold">
                                                <?= number_format($row['attendance_rate'], 1) ?>%
                                            </span>
                                            <div class="attendance-rate-bar" style="width: 60px;">
                                                <div class="attendance-rate-fill rate-poor" 
                                                     style="width: <?= $row['attendance_rate'] ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($row['attendance_rate'] < 50): ?>
                                            <span class="badge bg-danger">Critical</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Warning</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
function exportToCSV() {
    const table = document.getElementById('reportTable');
    if (!table) {
        alert('No data available to export');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let cellData = cols[j].innerText.replace(/"/g, '""');
            row.push('"' + cellData + '"');
        }
        
        csv.push(row.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    
    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'report_<?= $report_type ?>_<?= date('Y-m-d') ?>.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

// Auto-submit form when filters change
document.querySelectorAll('select[name="faculty_id"], select[name="department_id"]').forEach(select => {
    select.addEventListener('change', function() {
        this.form.submit();
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>