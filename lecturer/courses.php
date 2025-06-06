<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$lecturer_id = $_SESSION['lecturer_id'];
$message = '';
$message_type = '';

// Get lecturer information
$stmt = $conn->prepare("
    SELECT l.*, d.name as department_name, f.name as faculty_name 
    FROM lecturers l 
    JOIN departments d ON l.department_id = d.id 
    JOIN faculties f ON l.faculty_id = f.id 
    WHERE l.id = :lecturer_id
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturer) {
    header('Location: ../public/login.php?role=lecturer');
    exit;
}

// Get current academic session and semester (you might want to make this dynamic)
$current_session = '2024/2025';
$current_semester = 'first'; // This could be determined by current date


// Handle course selection/deselection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['select_course'])) {
        $course_id = $_POST['course_id'];
        
        // Check if already selected
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM lecturer_courses 
            WHERE lecturer_id = :lecturer_id AND course_id = :course_id 
            AND session_year = :session AND semester = :semester
        ");
        $stmt->execute([
            'lecturer_id' => $lecturer_id, 
            'course_id' => $course_id,
            'session' => $current_session,
            'semester' => $current_semester
        ]);
        
        if ($stmt->fetchColumn() == 0) {
            // Insert into lecturer_courses table
            $stmt = $conn->prepare("
                INSERT INTO lecturer_courses (lecturer_id, course_id, session_year, semester, created_at) 
                VALUES (:lecturer_id, :course_id, :session, :semester, NOW())
            ");
            if ($stmt->execute([
                'lecturer_id' => $lecturer_id, 
                'course_id' => $course_id,
                'session' => $current_session,
                'semester' => $current_semester
            ])) {
                // Update courses table
                $stmt = $conn->prepare("UPDATE courses SET lecturer_id = ? WHERE id = ?");
                $stmt->execute([$lecturer_id, $course_id]);
                
                $message = "Course selected successfully!";
                $message_type = "success";
            } else {
                $message = "Error selecting course. Please try again.";
                $message_type = "danger";
            }
        } else {
            $message = "You have already selected this course for the current session.";
            $message_type = "warning";
        }
    }
    
    if (isset($_POST['deselect_course'])) {
        $course_id = $_POST['course_id'];
        
        // Check if there are attendance sessions for this course
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM attendance_sessions 
            WHERE course_id = :course_id AND lecturer_id = :lecturer_id
        ");
        $stmt->execute(['course_id' => $course_id, 'lecturer_id' => $lecturer_id]);
        
        if ($stmt->fetchColumn() > 0) {
            $message = "Cannot deselect course. You have created attendance sessions for this course.";
            $message_type = "danger";
        } else {
            // Delete from lecturer_courses table
            $stmt = $conn->prepare("
                DELETE FROM lecturer_courses 
                WHERE lecturer_id = :lecturer_id AND course_id = :course_id 
                AND session_year = :session AND semester = :semester
            ");
            if ($stmt->execute([
                'lecturer_id' => $lecturer_id, 
                'course_id' => $course_id,
                'session' => $current_session,
                'semester' => $current_semester
            ])) {
                // Update courses table - remove lecturer_id
                $stmt = $conn->prepare("UPDATE courses SET lecturer_id = NULL WHERE id = ? AND lecturer_id = ?");
                $stmt->execute([$course_id, $lecturer_id]);
                
                $message = "Course deselected successfully!";
                $message_type = "success";
            } else {
                $message = "Error deselecting course. Please try again.";
                $message_type = "danger";
            }
        }
    }
}
 
$stmt = $conn->prepare("
    SELECT DISTINCT c.*,
           d.name as department_name,
           d.code as dept_code,
           f.name as faculty_name,
           (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id) as enrolled_students,
           (SELECT COUNT(*) FROM lecturer_courses lc 
            WHERE lc.course_id = c.id AND lc.lecturer_id = :lecturer_id 
            AND lc.session_year = :session AND lc.semester = :semester) as is_selected,
           CASE 
               WHEN c.department_id = :dept_id THEN 'departmental'
               WHEN c.course_type = 'general' THEN 'general'
               WHEN c.department_id != :dept_id2 AND d.faculty_id = :faculty_id THEN 'cross_department'
               ELSE 'other'
           END as course_type
    FROM courses c
    JOIN departments d ON c.department_id = d.id
    JOIN faculties f ON d.faculty_id = f.id
    WHERE c.is_active = 1
    AND c.semester = :semester2
    AND (
        -- Departmental courses (from lecturer's department)
        c.department_id = :dept_id3
        OR
        -- General courses (available to all lecturers)
        c.course_type = 'general'
        OR
        -- Cross-department courses (other departments within same faculty)
        (c.department_id != :dept_id4 AND d.faculty_id = :faculty_id2)
    )
    ORDER BY 
        CASE 
            WHEN c.department_id = :dept_id5 THEN 1  -- Departmental first
            WHEN c.course_type = 'general' THEN 2    -- General second
            ELSE 3                                   -- Cross-department third
        END,
        c.level ASC,
        c.code ASC
");

$stmt->execute([
    'lecturer_id' => $lecturer_id,
    'session' => $current_session,
    'semester' => $current_semester,
    'semester2' => $current_semester,
    'dept_id' => $lecturer['department_id'],
    'dept_id2' => $lecturer['department_id'],
    'dept_id3' => $lecturer['department_id'],
    'dept_id4' => $lecturer['department_id'],
    'dept_id5' => $lecturer['department_id'],
    'faculty_id' => $lecturer['faculty_id'],
    'faculty_id2' => $lecturer['faculty_id']
]);

$available_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group available courses by type
$course_groups = [
    'departmental' => [],
    'general' => [],
    'cross_department' => []
];

$selected_courses = [];

foreach ($available_courses as $course) {
    // Grouping
    $type = $course['course_type'];
    if (isset($course_groups[$type])) {
        $course_groups[$type][] = $course;
    }

    // Selection logic
    if ($course['is_selected'] > 0) {
        $selected_courses[] = $course;
    }
}

// Get statistics
$total_selected = count($selected_courses);
$total_available = count($available_courses);
$total_students = array_sum(array_column($selected_courses, 'enrolled_students'));

?>

<?php include_once '../includes/header.php'; ?>

<style>
.course-card {
    border: none;
    border-radius: 15px;
    transition: all 0.3s ease;
    height: 100%;
}

.course-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.course-header {
    color: white;
    border-radius: 15px 15px 0 0;
    padding: 20px;
}

.course-header.departmental {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
}

.course-header.borrowed {
    background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);
}

.course-header.other {
    background: linear-gradient(135deg, #6f42c1 0%, #5a2c91 100%);
}

.course-header.selected {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
}

.course-code {
    font-size: 1.2rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.course-title {
    font-size: 1rem;
    margin-bottom: 0;
}

.stats-card {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: white;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    margin-bottom: 20px;
}

.stats-card h3 {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.badge-status {
    padding: 0.5em 1em;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 500;
}

.selected { 
    background-color: #28a745; 
    color: white; 
}

.available { 
    background-color: #17a2b8; 
    color: white; 
}

.departmental-badge { 
    background-color: #007bff; 
    color: white; 
}

.borrowed-badge { 
    background-color: #17a2b8; 
    color: white; 
}

.other-badge { 
    background-color: #6f42c1; 
    color: white; 
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
}

.section-title {
    color: #495057;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.course-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.course-meta {
    font-size: 0.9em;
    color: #6c757d;
}

.btn-select {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    border: none;
    color: white;
    font-weight: 500;
}

.btn-deselect {
    background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
    border: none;
    color: white;
    font-weight: 500;
}

.alert-custom {
    border-radius: 15px;
    border: none;
    font-weight: 500;
}

.session-info {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 30px;
    border: 2px solid #dee2e6;
}
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="page-header">
        <div class="d-flex align-items-center mb-3">
            <a href="index.php" class="btn btn-outline-light me-3" style="border-radius: 10px; border: 2px solid rgba(255,255,255,0.3); backdrop-filter: blur(10px);">
                <i class="fas fa-arrow-left me-2"></i>
                Back to Dashboard
            </a>
            <div>
                <h2 class="mb-2">My Teaching Courses</h2>
                <p class="mb-0">Select and manage courses you want to teach this semester</p>
            </div>
        </div>
    </div>

    <!-- Current Session Info -->
    <div class="session-info">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="mb-2">
                    <i class="fas fa-calendar-alt text-primary"></i> 
                    Current Academic Session: <?= htmlspecialchars($current_session) ?>
                </h5>
                <p class="mb-0">
                    <i class="fas fa-clock text-info"></i> 
                    Semester: <?= ucfirst($current_semester) ?> Semester
                </p>
            </div>
            <div class="col-md-4 text-md-end">
                <p class="mb-1"><strong>Department:</strong> <?= htmlspecialchars($lecturer['department_name']) ?></p>
                <p class="mb-0"><strong>Faculty:</strong> <?= htmlspecialchars($lecturer['faculty_name']) ?></p>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-custom alert-dismissible fade show" role="alert">
            <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'times-circle') ?>"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stats-card">
                <h3><?= $total_selected ?></h3>
                <p class="mb-0">Selected Courses</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <h3><?= $total_available ?></h3>
                <p class="mb-0">Available Courses</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stats-card">
                <h3><?= $total_students ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
        </div>
    </div>

    <!-- Selected Courses -->
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-check-circle text-success"></i> My Selected Courses
            </h4>
        </div>
    </div>

    <?php if ($selected_courses): ?>
        <div class="row mb-5">
            <?php foreach ($selected_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header selected">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <span class="badge badge-status selected">Selected</span>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-building"></i>
                                <strong>Department:</strong> <?= htmlspecialchars($course['department_name']) ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-layer-group"></i>
                                <strong>Level:</strong> <?= $course['level'] ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-users"></i>
                                <strong>Enrolled Students:</strong> <?= $course['enrolled_students'] ?>
                            </div>                          
                            
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex gap-2">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                    <button type="submit" name="deselect_course" class="btn btn-deselect btn-sm" 
                                            onclick="return confirm('Are you sure you want to deselect this course?')">
                                        <i class="fas fa-times"></i> Deselect
                                    </button>
                                </form>
                                
                                <a href="attendance.php?course_id=<?= $course['id'] ?>" class="btn btn-info btn-sm">
                                    <i class="fas fa-clipboard-list"></i> Attendance
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-5 mb-5">
            <i class="fas fa-chalkboard-teacher fa-4x text-muted mb-3"></i>
            <h5 class="text-muted">No Courses Selected</h5>
            <p class="text-muted">You haven't selected any courses to teach yet. Browse available courses below.</p>
        </div>
    <?php endif; ?>

    <!-- Departmental Courses -->
    <?php if (!empty($course_groups['departmental'])): ?>
        <div class="row">
            <div class="col-12">
                <h4 class="section-title">
                    <i class="fas fa-building text-primary"></i> Departmental Courses 
                    <small class="text-muted">(<?= htmlspecialchars($lecturer['department_name']) ?>)</small>
                </h4>
            </div>
        </div>
        <div class="row mb-5">
            <?php foreach ($course_groups['departmental'] as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header departmental">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <?php if ($course['is_selected']): ?>
                                    <span class="badge badge-status selected">Selected</span>
                                <?php else: ?>
                                    <span class="badge badge-status departmental-badge">Departmental</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-layer-group"></i>
                                <strong>Level:</strong> <?= $course['level'] ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-users"></i>
                                <strong>Enrolled Students:</strong> <?= $course['enrolled_students'] ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-calendar"></i>
                                <strong>Semester:</strong> <?= ucfirst($course['semester']) ?>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!$course['is_selected']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                    <button type="submit" name="select_course" class="btn btn-select btn-sm">
                                        <i class="fas fa-plus"></i> Select Course
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-check"></i> Already Selected
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php
// Display General Courses
if (!empty($course_groups['general'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-globe text-purple"></i> General Courses
            </h4>
        </div>
    </div>
    <div class="row mb-5">
        <?php foreach ($course_groups['general'] as $course): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card course-card shadow-sm">
                    <div class="course-header other">
                        <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                        <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                    </div>
                    <div class="card-body">
                        <div class="course-info">
                            <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                            <?php if ($course['is_selected']): ?>
                                <span class="badge badge-status selected">Selected</span>
                            <?php else: ?>
                                <span class="badge badge-status other-badge">General</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-building"></i>
                            <strong>Department:</strong> <?= htmlspecialchars($course['department_name']) ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-university"></i>
                            <strong>Faculty:</strong> <?= htmlspecialchars($course['faculty_name']) ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-layer-group"></i>
                            <strong>Level:</strong> <?= $course['level'] ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-users"></i>
                            <strong>Enrolled Students:</strong> <?= $course['enrolled_students'] ?>
                        </div>
                        
                        <?php if ($course['description']): ?>
                            <div class="course-meta mb-3">
                                <small><?= htmlspecialchars($course['description']) ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$course['is_selected']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                <button type="submit" name="select_course" class="btn btn-select btn-sm">
                                    <i class="fas fa-plus"></i> Select Course
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-sm" disabled>
                                <i class="fas fa-check"></i> Already Selected
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// Display Cross-Department Courses
if (!empty($course_groups['cross_department'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-exchange-alt text-info"></i> Cross-Department Courses
            </h4>
        </div>
    </div>
    <div class="row mb-5">
        <?php foreach ($course_groups['cross_department'] as $course): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card course-card shadow-sm">
                    <div class="course-header borrowed">
                        <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                        <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                    </div>
                    <div class="card-body">
                        <div class="course-info">
                            <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                            <?php if ($course['is_selected']): ?>
                                <span class="badge badge-status selected">Selected</span>
                            <?php else: ?>
                                <span class="badge badge-status borrowed-badge">Cross-Dept</span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-building"></i>
                            <strong>Department:</strong> <?= htmlspecialchars($course['department_name']) ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-university"></i>
                            <strong>Faculty:</strong> <?= htmlspecialchars($course['faculty_name']) ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-layer-group"></i>
                            <strong>Level:</strong> <?= $course['level'] ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-users"></i>
                            <strong>Enrolled Students:</strong> <?= $course['enrolled_students'] ?>
                        </div>
                        
                        <?php if ($course['description']): ?>
                            <div class="course-meta mb-3">
                                <small><?= htmlspecialchars($course['description']) ?></small>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!$course['is_selected']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                <button type="submit" name="select_course" class="btn btn-select btn-sm">
                                    <i class="fas fa-plus"></i> Select Course
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-sm" disabled>
                                <i class="fas fa-check"></i> Already Selected
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
// Alternative: If you want to combine both in one section like your original code
if (!empty($course_groups['general']) || !empty($course_groups['cross_department'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-globe text-purple"></i> General & Cross-Department Courses
            </h4>
        </div>
    </div>
    
    </div>
<?php endif; ?>

    <!-- No Available Courses Message -->
    <?php if (empty($available_courses)): ?>
        <div class="text-center py-5">
            <i class="fas fa-search fa-4x text-muted mb-3"></i>
            <h5 class="text-muted">No Available Courses</h5>
            <p class="text-muted">There are currently no courses available for selection in this semester.</p>
        </div>
    <?php endif; ?>

    <!-- Course Selection Guidelines -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-radius: 15px;">
                <div class="card-header bg-light" style="border-radius: 15px 15px 0 0;">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle text-info"></i> Course Selection Guidelines
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">Course Categories:</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <span class="badge departmental-badge me-2">Departmental</span>
                                    Courses from your department that you can teach
                                </li>
                                <li class="mb-2">
                                    <span class="badge borrowed-badge me-2">Cross-Dept</span>
                                    Courses from other departments you're qualified to teach
                                </li>
                                <li class="mb-2">
                                    <span class="badge other-badge me-2">General</span>
                                    General studies and interdisciplinary courses
                                </li>
                            </ul>
                        </div>

                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success mb-3">Important Notes:</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    You can select multiple courses per semester
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    Cannot deselect courses with existing attendance sessions
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-users text-info me-2"></i>
                                    Check enrolled student count before selecting
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-calendar-alt text-primary me-2"></i>
                                    Course selection is per academic session and semester
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3" role="alert">
                        <i class="fas fa-lightbulb me-2"></i>
                        <strong>Tip:</strong> Review the course descriptions and student enrollment numbers to make informed decisions about which courses to teach.
                    </div>
                </div>
            </div>
        </div>
    </div>

</main>

<?php include_once '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add loading state to buttons
    const formsSimple = document.querySelectorAll('form');
formsSimple.forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.disabled) {
            // Use setTimeout to allow form submission to process first
            setTimeout(() => {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }, 50);
        }
    });
});

    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.classList.contains('show')) {
                alert.classList.remove('show');
                alert.classList.add('fade');
                setTimeout(() => {
                    alert.remove();
                }, 150);
            }
        }, 5000);
    });

    // Add smooth scrolling for better UX
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });

    // Course card hover effects
    const courseCards = document.querySelectorAll('.course-card');
    courseCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Confirmation for course deselection with more details
    const deselectForms = document.querySelectorAll('form[method="POST"]');
    deselectForms.forEach(form => {
        const deselectBtn = form.querySelector('button[name="deselect_course"]');
        if (deselectBtn) {
            deselectBtn.addEventListener('click', function(e) {
                e.preventDefault();
                
                const courseCard = this.closest('.course-card');
                const courseCode = courseCard.querySelector('.course-code').textContent;
                const courseName = courseCard.querySelector('.course-title').textContent;
                const attendanceSessions = courseCard.querySelector('.course-meta:contains("Attendance Sessions")');
                
                let confirmMsg = `Are you sure you want to deselect the course:\n\n${courseCode} - ${courseName}?`;
                
                if (attendanceSessions && attendanceSessions.textContent.includes('0')) {
                    confirmMsg += '\n\nThis action cannot be undone.';
                } else {
                    confirmMsg += '\n\nNote: You have attendance sessions for this course, so deselection may not be allowed.';
                }
                
                if (confirm(confirmMsg)) {
                    form.submit();
                }
            });
        }
    });

    // Add tooltips for better user experience
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Stats counter animation
    const statsNumbers = document.querySelectorAll('.stats-card h3');
    statsNumbers.forEach(stat => {
        const finalNumber = parseInt(stat.textContent);
        let currentNumber = 0;
        const increment = Math.ceil(finalNumber / 20);
        
        const timer = setInterval(() => {
            currentNumber += increment;
            if (currentNumber >= finalNumber) {
                currentNumber = finalNumber;
                clearInterval(timer);
            }
            stat.textContent = currentNumber;
        }, 50);
    });

    // Search functionality for courses (if needed in future)
    function initCourseSearch() {
        const searchInput = document.getElementById('courseSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const courseCards = document.querySelectorAll('.course-card');
                
                courseCards.forEach(card => {
                    const courseCode = card.querySelector('.course-code').textContent.toLowerCase();
                    const courseName = card.querySelector('.course-title').textContent.toLowerCase();
                    const department = card.querySelector('.course-meta').textContent.toLowerCase();
                    
                    if (courseCode.includes(searchTerm) || 
                        courseName.includes(searchTerm) || 
                        department.includes(searchTerm)) {
                        card.closest('.col-md-6, .col-lg-4').style.display = 'block';
                    } else {
                        card.closest('.col-md-6, .col-lg-4').style.display = 'none';
                    }
                });
            });
        }
    }

    // Initialize search if search input exists
    initCourseSearch();
});

// Function to show success message with animation
function showSuccessMessage(message) {
    const alertHtml = `
        <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert" style="position: fixed; top: 100px; right: 20px; z-index: 9999; min-width: 300px;">
            <i class="fas fa-check-circle"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', alertHtml);
    
    // Auto-remove after 4 seconds
    setTimeout(() => {
        const alert = document.querySelector('.alert[style*="position: fixed"]');
        if (alert) {
            alert.remove();
        }
    }, 4000);
}

// Function to handle course selection with better feedback
function handleCourseSelection(courseId, courseName, action) {
    // Show loading state
    const actionBtn = document.querySelector(`form input[value="${courseId}"]`).closest('form').querySelector('button');
    const originalText = actionBtn.innerHTML;
    actionBtn.disabled = true;
    actionBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    
    // Simulate processing (in real implementation, this would be handled by the form submission)
    setTimeout(() => {
        actionBtn.disabled = false;
        actionBtn.innerHTML = originalText;
        
        if (action === 'select') {
            showSuccessMessage(`Successfully selected course: ${courseName}`);
        } else {
            showSuccessMessage(`Successfully deselected course: ${courseName}`);
        }
    }, 1000);
}
</script>

<style>
/* Additional responsive styles */
@media (max-width: 768px) {
    .stats-card {
        margin-bottom: 15px;
    }
    
    .stats-card h3 {
        font-size: 2rem;
    }
    
    .course-card {
        margin-bottom: 20px;
    }
    
    .page-header {
        padding: 20px;
        text-align: center;
    }
    
    .page-header .d-flex {
        flex-direction: column;
        align-items: center !important;
    }
    
    .page-header .btn {
        margin-bottom: 15px;
    }
    
    .session-info .row {
        text-align: center;
    }
    
    .session-info .col-md-4 {
        margin-top: 15px;
    }
}

@media (max-width: 576px) {
    .container-fluid {
        padding-left: 15px;
        padding-right: 15px;
    }
    
    .course-card {
        margin-bottom: 15px;
    }
    
    .course-header {
        padding: 15px;
    }
    
    .course-code {
        font-size: 1rem;
    }
    
    .stats-card {
        padding: 20px;
    }
    
    .stats-card h3 {
        font-size: 1.8rem;
    }
}

/* Loading animation */
@keyframes pulse {
    0% {
        opacity: 1;
    }
    50% {
        opacity: 0.5;
    }
    100% {
        opacity: 1;
    }
}

.loading {
    animation: pulse 1.5s ease-in-out infinite;
}

/* Custom scrollbar for better aesthetics */
::-webkit-scrollbar {
    width: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
}

/* Print styles */
@media print {
    .btn, .alert, .page-header .btn {
        display: none !important;
    }
    
    .course-card {
        break-inside: avoid;
    }
    
    .stats-card {
        background: #f8f9fa !important;
        color: #333 !important;
    }
}
</style>



