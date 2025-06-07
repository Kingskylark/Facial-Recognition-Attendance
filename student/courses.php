<?php
session_start();
require_once '../config/database.php';

// Check if student is logged in
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../public/login.php?role=student');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$student_id = $_SESSION['student_id'];
$message = '';
$message_type = '';

// Handle course registration
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register_course'])) {
        $course_id = $_POST['course_id'];
        
        // Check if already registered
        $check_stmt = $conn->prepare("SELECT id FROM student_courses WHERE student_id = ? AND course_id = ?");
        $check_stmt->execute([$student_id, $course_id]);
        
        if ($check_stmt->rowCount() > 0) {
            $message = "You are already registered for this course.";
            $message_type = "warning";
        } else {
            // Register for the course
            $register_stmt = $conn->prepare("INSERT INTO student_courses (student_id, course_id, created_at) VALUES (?, ?, NOW())");
            
            if ($register_stmt->execute([$student_id, $course_id])) {
                $message = "Course registered successfully!";
                $message_type = "success";
            } else {
                $message = "Error registering for course. Please try again.";
                $message_type = "danger";
            }
        }
    }
    
    if (isset($_POST['unregister_course'])) {
        $course_id = $_POST['course_id'];
        
        // Check if student has attendance records for this course
        $attendance_check = $conn->prepare("
            SELECT COUNT(*) as attendance_count 
            FROM attendance a 
            JOIN attendance_sessions ats ON a.attendance_session_id = ats.id 
            WHERE a.student_id = ? AND ats.course_id = ?
        ");
        $attendance_check->execute([$student_id, $course_id]);
        $attendance_result = $attendance_check->fetch(PDO::FETCH_ASSOC);
        
        if ($attendance_result['attendance_count'] > 0) {
            $message = "Cannot unregister from this course as you have attendance records.";
            $message_type = "warning";
        } else {
            // Unregister from the course
            $unregister_stmt = $conn->prepare("DELETE FROM student_courses WHERE student_id = ? AND course_id = ?");
            
            if ($unregister_stmt->execute([$student_id, $course_id])) {
                $message = "Course unregistered successfully!";
                $message_type = "success";
            } else {
                $message = "Error unregistering from course. Please try again.";
                $message_type = "danger";
            }
        }
    }
}

// Get student information (keep existing code)
$stmt = $conn->prepare("
    SELECT s.*, d.name as department_name, f.name as faculty_name 
    FROM students s 
    JOIN departments d ON s.department_id = d.id 
    JOIN faculties f ON s.faculty_id = f.id 
    WHERE s.id = :student_id
");
$stmt->execute(['student_id' => $student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Main query to get available courses based on your logic
$stmt = $conn->prepare("
    SELECT DISTINCT c.*, 
           CONCAT(l.firstname, ' ', l.surname) as lecturer_name,
           l.email as lecturer_email,
           d.name as course_department,
           d.code as dept_code,
           ce.is_general,
           ce.level as course_level,
           f.name as course_faculty_name,
           (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id) as enrolled_count,
           (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id AND sc.student_id = :student_id) as is_registered,
           CASE 
               -- Current level courses from student's department
               WHEN ce.department_id = :student_dept_id AND ce.level = :student_level THEN 'current_level'
               
               -- Other departmental courses (including carryover) - same department, student level >= course level
               WHEN ce.department_id = :student_dept_id2 AND ce.level <= :student_level2 AND ce.level != :student_level3 THEN 'departmental'
               
               -- General courses - marked as general, student level >= course level
               WHEN ce.is_general = 1 AND ce.level <= :student_level4 THEN 'general'
               
               -- Cross departmental/borrowed - same faculty, different department, student level >= course level
               WHEN ce.department_id != :student_dept_id3 
                    AND ce.department_id IN (
                        SELECT dept.id FROM departments dept 
                        WHERE dept.faculty_id = :student_faculty_id
                    ) 
                    AND ce.level <= :student_level5 THEN 'borrowed'
               
               ELSE 'other'
           END as course_type
    FROM courses c
    JOIN course_eligibility ce ON c.id = ce.course_id
    LEFT JOIN lecturers l ON c.lecturer_id = l.id
    LEFT JOIN departments d ON ce.department_id = d.id
    LEFT JOIN faculties f ON d.faculty_id = f.id
    WHERE c.is_active = 1
    AND (
        -- Current level courses from student's department
        (ce.department_id = :student_dept_id4 AND ce.level = :student_level6)
        OR
        -- Other departmental courses (student level >= course level)
        (ce.department_id = :student_dept_id5 AND ce.level <= :student_level7 AND ce.level != :student_level8)
        OR
        -- General courses (student level >= course level)
        (ce.is_general = 1 AND ce.level <= :student_level9)
        OR
        -- Cross departmental/borrowed courses from same faculty (student level >= course level)
        (ce.department_id != :student_dept_id6 
         AND ce.department_id IN (
             SELECT dept.id FROM departments dept 
             WHERE dept.faculty_id = :student_faculty_id2
         ) 
         AND ce.level <= :student_level10)
    )
    ORDER BY 
        CASE course_type
            WHEN 'current_level' THEN 1
            WHEN 'departmental' THEN 2
            WHEN 'general' THEN 3
            WHEN 'borrowed' THEN 4
        END,
        ce.level ASC,
        c.code ASC
");

// Execute with parameters
$stmt->execute([
    'student_id' => $student_id,
    'student_dept_id' => $student['department_id'],
    'student_level' => $student['level'],
    'student_dept_id2' => $student['department_id'],
    'student_level2' => $student['level'],
    'student_level3' => $student['level'],
    'student_level4' => $student['level'],
    'student_dept_id3' => $student['department_id'],
    'student_faculty_id' => $student['faculty_id'],
    'student_level5' => $student['level'],
    'student_dept_id4' => $student['department_id'],
    'student_level6' => $student['level'],
    'student_dept_id5' => $student['department_id'],
    'student_level7' => $student['level'],
    'student_level8' => $student['level'],
    'student_level9' => $student['level'],
    'student_dept_id6' => $student['department_id'],
    'student_faculty_id2' => $student['faculty_id'],
    'student_level10' => $student['level']
]);

$available_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group courses by type for display
$course_groups = [
    'current_level' => [],
    'departmental' => [],
    'general' => [],
    'borrowed' => []
];

foreach ($available_courses as $course) {
    $type = $course['course_type'];
    if (isset($course_groups[$type])) {
        $course_groups[$type][] = $course;
    }
}

// Further group departmental courses by level for better organization
$departmental_by_level = [];
foreach ($course_groups['departmental'] as $course) {
    $level = $course['course_level'];
    if (!isset($departmental_by_level[$level])) {
        $departmental_by_level[$level] = [];
    }
    $departmental_by_level[$level][] = $course;
}

// Sort levels in ascending order
ksort($departmental_by_level);

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

.course-header.other-level {
    background: linear-gradient(135deg, #fd7e14 0%, #e55a00 100%);
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

.other-level-badge { 
    background-color: #fd7e14; 
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

    <!-- 1. Current Level Courses (Core) -->
<?php if (!empty($course_groups['current_level'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-graduation-cap text-primary"></i> Current Level Courses 
                <small class="text-muted">(Level <?= $student['level'] ?> - <?= htmlspecialchars($student['department_name']) ?>)</small>
            </h4>
        </div>
    </div>
    <div class="row mb-5">
        <?php foreach ($course_groups['current_level'] as $course): ?>
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

<!-- 2. Other Departmental Level Courses -->
<?php if (!empty($course_groups['departmental'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-building text-purple"></i> Other Departmental Level Courses 
                <small class="text-muted">(<?= htmlspecialchars($student['department_name']) ?> - All Available Levels)</small>
            </h4>
        </div>
    </div>

    <?php foreach ($departmental_by_level as $level => $courses): ?>
        <div class="col-12">
            <h5 class="level-title">
                <i class="fas fa-layer-group"></i> <?= $level ?> Level Courses
                <?php if ($level < $student['level']): ?>
                    <small class="text-info">(Previous Level Courses)</small>
                <?php endif; ?>
            </h5>
        </div>
        <div class="row mb-4">
            <?php foreach ($courses as $course): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card course-card shadow-sm">
                        <div class="course-header <?= $level < $student['level'] ? 'other-level' : 'departmental' ?>">
                            <div class="course-code"><?= htmlspecialchars($course['code']) ?></div>
                            <div class="course-title"><?= htmlspecialchars($course['name']) ?></div>
                        </div>
                        <div class="card-body">
                            <div class="course-info">
                                <span><strong>Credits:</strong> <?= $course['credit_units'] ?></span>
                                <?php if ($course['is_registered']): ?>
                                    <span class="badge badge-status registered">Registered</span>
                                <?php elseif ($level < $student['level']): ?>
                                    <span class="badge badge-status other-level-badge">Other Level</span>
                                <?php else: ?>
                                    <span class="badge badge-status departmental-badge">Departmental</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="course-meta mb-2">
                                <i class="fas fa-level-up-alt"></i>
                                <strong>Level:</strong> <?= $course['course_level'] ?>
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
                            
                            <?php if ($level < $student['level']): ?>
                                <div class="alert alert-info alert-sm mb-3">
                                    <small><i class="fas fa-info-circle"></i> This is from Level <?= $level ?> of your department.</small>
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
    <?php endforeach; ?>
    <div class="mb-4"></div>
<?php endif; ?>

<!-- 3. General Studies Courses -->
<?php if (!empty($course_groups['general'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-globe text-success"></i> General Studies Courses
                <small class="text-muted">(GST, USE, etc. - Available to all students)</small>
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
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-level-up-alt"></i>
                            <strong>Level:</strong> <?= $course['course_level'] ?>
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

<!-- 4. Borrowed Courses (Cross-Departmental) -->
<?php if (!empty($course_groups['borrowed'])): ?>
    <div class="row">
        <div class="col-12">
            <h4 class="section-title">
                <i class="fas fa-exchange-alt text-info"></i> Borrowed Courses 
                <small class="text-muted">(From other departments in <?= htmlspecialchars($student['faculty_name']) ?>)</small>
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
                            <?php endif; ?>
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-building"></i>
                            <strong>Department:</strong> <?= htmlspecialchars($course['course_department']) ?> (<?= htmlspecialchars($course['dept_code']) ?>)
                        </div>
                        
                        <div class="course-meta mb-2">
                            <i class="fas fa-level-up-alt"></i>
                            <strong>Level:</strong> <?= $course['course_level'] ?>
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
                        
                        <div class="alert alert-info alert-sm mb-3">
                            <small><i class="fas fa-info-circle"></i> This course is borrowed from <?= htmlspecialchars($course['course_department']) ?> department.</small>
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
        <i class="fas fa-book fa-4x text-muted mb-3"></i>
        <h5 class="text-muted">No Available Courses</h5>
        <p class="text-muted">There are currently no courses available for registration based on your level and department.</p>
    </div>
<?php endif; ?>

</main>

<script>
// Add some interactive features
document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            if (alert.classList.contains('show')) {
                alert.classList.remove('show');
                setTimeout(function() {
                    alert.remove();
                }, 150);
            }
        }, 5000);
    });
    
    // Add confirmation for registration
    const registerForms = document.querySelectorAll('form[method="POST"]');
    registerForms.forEach(function(form) {
        if (form.querySelector('button[name="register_course"]')) {
            form.addEventListener('submit', function(e) {
                const courseCode = form.closest('.card').querySelector('.course-code').textContent;
                if (!confirm(`Are you sure you want to register for ${courseCode}?`)) {
                    e.preventDefault();
                }
            });
        }
    });
    
    // Add hover effects for course cards
    const courseCards = document.querySelectorAll('.course-card');
    courseCards.forEach(function(card) {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>