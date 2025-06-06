<?php
session_start();
require_once '../config/database.php';

// Check if lecturer is logged in
if (!isset($_SESSION['lecturer_id']) || $_SESSION['role'] !== 'lecturer') {
    header('Location: login.php?role=lecturer');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$lecturer_id = $_SESSION['lecturer_id'];

// Get lecturer information
$stmt = $conn->prepare("
    SELECT l.*, f.name as faculty_name, d.name as department_name 
    FROM lecturers l 
    JOIN faculties f ON l.faculty_id = f.id 
    JOIN departments d ON l.department_id = d.id 
    WHERE l.id = :lecturer_id
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturer) {
    header('Location: login.php?role=lecturer');
    exit;
}

// Define current academic session and semester
$current_session = '2024/2025';
$current_semester = 'first';

// Count total courses assigned to lecturer for this session/semester
$stmt = $conn->prepare("
    SELECT COUNT(course_id) as total 
    FROM lecturer_courses 
    WHERE lecturer_id = :lecturer_id 
    AND session_year = :session 
    AND semester = :semester
");
$stmt->execute([
    'lecturer_id' => $lecturer_id,
    'session' => $current_session,
    'semester' => $current_semester
]);
$total_courses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Count total unique students offering those courses
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT sc.student_id) as total 
    FROM student_courses sc
    JOIN lecturer_courses lc ON sc.course_id = lc.course_id
    WHERE lc.lecturer_id = :lecturer_id 
    AND lc.session_year = :session 
    AND lc.semester = :semester
");
$stmt->execute([
    'lecturer_id' => $lecturer_id,
    'session' => $current_session,
    'semester' => $current_semester
]);
$total_students = $stmt->fetch(PDO::FETCH_ASSOC)['total'];



// Count attendance sessions conducted
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM attendance_sessions WHERE lecturer_id = :lecturer_id");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$total_sessions = $stmt->fetch()['total'];

// Get attendance statistics for today
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT a.student_id) as total_marked,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count
    FROM attendance a
    JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
    WHERE ats.lecturer_id = :lecturer_id AND ats.session_date = CURDATE()
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$today_attendance = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent attendance sessions (last 5)
$stmt = $conn->prepare("
    SELECT 
        c.name as course_name,
        c.code as course_code,
        ats.session_date,
        ats.start_time,
        ats.end_time,
        COUNT(a.id) as students_marked,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count
    FROM attendance_sessions ats
    JOIN courses c ON ats.course_id = c.id
    LEFT JOIN attendance a ON ats.id = a.attendance_session_id
    WHERE ats.lecturer_id = :lecturer_id
    GROUP BY ats.id, c.name, c.code, ats.session_date, ats.start_time, ats.end_time
    ORDER BY ats.session_date DESC, ats.start_time DESC
    LIMIT 5
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$recent_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lecturer's courses for quick access
$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.name as course_name,
        c.code as course_code,
        COUNT(sc.student_id) as enrolled_students
    FROM courses c
    LEFT JOIN student_courses sc ON c.id = sc.course_id
    WHERE c.lecturer_id = :lecturer_id
    GROUP BY c.id, c.name, c.code
    ORDER BY c.code
    LIMIT 4
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$lecturer_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
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

.session-status {
    padding: 0.4em 0.8em;
    border-radius: 20px;
    font-size: 0.8em;
    font-weight: 500;
}

.completed { background-color: #28a745; color: white; }
.pending { background-color: #ffc107; color: black; }

.welcome-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%);
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

.course-card {
    border-left: 4px solid #007bff;
    transition: all 0.3s ease;
}

.course-card:hover {
    border-left-color: #0056b3;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
</style>

<main class="container-fluid py-4">
    <!-- Welcome Header -->
    <div class="welcome-header text-center">
        <div class="row align-items-center">
            <div class="col-md-3">
                <?php if ($lecturer['image_path']): ?>
                    <img src="../uploads/lecturers/<?= htmlspecialchars($lecturer['image_path']) ?>" 
                         alt="Profile" class="profile-img">
                <?php else: ?>
                    <div class="profile-img mx-auto bg-white bg-opacity-20 d-flex align-items-center justify-content-center">
                        <i class="fas fa-user-tie fa-3x text-white"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-9 text-start">
                <h2 class="mb-1">Welcome, <?= htmlspecialchars($lecturer['title'] . ' ' .$lecturer['firstname'] . ' ' . $lecturer['surname']) ?>!</h2>
                <p class="mb-1"><?= htmlspecialchars($lecturer['staff_id']) ?> | <?= htmlspecialchars($lecturer['department_name']) ?></p>
                <p class="mb-0"><?= htmlspecialchars($lecturer['title']) ?> | <?= htmlspecialchars($lecturer['faculty_name']) ?></p>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-chalkboard-teacher fa-2x mb-2"></i>
                <h3><?= $total_courses ?></h3>
                <p class="mb-0">Assigned Courses</p>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-users fa-2x mb-2"></i>
                <h3><?= $total_students ?: 0 ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-calendar-check fa-2x mb-2"></i>
                <h3><?= $total_sessions ?: 0 ?></h3>
                <p class="mb-0">Sessions Conducted</p>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="stat-card">
                <i class="fas fa-user-check fa-2x mb-2"></i>
                <h3><?= $today_attendance['total_marked'] ?: 0 ?></h3>
                <p class="mb-0">Today's Attendance</p>
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
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <h5>Mark Attendance</h5>
                    <p class="text-muted mb-3">Start a new attendance session</p>
                    <a href="mark-attendance.php" class="btn btn-primary">Mark Attendance</a>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-success">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h5>View Reports</h5>
                    <p class="text-muted mb-3">Generate attendance reports</p>
                    <a href="reports.php" class="btn btn-success">View Reports</a>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-info">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h5>Manage Courses</h5>
                    <p class="text-muted mb-3">View and manage your courses</p>
                    <a href="courses.php" class="btn btn-info">Manage Courses</a>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card quick-action-card shadow-sm text-center">
                <div class="card-body">
                    <div class="quick-action-icon text-secondary">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <h5>Profile</h5>
                    <p class="text-muted mb-3">Update your profile information</p>
                    <a href="profile.php" class="btn btn-secondary">Edit Profile</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Recent Attendance Sessions -->
        <div class="col-md-8 mb-4">
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-history text-primary"></i> Recent Attendance Sessions</h5>
                </div>
                <div class="card-body">
                    <?php if ($recent_sessions): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Students</th>
                                        <th>Present</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_sessions as $session): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($session['course_code']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($session['course_name']) ?></small>
                                            </td>
                                            <td><?= date('M j, Y', strtotime($session['session_date'])) ?></td>
                                            <td>
                                                <?= date('g:i A', strtotime($session['start_time'])) ?>
                                                <?php if ($session['end_time']): ?>
                                                    - <?= date('g:i A', strtotime($session['end_time'])) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $session['students_marked'] ?: 0 ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?= $session['present_count'] ?: 0 ?></span>
                                            </td>
                                            <td>
                                                <a href="view-session.php?session_id=<?= $session['id'] ?? '' ?>" 
                                                   class="btn btn-sm btn-outline-primary">View</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="text-center mt-3">
                            <a href="attendance-history.php" class="btn btn-outline-primary">View All Sessions</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No attendance sessions found.</p>
                            <p class="small text-muted">Start marking attendance to see your sessions here.</p>
                            <a href="mark-attendance.php" class="btn btn-primary">Mark Attendance</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- My Courses & Today's Stats -->
        <div class="col-md-4">
            <!-- My Courses -->
            <div class="card shadow-sm mb-3">
                <div class="card-header card-header-custom">
                    <h6 class="mb-0"><i class="fas fa-book text-success"></i> My Courses</h6>
                </div>
                <div class="card-body">
                    <?php if ($lecturer_courses): ?>
                        <?php foreach ($lecturer_courses as $course): ?>
                            <div class="course-card p-3 mb-2 bg-light rounded">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?= htmlspecialchars($course['course_code']) ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars($course['course_name']) ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-primary"><?= $course['enrolled_students'] ?></span>
                                        <br><small class="text-muted">students</small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="courses.php" class="btn btn-sm btn-outline-success">Manage All Courses</a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-book fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-2">No courses assigned</p>
                            <small class="text-muted">Contact admin to assign courses</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Today's Quick Stats -->
            <div class="card shadow-sm">
                <div class="card-header card-header-custom">
                    <h6 class="mb-0"><i class="fas fa-calendar-day text-info"></i> Today's Summary</h6>
                </div>
                <div class="card-body">
                    <?php if ($today_attendance['total_marked'] > 0): ?>
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="border-end">
                                    <h5 class="text-success mb-1"><?= $today_attendance['present_count'] ?: 0 ?></h5>
                                    <small class="text-muted">Present</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border-end">
                                    <h5 class="text-warning mb-1"><?= $today_attendance['late_count'] ?: 0 ?></h5>
                                    <small class="text-muted">Late</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <h5 class="text-danger mb-1"><?= $today_attendance['absent_count'] ?: 0 ?></h5>
                                <small class="text-muted">Absent</small>
                            </div>
                        </div>
                        <hr>
                        <div class="text-center">
                            <small class="text-muted">Total students marked today</small>
                            <h4 class="text-primary mb-0"><?= $today_attendance['total_marked'] ?></h4>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fas fa-clipboard-list fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-2">No attendance marked today</p>
                            <a href="mark-attendance.php" class="btn btn-sm btn-primary">Start Marking</a>
                        </div>
                    <?php endif; ?>
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