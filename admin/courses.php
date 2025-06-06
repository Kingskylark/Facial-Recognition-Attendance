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
        case 'add_course':
            try {
                $stmt = $conn->prepare("
                    INSERT INTO courses (department_id, faculty_id, name, code, credit_units, semester, level, description, course_type, lecturer_id, created_at) 
                    VALUES (:department_id, :faculty_id, :name, :code, :credit_units, :semester, :level, :description, :course_type, :lecturer_id, NOW())
                ");
                
                $stmt->execute([
                    'department_id' => $_POST['department_id'],
                    'faculty_id' => $_POST['faculty_id'] ?: null,
                    'name' => $_POST['name'],
                    'code' => strtoupper($_POST['code']),
                    'credit_units' => $_POST['credit_units'],
                    'semester' => $_POST['semester'],
                    'level' => $_POST['level'],
                    'description' => $_POST['description'] ?: null,
                    'course_type' => $_POST['course_type'],
                    'lecturer_id' => $_POST['lecturer_id'] ?: null
                ]);
                
                $message = 'Course added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Course code already exists!';
                } else {
                    $error = 'Error adding course: ' . $e->getMessage();
                }
            }
            break;
        case 'update_course':
              try {
                $stmt = $conn->prepare("
                    UPDATE courses SET 
                        department_id = :department_id,
                        faculty_id = :faculty_id,
                        name = :name, 
                        code = :code,
                        credit_units = :credit_units,
                        semester = :semester,
                        level = :level,
                        description = :description,
                        course_type = :course_type,
                        lecturer_id = :lecturer_id,
                        is_active = :is_active
                    WHERE id = :course_id
                ");
                
                $stmt->execute([
                    'department_id' => $_POST['department_id'],
                    'faculty_id' => $_POST['faculty_id'] ?: null,
                    'name' => $_POST['name'],
                    'code' => strtoupper($_POST['code']),
                    'credit_units' => $_POST['credit_units'],
                    'semester' => $_POST['semester'],
                    'level' => $_POST['level'],
                    'description' => $_POST['description'] ?: null,
                    'course_type' => $_POST['course_type'],
                    'lecturer_id' => $_POST['lecturer_id'] ?: null,
                    'is_active' => $_POST['is_active'] ?? 1,
                    'course_id' => $_POST['course_id']
                ]);
                
                $message = 'Course updated successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Course code already exists!';
                } else {
                    $error = 'Error updating course: ' . $e->getMessage();
                }
            }
            break;
            
        case 'delete_course':
            try {
                // Check if course has students enrolled
                $check_stmt = $conn->prepare("SELECT COUNT(*) as enrollment_count FROM student_courses WHERE course_id = :course_id");
                $check_stmt->execute(['course_id' => $_POST['course_id']]);
                $enrollment_count = $check_stmt->fetch()['enrollment_count'];
                
                if ($enrollment_count > 0) {
                    $error = 'Cannot delete course. It has ' . $enrollment_count . ' students enrolled.';
                } else {
                    // Remove lecturer assignments first
                    $stmt = $conn->prepare("DELETE FROM lecturer_courses WHERE course_id = :course_id");
                    $stmt->execute(['course_id' => $_POST['course_id']]);
                    
                    $stmt = $conn->prepare("DELETE FROM courses WHERE id = :course_id");
                    $stmt->execute(['course_id' => $_POST['course_id']]);
                    $message = 'Course deleted successfully!';
                }
            } catch (PDOException $e) {
                $error = 'Error deleting course: ' . $e->getMessage();
            }
            break;
            
        case 'assign_lecturer':
            try {
                // Check if assignment already exists
                $check_stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM lecturer_courses 
                    WHERE course_id = :course_id AND lecturer_id = :lecturer_id AND session_year = :session_year AND semester = :semester
                ");
                $check_stmt->execute([
                    'course_id' => $_POST['course_id'],
                    'lecturer_id' => $_POST['lecturer_id'],
                    'session_year' => $_POST['session_year'],
                    'semester' => $_POST['semester']
                ]);
                
                if ($check_stmt->fetch()['count'] > 0) {
                    $error = 'Lecturer is already assigned to this course for the selected session and semester!';
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO lecturer_courses (course_id, lecturer_id, session_year, semester, created_at) 
                        VALUES (:course_id, :lecturer_id, :session_year, :semester, NOW())
                    ");
                    
                    $stmt->execute([
                        'course_id' => $_POST['course_id'],
                        'lecturer_id' => $_POST['lecturer_id'],
                        'session_year' => $_POST['session_year'],
                        'semester' => $_POST['semester']
                    ]);
                    
                    $message = 'Lecturer assigned to course successfully!';
                }
            } catch (PDOException $e) {
                $error = 'Error assigning lecturer: ' . $e->getMessage();
            }
            break;
            
        case 'remove_lecturer':
            try {
                $stmt = $conn->prepare("
                    DELETE FROM lecturer_courses 
                    WHERE course_id = :course_id AND lecturer_id = :lecturer_id AND id = :assignment_id
                ");
                
                $stmt->execute([
                    'course_id' => $_POST['course_id'],
                    'lecturer_id' => $_POST['lecturer_id'],
                    'assignment_id' => $_POST['assignment_id']
                ]);
                
                $message = 'Lecturer removed from course successfully!';
            } catch (PDOException $e) {
                $error = 'Error removing lecturer: ' . $e->getMessage();
            }
            break;
            
        case 'toggle_status':
            try {
                $stmt = $conn->prepare("UPDATE courses SET is_active = :is_active WHERE id = :course_id");
                $stmt->execute([
                    'is_active' => $_POST['is_active'],
                    'course_id' => $_POST['course_id']
                ]);
                
                $status = $_POST['is_active'] ? 'activated' : 'deactivated';
                $message = "Course {$status} successfully!";
            } catch (PDOException $e) {
                $error = 'Error updating course status: ' . $e->getMessage();
            }
            break;
    }
    // Get students for registration dropdown
$students_stmt = $conn->prepare("
    SELECT s.id, s.student_id, s.firstname, s.surname, s.level, d.name as department_name, d.code as department_code
    FROM students s 
    LEFT JOIN departments d ON s.department_id = d.id 
    WHERE s.is_active = 1
    ORDER BY s.firstname, s.surname
");
$students_stmt->execute();
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get filters
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$semester_filter = $_GET['semester'] ?? '';
$level_filter = $_GET['level'] ?? '';
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(c.name LIKE :search OR c.code LIKE :search)";
    $params['search'] = "%$search%";
}

if ($department_filter) {
    $where_conditions[] = "c.department_id = :department_filter";
    $params['department_filter'] = $department_filter;
}

if ($faculty_filter) {
    $where_conditions[] = "c.faculty_id = :faculty_filter";
    $params['faculty_filter'] = $faculty_filter;
}

if ($semester_filter) {
    $where_conditions[] = "c.semester = :semester_filter";
    $params['semester_filter'] = $semester_filter;
}

if ($level_filter) {
    $where_conditions[] = "c.level = :level_filter";
    $params['level_filter'] = $level_filter;
}

if ($type_filter) {
    $where_conditions[] = "c.course_type = :type_filter";
    $params['type_filter'] = $type_filter;
}

if ($status_filter !== '') {
    $where_conditions[] = "c.is_active = :status_filter";
    $params['status_filter'] = $status_filter;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get courses with pagination
$page = $_GET['page'] ?? 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT 
        c.*,
        d.name as department_name,
        d.code as department_code,
        f.name as faculty_name,
        lecturer.firstname as lecturer_first_name,
        lecturer.surname as lecturer_last_name,
        COUNT(DISTINCT sc.id) as enrollment_count,
        COUNT(DISTINCT lc.id) as lecturer_assignment_count,
        GROUP_CONCAT(DISTINCT CONCAT(u.firstname, ' ', u.surname, ' (', lc.session_year, ' - ', lc.semester, ')') SEPARATOR '; ') as assigned_lecturers
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN faculties f ON c.faculty_id = f.id
    LEFT JOIN lecturers lecturer ON c.lecturer_id = lecturer.id
    LEFT JOIN student_courses sc ON c.id = sc.course_id
    LEFT JOIN lecturer_courses lc ON c.id = lc.course_id
    LEFT JOIN lecturers u ON lc.lecturer_id = u.id
    $where_clause
    GROUP BY c.id
    ORDER BY c.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT c.id) as total 
    FROM courses c
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN faculties f ON c.faculty_id = f.id
    $where_clause
");
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_courses = $count_stmt->fetch()['total'];
$total_pages = ceil($total_courses / $limit);

// Get course statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT c.id) as total_courses,
        COUNT(DISTINCT c.department_id) as departments_with_courses,
        COUNT(DISTINCT sc.id) as total_enrollments,
        AVG(c.credit_units) as avg_credit_units,
        COUNT(DISTINCT lc.id) as total_lecturer_assignments,
        COUNT(DISTINCT CASE WHEN c.is_active = 1 THEN c.id END) as active_courses,
        COUNT(DISTINCT CASE WHEN c.is_active = 0 THEN c.id END) as inactive_courses
    FROM courses c
    LEFT JOIN student_courses sc ON c.id = sc.course_id
    LEFT JOIN lecturer_courses lc ON c.id = lc.course_id
");
$stats_stmt->execute();
$course_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get departments for dropdown
$departments_stmt = $conn->prepare("
    SELECT d.id, d.name, d.code, f.name as faculty_name 
    FROM departments d 
    LEFT JOIN faculties f ON d.faculty_id = f.id 
    ORDER BY d.name
");
$departments_stmt->execute();
$departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get faculties for dropdown
$faculties_stmt = $conn->prepare("SELECT id, name FROM faculties ORDER BY name");
$faculties_stmt->execute();
$faculties = $faculties_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lecturers for assignment
$lecturers_stmt = $conn->prepare("
    SELECT id, firstname, surname, email 
    FROM lecturers 
    ORDER BY firstname, surname
");
$lecturers_stmt->execute();
$lecturers = $lecturers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current session year (you might want to make this dynamic)
$current_session = date('Y') . '/' . (date('Y') + 1);
?>

<?php include_once '../includes/header.php'; ?>

<style>
.course-card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    height: 100%;
}

.course-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.course-card.inactive {
    opacity: 0.7;
    border-left: 4px solid #dc3545;
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

.stat-card.departments {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-card.enrollments {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.stat-card.credit-units {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.stat-card.active {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
    color: #333;
}

.stat-card.inactive {
    background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
}

.filter-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.course-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 18px;
    margin: 0 auto 15px;
}

.course-type-badge {
    background: rgba(102, 126, 234, 0.1);
    color: #667eea;
    padding: 4px 8px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
}

.course-type-badge.core {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.course-type-badge.elective {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.course-type-badge.general {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.course-type-badge.borrowed {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

.status-toggle {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.status-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #28a745;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.modal-header-custom {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.form-control:focus {
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

.pagination .page-link {
    color: #667eea;
}

.pagination .page-item.active .page-link {
    background-color: #667eea;
    border-color: #667eea;
}

.course-code {
    background: rgba(102, 126, 234, 0.1);
    color: #667eea;
    padding: 4px 8px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
}

.department-badge {
    background: rgba(76, 175, 254, 0.1);
    color: #4cafff;
    padding: 2px 6px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: bold;
}

.semester-badge {
    background: rgba(67, 233, 123, 0.1);
    color: #43e97b;
    padding: 2px 6px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: bold;
}

.level-badge {
    background: rgba(247, 112, 154, 0.1);
    color: #f7709a;
    padding: 2px 6px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: bold;
}

.stats-row {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 15px;
}

.lecturer-assignment-item {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.lecturer-assignment-item:last-child {
    margin-bottom: 0;
}
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-book-open text-primary"></i> Course Management</h2>
            <p class="text-muted">Manage university courses and lecturer assignments</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addCourseModal">
            <i class="fas fa-plus"></i> Add New Course
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
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card">
                <i class="fas fa-book-open fa-2x mb-2"></i>
                <h3><?= number_format($course_stats['total_courses']) ?></h3>
                <p class="mb-0">Total Courses</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card active">
                <i class="fas fa-check-circle fa-2x mb-2"></i>
                <h3><?= number_format($course_stats['active_courses']) ?></h3>
                <p class="mb-0">Active Courses</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card inactive">
                <i class="fas fa-times-circle fa-2x mb-2"></i>
                <h3><?= number_format($course_stats['inactive_courses']) ?></h3>
                <p class="mb-0">Inactive Courses</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card departments">
                <i class="fas fa-building fa-2x mb-2"></i>
                <h3><?= number_format($course_stats['departments_with_courses']) ?></h3>
                <p class="mb-0">Departments</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card enrollments">
                <i class="fas fa-user-graduate fa-2x mb-2"></i>
                <h3><?= number_format($course_stats['total_enrollments']) ?></h3>
                <p class="mb-0">Enrollments</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card credit-units">
                <i class="fas fa-clock fa-2x mb-2"></i>
                <h3><?= number_format($course_stats['avg_credit_units'] ?? 0, 1) ?></h3>
                <p class="mb-0">Avg Units</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Search Courses</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by name or code...">
            </div>
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= $department['id'] ?>" <?= $department_filter == $department['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($department['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Faculty</label>
                <select class="form-select" name="faculty">
                    <option value="">All Faculties</option>
                    <?php foreach ($faculties as $faculty): ?>
                        <option value="<?= $faculty['id'] ?>" <?= $faculty_filter == $faculty['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($faculty['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester">
                    <option value="">All</option>
                    <option value="first" <?= $semester_filter == 'first' ? 'selected' : '' ?>>First</option>
                    <option value="second" <?= $semester_filter == 'second' ? 'selected' : '' ?>>Second</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="">All</option>
                    <option value="100" <?= $level_filter == '100' ? 'selected' : '' ?>>100</option>
                    <option value="200" <?= $level_filter == '200' ? 'selected' : '' ?>>200</option>
                    <option value="300" <?= $level_filter == '300' ? 'selected' : '' ?>>300</option>
                    <option value="400" <?= $level_filter == '400' ? 'selected' : '' ?>>400</option>
                    <option value="500" <?= $level_filter == '500' ? 'selected' : '' ?>>500</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Course Type</label>
                <select class="form-select" name="type">
                    <option value="">All Types</option>
                    <option value="core" <?= $type_filter == 'core' ? 'selected' : '' ?>>Core</option>
                    <option value="elective" <?= $type_filter == 'elective' ? 'selected' : '' ?>>Elective</option>
                    <option value="general" <?= $type_filter == 'general' ? 'selected' : '' ?>>General</option>
                    <option value="borrowed" <?= $type_filter == 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </form>
    </div>

    <!-- Courses List -->
    <div class="row">
        <?php if ($courses): ?>
            <?php foreach ($courses as $course): ?>
                <div class="col-xl-4 col-lg-6 mb-4">
                    <div class="card course-card <?= !$course['is_active'] ? 'inactive' : '' ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div class="course-icon">
                                    <?= strtoupper(substr($course['code'], 0, 3)) ?>
                                </div>
                                <div class="status-toggle">
                                    <input type="checkbox" id="status_<?= $course['id'] ?>" 
                                           <?= $course['is_active'] ? 'checked' : '' ?>
                                           onchange="toggleCourseStatus(<?= $course['id'] ?>, this.checked)">
                                    <span class="slider"></span>
                                </div>
                            </div>
                            
                            <h5 class="card-title text-center mb-2"><?= htmlspecialchars($course['name']) ?></h5>
                            <div class="text-center mb-3">
                                <span class="course-code"><?= htmlspecialchars($course['code']) ?></span>
                                </div>
                            
                            <div class="row text-center mb-3">
                                <div class="col-4">
                                    <span class="department-badge"><?= htmlspecialchars($course['department_code']) ?></span>
                                    <br><small class="text-muted">Department</small>
                                </div>
                                <div class="col-4">
                                    <span class="semester-badge"><?= ucfirst($course['semester']) ?></span>
                                    <br><small class="text-muted">Semester</small>
                                </div>
                                <div class="col-4">
                                    <span class="level-badge"><?= $course['level'] ?>L</span>
                                    <br><small class="text-muted">Level</small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="course-type-badge <?= $course['course_type'] ?>">
                                    <?= ucfirst($course['course_type']) ?>
                                </span>
                                <span class="float-end">
                                    <strong><?= $course['credit_units'] ?></strong> Units
                                </span>
                            </div>
                            
                            <?php if ($course['description']): ?>
                                <p class="card-text small text-muted mb-3">
                                    <?= htmlspecialchars(substr($course['description'], 0, 100)) ?>
                                    <?= strlen($course['description']) > 100 ? '...' : '' ?>
                                </p>
                            <?php endif; ?>
                            
                            <div class="stats-row">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <i class="fas fa-users text-primary"></i>
                                        <span class="ms-1"><?= $course['enrollment_count'] ?> Students</span>
                                    </div>
                                    <div class="col-6">
                                        <i class="fas fa-chalkboard-teacher text-success"></i>
                                        <span class="ms-1"><?= $course['lecturer_assignment_count'] ?> Lecturers</span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($course['lecturer_first_name']): ?>
                                <div class="mb-2">
                                    <small class="text-muted">Primary Lecturer:</small><br>
                                    <strong><?= htmlspecialchars($course['lecturer_first_name'] . ' ' . $course['lecturer_last_name']) ?></strong>
                                </div>
                            <?php endif; ?>
                            
                            <div class="d-flex gap-2 mt-3">
    <button class="btn btn-primary btn-sm flex-fill" 
            onclick="editCourse(<?= htmlspecialchars(json_encode($course)) ?>)">
        <i class="fas fa-edit"></i> Edit
    </button>
    <button class="btn btn-success btn-sm flex-fill" 
            onclick="assignLecturer(<?= $course['id'] ?>)">
        <i class="fas fa-user-plus"></i> Assign
    </button>
    <a href="register_student_course.php?course_id=<?= $course['id'] ?>&course_name=<?= urlencode($course['name']) ?>" 
   class="btn btn-warning btn-sm flex-fill text-decoration-none">
    <i class="fas fa-user-graduate"></i> Register
</a>
    <button class="btn btn-info btn-sm flex-fill" 
            onclick="viewCourseDetails(<?= htmlspecialchars(json_encode($course)) ?>)">
        <i class="fas fa-eye"></i> View
    </button>
    <button class="btn btn-danger btn-sm" 
            onclick="deleteCourse(<?= $course['id'] ?>, '<?= htmlspecialchars($course['name']) ?>')">
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
                    <i class="fas fa-book-open fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No courses found</h4>
                    <p class="text-muted">Try adjusting your search filters or add a new course.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                        <i class="fas fa-plus"></i> Add First Course
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Course pagination" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <?php 
                    $prev_params = $_GET;
                    $prev_params['page'] = $page - 1;
                    ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query($prev_params) ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php 
                    $page_params = $_GET;
                    $page_params['page'] = $i;
                    ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query($page_params) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <?php 
                    $next_params = $_GET;
                    $next_params['page'] = $page + 1;
                    ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query($next_params) ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
        
        <div class="text-center text-muted">
            Showing <?= ($page - 1) * $limit + 1 ?> to <?= min($page * $limit, $total_courses) ?> 
            of <?= $total_courses ?> courses
        </div>
    <?php endif; ?>
</main>


<!-- Add Course Modal -->
<div class="modal fade" id="addCourseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add New Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_course">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Name *</label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Code *</label>
                            <input type="text" class="form-control" name="code" required 
                                   placeholder="e.g., CSC101">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department *</label>
                            <select class="form-select" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>">
                                        <?= htmlspecialchars($dept['name']) ?> (<?= htmlspecialchars($dept['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty</label>
                            <select class="form-select" name="faculty_id">
                                <option value="">Select Faculty (Optional)</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= $faculty['id'] ?>">
                                        <?= htmlspecialchars($faculty['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Credit Units *</label>
                            <input type="number" class="form-control" name="credit_units" 
                                   min="1" max="6" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Semester *</label>
                            <select class="form-select" name="semester" required>
                                <option value="">Select Semester</option>
                                <option value="first">First Semester</option>
                                <option value="second">Second Semester</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="level" required>
                                <option value="">Select Level</option>
                                <option value="100">100 Level</option>
                                <option value="200">200 Level</option>
                                <option value="300">300 Level</option>
                                <option value="400">400 Level</option>
                                <option value="500">500 Level</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Type *</label>
                            <select class="form-select" name="course_type" required>
                                <option value="">Select Type</option>
                                <option value="core">Core Course</option>
                                <option value="elective">Elective Course</option>
                                <option value="general">General Studies</option>
                                <option value="borrowed">Borrowed Course</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Primary Lecturer</label>
                            <select class="form-select" name="lecturer_id">
                                <option value="">Select Lecturer (Optional)</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?= $lecturer['id'] ?>">
                                        <?= htmlspecialchars($lecturer['firstname'] . ' ' . $lecturer['surname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" 
                                      placeholder="Brief course description..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Course
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Course Modal -->
<div class="modal fade" id="editCourseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Course</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editCourseForm">
                <input type="hidden" name="action" value="update_course">
                <input type="hidden" name="course_id" id="edit_course_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Name *</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Code *</label>
                            <input type="text" class="form-control" name="code" id="edit_code" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Department *</label>
                            <select class="form-select" name="department_id" id="edit_department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= $dept['id'] ?>">
                                        <?= htmlspecialchars($dept['name']) ?> (<?= htmlspecialchars($dept['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Faculty</label>
                            <select class="form-select" name="faculty_id" id="edit_faculty_id">
                                <option value="">Select Faculty (Optional)</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= $faculty['id'] ?>">
                                        <?= htmlspecialchars($faculty['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Credit Units *</label>
                            <input type="number" class="form-control" name="credit_units" 
                                   id="edit_credit_units" min="1" max="6" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Semester *</label>
                            <select class="form-select" name="semester" id="edit_semester" required>
                                <option value="">Select Semester</option>
                                <option value="first">First Semester</option>
                                <option value="second">Second Semester</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="level" id="edit_level" required>
                                <option value="">Select Level</option>
                                <option value="100">100 Level</option>
                                <option value="200">200 Level</option>
                                <option value="300">300 Level</option>
                                <option value="400">400 Level</option>
                                <option value="500">500 Level</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Course Type *</label>
                            <select class="form-select" name="course_type" id="edit_course_type" required>
                                <option value="">Select Type</option>
                                <option value="core">Core Course</option>
                                <option value="elective">Elective Course</option>
                                <option value="general">General Studies</option>
                                <option value="borrowed">Borrowed Course</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Primary Lecturer</label>
                            <select class="form-select" name="lecturer_id" id="edit_lecturer_id">
                                <option value="">Select Lecturer (Optional)</option>
                                <?php foreach ($lecturers as $lecturer): ?>
                                    <option value="<?= $lecturer['id'] ?>">
                                        <?= htmlspecialchars($lecturer['firstname'] . ' ' . $lecturer['surname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" 
                                       id="edit_is_active" value="1">
                                <label class="form-check-label" for="edit_is_active">
                                    Course is Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Course
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Lecturer Modal -->
<div class="modal fade" id="assignLecturerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Assign Lecturer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="assignLecturerForm">
                <input type="hidden" name="action" value="assign_lecturer">
                <input type="hidden" name="course_id" id="assign_course_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Course</label>
                        <input type="text" class="form-control" id="assign_course_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Lecturer *</label>
                        <select class="form-select" name="lecturer_id" required>
                            <option value="">Select Lecturer</option>
                            <?php foreach ($lecturers as $lecturer): ?>
                                <option value="<?= $lecturer['id'] ?>">
                                    <?= htmlspecialchars($lecturer['firstname'] . ' ' . $lecturer['surname']) ?>
                                    (<?= htmlspecialchars($lecturer['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Session Year *</label>
                        <input type="text" class="form-control" name="session_year" 
                               value="<?= $current_session ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Semester *</label>
                        <select class="form-select" name="semester" required>
                            <option value="">Select Semester</option>
                            <option value="first">First Semester</option>
                            <option value="second">Second Semester</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Assign Lecturer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Course Details Modal -->
<div class="modal fade" id="courseDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Course Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="courseDetailsContent">
                <!-- Content loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>



<script>
// Toggle course status
function toggleCourseStatus(courseId, isActive) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('course_id', courseId);
    formData.append('is_active', isActive ? 1 : 0);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        location.reload();
    })
    .catch(error => {
        console.error('Error:', error);
        location.reload();
    });
}

// Edit course
function editCourse(course) {
    document.getElementById('edit_course_id').value = course.id;
    document.getElementById('edit_name').value = course.name;
    document.getElementById('edit_code').value = course.code;
    document.getElementById('edit_department_id').value = course.department_id;
    document.getElementById('edit_faculty_id').value = course.faculty_id || '';
    document.getElementById('edit_credit_units').value = course.credit_units;
    document.getElementById('edit_semester').value = course.semester;
    document.getElementById('edit_level').value = course.level;
    document.getElementById('edit_course_type').value = course.course_type;
    document.getElementById('edit_lecturer_id').value = course.lecturer_id || '';
    document.getElementById('edit_description').value = course.description || '';
    document.getElementById('edit_is_active').checked = course.is_active == 1;
    
    new bootstrap.Modal(document.getElementById('editCourseModal')).show();
}

// Assign lecturer
function assignLecturer(courseId) {
    document.getElementById('assign_course_id').value = courseId;
    
    // You might want to fetch course name via AJAX here
    fetch(`get_course.php?id=${courseId}`)
        .then(response => response.json())
        .then(course => {
            document.getElementById('assign_course_name').value = course.name + ' (' + course.code + ')';
        })
        .catch(() => {
            document.getElementById('assign_course_name').value = 'Course ID: ' + courseId;
        });
    
    new bootstrap.Modal(document.getElementById('assignLecturerModal')).show();
}

// View course details
function viewCourseDetails(course) {
    const detailsHtml = `
        <div class="row">
            <div class="col-md-8">
                <h4 class="mb-3">${course.name}</h4>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Course Code:</strong>
                        <span class="course-code ms-2">${course.code}</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Credit Units:</strong>
                        <span class="badge bg-primary ms-2">${course.credit_units} Units</span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Department:</strong>
                        <span class="department-badge ms-2">${course.department_name || 'N/A'} (${course.department_code || 'N/A'})</span>
                    </div>
                    <div class="col-md-6">
                        <strong>Faculty:</strong>
                        <span class="ms-2">${course.faculty_name || 'Not specified'}</span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Semester:</strong>
                        <span class="semester-badge ms-2">${course.semester.charAt(0).toUpperCase() + course.semester.slice(1)}</span>
                    </div>
                    <div class="col-md-4">
                        <strong>Level:</strong>
                        <span class="level-badge ms-2">${course.level}L</span>
                    </div>
                    <div class="col-md-4">
                        <strong>Type:</strong>
                        <span class="course-type-badge ${course.course_type} ms-2">${course.course_type.charAt(0).toUpperCase() + course.course_type.slice(1)}</span>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Status:</strong>
                        <span class="badge ${course.is_active == 1 ? 'bg-success' : 'bg-danger'} ms-2">
                            ${course.is_active == 1 ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <div class="col-md-6">
                        <strong>Created:</strong>
                        <span class="ms-2">${new Date(course.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
                
                ${course.description ? `
                    <div class="mb-3">
                        <strong>Description:</strong>
                        <p class="mt-2 p-3 bg-light rounded">${course.description}</p>
                    </div>
                ` : ''}
                
                ${course.lecturer_first_name ? `
                    <div class="mb-3">
                        <strong>Primary Lecturer:</strong>
                        <div class="mt-2 p-3 bg-light rounded">
                            <i class="fas fa-user-tie me-2"></i>
                            ${course.lecturer_first_name} ${course.lecturer_last_name}
                        </div>
                    </div>
                ` : ''}
                
                ${course.assigned_lecturers && course.assigned_lecturers !== 'null' ? `
                    <div class="mb-3">
                        <strong>Assigned Lecturers:</strong>
                        <div class="mt-2">
                            ${course.assigned_lecturers.split('; ').map(lecturer => 
                                `<div class="lecturer-assignment-item mb-2">
                                    <i class="fas fa-chalkboard-teacher me-2"></i>
                                    ${lecturer}
                                </div>`
                            ).join('')}
                        </div>
                    </div>
                ` : ''}
            </div>
            
            <div class="col-md-4">
                <div class="card bg-light">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Course Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span><i class="fas fa-users text-primary me-1"></i>Students Enrolled:</span>
                            <strong>${course.enrollment_count}</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span><i class="fas fa-chalkboard-teacher text-success me-1"></i>Lecturer Assignments:</span>
                            <strong>${course.lecturer_assignment_count}</strong>
                        </div>
                        <hr>
                        <div class="text-center">
                            <div class="course-icon mx-auto mb-2">
                                ${course.code.substring(0, 3).toUpperCase()}
                            </div>
                            <small class="text-muted">Course Icon</small>
                        </div>
                    </div>
                </div>
                
                <div class="mt-3">
                    <h6><i class="fas fa-cog me-2"></i>Quick Actions</h6>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-sm" onclick="editCourse(${JSON.stringify(course).replace(/"/g, '&quot;')})">
                            <i class="fas fa-edit me-1"></i>Edit Course
                        </button>
                        <button class="btn btn-success btn-sm" onclick="assignLecturer(${course.id})">
                            <i class="fas fa-user-plus me-1"></i>Assign Lecturer
                        </button>
                        <button class="btn btn-warning btn-sm" onclick="toggleCourseStatus(${course.id}, ${course.is_active == 1 ? 'false' : 'true'})">
                            <i class="fas fa-toggle-${course.is_active == 1 ? 'off' : 'on'} me-1"></i>
                            ${course.is_active == 1 ? 'Deactivate' : 'Activate'}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('courseDetailsContent').innerHTML = detailsHtml;
    new bootstrap.Modal(document.getElementById('courseDetailsModal')).show();
}

// Delete course
function deleteCourse(courseId, courseName) {
    if (confirm(`Are you sure you want to delete the course "${courseName}"?\n\nThis action cannot be undone.`)) {
        const formData = new FormData();
        formData.append('action', 'delete_course');
        formData.append('course_id', courseId);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting course. Please try again.');
        });
    }
}

// Auto-hide alerts
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.classList.contains('show')) {
                alert.classList.remove('show');
                alert.classList.add('fade');
                setTimeout(() => alert.remove(), 150);
            }
        }, 5000);
    });
});



</script>

<?php include_once '../includes/footer.php'; ?>