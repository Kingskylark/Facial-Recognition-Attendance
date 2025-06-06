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

// Get filter parameters
$selected_course = $_GET['course_id'] ?? '';
$selected_semester = $_GET['semester'] ?? '';

// Get available courses for the student
$stmt = $conn->prepare("
    SELECT DISTINCT c.id, c.name, c.code, c.semester
    FROM courses c
    JOIN student_courses sc ON c.id = sc.course_id
    WHERE sc.student_id = :student_id
    ORDER BY c.semester DESC, c.name ASC
");
$stmt->execute(['student_id' => $student_id]);
$student_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available semesters
$available_semesters = array_unique(array_column($student_courses, 'semester'));
sort($available_semesters);

// Build attendance query with filters
$where_conditions = ['a.student_id = :student_id'];
$params = ['student_id' => $student_id];

if ($selected_course) {
    $where_conditions[] = 'c.id = :course_id';
    $params['course_id'] = $selected_course;
}

if ($selected_semester) {
    $where_conditions[] = 'c.semester = :semester';
    $params['semester'] = $selected_semester;
}

$where_clause = implode(' AND ', $where_conditions);

// Get attendance records
$stmt = $conn->prepare("
    SELECT 
        a.*,
        c.name as course_name,
        c.code as course_code,
        c.semester,
        c.credit_units,
        ats.session_date,
        ats.start_time,
        ats.end_time,
        ats.session_title,
        CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
        CONCAT(ml.firstname, ' ', ml.surname) as marked_by_name
    FROM attendance a
    JOIN attendance_sessions ats ON a.attendance_session_id = ats.id
    JOIN courses c ON ats.course_id = c.id
    JOIN student_courses sc ON c.id = sc.course_id AND sc.student_id = a.student_id
    JOIN lecturers l ON ats.lecturer_id = l.id
    LEFT JOIN lecturers ml ON a.marked_by_lecturer_id = ml.id
    WHERE $where_clause
    ORDER BY c.semester DESC, c.name ASC, ats.session_date DESC, ats.start_time DESC
");
$stmt->execute($params);
$attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics per course
$course_stats = [];
foreach ($attendance_records as $record) {
    $course_key = $record['course_code'];
    
    if (!isset($course_stats[$course_key])) {
        $course_stats[$course_key] = [
            'course_name' => $record['course_name'],
            'course_code' => $record['course_code'],
            'semester' => $record['semester'],
            'credit_units' => $record['credit_units'],
            'total' => 0,
            'present' => 0,
            'late' => 0,
            'absent' => 0
        ];
    }
    
    $course_stats[$course_key]['total']++;
    $course_stats[$course_key][$record['status']]++;
}

// Calculate attendance rates
foreach ($course_stats as &$stats) {
    $stats['attendance_rate'] = $stats['total'] > 0 ? 
        round((($stats['present'] + $stats['late']) / $stats['total']) * 100, 1) : 0;
    $stats['present_rate'] = $stats['total'] > 0 ? 
        round(($stats['present'] / $stats['total']) * 100, 1) : 0;
}

// Group records by course for display
$records_by_course = [];
foreach ($attendance_records as $record) {
    $records_by_course[$record['course_code']][] = $record;
}
?>

<?php include_once '../includes/header.php'; ?>

<style>
.attendance-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.course-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: white;
    border-radius: 15px 15px 0 0;
    padding: 20px;
}

.stats-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    margin-bottom: 20px;
}

.attendance-badge {
    padding: 0.4em 0.8em;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 500;
}

.present { background-color: #28a745; color: white; }
.late { background-color: #ffc107; color: black; }
.absent { background-color: #dc3545; color: white; }

.filter-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: none;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 25px;
}

.progress-custom {
    height: 10px;
    border-radius: 10px;
}

.session-card {
    border-left: 4px solid #4f46e5;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}

.session-card:hover {
    transform: translateX(5px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.confidence-indicator {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    margin-left: 5px;
}

.confidence-high { background-color: #28a745; }
.confidence-medium { background-color: #ffc107; }
.confidence-low { background-color: #dc3545; }

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Attendance Records</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-calendar-check text-primary"></i> My Attendance</h2>
                    <p class="text-muted">View your attendance records for all courses</p>
                </div>
                <div class="text-end">
                    <small class="text-muted">Student: <?= htmlspecialchars($student['firstname']) ?> <?= htmlspecialchars($student['surname']) ?></small><br>
                    <small class="text-muted"><?= htmlspecialchars($student['reg_number']) ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-card">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <label for="course_id" class="form-label"><i class="fas fa-book"></i> Filter by Course</label>
                <select class="form-select" id="course_id" name="course_id">
                    <option value="">All Courses</option>
                    <?php foreach ($student_courses as $course): ?>
                        <option value="<?= $course['id'] ?>" <?= $selected_course == $course['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['code']) ?> - <?= htmlspecialchars($course['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="semester" class="form-label"><i class="fas fa-calendar"></i> Filter by Semester</label>
                <select class="form-select" id="semester" name="semester">
                    <option value="">All Semesters</option>
                    <?php foreach ($available_semesters as $semester): ?>
                        <option value="<?= $semester ?>" <?= $selected_semester == $semester ? 'selected' : '' ?>>
                            <?= htmlspecialchars($semester) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                <a href="attendance.php" class="btn btn-outline-secondary">
                    <i class="fas fa-refresh"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <?php if ($course_stats): ?>
        <!-- Overall Statistics -->
        <div class="row mb-4">
            <?php 
            $total_classes = array_sum(array_column($course_stats, 'total'));
            $total_present = array_sum(array_column($course_stats, 'present'));
            $total_late = array_sum(array_column($course_stats, 'late'));
            $total_absent = array_sum(array_column($course_stats, 'absent'));
            $overall_rate = $total_classes > 0 ? round((($total_present + $total_late) / $total_classes) * 100, 1) : 0;
            ?>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-calendar-day fa-2x mb-2"></i>
                    <h3><?= $total_classes ?></h3>
                    <p class="mb-0">Total Classes</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-check-circle fa-2x mb-2"></i>
                    <h3><?= $total_present ?></h3>
                    <p class="mb-0">Present</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-clock fa-2x mb-2"></i>
                    <h3><?= $total_late ?></h3>
                    <p class="mb-0">Late</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-percentage fa-2x mb-2"></i>
                    <h3><?= $overall_rate ?>%</h3>
                    <p class="mb-0">Attendance Rate</p>
                </div>
            </div>
        </div>

        <!-- Course-wise Attendance -->
        <?php foreach ($course_stats as $course_code => $stats): ?>
            <div class="attendance-card">
                <div class="course-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h4 class="mb-1"><?= htmlspecialchars($stats['course_name']) ?></h4>
                            <p class="mb-0">
                                <strong><?= htmlspecialchars($stats['course_code']) ?></strong> | 
                                <?= htmlspecialchars($stats['semester']) ?> | 
                                <?= htmlspecialchars($stats['credit_units']) ?> Credit Units
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <h3 class="mb-0"><?= $stats['attendance_rate'] ?>%</h3>
                            <small>Attendance Rate</small>
                        </div>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="mt-3">
                        <div class="progress progress-custom">
                            <div class="progress-bar bg-success" style="width: <?= $stats['present_rate'] ?>%"></div>
                            <div class="progress-bar bg-warning" style="width: <?= $stats['total'] > 0 ? ($stats['late'] / $stats['total']) * 100 : 0 ?>%"></div>
                        </div>
                        <div class="row mt-2 text-center">
                            <div class="col-4">
                                <small>Present: <?= $stats['present'] ?></small>
                            </div>
                            <div class="col-4">
                                <small>Late: <?= $stats['late'] ?></small>
                            </div>
                            <div class="col-4">
                                <small>Absent: <?= $stats['absent'] ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card-body">
                    <?php if (isset($records_by_course[$course_code])): ?>
                        <div class="row">
                            <?php foreach ($records_by_course[$course_code] as $record): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card session-card">
                                        <div class="card-body p-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <?= date('M j, Y', strtotime($record['session_date'])) ?>
                                                        <span class="attendance-badge <?= $record['status'] ?> ms-2">
                                                            <?= ucfirst($record['status']) ?>
                                                        </span>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock"></i> 
                                                        <?= date('g:i A', strtotime($record['start_time'])) ?>
                                                        <?php if ($record['end_time']): ?>
                                                            - <?= date('g:i A', strtotime($record['end_time'])) ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <?php if ($record['confidence_score']): ?>
                                                    <div class="confidence-indicator <?= 
                                                        $record['confidence_score'] >= 0.8 ? 'confidence-high' : 
                                                        ($record['confidence_score'] >= 0.6 ? 'confidence-medium' : 'confidence-low') 
                                                    ?>" title="Confidence: <?= round($record['confidence_score'] * 100) ?>%"></div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($record['session_type']): ?>
                                                <p class="mb-1">
                                                    <small><strong>Type:</strong> <?= htmlspecialchars($record['session_type']) ?></small>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($record['topic']): ?>
                                                <p class="mb-1">
                                                    <small><strong>Topic:</strong> <?= htmlspecialchars($record['topic']) ?></small>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <p class="mb-1">
                                                <small><strong>Lecturer:</strong> <?= htmlspecialchars($record['lecturer_name']) ?></small>
                                            </p>
                                            
                                            <?php if ($record['marked_by_name'] && $record['marked_by_name'] !== $record['lecturer_name']): ?>
                                                <p class="mb-1">
                                                    <small><strong>Marked by:</strong> <?= htmlspecialchars($record['marked_by_name']) ?></small>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <?php if ($record['remarks']): ?>
                                                <p class="mb-1">
                                                    <small><strong>Remarks:</strong> <?= htmlspecialchars($record['remarks']) ?></small>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <small class="text-muted">
                                                <i class="fas fa-calendar-alt"></i> 
                                                Marked: <?= date('M j, Y g:i A', strtotime($record['marked_at'])) ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php else: ?>
        <!-- Empty State -->
        <div class="card attendance-card">
            <div class="card-body">
                <div class="empty-state">
                    <i class="fas fa-calendar-times fa-4x mb-3"></i>
                    <h4>No Attendance Records Found</h4>
                    <?php if ($selected_course || $selected_semester): ?>
                        <p>No attendance records match your current filters.</p>
                        <a href="attendance.php" class="btn btn-primary">
                            <i class="fas fa-refresh"></i> View All Records
                        </a>
                    <?php else: ?>
                        <p>You don't have any attendance records yet.</p>
                        <p class="text-muted">Attendance records will appear here once you start attending classes.</p>
                        <a href="courses.php" class="btn btn-primary">
                            <i class="fas fa-book"></i> View Your Courses
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Back to Dashboard -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="index.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</main>

<script>
// Auto-submit form when filters change
document.getElementById('course_id').addEventListener('change', function() {
    this.form.submit();
});

document.getElementById('semester').addEventListener('change', function() {
    this.form.submit();
});

// Add tooltips for confidence indicators
document.querySelectorAll('.confidence-indicator').forEach(function(el) {
    new bootstrap.Tooltip(el);
});
</script>

<?php include_once '../includes/footer.php'; ?>