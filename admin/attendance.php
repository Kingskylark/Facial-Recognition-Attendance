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

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_session':
            try {
                $stmt = $conn->prepare("
                    INSERT INTO attendance_sessions (lecturer_id, course_id, session_date, start_time, end_time, 
                                                   session_type, location, session_year, semester, created_at) 
                    VALUES (:lecturer_id, :course_id, :session_date, :start_time, :end_time, 
                            :session_type, :location, :session_year, :semester, NOW())
                ");
                
                $stmt->execute([
                    'lecturer_id' => $_POST['lecturer_id'],
                    'course_id' => $_POST['course_id'],
                    'session_date' => $_POST['session_date'],
                    'start_time' => $_POST['start_time'],
                    'end_time' => $_POST['end_time'],
                    'session_type' => $_POST['session_type'],
                    'location' => $_POST['location'],
                    'session_year' => $_POST['session_year'],
                    'semester' => $_POST['semester']
                ]);
                
                $session_id = $conn->lastInsertId();
                
                // Auto-enroll students if requested
                if (isset($_POST['auto_enroll']) && $_POST['auto_enroll'] == '1') {
                    $enroll_stmt = $conn->prepare("
                        INSERT INTO attendance (student_id, attendance_session_id, course_id, status, method, created_at)
                        SELECT s.id, :session_id, :course_id, 'absent', 'auto', NOW()
                        FROM students s 
                        WHERE s.course_id = :course_id2
                    ");
                    $enroll_stmt->execute([
                        'session_id' => $session_id,
                        'course_id' => $_POST['course_id'],
                        'course_id2' => $_POST['course_id']
                    ]);
                }
                
                $message = 'Attendance session created successfully!';
            } catch (PDOException $e) {
                $error = 'Error creating session: ' . $e->getMessage();
            }
            break;
            
        case 'update_session':
            try {
                $stmt = $conn->prepare("
                    UPDATE attendance_sessions SET 
                        lecturer_id = :lecturer_id,
                        course_id = :course_id,
                        session_date = :session_date,
                        start_time = :start_time,
                        end_time = :end_time,
                        session_type = :session_type,
                        location = :location,
                        status = :status,
                        session_year = :session_year,
                        semester = :semester
                    WHERE id = :session_id
                ");
                
                $stmt->execute([
                    'lecturer_id' => $_POST['lecturer_id'],
                    'course_id' => $_POST['course_id'],
                    'session_date' => $_POST['session_date'],
                    'start_time' => $_POST['start_time'],
                    'end_time' => $_POST['end_time'],
                    'session_type' => $_POST['session_type'],
                    'location' => $_POST['location'],
                    'status' => $_POST['status'],
                    'session_year' => $_POST['session_year'],
                    'semester' => $_POST['semester'],
                    'session_id' => $_POST['session_id']
                ]);
                
                $message = 'Session updated successfully!';
            } catch (PDOException $e) {
                $error = 'Error updating session: ' . $e->getMessage();
            }
            break;
            
        case 'mark_attendance':
            try {
                $conn->beginTransaction();
                
                foreach ($_POST['attendance'] as $student_id => $status) {
                    // Check if attendance record exists
                    $check_stmt = $conn->prepare("
                        SELECT id FROM attendance 
                        WHERE student_id = :student_id AND attendance_session_id = :session_id
                    ");
                    $check_stmt->execute([
                        'student_id' => $student_id,
                        'session_id' => $_POST['session_id']
                    ]);
                    
                    if ($check_stmt->rowCount() > 0) {
                        // Update existing record
                        $stmt = $conn->prepare("
                            UPDATE attendance SET 
                                status = :status, 
                                method = 'manual',
                                marked_at = NOW(),
                                updated_at = NOW()
                            WHERE student_id = :student_id AND attendance_session_id = :session_id
                        ");
                    } else {
                        // Insert new record
                        $stmt = $conn->prepare("
                            INSERT INTO attendance (student_id, attendance_session_id, course_id, status, method, marked_at, created_at)
                            VALUES (:student_id, :session_id, :course_id, :status, 'manual', NOW(), NOW())
                        ");
                        $stmt->bindValue('course_id', $_POST['course_id']);
                    }
                    
                    $stmt->execute([
                        'student_id' => $student_id,
                        'session_id' => $_POST['session_id'],
                        'status' => $status
                    ]);
                }
                
                $conn->commit();
                $message = 'Attendance marked successfully!';
            } catch (PDOException $e) {
                $conn->rollback();
                $error = 'Error marking attendance: ' . $e->getMessage();
            }
            break;
            
        case 'delete_session':
            try {
                $conn->beginTransaction();
                
                // Delete attendance records first
                $stmt = $conn->prepare("DELETE FROM attendance WHERE attendance_session_id = :session_id");
                $stmt->execute(['session_id' => $_POST['session_id']]);
                
                // Delete session
                $stmt = $conn->prepare("DELETE FROM attendance_sessions WHERE id = :session_id");
                $stmt->execute(['session_id' => $_POST['session_id']]);
                
                $conn->commit();
                $message = 'Session deleted successfully!';
            } catch (PDOException $e) {
                $conn->rollback();
                $error = 'Error deleting session: ' . $e->getMessage();
            }
            break;
    }
}

// Get filters
$course_filter = $_GET['course'] ?? '';
$lecturer_filter = $_GET['lecturer'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status_filter = $_GET['status'] ?? '';
$semester_filter = $_GET['semester'] ?? '';
$year_filter = $_GET['year'] ?? '2024/2025';

// Build query with filters
$where_conditions = [];
$params = [];

if ($course_filter) {
    $where_conditions[] = "ats.course_id = :course_filter";
    $params['course_filter'] = $course_filter;
}

if ($lecturer_filter) {
    $where_conditions[] = "ats.lecturer_id = :lecturer_filter";
    $params['lecturer_filter'] = $lecturer_filter;
}

if ($date_from) {
    $where_conditions[] = "ats.session_date >= :date_from";
    $params['date_from'] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "ats.session_date <= :date_to";
    $params['date_to'] = $date_to;
}

if ($status_filter) {
    $where_conditions[] = "ats.status = :status_filter";
    $params['status_filter'] = $status_filter;
}

if ($semester_filter) {
    $where_conditions[] = "ats.semester = :semester_filter";
    $params['semester_filter'] = $semester_filter;
}

if ($year_filter) {
    $where_conditions[] = "ats.session_year = :year_filter";
    $params['year_filter'] = $year_filter;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get attendance sessions with pagination
$page = $_GET['page'] ?? 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT 
        ats.*,
        c.name,
        c.code,
        CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
        COUNT(DISTINCT a.id) as total_students,
        COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.id END) as present_count,
        COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.id END) as absent_count,
        COUNT(DISTINCT CASE WHEN a.status = 'late' THEN a.id END) as late_count
    FROM attendance_sessions ats
    LEFT JOIN courses c ON ats.course_id = c.id
    LEFT JOIN lecturers l ON ats.lecturer_id = l.id
    LEFT JOIN attendance a ON ats.id = a.attendance_session_id
    $where_clause
    GROUP BY ats.id
    ORDER BY ats.session_date DESC, ats.start_time DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT ats.id) as total FROM attendance_sessions ats $where_clause");
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_sessions = $count_stmt->fetch()['total'];
$total_pages = ceil($total_sessions / $limit);

// Get attendance statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT ats.id) as total_sessions,
        COUNT(DISTINCT a.id) as total_records,
        COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.id END) as total_present,
        COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.id END) as total_absent,
        COUNT(DISTINCT CASE WHEN a.status = 'late' THEN a.id END) as total_late,
        ROUND(AVG(CASE WHEN a.status = 'present' THEN 100 ELSE 0 END), 1) as avg_attendance_rate
    FROM attendance_sessions ats
    LEFT JOIN attendance a ON ats.id = a.attendance_session_id
    WHERE ats.session_year = :year
");
$stats_stmt->execute(['year' => $year_filter]);
$attendance_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get courses for dropdown
$courses_stmt = $conn->prepare("SELECT id, name, code FROM courses ORDER BY name");
$courses_stmt->execute();
$courses = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lecturers for dropdown
$lecturers_stmt = $conn->prepare("SELECT id, CONCAT(firstname, ' ', surname) as name FROM lecturers ORDER BY firstname");
$lecturers_stmt->execute();
$lecturers = $lecturers_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include_once '../includes/header.php'; ?>

<style>
.session-card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    height: 100%;
    overflow: hidden;
}

.session-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 24px rgba(0,0,0,0.15);
}

.session-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    margin: -1px -1px 15px -1px;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 15px;
    padding: 20px;
    text-align: center;
    border: none;
    height: 100%;
}

.stat-card.present {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.stat-card.absent {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.stat-card.late {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.stat-card.rate {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.filter-section {
    background: #f8f9fa;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: bold;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
}

.status-completed {
    background-color: #cce7ff;
    color: #004085;
}

.status-cancelled {
    background-color: #f8d7da;
    color: #721c24;
}

.attendance-summary {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 12px;
    margin-top: 10px;
}

.progress-attendance {
    height: 8px;
    border-radius: 10px;
    overflow: hidden;
    background: #e9ecef;
}

.progress-bar-present {
    background: linear-gradient(90deg, #43e97b, #38f9d7);
}

.progress-bar-late {
    background: linear-gradient(90deg, #f093fb, #f5576c);
}

.modal-header-custom {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
}

.session-time {
    background: rgba(255,255,255,0.2);
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.85rem;
    display: inline-block;
    margin: 2px;
}

.student-attendance-row {
    border-bottom: 1px solid #e9ecef;
    padding: 8px 0;
}

.student-attendance-row:last-child {
    border-bottom: none;
}

.attendance-radio {
    margin: 0 5px;
}

.quick-stats {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 15px;
}
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-clipboard-check text-primary"></i> Attendance Management</h2>
            <p class="text-muted">Track and manage student attendance sessions</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#createSessionModal">
            <i class="fas fa-plus"></i> Create New Session
        </button>
    </div>

    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card">
                <i class="fas fa-clipboard-list fa-2x mb-2"></i>
                <h3><?= number_format($attendance_stats['total_sessions']) ?></h3>
                <p class="mb-0">Total Sessions</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card present">
                <i class="fas fa-user-check fa-2x mb-2"></i>
                <h3><?= number_format($attendance_stats['total_present']) ?></h3>
                <p class="mb-0">Present Records</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card absent">
                <i class="fas fa-user-times fa-2x mb-2"></i>
                <h3><?= number_format($attendance_stats['total_absent']) ?></h3>
                <p class="mb-0">Absent Records</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card rate">
                <i class="fas fa-percentage fa-2x mb-2"></i>
                <h3><?= number_format($attendance_stats['avg_attendance_rate'] ?? 0, 1) ?>%</h3>
                <p class="mb-0">Average Attendance</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Course</label>
                <select class="form-select" name="course">
                    <option value="">All Courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>" <?= $course_filter == $course['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Lecturer</label>
                <select class="form-select" name="lecturer">
                    <option value="">All Lecturers</option>
                    <?php foreach ($lecturers as $lecturer): ?>
                        <option value="<?= $lecturer['id'] ?>" <?= $lecturer_filter == $lecturer['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lecturer['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester">
                    <option value="">All Semesters</option>
                    <option value="first" <?= $semester_filter == 'first' ? 'selected' : '' ?>>First</option>
                    <option value="second" <?= $semester_filter == 'second' ? 'selected' : '' ?>>Second</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Academic Year</label>
                <input type="text" class="form-control" name="year" value="<?= htmlspecialchars($year_filter) ?>" placeholder="2024/2025">
            </div>
            <div class="col-md-8"></div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <a href="?" class="btn btn-outline-secondary w-100">
                    <i class="fas fa-times"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Sessions List -->
    <div class="row">
        <?php if ($sessions): ?>
            <?php foreach ($sessions as $session): ?>
                <div class="col-xl-4 col-lg-6 mb-4">
                    <div class="card session-card">
                        <div class="session-header">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="mb-1"><?= htmlspecialchars($session['code']) ?></h5>
                                    <p class="mb-2 opacity-75"><?= htmlspecialchars($session['name']) ?></p>
                                </div>
                                <span class="status-badge status-<?= $session['status'] ?>">
                                    <?= ucfirst($session['status']) ?>
                                </span>
                            </div>
                            <div class="d-flex gap-2 flex-wrap mt-2">
                                <span class="session-time">
                                    <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($session['session_date'])) ?>
                                </span>
                                <span class="session-time">
                                    <i class="fas fa-clock"></i> <?= date('H:i', strtotime($session['start_time'])) ?>
                                    <?php if ($session['end_time']): ?>
                                        - <?= date('H:i', strtotime($session['end_time'])) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="quick-stats">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <strong class="text-success"><?= $session['present_count'] ?></strong><br>
                                        <small class="text-muted">Present</small>
                                    </div>
                                    <div class="col-4">
                                        <strong class="text-danger"><?= $session['absent_count'] ?></strong><br>
                                        <small class="text-muted">Absent</small>
                                    </div>
                                    <div class="col-4">
                                        <strong class="text-warning"><?= $session['late_count'] ?></strong><br>
                                        <small class="text-muted">Late</small>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($session['total_students'] > 0): ?>
                                <div class="progress-attendance mb-3">
                                    <?php 
                                    $present_percent = ($session['present_count'] / $session['total_students']) * 100;
                                    $late_percent = ($session['late_count'] / $session['total_students']) * 100;
                                    ?>
                                    <div class="progress-bar progress-bar-present" style="width: <?= $present_percent ?>%"></div>
                                    <div class="progress-bar progress-bar-late" style="width: <?= $late_percent ?>%"></div>
                                </div>
                                <small class="text-muted">
                                    <?= number_format($present_percent, 1) ?>% attendance rate
                                </small>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <div class="row">
                                    <div class="col-6">
                                        <small class="text-muted">Lecturer:</small><br>
                                        <strong><?= htmlspecialchars($session['lecturer_name']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <small class="text-muted">Type:</small><br>
                                        <strong><?= htmlspecialchars($session['session_type'] ?: 'Regular') ?></strong>
                                    </div>
                                </div>
                                <?php if ($session['location']): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">Location:</small><br>
                                        <strong><?= htmlspecialchars($session['location']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="d-flex gap-2 justify-content-center mt-3">
                                <button class="btn btn-sm btn-success" 
                                        onclick="markAttendance(<?= $session['id'] ?>)"
                                        title="Mark Attendance">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="editSession(<?= htmlspecialchars(json_encode($session)) ?>)"
                                        title="Edit Session">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="viewSession(<?= htmlspecialchars(json_encode($session)) ?>)"
                                        title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" 
                                        onclick="deleteSession(<?= $session['id'] ?>, '<?= htmlspecialchars($session['code']) ?>')"
                                        title="Delete Session">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="fas fa-clipboard-check fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No attendance sessions found</h4>
                    <p class="text-muted">Try adjusting your filters or create a new session.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSessionModal">
                        <i class="fas fa-plus"></i> Create First Session
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Sessions pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) ?>">Previous</a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)) ?>">Next</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<!-- Create Session Modal -->
<div class="modal fade" id="createSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Create New Attendance Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_session">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select class="form-select" name="course_id" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= $course['id'] ?>">
                                        <?= htmlspecialchars($course['code'] . ' - ' . $course['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Lecturer <span class="text-danger">*</span></label>
                            <select class="form-select" name="lecturer_id" required>
                                <option value="">Select Lecturer</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?= $lecturer['id'] ?>">
                                        <?= htmlspecialchars($lecturer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Session Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="session_date" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="start_time" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control" name="end_time">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Session Type</label>
                            <select class="form-select" name="session_type">
                                <option value="lecture">Lecture</option>
                                <option value="tutorial">Tutorial</option>
                                <option value="practical">Practical</option>
                                <option value="exam">Exam</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" placeholder="Room/Building">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="session_year" value="2024/2025" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" name="semester" required>
                                <option value="">Select Semester</option>
                                <option value="first">First Semester</option>
                                <option value="second">Second Semester</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="auto_enroll" value="1" id="autoEnroll">
                                <label class="form-check-label" for="autoEnroll">
                                    Auto-enroll all students in this course (marked as absent by default)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Session Modal -->
<div class="modal fade" id="editSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Attendance Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editSessionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_session">
                    <input type="hidden" name="session_id" id="edit_session_id">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Course <span class="text-danger">*</span></label>
                            <select class="form-select" name="course_id" id="edit_course_id" required>
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= $course['id'] ?>">
                                        <?= htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Lecturer <span class="text-danger">*</span></label>
                            <select class="form-select" name="lecturer_id" id="edit_lecturer_id" required>
                                <option value="">Select Lecturer</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?= $lecturer['id'] ?>">
                                        <?= htmlspecialchars($lecturer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Session Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="session_date" id="edit_session_date" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="start_time" id="edit_start_time" required>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control" name="end_time" id="edit_end_time">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Session Type</label>
                            <select class="form-select" name="session_type" id="edit_session_type">
                                <option value="lecture">Lecture</option>
                                <option value="tutorial">Tutorial</option>
                                <option value="practical">Practical</option>
                                <option value="exam">Exam</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="col-md-12">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" id="edit_location" placeholder="Room/Building">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Academic Year <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="session_year" id="edit_session_year" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label">Semester <span class="text-danger">*</span></label>
                            <select class="form-select" name="semester" id="edit_semester" required>
                                <option value="">Select Semester</option>
                                <option value="first">First Semester</option>
                                <option value="second">Second Semester</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Attendance Modal -->
<div class="modal fade" id="markAttendanceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-check"></i> Mark Attendance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="markAttendanceForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="mark_attendance">
                    <input type="hidden" name="session_id" id="attendance_session_id">
                    <input type="hidden" name="course_id" id="attendance_course_id">
                    
                    <div id="attendance_session_info" class="alert alert-info mb-3"></div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <button type="button" class="btn btn-success btn-sm" onclick="markAllAs('present')">
                                <i class="fas fa-check-circle"></i> Mark All Present
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-danger btn-sm" onclick="markAllAs('absent')">
                                <i class="fas fa-times-circle"></i> Mark All Absent
                            </button>
                        </div>
                        <div class="col-md-4">
                            <button type="button" class="btn btn-warning btn-sm" onclick="markAllAs('late')">
                                <i class="fas fa-clock"></i> Mark All Late
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Present</th>
                                    <th>Absent</th>
                                    <th>Late</th>
                                </tr>
                            </thead>
                            <tbody id="attendance_students_list">
                                <!-- Students will be loaded here via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Session Modal -->
<div class="modal fade" id="viewSessionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Session Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="session_details_content">
                    <!-- Session details will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteSessionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash"></i> Delete Session</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="deleteSessionForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="delete_session">
                    <input type="hidden" name="session_id" id="delete_session_id">
                    
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                        <h5>Are you sure you want to delete this session?</h5>
                        <p class="text-muted">This will permanently delete the session and all associated attendance records for:</p>
                        <p class="fw-bold" id="delete_session_name"></p>
                        <p class="text-danger">This action cannot be undone!</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// JavaScript functions for modal operations
function editSession(session) {
    document.getElementById('edit_session_id').value = session.id;
    document.getElementById('edit_course_id').value = session.course_id;
    document.getElementById('edit_lecturer_id').value = session.lecturer_id;
    document.getElementById('edit_session_date').value = session.session_date;
    document.getElementById('edit_start_time').value = session.start_time;
    document.getElementById('edit_end_time').value = session.end_time || '';
    document.getElementById('edit_session_type').value = session.session_type || 'lecture';
    document.getElementById('edit_status').value = session.status;
    document.getElementById('edit_location').value = session.location || '';
    document.getElementById('edit_session_year').value = session.session_year;
    document.getElementById('edit_semester').value = session.semester;
    
    new bootstrap.Modal(document.getElementById('editSessionModal')).show();
}

function viewSession(session) {
    const content = `
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary">Course Information</h6>
                <p><strong>Course:</strong> ${session.course_code} - ${session.course_name}</p>
                <p><strong>Lecturer:</strong> ${session.lecturer_name}</p>
                <p><strong>Academic Year:</strong> ${session.session_year}</p>
                <p><strong>Semester:</strong> ${session.semester.charAt(0).toUpperCase() + session.semester.slice(1)}</p>
            </div>
            <div class="col-md-6">
                <h6 class="text-primary">Session Details</h6>
                <p><strong>Date:</strong> ${new Date(session.session_date).toLocaleDateString()}</p>
                <p><strong>Time:</strong> ${session.start_time}${session.end_time ? ' - ' + session.end_time : ''}</p>
                <p><strong>Type:</strong> ${session.session_type || 'Regular'}</p>
                <p><strong>Location:</strong> ${session.location || 'Not specified'}</p>
                <p><strong>Status:</strong> <span class="badge bg-${session.status === 'active' ? 'success' : session.status === 'completed' ? 'primary' : 'danger'}">${session.status.charAt(0).toUpperCase() + session.status.slice(1)}</span></p>
            </div>
        </div>
        <hr>
        <div class="row">
            <div class="col-md-12">
                <h6 class="text-primary">Attendance Summary</h6>
                <div class="row text-center">
                    <div class="col-3">
                        <div class="bg-light p-3 rounded">
                            <h4 class="text-success">${session.present_count}</h4>
                            <small>Present</small>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="bg-light p-3 rounded">
                            <h4 class="text-danger">${session.absent_count}</h4>
                            <small>Absent</small>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="bg-light p-3 rounded">
                            <h4 class="text-warning">${session.late_count}</h4>
                            <small>Late</small>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="bg-light p-3 rounded">
                            <h4 class="text-primary">${session.total_students}</h4>
                            <small>Total</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('session_details_content').innerHTML = content;
    new bootstrap.Modal(document.getElementById('viewSessionModal')).show();
}

function deleteSession(sessionId, courseName) {
    document.getElementById('delete_session_id').value = sessionId;
    document.getElementById('delete_session_name').textContent = courseName;
    
    new bootstrap.Modal(document.getElementById('deleteSessionModal')).show();
}

function markAttendance(sessionId) {
    // Set session ID
    document.getElementById('attendance_session_id').value = sessionId;
    
    // Fetch students for this session via AJAX
    fetch(`get_session_students.php?session_id=${sessionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Set course ID
                document.getElementById('attendance_course_id').value = data.session.course_id;
                
                // Update session info
                document.getElementById('attendance_session_info').innerHTML = `
                    <strong>${data.session.course_code}</strong> - ${data.session.course_name}<br>
                    Date: ${new Date(data.session.session_date).toLocaleDateString()} | 
                    Time: ${data.session.start_time}${data.session.end_time ? ' - ' + data.session.end_time : ''} | 
                    Lecturer: ${data.session.lecturer_name}
                `;
                
                // Populate students list
                let studentsHtml = '';
                data.students.forEach(student => {
                    studentsHtml += `
                        <tr class="student-attendance-row">
                            <td>${student.student_id}</td>
                            <td>${student.first_name} ${student.last_name}</td>
                            <td class="text-center">
                                <input type="radio" name="attendance[${student.id}]" value="present" 
                                       class="attendance-radio" ${student.current_status === 'present' ? 'checked' : ''}>
                            </td>
                            <td class="text-center">
                                <input type="radio" name="attendance[${student.id}]" value="absent" 
                                       class="attendance-radio" ${student.current_status === 'absent' ? 'checked' : ''}>
                            </td>
                            <td class="text-center">
                                <input type="radio" name="attendance[${student.id}]" value="late" 
                                       class="attendance-radio" ${student.current_status === 'late' ? 'checked' : ''}>
                            </td>
                        </tr>
                    `;
                });
                
                document.getElementById('attendance_students_list').innerHTML = studentsHtml;
                
                // Show modal
                new bootstrap.Modal(document.getElementById('markAttendanceModal')).show();
            } else {
                alert('Error loading students: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading students');
        });
}

function markAllAs(status) {
    const radios = document.querySelectorAll(`input[value="${status}"]`);
    radios.forEach(radio => {
        radio.checked = true;
    });
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.classList.contains('show')) {
                alert.classList.remove('show');
                setTimeout(() => alert.remove(), 150);
            }
        }, 5000);
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>