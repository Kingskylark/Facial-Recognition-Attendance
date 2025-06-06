<?php
session_start();
require_once '../config/database.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/login.php?role=student');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$student_id = $_SESSION['student_id'];
$message = '';
$message_type = '';

// Get student information
$stmt = $conn->prepare("
    SELECT s.*, d.name as department_name, f.name as faculty_name 
    FROM students s 
    JOIN departments d ON s.department_id = d.id 
    JOIN faculties f ON s.faculty_id = f.id 
    WHERE s.id = :student_id
");
$stmt->execute(['student_id' => $student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: ../public/login.php?role=student');
    exit;
}

// Handle course registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register_course'])) {
        $course_id = $_POST['course_id'];
        
        // Check if already registered
        $stmt = $conn->prepare("SELECT COUNT(*) FROM student_courses WHERE student_id = :student_id AND course_id = :course_id");
        $stmt->execute(['student_id' => $student_id, 'course_id' => $course_id]);
        
        if ($stmt->fetchColumn() == 0) {
            // Register for the course
            $stmt = $conn->prepare("INSERT INTO student_courses (student_id, course_id, created_at) VALUES (:student_id, :course_id, NOW())");
            if ($stmt->execute(['student_id' => $student_id, 'course_id' => $course_id])) {
                $message = "Course registered successfully!";
                $message_type = "success";
            } else {
                $message = "Error registering for course. Please try again.";
                $message_type = "danger";
            }
        } else {
            $message = "You are already registered for this course.";
            $message_type = "warning";
        }
    }
    
    if (isset($_POST['unregister_course'])) {
        $course_id = $_POST['course_id'];
        
        // Check if there are attendance records for this course
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM attendance a 
            JOIN attendance_sessions ats ON a.attendance_session_id = ats.id 
            WHERE a.student_id = :student_id AND ats.course_id = :course_id
        ");
        $stmt->execute(['student_id' => $student_id, 'course_id' => $course_id]);
        
        if ($stmt->fetchColumn() > 0) {
            $message = "Cannot unregister from course. You have attendance records for this course.";
            $message_type = "danger";
        } else {
            // Unregister from the course
            $stmt = $conn->prepare("DELETE FROM student_courses WHERE student_id = :student_id AND course_id = :course_id");
            if ($stmt->execute(['student_id' => $student_id, 'course_id' => $course_id])) {
                $message = "Course unregistered successfully!";
                $message_type = "success";
            } else {
                $message = "Error unregistering from course. Please try again.";
                $message_type = "danger";
            }
        }
    }
}

$stmt = $conn->prepare("
    SELECT DISTINCT c.*, 
           CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
           l.email as lecturer_email,
           d.name as course_department,
           d.code as dept_code,
           ce.is_general,
           ce.is_carryover_allowed,
           (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id) as enrolled_count,
           (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id AND sc.student_id = :student_id) as is_registered,
           CASE 
               WHEN ce.department_id = :dept_id1 AND ce.level = :level1 THEN 'core'
               WHEN ce.department_id = :dept_id2 AND ce.level != :level2 THEN 'departmental'
               WHEN ce.is_general = 1 THEN 'general'
               WHEN ce.department_id != :dept_id3 OR ce.level != :level3 THEN 'borrowed'
               ELSE 'elective'
           END as availability_type
    FROM courses c
    JOIN course_eligibility ce ON c.id = ce.course_id
    LEFT JOIN lecturers l ON c.lecturer_id = l.id
    LEFT JOIN departments d ON c.department_id = d.id
    WHERE c.is_active = 1
    AND (
        -- Student's own department courses (all levels)
        (ce.department_id = :dept_id4 AND ce.level BETWEEN 100 AND 300)
        OR
        -- General courses (GST, USE, etc.)
        (ce.is_general = 1 AND :level4 BETWEEN ce.min_level AND ce.max_level)
        OR
        -- Borrowed courses from other departments
        (ce.department_id IN (
            SELECT allowed_dept.id FROM departments allowed_dept 
            WHERE allowed_dept.id != :dept_id5
        ) AND :level5 BETWEEN ce.min_level AND ce.max_level)
        OR
        -- Carryover courses (from previous levels)
        (ce.is_carryover_allowed = 1 AND ce.level < :level6 AND ce.department_id = :dept_id6)
        OR
        -- Faculty-wide courses
        (ce.faculty_id = :faculty_id AND ce.department_id IS NULL)
        OR
        -- Universal courses (department_id and faculty_id are NULL)
        (ce.department_id IS NULL AND ce.faculty_id IS NULL AND :level7 BETWEEN ce.min_level AND ce.max_level)
    )
    ORDER BY 
        CASE availability_type
            WHEN 'core' THEN 1
            WHEN 'departmental' THEN 2
            WHEN 'general' THEN 3
            WHEN 'borrowed' THEN 4
        END,
        c.level ASC,
        c.code ASC
");

// Execute with named parameters (much clearer and less error-prone)
$stmt->execute([
    'student_id' => $student_id,
    'dept_id1' => $student['department_id'],
    'level1' => $student['level'],
    'dept_id2' => $student['department_id'],
    'level2' => $student['level'],
    'dept_id3' => $student['department_id'],
    'level3' => $student['level'],
    'dept_id4' => $student['department_id'],
    'level4' => $student['level'],
    'dept_id5' => $student['department_id'],
    'level5' => $student['level'],
    'level6' => $student['level'],
    'dept_id6' => $student['department_id'],
    'faculty_id' => $student['faculty_id'],
    'level7' => $student['level']
]);

$available_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group courses by type and level for better display
$course_groups = [
    'core' => [],
    'departmental' => [],
    'general' => [],
    'borrowed' => [],
    'carryover' => []
];

// Further group departmental courses by level
$departmental_by_level = [
    100 => [],
    200 => [],
    300 => [],
    400 => []
];

foreach ($available_courses as $course) {
    $type = $course['availability_type'];
    
    if ($course['level'] < $student['level'] && $course['availability_type'] == 'core') {
        $course_groups['carryover'][] = $course;
    } elseif ($type == 'departmental') {
        // Group departmental courses by level
        $level = $course['level'];
        if (isset($departmental_by_level[$level])) {
            $departmental_by_level[$level][] = $course;
        }
    } else {
        $course_groups[$type][] = $course;
    }
}

// Get registered courses
$stmt = $conn->prepare("
    SELECT c.*, 
           CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
           l.email as lecturer_email,
           sc.created_at as created_at,
           (SELECT COUNT(*) FROM attendance a 
            JOIN attendance_sessions ats ON a.attendance_session_id = ats.id 
            WHERE a.student_id = ? AND ats.course_id = c.id) as attendance_count
    FROM student_courses sc
    JOIN courses c ON sc.course_id = c.id
    LEFT JOIN lecturers l ON c.lecturer_id = l.id
    WHERE sc.student_id = ?
    ORDER BY c.code, c.name
");
$stmt->execute([$student_id, $student_id]);
$registered_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$total_registered = count($registered_courses);
$total_available = count($available_courses);
$total_credits = array_sum(array_column($registered_courses, 'credit_units'));

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

.course-header.core {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
}

.course-header.departmental {
    background: linear-gradient(135deg, #6f42c1 0%, #5a2c91 100%);
}

.course-header.general {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
}

.course-header.borrowed {
    background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);
}

.course-header.carryover {
    background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);
}

.course-header.registered {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

.registered { 
    background-color: #28a745; 
    color: white; 
}

.available { 
    background-color: #17a2b8; 
    color: white; 
}

.core-badge { 
    background-color: #007bff; 
    color: white; 
}

.departmental-badge { 
    background-color: #6f42c1; 
    color: white; 
}

.general-badge { 
    background-color: #28a745; 
    color: white; 
}

.borrowed-badge { 
    background-color: #17a2b8; 
    color: white; 
}

.carryover-badge { 
    background-color: #ffc107; 
    color: black; 
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

.level-title {
    color: #6f42c1;
    font-weight: 500;
    margin-bottom: 15px;
    margin-top: 25px;
    font-size: 1.1rem;
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

.btn-register {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    border: none;
    color: white;
    font-weight: 500;
}

.btn-unregister {
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
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <!-- Page Header -->
<div class="page-header">
    <div class="d-flex align-items-center mb-3">
        <a href="index.php" class="btn btn-outline-light me-3" style="border-radius: 10px; border: 2px solid rgba(255,255,255,0.3); backdrop-filter: blur(10px);">
            <i class="fas fa-arrow-left me-2"></i>
            Back to Dashboard
        </a>
        <div>
            <h2 class="mb-2">Course Registration</h2>
            <p class="mb-0">Register for core, departmental, general, and borrowed courses</p>
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
                <h3><?= $total_registered ?></h3>
                <p class="mb-0">Registered Courses</p>
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
                <h3><?= $total_credits ?></h3>
                <p class="mb-0">Total Credits</p>
            </div>
        </div>
    </div>

    <!-- Registered Courses -->
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-check-circle text-success"></i> My Registered Courses
            </h4>
        </div>
    </div>

    <?php if ($registered_courses): ?>
        <div class="row mb-5">
            <?php foreach ($registered_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header registered">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <span class="badge badge-status registered">Registered</span>
                            </div>
                            
                            <?php if ($course['lecturer_name']): ?>
                                <div class="course-meta mb-2">
                                    <i class="fas fa-user-tie"></i>
                                    <strong>Lecturer:</strong> <?= htmlspecialchars($course['lecturer_name']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-calendar"></i>
                                <strong>Registered:</strong> <?= date('M j, Y', strtotime($course['created_at'])) ?>
                            </div>
                            
                            <div class="course-meta mb-3">
                                <i class="fas fa-calendar-check"></i>
                                <strong>Classes Attended:</strong> <?= $course['attendance_count'] ?>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                <button type="submit" name="unregister_course" class="btn btn-unregister btn-sm" 
                                        onclick="return confirm('Are you sure you want to unregister from this course?')">
                                    <i class="fas fa-times"></i> Unregister
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="text-center py-5 mb-5">
            <i class="fas fa-book-open fa-4x text-muted mb-3"></i>
            <h5 class="text-muted">No Courses Registered</h5>
            <p class="text-muted">You haven't registered for any courses yet. Browse available courses below.</p>
        </div>
    <?php endif; ?>

    <!-- Core Courses (Student's Department & Level) -->
    <?php 
    // Show all departmental courses from student's current level as core courses
    $combined_core_courses = [];
    if (isset($departmental_by_level[$student['level']]) && !empty($departmental_by_level[$student['level']])) {
        $combined_core_courses = $departmental_by_level[$student['level']];
    }
    ?>

    <?php if (!empty($combined_core_courses)): ?>
        <div class="row">
            <div class="col-12">
                <h4 class="section-title">
                    <i class="fas fa-graduation-cap text-primary"></i> Core Courses 
                    <small class="text-muted">(Level <?= $student['level'] ?> - <?= htmlspecialchars($student['department_name']) ?>)</small>
                </h4>
            </div>
        </div>
        <div class="row mb-5">
            <?php foreach ($combined_core_courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header core">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <?php if ($course['is_registered']): ?>
                                    <span class="badge badge-status registered">Registered</span>
                                <?php else: ?>
                                    <span class="badge badge-status core-badge">Core</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($course['lecturer_name']): ?>
                                <div class="course-meta mb-2">
                                    <i class="fas fa-user-tie"></i>
                                    <strong>Lecturer:</strong> <?= htmlspecialchars($course['lecturer_name']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-users"></i>
                                <strong>Enrolled Students:</strong> <?= $course['enrolled_count'] ?>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!$course['is_registered']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                    <button type="submit" name="register_course" class="btn btn-register btn-sm">
                                        <i class="fas fa-plus"></i> Register
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-check"></i> Already Registered
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <!-- Departmental Courses (Other levels from same department) -->
    <?php 
    $has_departmental_courses = false;
    foreach ($departmental_by_level as $level => $courses) {
        // Skip courses that match the student's current level
        if ($level == $student['level'] || empty($courses)) {
            continue;
        }
        $has_departmental_courses = true;
        break;
    }
    ?>

    <?php if ($has_departmental_courses): ?>
        <div class="row">
            <div class="col-12">
                <h4 class="section-title">
                    <i class="fas fa-building text-purple"></i> Other Departmental Courses 
                    <small class="text-muted">(<?= htmlspecialchars($student['department_name']) ?>)</small>
                </h4>
            </div>
        </div>

        <?php foreach ($departmental_by_level as $level => $courses): ?>
            <?php if (!empty($courses) && $level != $student['level']): ?>
                <div class="col-12">
                    <h5 class="level-title">
                        <i class="fas fa-layer-group"></i> <?= $level ?> Level Courses
                    </h5>
                </div>
                <div class="row mb-4">
                    <?php foreach ($courses as $course): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card course-card shadow-sm">
                                <div class="course-header departmental">
                                    <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                                    <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                                </div>
                                <div class="card-body">
                                    <div class="course-info">
                                        <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                        <?php if ($course['is_registered']): ?>
                                            <span class="badge badge-status registered">Registered</span>
                                        <?php else: ?>
                                            <span class="badge badge-status departmental-badge">Departmental</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="course-meta mb-2">
                                        <i class="fas fa-level-up-alt"></i>
                                        <strong>Level:</strong> <?= $course['level'] ?>
                                    </div>
                                    
                                    <?php if ($course['lecturer_name']): ?>
                                        <div class="course-meta mb-2">
                                            <i class="fas fa-user-tie"></i>
                                            <strong>Lecturer:</strong> <?= htmlspecialchars($course['lecturer_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="course-meta mb-2">
                                        <i class="fas fa-users"></i>
                                        <strong>Enrolled Students:</strong> <?= $course['enrolled_count'] ?>
                                    </div>
                                    
                                    <?php if ($course['description']): ?>
                                        <div class="course-meta mb-3">
                                            <small><?= htmlspecialchars($course['description']) ?></small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!$course['is_registered']): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                            <button type="submit" name="register_course" class="btn btn-register btn-sm">
                                                <i class="fas fa-plus"></i> Register
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled>
                                            <i class="fas fa-check"></i> Already Registered
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="mb-4"></div>
    <?php endif; ?>

    <!-- General Studies Courses (GST, USE, etc.) -->
    <?php if (!empty($course_groups['general'])): ?>
        <div class="row">
            <div class="col-12">
                <h4 class="section-title">
                    <i class="fas fa-globe text-success"></i> General Studies Courses
                    <small class="text-muted">(Available to all students)</small>
                </h4>
            </div>
        </div>
        <div class="row mb-5">
            <?php foreach ($course_groups['general'] as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header general">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <?php if ($course['is_registered']): ?>
                                    <span class="badge badge-status registered">Registered</span>
                                <?php else: ?>
                                    <span class="badge badge-status general-badge">General</span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($course['lecturer_name']): ?>
                                <div class="course-meta mb-2">
                                    <i class="fas fa-user-tie"></i>
                                    <strong>Lecturer:</strong> <?= htmlspecialchars($course['lecturer_name']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-users"></i>
                                <strong>Enrolled Students:</strong> <?= $course['enrolled_count'] ?>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!$course['is_registered']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                    <button type="submit" name="register_course" class="btn btn-register btn-sm">
                                        <i class="fas fa-plus"></i> Register
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-check"></i> Already Registered
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Borrowed Courses (From other departments) -->
    <?php if (!empty($course_groups['borrowed'])): ?>
        <div class="row">
            <div class="col-12">
                <h4 class="section-title">
                    <i class="fas fa-exchange-alt text-info"></i> Borrowed Courses
                    <small class="text-muted">(From other departments)</small>
                </h4>
            </div>
        </div>
        <div class="row mb-5">
            <?php foreach ($course_groups['borrowed'] as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header borrowed">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <?php if ($course['is_registered']): ?>
                                    <span class="badge badge-status registered">Registered</span>
                                <?php else: ?>
                                    <span class="badge badge-status borrowed-badge">Borrowed</span>
                                <?php endif
                                ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-building"></i>
                                <strong>Department:</strong> <?= htmlspecialchars($course['course_department']) ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-level-up-alt"></i>
                                <strong>Level:</strong> <?= $course['level'] ?>
                            </div>
                            
                            <?php if ($course['lecturer_name']): ?>
                                <div class="course-meta mb-2">
                                    <i class="fas fa-user-tie"></i>
                                    <strong>Lecturer:</strong> <?= htmlspecialchars($course['lecturer_name']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-users"></i>
                                <strong>Enrolled Students:</strong> <?= $course['enrolled_count'] ?>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!$course['is_registered']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                    <button type="submit" name="register_course" class="btn btn-register btn-sm">
                                        <i class="fas fa-plus"></i> Register
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-check"></i> Already Registered
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Carryover Courses (Previous level courses from same department) -->
    <?php if (!empty($course_groups['carryover'])): ?>
        <div class="row">
            <div class="col-12">
                <h4 class="section-title">
                    <i class="fas fa-redo text-warning"></i> Carryover Courses
                    <small class="text-muted">(Previous level courses you can retake)</small>
                </h4>
            </div>
        </div>
        <div class="row mb-5">
            <?php foreach ($course_groups['carryover'] as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header carryover">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <?php if ($course['is_registered']): ?>
                                    <span class="badge badge-status registered">Registered</span>
                                <?php else: ?>
                                    <span class="badge badge-status carryover-badge">Carryover</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-level-down-alt"></i>
                                <strong>Original Level:</strong> <?= $course['level'] ?>
                            </div>
                            
                            <?php if ($course['lecturer_name']): ?>
                                <div class="course-meta mb-2">
                                    <i class="fas fa-user-tie"></i>
                                    <strong>Lecturer:</strong> <?= htmlspecialchars($course['lecturer_name']) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-users"></i>
                                <strong>Enrolled Students:</strong> <?= $course['enrolled_count'] ?>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <div class="course-meta mb-3">
                                    <small><?= htmlspecialchars($course['description']) ?></small>
                                </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-warning alert-sm mb-3">
                                <small><i class="fas fa-info-circle"></i> This is a carryover course from a previous level.</small>
                            </div>
                            
                            <?php if (!$course['is_registered']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="course_id" value="<?= $course['id'] ?>">
                                    <button type="submit" name="register_course" class="btn btn-register btn-sm">
                                        <i class="fas fa-plus"></i> Register
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary btn-sm" disabled>
                                    <i class="fas fa-check"></i> Already Registered
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- No Available Courses Message -->
    <?php if (empty($available_courses)): ?>
        <div class="text-center py-5">
            <i class="fas fa-search fa-4x text-muted mb-3"></i>
            <h5 class="text-muted">No Available Courses</h5>
            <p class="text-muted">There are currently no courses available for registration.</p>
        </div>
    <?php endif; ?>

    <!-- Registration Guidelines -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="border-radius: 15px;">
                <div class="card-header bg-light" style="border-radius: 15px 15px 0 0;">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle text-info"></i> Course Registration Guidelines
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">Course Types:</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <span class="badge core-badge me-2">Core</span>
                                    Required courses for your current level and department
                                </li>
                                <li class="mb-2">
                                    <span class="badge departmental-badge me-2">Departmental</span>
                                    Optional courses from your department (other levels)
                                </li>
                                <li class="mb-2">
                                    <span class="badge general-badge me-2">General</span>
                                    General studies courses (GST, USE, etc.)
                                </li>
                                <li class="mb-2">
                                    <span class="badge borrowed-badge me-2">Borrowed</span>
                                    Courses from other departments
                                </li>
                                <li class="mb-2">
                                    <span class="badge carryover-badge me-2">Carryover</span>
                                    Previous level courses you can retake
                                </li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success mb-3">Registration Rules:</h6>
                            <ul class="list-unstyled">
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    You can register for multiple courses at once
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Core courses are highly recommended for your level
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                    Cannot unregister if you have attendance records
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-info-circle text-info me-2"></i>
                                    Check prerequisites before registering
                                </li>
                                <li class="mb-2">
                                    <i class="fas fa-clock text-secondary me-2"></i>
                                    Registration may have deadlines - register early
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Additional Custom JavaScript -->
<script>
// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert && alert.classList.contains('show')) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    });
});

// Smooth scroll to sections
function scrollToSection(sectionId) {
    const element = document.getElementById(sectionId);
    if (element) {
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
}

// Add loading state to buttons
// document.querySelectorAll('form button[type="submit"]').forEach(button => {
//     button.addEventListener('click', function() {
//         const originalHtml = this.innerHTML;
//         this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
//         this.disabled = true;
        
//         // Re-enable button after form submission (in case of validation errors)
//         setTimeout(() => {
//             this.innerHTML = originalHtml;
//             this.disabled = false;
//         }, 3000);
//     });
// });

// Course card hover effects
document.querySelectorAll('.course-card').forEach(card => {
    card.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-5px)';
        this.style.boxShadow = '0 10px 30px rgba(0,0,0,0.2)';
    });
    
    card.addEventListener('mouseleave', function() {
        this.style.transform = 'translateY(0)';
        this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
    });
});

// Search functionality for courses
function createSearchBox() {
    const searchHtml = `
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius: 15px;">
                    <div class="card-body">
                        <h6 class="mb-3">
                            <i class="fas fa-search text-primary"></i> Search Courses
                        </h6>
                        <div class="row">
                            <div class="col-md-8">
                                <input type="text" id="courseSearch" class="form-control" 
                                       placeholder="Search by course code, name, or lecturer..." 
                                       style="border-radius: 10px;">
                            </div>
                            <div class="col-md-4">
                                <select id="courseFilter" class="form-select" style="border-radius: 10px;">
                                    <option value="">All Course Types</option>
                                    <option value="core">Core Courses</option>
                                    <option value="departmental">Departmental</option>
                                    <option value="general">General Studies</option>
                                    <option value="borrowed">Borrowed</option>
                                    <option value="carryover">Carryover</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Insert search box after statistics
    const statsRow = document.querySelector('.row.mb-4');
    if (statsRow) {
        statsRow.insertAdjacentHTML('afterend', searchHtml);
        
        // Add search functionality
        const searchInput = document.getElementById('courseSearch');
        const filterSelect = document.getElementById('courseFilter');
        
        function filterCourses() {
            const searchTerm = searchInput.value.toLowerCase();
            const filterType = filterSelect.value;
            
            document.querySelectorAll('.course-card').forEach(card => {
                const courseCode = card.querySelector('.course-code')?.textContent.toLowerCase() || '';
                const courseTitle = card.querySelector('.course-title')?.textContent.toLowerCase() || '';
                const lecturerName = card.querySelector('[data-lecturer]')?.textContent.toLowerCase() || '';
                const cardHeader = card.querySelector('.course-header');
                
                const matchesSearch = courseCode.includes(searchTerm) || 
                                    courseTitle.includes(searchTerm) || 
                                    lecturerName.includes(searchTerm);
                
                let matchesFilter = true;
                if (filterType) {
                    matchesFilter = cardHeader?.classList.contains(filterType);
                }
                
                const shouldShow = matchesSearch && matchesFilter;
                card.closest('.col-md-6, .col-lg-4').style.display = shouldShow ? 'block' : 'none';
            });
        }
        
        searchInput.addEventListener('input', filterCourses);
        filterSelect.addEventListener('change', filterCourses);
    }
}

// Initialize search functionality after page load
document.addEventListener('DOMContentLoaded', createSearchBox);
</script>

<?php include_once '../includes/footer.php'; ?>