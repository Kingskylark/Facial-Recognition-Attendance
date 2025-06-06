<?php
session_start();
require_once '../config/database.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'student') {
    header('Location: login.php?role=student');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$student_id = $_SESSION['student_id'];

// Get student information
$stmt = $conn->prepare("
    SELECT s.*, f.name as faculty_name, d.name as department_name 
    FROM students s 
    JOIN faculties f ON s.faculty_id = f.id 
    JOIN departments d ON s.department_id = d.id 
    WHERE s.id = :student_id
");
$stmt->execute(['student_id' => $student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: login.php?role=student');
    exit;
}

// Get dashboard statistics
// Count registered courses
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_courses WHERE student_id = :student_id");
$stmt->execute(['student_id' => $student_id]);
$total_courses = $stmt->fetch()['total'];

// Get attendance statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_classes,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
    FROM attendance a
    JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
    WHERE a.student_id = :student_id
        AND ats.status = 'active'
");
$stmt->execute(['student_id' => $student_id]);
$attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);

$attendance_rate = $attendance_stats['total_classes'] > 0 
    ? round(($attendance_stats['present_count'] / $attendance_stats['total_classes']) * 100, 1) 
    : 0;

// Get recent attendance records (last 5)
$stmt = $conn->prepare("
    SELECT 
        c.name as course_name,
        c.code as course_code,
        ats.session_date,
        ats.start_time,
        a.status,
        CONCAT(l.firstname, ' ', l.surname) as lecturer_name
    FROM attendance a
    JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
    JOIN courses c ON ats.course_id = c.id
    JOIN lecturers l ON ats.lecturer_id = l.id
    WHERE a.student_id = :student_id
    ORDER BY ats.session_date DESC, ats.start_time DESC
    LIMIT 5
");
$stmt->execute(['student_id' => $student_id]);
$recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming classes (if you have schedule table)
$stmt = $conn->prepare("
    SELECT 
        c.name as course_name,
        c.code as course_code,
        'Today' as day_info
    FROM student_courses sc
    JOIN courses c ON sc.course_id = c.id
    WHERE sc.student_id = :student_id
    LIMIT 3
");
$stmt->execute(['student_id' => $student_id]);
$upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include_once '../includes/header.php'; ?>

<style>
.profile-img {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border-radius: 50%;
    border: 4px solid #fff;
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
}

.stat-card:hover {
    transform: translateY(-5px);
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

.attendance-badge {
    padding: 0.4em 0.8em;
    border-radius: 20px;
    font-size: 0.8em;
    font-weight: 500;
}

.present { background-color: #28a745; color: white; }
.late { background-color: #ffc107; color: black; }
.absent { background-color: #dc3545; color: white; }

.welcome-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
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
</style>

<main class="container-fluid py-4">
    <!-- Welcome Header -->
    <div class="welcome-header text-center">
        <div class="row align-items-center">
            <div class="col-md-3">
                <?php if ($student['image_path']): ?>
                    <img src="../uploads/students/<?= htmlspecialchars($student['image_path']) ?>" 
                         alt="Profile" class="profile-img">
                <?php else: ?>
                    <div class="profile-img mx-auto bg-white bg-opacity-20 d-flex align-items-center justify-content-center">
                        <i class="fas fa-user fa-3x text-white"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-9 text-start">
                <h2 class="mb-1">Welcome back, <?= htmlspecialchars($student['firstname']) ?>!</h2>
                <p class="mb-1"><?= htmlspecialchars($student['reg_number']) ?> | <?= htmlspecialchars($student['department_name']) ?></p>
                <p class="mb-0">Level <?= htmlspecialchars($student['level']) ?> | <?= htmlspecialchars($student['faculty_name']) ?></p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-book-open fa-2x mb-2"></i>
                <h3><?= $total_courses ?></h3>
                <p class="mb-0">Registered Courses</p>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-calendar-check fa-2x mb-2"></i>
                <h3><?= $attendance_stats['total_classes'] ?: 0 ?></h3>
                <p class="mb-0">Total Classes</p>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-check-circle fa-2x mb-2"></i>
                <h3><?= $attendance_stats['present_count'] ?: 0 ?></h3>
                <p class="mb-0">Classes Attended</p>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-percentage fa-2x mb-2"></i>
                <h3><?= $attendance_rate ?>%</h3>
                <p class="mb-0">Attendance Rate</p>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-3"><i class="fas fa-bolt text-warning"></i> Quick Actions</h4>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-primary">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <h5>Update Profile</h5>
                    <p class="text-muted mb-3">Edit your personal information and photo</p>
                    <a href="profile.php" class="btn btn-primary">Go to Profile</a>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-success">
                        <i class="fas fa-book"></i>
                    </div>
                    <h5>Manage Courses</h5>
                    <p class="text-muted mb-3">Register for courses and view registered ones</p>
                    <a href="courses.php" class="btn btn-success">Manage Courses</a>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-info">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h5>Check Attendance</h5>
                    <p class="text-muted mb-3">View detailed attendance records</p>
                    <a href="attendance.php" class="btn btn-info">View Attendance</a>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-secondary">
                        <i class="fas fa-cog"></i>
                    </div>
                    <h5>Settings</h5>
                    <p class="text-muted mb-3">Change password and preferences</p>
                    <a href="settings.php" class="btn btn-secondary">Settings</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Attendance -->
        <div class="col-md-8 mb-4">
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-clock text-primary"></i> Recent Attendance</h5>
                </div>
                <div class="card-body">
                    <?php if ($recent_attendance): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Status</th>
                                        <th>Lecturer</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_attendance as $record): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($record['course_code']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($record['course_name']) ?></small>
                                            </td>
                                            <td><?= date('M j, Y', strtotime($record['session_date'])) ?></td>
                                            <td><?= date('g:i A', strtotime($record['start_time'])) ?></td>
                                            <td>
                                                <span class="attendance-badge <?= $record['status'] ?>">
                                                    <?= ucfirst($record['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($record['lecturer_name']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="attendance.php" class="btn btn-outline-primary">View All Attendance Records</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No attendance records found.</p>
                            <p class="small text-muted">Attendance will appear here once you start attending classes.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Upcoming Classes & Quick Info -->
        <div class="col-md-4">
            <!-- Upcoming Classes -->
            <div class="card shadow-sm mb-3">
                <div class="card-header card-header-custom">
                    <h6 class="mb-0"><i class="fas fa-calendar text-success"></i> Registered Courses</h6>
                </div>
                <div class="card-body">
                    <?php if ($upcoming_classes): ?>
                        <?php foreach ($upcoming_classes as $class): ?>
                            <div class="d-flex align-items-center mb-2 p-2 bg-light rounded">
                                <div class="flex-grow-1">
                                    <strong><?= htmlspecialchars($class['course_code']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($class['course_name']) ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="courses.php" class="btn btn-sm btn-outline-success">Manage Courses</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-book fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-2">No courses registered</p>
                            <a href="courses.php" class="btn btn-sm btn-primary">Register Courses</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h6 class="mb-0"><i class="fas fa-info-circle text-info"></i> Quick Stats</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <div class="border-end">
                                <h5 class="text-success mb-1"><?= $attendance_stats['present_count'] ?: 0 ?></h5>
                                <small class="text-muted">Present</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border-end">
                                <h5 class="text-warning mb-1"><?= $attendance_stats['late_count'] ?: 0 ?></h5>
                                <small class="text-muted">Late</small>
                            </div>
                        </div>
                        <div class="col-4">
                            <h5 class="text-danger mb-1"><?= $attendance_stats['absent_count'] ?: 0 ?></h5>
                            <small class="text-muted">Absent</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Button -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="logout.php" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</main>

<?php include_once '../includes/footer.php'; ?>