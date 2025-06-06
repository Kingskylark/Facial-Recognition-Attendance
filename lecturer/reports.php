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

// REFACTORED: Get lecturer's courses with all needed data from lecturer_courses table
$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.name as course_name,
        c.code as course_code,
        c.credit_units,
        c.level,
        c.semester as course_semester,
        c.course_type,
        lc.session_year,
        lc.semester,
        lc.created_at as assignment_date,
        COUNT(DISTINCT sc.student_id) as enrolled_students,
        COUNT(DISTINCT ats.id) as total_sessions
    FROM lecturer_courses lc
    JOIN courses c ON lc.course_id = c.id
    LEFT JOIN student_courses sc ON c.id = sc.course_id 
        AND sc.session_year = lc.session_year 
        AND sc.semester = lc.semester
    LEFT JOIN attendance_sessions ats ON c.id = ats.course_id 
        AND ats.lecturer_id = lc.lecturer_id
        AND ats.session_year = lc.session_year
        AND ats.semester = lc.semester
    WHERE lc.lecturer_id = :lecturer_id
    GROUP BY 
        c.id, c.name, c.code, c.credit_units, c.level, c.semester, c.course_type,
        lc.session_year, lc.semester, lc.created_at
    ORDER BY lc.session_year DESC, lc.semester DESC, c.code
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$lecturer_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission for generating reports
$selected_course = null;
$selected_session = null;
$selected_semester = null;
$attendance_report = [];
$download_requested = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_course = $_POST['course_id'] ?? null;
    $selected_session = $_POST['session_year'] ?? $current_session;
    $selected_semester = $_POST['semester'] ?? $current_semester;
    $download_requested = isset($_POST['download']);
    
    if ($selected_course) {
        // Get course information
        $stmt = $conn->prepare("
            SELECT c.*, COUNT(DISTINCT sc.student_id) as enrolled_students
            FROM courses c
            LEFT JOIN student_courses sc ON c.id = sc.course_id 
                AND sc.session_year = :session_year 
                AND sc.semester = :semester
            WHERE c.id = :course_id
            GROUP BY c.id
        ");
        $stmt->execute([
            'course_id' => $selected_course,
            'session_year' => $selected_session,
            'semester' => $selected_semester
        ]);
        $course_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get attendance report grouped by date
        $stmt = $conn->prepare("
            SELECT 
                ats.id as session_id,
                ats.session_date,
                ats.start_time,
                ats.end_time,
                ats.session_type,
                ats.location,
                ats.status as session_status,
                COUNT(DISTINCT a.student_id) as total_marked,
                SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                GROUP_CONCAT(
                    CONCAT(s.surname, ', ', s.firstname, ' (', s.reg_number, ') - ', a.status)
                    ORDER BY s.surname, s.firstname
                    SEPARATOR '|'
                ) as student_attendance_details
            FROM attendance_sessions ats
            LEFT JOIN attendance a ON ats.id = a.attendance_session_id
            LEFT JOIN students s ON a.student_id = s.id
            WHERE ats.lecturer_id = :lecturer_id 
                AND ats.course_id = :course_id
                AND ats.session_year = :session_year
                AND ats.semester = :semester
            GROUP BY ats.id, ats.session_date, ats.start_time, ats.end_time, 
                     ats.session_type, ats.location, ats.status
            ORDER BY ats.session_date DESC, ats.start_time DESC
        ");
        $stmt->execute([
            'lecturer_id' => $lecturer_id,
            'course_id' => $selected_course,
            'session_year' => $selected_session,
            'semester' => $selected_semester
        ]);
        $attendance_report = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If download is requested, generate CSV
        if ($download_requested && !empty($attendance_report)) {
            $filename = 'attendance_report_' . $course_info['code'] . '_' . 
                       str_replace('/', '-', $selected_session) . '_' . 
                       $selected_semester . '_' . date('Y-m-d') . '.csv';
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // CSV Headers
            fputcsv($output, [
                'Course Code',
                'Course Name',
                'Session Year',
                'Semester',
                'Date',
                'Time',
                'Session Type',
                'Location',
                'Status',
                'Total Marked',
                'Present',
                'Late',
                'Absent',
                'Attendance Rate (%)'
            ]);
            
            // CSV Data
            foreach ($attendance_report as $session) {
                $attendance_rate = $session['total_marked'] > 0 ? 
                    round(($session['present_count'] + $session['late_count']) / $session['total_marked'] * 100, 2) : 0;
                
                fputcsv($output, [
                    $course_info['code'],
                    $course_info['name'],
                    $selected_session,
                    ucfirst($selected_semester),
                    $session['session_date'],
                    $session['start_time'] . ($session['end_time'] ? ' - ' . $session['end_time'] : ''),
                    ucfirst($session['session_type']),
                    $session['location'] ?: 'N/A',
                    ucfirst($session['session_status']),
                    $session['total_marked'],
                    $session['present_count'],
                    $session['late_count'],
                    $session['absent_count'],
                    $attendance_rate . '%'
                ]);
            }
            
            fclose($output);
            exit;
        }
    }
}

?>

<?php include_once '../includes/header.php'; ?>

<style>
.report-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%);
    color: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 30px;
}

.filter-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.session-card {
    border-left: 4px solid #007bff;
    transition: all 0.3s ease;
    margin-bottom: 15px;
}

.session-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    border-left-color: #0056b3;
}

.status-badge {
    padding: 0.4em 0.8em;
    border-radius: 20px;
    font-size: 0.8em;
    font-weight: 500;
}

.completed { background-color: #28a745; color: white; }
.active { background-color: #17a2b8; color: white; }
.cancelled { background-color: #dc3545; color: white; }

.stats-row {
    background-color: #f8f9fa;
    border-radius: 10px;
    padding: 15px;
    margin: 10px 0;
}

.attendance-details {
    font-size: 0.9em;
    max-height: 100px;
    overflow-y: auto;
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    border-left: 3px solid #007bff;
}

.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}
</style>

<main class="container-fluid py-4">
    <!-- Header -->
    <div class="report-header text-center">
        <h2><i class="fas fa-chart-bar me-2"></i>Attendance Reports</h2>
        <p class="mb-0">Generate and download detailed attendance reports for your courses</p>
    </div>

    <!-- Filter Form -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card filter-card">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Report Filters</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <div class="col-md-4">
                            <label for="course_id" class="form-label">Select Course *</label>
                            <select name="course_id" id="course_id" class="form-select" required>
                                <option value="">Choose a course...</option>
                                <?php foreach ($lecturer_courses as $course): ?>
                                    <option value="<?= $course['id'] ?>" 
                                            data-session="<?= htmlspecialchars($course['session_year']) ?>"
                                            data-semester="<?= htmlspecialchars($course['semester']) ?>"
                                            <?= ($selected_course == $course['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($course['course_code']) ?> - <?= htmlspecialchars($course['course_name']) ?>
                                        (<?= htmlspecialchars($course['session_year']) ?> - <?= ucfirst($course['semester']) ?> Semester)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="session_year" class="form-label">Session Year</label>
                            <select name="session_year" id="session_year" class="form-select">
                                <option value="2024/2025" <?= ($selected_session == '2024/2025') ? 'selected' : '' ?>>2024/2025</option>
                                <option value="2023/2024" <?= ($selected_session == '2023/2024') ? 'selected' : '' ?>>2023/2024</option>
                                <option value="2022/2023" <?= ($selected_session == '2022/2023') ? 'selected' : '' ?>>2022/2023</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="semester" class="form-label">Semester</label>
                            <select name="semester" id="semester" class="form-select">
                                <option value="first" <?= ($selected_semester == 'first') ? 'selected' : '' ?>>First Semester</option>
                                <option value="second" <?= ($selected_semester == 'second') ? 'selected' : '' ?>>Second Semester</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Results -->
    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selected_course): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="mb-1">
                                <i class="fas fa-book me-2"></i>
                                <?= htmlspecialchars($course_info['code'] ?? 'Course') ?> - 
                                <?= htmlspecialchars($course_info['name'] ?? 'Unknown Course') ?>
                            </h5>
                            <small class="text-muted">
                                <?= htmlspecialchars($selected_session) ?> Academic Session - 
                                <?= ucfirst($selected_semester) ?> Semester
                                | Enrolled Students: <?= $course_info['enrolled_students'] ?? 0 ?>
                            </small>
                        </div>
                        <?php if (!empty($attendance_report)): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="course_id" value="<?= $selected_course ?>">
                                <input type="hidden" name="session_year" value="<?= $selected_session ?>">
                                <input type="hidden" name="semester" value="<?= $selected_semester ?>">
                                <button type="submit" name="download" class="btn btn-success">
                                    <i class="fas fa-download me-1"></i>Download CSV
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($attendance_report)): ?>
                            <!-- Summary Statistics -->
                            <?php
                            $total_sessions = count($attendance_report);
                            $total_students_marked = array_sum(array_column($attendance_report, 'total_marked'));
                            $total_present = array_sum(array_column($attendance_report, 'present_count'));
                            $total_late = array_sum(array_column($attendance_report, 'late_count'));
                            $total_absent = array_sum(array_column($attendance_report, 'absent_count'));
                            $overall_attendance_rate = $total_students_marked > 0 ? 
                                round(($total_present + $total_late) / $total_students_marked * 100, 2) : 0;
                            ?>
                            
                            <div class="stats-row mb-4">
                                <div class="row text-center">
                                    <div class="col-md-2">
                                        <h4 class="text-primary mb-1"><?= $total_sessions ?></h4>
                                        <small class="text-muted">Total Sessions</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h4 class="text-info mb-1"><?= $total_students_marked ?></h4>
                                        <small class="text-muted">Total Marked</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h4 class="text-success mb-1"><?= $total_present ?></h4>
                                        <small class="text-muted">Present</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h4 class="text-warning mb-1"><?= $total_late ?></h4>
                                        <small class="text-muted">Late</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h4 class="text-danger mb-1"><?= $total_absent ?></h4>
                                        <small class="text-muted">Absent</small>
                                    </div>
                                    <div class="col-md-2">
                                        <h4 class="text-primary mb-1"><?= $overall_attendance_rate ?>%</h4>
                                        <small class="text-muted">Attendance Rate</small>
                                    </div>
                                </div>
                            </div>

                            <!-- Detailed Sessions -->
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Type</th>
                                            <th>Location</th>
                                            <th>Status</th>
                                            <th>Present</th>
                                            <th>Late</th>
                                            <th>Absent</th>
                                            <th>Rate</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendance_report as $session): ?>
                                            <?php 
                                            $session_rate = $session['total_marked'] > 0 ? 
                                                round(($session['present_count'] + $session['late_count']) / $session['total_marked'] * 100, 2) : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong><?= date('M j, Y', strtotime($session['session_date'])) ?></strong><br>
                                                    <small class="text-muted"><?= date('l', strtotime($session['session_date'])) ?></small>
                                                </td>
                                                <td>
                                                    <?= date('g:i A', strtotime($session['start_time'])) ?>
                                                    <?php if ($session['end_time']): ?>
                                                        <br><small class="text-muted">to <?= date('g:i A', strtotime($session['end_time'])) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= ucfirst($session['session_type']) ?></td>
                                                <td><?= htmlspecialchars($session['location'] ?: 'N/A') ?></td>
                                                <td>
                                                    <span class="status-badge <?= $session['session_status'] ?>">
                                                        <?= ucfirst($session['session_status']) ?>
                                                    </span>
                                                </td>
                                                <td><span class="badge bg-success"><?= $session['present_count'] ?></span></td>
                                                <td><span class="badge bg-warning"><?= $session['late_count'] ?></span></td>
                                                <td><span class="badge bg-danger"><?= $session['absent_count'] ?></span></td>
                                                <td>
                                                    <strong class="<?= $session_rate >= 75 ? 'text-success' : ($session_rate >= 50 ? 'text-warning' : 'text-danger') ?>">
                                                        <?= $session_rate ?>%
                                                    </strong>
                                                </td>
                                                <td>
                                                    <a href="view-session.php?session_id=<?= $session['session_id'] ?>" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-chart-line fa-4x mb-3"></i>
                                <h4>No Attendance Data Found</h4>
                                <p class="text-muted">
                                    No attendance sessions have been conducted for this course in the selected period.
                                </p>
                                <a href="mark-attendance.php" class="btn btn-primary">
                                    <i class="fas fa-plus-circle me-1"></i>Start Marking Attendance
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Course Statistics Overview (when no specific course selected) -->
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($lecturer_courses)): ?>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Your Courses Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course</th>
                                        <th>Session/Semester</th>
                                        <th>Enrolled Students</th>
                                        <th>Total Sessions</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($lecturer_courses as $course): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($course['course_code']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($course['course_name']) ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($course['session_year']) ?><br>
                                                <small class="text-muted"><?= ucfirst($course['semester']) ?> Semester</small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $course['enrolled_students'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $course['total_sessions'] ?></span>
                                            </td>
                                            <td>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                                    <input type="hidden" name="session_year" value="<?= $course['session_year'] ?>">
                                                    <input type="hidden" name="semester" value="<?= $course['semester'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-primary">
                                                        <i class="fas fa-chart-bar me-1"></i>View Report
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Back to Dashboard -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
            </a>
        </div>
    </div>
</main>

<script>
// Auto-populate session and semester when course is selected
document.getElementById('course_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    if (selectedOption.value) {
        const session = selectedOption.getAttribute('data-session');
        const semester = selectedOption.getAttribute('data-semester');
        
        if (session) {
            document.getElementById('session_year').value = session;
        }
        if (semester) {
            document.getElementById('semester').value = semester;
        }
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>