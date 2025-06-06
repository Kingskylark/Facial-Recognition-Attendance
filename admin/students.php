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
        case 'add_student':
            try {
                $stmt = $conn->prepare("
                    INSERT INTO students (
                        reg_number, firstname, surname, email, phone, 
                        faculty_id, department_id, level, admission_year, 
                        date_of_birth, gender, address, guardian_name, 
                        guardian_phone, created_at
                    ) VALUES (
                        :reg_number, :firstname, :surname, :email, :phone,
                        :faculty_id, :department_id, :level, :admission_year,
                        :date_of_birth, :gender, :address, :guardian_name,
                        :guardian_phone, NOW()
                    )
                ");
                
                $stmt->execute([
                    'reg_number' => $_POST['reg_number'],
                    'firstname' => $_POST['firstname'],
                    'surname' => $_POST['surname'],
                    'email' => $_POST['email'],
                    'phone' => $_POST['phone'],
                    'faculty_id' => $_POST['faculty_id'],
                    'department_id' => $_POST['department_id'],
                    'level' => $_POST['level'],
                    'admission_year' => $_POST['admission_year'],
                    'date_of_birth' => $_POST['date_of_birth'],
                    'gender' => $_POST['gender'],
                    'address' => $_POST['address'],
                    'guardian_name' => $_POST['guardian_name'],
                    'guardian_phone' => $_POST['guardian_phone']
                ]);
                
                $message = 'Student added successfully!';
            } catch (PDOException $e) {
                $error = 'Error adding student: ' . $e->getMessage();
            }
            break;
            
        case 'update_student':
            try {
                $stmt = $conn->prepare("
                    UPDATE students SET 
                        firstname = :firstname, surname = :surname, email = :email,
                        phone = :phone, faculty_id = :faculty_id, department_id = :department_id,
                        level = :level, date_of_birth = :date_of_birth, gender = :gender,
                        address = :address, guardian_name = :guardian_name,
                        guardian_phone = :guardian_phone, updated_at = NOW()
                    WHERE id = :student_id
                ");
                
                $stmt->execute([
                    'firstname' => $_POST['firstname'],
                    'surname' => $_POST['surname'],
                    'email' => $_POST['email'],
                    'phone' => $_POST['phone'],
                    'faculty_id' => $_POST['faculty_id'],
                    'department_id' => $_POST['department_id'],
                    'level' => $_POST['level'],
                    'date_of_birth' => $_POST['date_of_birth'],
                    'gender' => $_POST['gender'],
                    'address' => $_POST['address'],
                    'guardian_name' => $_POST['guardian_name'],
                    'guardian_phone' => $_POST['guardian_phone'],
                    'student_id' => $_POST['student_id']
                ]);
                
                $message = 'Student updated successfully!';
            } catch (PDOException $e) {
                $error = 'Error updating student: ' . $e->getMessage();
            }
            break;
            
        case 'toggle_status':
            try {
                $stmt = $conn->prepare("UPDATE students SET is_active = !is_active WHERE id = :student_id");
                $stmt->execute(['student_id' => $_POST['student_id']]);
                $message = 'Student status updated successfully!';
            } catch (PDOException $e) {
                $error = 'Error updating student status: ' . $e->getMessage();
            }
            break;
    }
}

// Get filters
$search = $_GET['search'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$department_filter = $_GET['department'] ?? '';
$level_filter = $_GET['level'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(s.firstname LIKE :search OR s.surname LIKE :search OR s.reg_number LIKE :search OR s.email LIKE :search)";
    $params['search'] = "%$search%";
}

if ($faculty_filter) {
    $where_conditions[] = "s.faculty_id = :faculty_filter";
    $params['faculty_filter'] = $faculty_filter;
}

if ($department_filter) {
    $where_conditions[] = "s.department_id = :department_filter";
    $params['department_filter'] = $department_filter;
}

if ($level_filter) {
    $where_conditions[] = "s.level = :level_filter";
    $params['level_filter'] = $level_filter;
}

if ($status_filter !== '') {
    $where_conditions[] = "s.is_active = :status_filter";
    $params['status_filter'] = $status_filter;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get students with pagination
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT 
        s.*,
        f.name as faculty_name,
        d.name as department_name,
        COUNT(sc.course_id) as enrolled_courses
    FROM students s
    LEFT JOIN faculties f ON s.faculty_id = f.id
    LEFT JOIN departments d ON s.department_id = d.id
    LEFT JOIN student_courses sc ON s.id = sc.student_id
    $where_clause
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT s.id) as total FROM students s $where_clause");
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_students = $count_stmt->fetch()['total'];
$total_pages = ceil($total_students / $limit);

// Get faculties and departments for dropdowns
$faculties_stmt = $conn->prepare("SELECT * FROM faculties ORDER BY name");
$faculties_stmt->execute();
$faculties = $faculties_stmt->fetchAll(PDO::FETCH_ASSOC);

$departments_stmt = $conn->prepare("SELECT d.*, f.name as faculty_name FROM departments d LEFT JOIN faculties f ON d.faculty_id = f.id ORDER BY f.name, d.name");
$departments_stmt->execute();
$departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get student statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_students,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_students,
        COUNT(DISTINCT faculty_id) as faculties_represented
    FROM students
");
$stats_stmt->execute();
$student_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

?>

<?php include_once '../includes/header.php'; ?>

<style>
.student-card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.student-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
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

.stat-card.active {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-card.inactive {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.stat-card.faculties {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.filter-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.student-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 18px;
}

.status-badge {
    position: absolute;
    top: 10px;
    right: 10px;
}

.quick-stats {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
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
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-user-graduate text-primary"></i> Student Management</h2>
            <p class="text-muted">Manage student records, enrollment, and information</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addStudentModal">
            <i class="fas fa-plus"></i> Add New Student
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
                <i class="fas fa-users fa-2x mb-2"></i>
                <h3><?= number_format($student_stats['total_students']) ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card active">
                <i class="fas fa-user-check fa-2x mb-2"></i>
                <h3><?= number_format($student_stats['active_students']) ?></h3>
                <p class="mb-0">Active Students</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card inactive">
                <i class="fas fa-user-times fa-2x mb-2"></i>
                <h3><?= number_format($student_stats['inactive_students']) ?></h3>
                <p class="mb-0">Inactive Students</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card faculties">
                <i class="fas fa-university fa-2x mb-2"></i>
                <h3><?= number_format($student_stats['faculties_represented']) ?></h3>
                <p class="mb-0">Faculties</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name, reg number, email...">
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
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select class="form-select" name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= $department['id'] ?>" <?= $department_filter == $department['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($department['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Level</label>
                <select class="form-select" name="level">
                    <option value="">All Levels</option>
                    <option value="100" <?= $level_filter == '100' ? 'selected' : '' ?>>100 Level</option>
                    <option value="200" <?= $level_filter == '200' ? 'selected' : '' ?>>200 Level</option>
                    <option value="300" <?= $level_filter == '300' ? 'selected' : '' ?>>300 Level</option>
                    <option value="400" <?= $level_filter == '400' ? 'selected' : '' ?>>400 Level</option>
                    <option value="500" <?= $level_filter == '500' ? 'selected' : '' ?>>500 Level</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>

    <!-- Students List -->
    <div class="row">
        <?php if ($students): ?>
            <?php foreach ($students as $student): ?>
                <div class="col-xl-4 col-lg-6 mb-4">
                    <div class="card student-card position-relative">
                        <span class="status-badge">
                            <?php if ($student['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </span>
                        
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="student-avatar me-3">
                                    <?= strtoupper(substr($student['firstname'], 0, 1) . substr($student['surname'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h6 class="mb-1"><?= htmlspecialchars($student['firstname'] . ' ' . $student['surname']) ?></h6>
                                    <small class="text-muted"><?= htmlspecialchars($student['reg_number']) ?></small>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Faculty</small><br>
                                    <strong><?= htmlspecialchars($student['faculty_name'] ?? 'Not Assigned') ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Level</small><br>
                                    <strong><?= $student['level'] ?> Level</strong>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Department</small><br>
                                    <strong><?= htmlspecialchars($student['department_name'] ?? 'Not Assigned') ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Enrolled Courses</small><br>
                                    <strong><?= $student['enrolled_courses'] ?></strong>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Email</small><br>
                                <small><?= htmlspecialchars($student['email']) ?></small>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Phone</small><br>
                                <small><?= htmlspecialchars($student['phone'] ?? 'Not provided') ?></small>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary flex-fill" 
                                        onclick="editStudent(<?= htmlspecialchars(json_encode($student)) ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="viewStudent(<?= htmlspecialchars(json_encode($student)) ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $student['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                        <i class="fas fa-<?= $student['is_active'] ? 'pause' : 'play' ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No students found</h4>
                    <p class="text-muted">Try adjusting your search criteria or add a new student.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
                        <i class="fas fa-plus"></i> Add First Student
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Student pagination">
            <ul class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page - 1 ?>&<?= http_build_query($_GET) ?>">Previous</a>
                    </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>&<?= http_build_query($_GET) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $page + 1 ?>&<?= http_build_query($_GET) ?>">Next</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New Student</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_student">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Registration Number *</label>
                            <input type="text" class="form-control" name="reg_number" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Admission Year *</label>
                            <select class="form-select" name="admission_year" required>
                                <?php for ($year = date('Y'); $year >= date('Y') - 10; $year--): ?>
                                    <option value="<?= $year ?>"><?= $year ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="firstname" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Surname *</label>
                            <input type="text" class="form-control" name="surname" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Faculty *</label>
                            <select class="form-select" name="faculty_id" required id="addFacultySelect">
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= $faculty['id'] ?>"><?= htmlspecialchars($faculty['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department *</label>
                            <select class="form-select" name="department_id" required id="addDepartmentSelect">
                                <option value="">Select Department</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="level" required>
                                <option value="100">100 Level</option>
                                <option value="200">200 Level</option>
                                <option value="300">300 Level</option>
                                <option value="400">400 Level</option>
                                <option value="500">500 Level</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Guardian Name</label>
                            <input type="text" class="form-control" name="guardian_name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Guardian Phone</label>
                            <input type="tel" class="form-control" name="guardian_phone">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Student</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editStudentForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_student">
                    <input type="hidden" name="student_id" id="editStudentId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Registration Number</label>
                            <input type="text" class="form-control" id="editRegNumber" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Admission Year</label>
                            <input type="text" class="form-control" id="editAdmissionYear" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">First Name *</label>
                            <input type="text" class="form-control" name="firstname" id="editFirstname" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Surname *</label>
                            <input type="text" class="form-control" name="surname" id="editSurname" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email *</label>
                            <input type="email" class="form-control" name="email" id="editEmail" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="tel" class="form-control" name="phone" id="editPhone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Faculty *</label>
                            <select class="form-select" name="faculty_id" required id="editFacultySelect">
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= $faculty['id'] ?>"><?= htmlspecialchars($faculty['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department *</label>
                            <select class="form-select" name="department_id" required id="editDepartmentSelect">
                                <option value="">Select Department</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Level *</label>
                            <select class="form-select" name="level" required id="editLevel">
                                <option value="100">100 Level</option>
                                <option value="200">200 Level</option>
                                <option value="300">300 Level</option>
                                <option value="400">400 Level</option>
                                <option value="500">500 Level</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth" id="editDateOfBirth">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-select" name="gender" id="editGender">
                                <option value="">Select Gender</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Guardian Name</label>
                            <input type="text" class="form-control" name="guardian_name" id="editGuardianName">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Guardian Phone</label>
                            <input type="tel" class="form-control" name="guardian_phone" id="editGuardianPhone">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="3" id="editAddress"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Student Modal -->
<div class="modal fade" id="viewStudentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Student Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 text-center mb-4">
                        <div class="student-avatar mx-auto mb-3" style="width: 80px; height: 80px; font-size: 24px;" id="viewAvatar">
                        </div>
                        <h5 id="viewFullName"></h5>
                        <p class="text-muted" id="viewRegNumber"></p>
                        <span class="badge" id="viewStatus"></span>
                    </div>
                    <div class="col-md-8">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <strong>Email:</strong><br>
                                <span id="viewEmail"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Phone:</strong><br>
                                <span id="viewPhone"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Faculty:</strong><br>
                                <span id="viewFaculty"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Department:</strong><br>
                                <span id="viewDepartment"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Level:</strong><br>
                                <span id="viewLevel"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Admission Year:</strong><br>
                                <span id="viewAdmissionYear"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Date of Birth:</strong><br>
                                <span id="viewDateOfBirth"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Gender:</strong><br>
                                <span id="viewGender"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Guardian Name:</strong><br>
                                <span id="viewGuardianName"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Guardian Phone:</strong><br>
                                <span id="viewGuardianPhone"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Enrolled Courses:</strong><br>
                                <span id="viewEnrolledCourses"></span>
                            </div>
                            <div class="col-md-6">
                                <strong>Created:</strong><br>
                                <span id="viewCreatedAt"></span>
                            </div>
                            <div class="col-12">
                                <strong>Address:</strong><br>
                                <span id="viewAddress"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="editFromView()">Edit Student</button>
            </div>
        </div>
    </div>
</div>

<script>
// Department data for dynamic loading
const departmentData = <?= json_encode($departments) ?>;

// Function to update departments based on selected faculty
function updateDepartments(facultySelect, departmentSelect) {
    const facultyId = facultySelect.value;
    const departmentOptions = departmentSelect;
    
    // Clear existing options
    departmentOptions.innerHTML = '<option value="">Select Department</option>';
    
    if (facultyId) {
        // Filter departments by faculty
        const filteredDepartments = departmentData.filter(dept => dept.faculty_id == facultyId);
        
        filteredDepartments.forEach(dept => {
            const option = document.createElement('option');
            option.value = dept.id;
            option.textContent = dept.name;
            departmentOptions.appendChild(option);
        });
    }
}

// Add event listeners for faculty selection
document.getElementById('addFacultySelect').addEventListener('change', function() {
    updateDepartments(this, document.getElementById('addDepartmentSelect'));
});

document.getElementById('editFacultySelect').addEventListener('change', function() {
    updateDepartments(this, document.getElementById('editDepartmentSelect'));
});

// Function to edit student
function editStudent(student) {
    // Populate form fields
    document.getElementById('editStudentId').value = student.id;
    document.getElementById('editRegNumber').value = student.reg_number;
    document.getElementById('editAdmissionYear').value = student.admission_year;
    document.getElementById('editFirstname').value = student.firstname;
    document.getElementById('editSurname').value = student.surname;
    document.getElementById('editEmail').value = student.email;
    document.getElementById('editPhone').value = student.phone || '';
    document.getElementById('editLevel').value = student.level;
    document.getElementById('editDateOfBirth').value = student.date_of_birth || '';
    document.getElementById('editGender').value = student.gender || '';
    document.getElementById('editGuardianName').value = student.guardian_name || '';
    document.getElementById('editGuardianPhone').value = student.guardian_phone || '';
    document.getElementById('editAddress').value = student.address || '';
    
    // Set faculty
    document.getElementById('editFacultySelect').value = student.faculty_id;
    
    // Update departments and set department
    updateDepartments(
        document.getElementById('editFacultySelect'),
        document.getElementById('editDepartmentSelect')
    );
    
    // Set department after departments are loaded
    setTimeout(() => {
        document.getElementById('editDepartmentSelect').value = student.department_id;
    }, 100);
    
    // Show modal
    new bootstrap.Modal(document.getElementById('editStudentModal')).show();
}

// Function to view student details
function viewStudent(student) {
    // Set avatar
    const avatar = document.getElementById('viewAvatar');
    avatar.textContent = (student.firstname.charAt(0) + student.surname.charAt(0)).toUpperCase();
    
    // Set basic info
    document.getElementById('viewFullName').textContent = student.firstname + ' ' + student.surname;
    document.getElementById('viewRegNumber').textContent = student.reg_number;
    
    // Set status badge
    const statusBadge = document.getElementById('viewStatus');
    if (student.is_active == 1) {
        statusBadge.className = 'badge bg-success';
        statusBadge.textContent = 'Active';
    } else {
        statusBadge.className = 'badge bg-danger';
        statusBadge.textContent = 'Inactive';
    }
    
    // Set detailed info
    document.getElementById('viewEmail').textContent = student.email;
    document.getElementById('viewPhone').textContent = student.phone || 'Not provided';
    document.getElementById('viewFaculty').textContent = student.faculty_name || 'Not assigned';
    document.getElementById('viewDepartment').textContent = student.department_name || 'Not assigned';
    document.getElementById('viewLevel').textContent = student.level + ' Level';
    document.getElementById('viewAdmissionYear').textContent = student.admission_year;
    document.getElementById('viewDateOfBirth').textContent = student.date_of_birth || 'Not provided';
    document.getElementById('viewGender').textContent = student.gender || 'Not specified';
    document.getElementById('viewGuardianName').textContent = student.guardian_name || 'Not provided';
    document.getElementById('viewGuardianPhone').textContent = student.guardian_phone || 'Not provided';
    document.getElementById('viewEnrolledCourses').textContent = student.enrolled_courses + ' courses';
    document.getElementById('viewAddress').textContent = student.address || 'Not provided';
    
    // Format created date
    if (student.created_at) {
        const createdDate = new Date(student.created_at);
        document.getElementById('viewCreatedAt').textContent = createdDate.toLocaleDateString();
    }
    
    // Store student data for potential editing
    window.currentViewingStudent = student;
    
    // Show modal
    new bootstrap.Modal(document.getElementById('viewStudentModal')).show();
}

// Function to edit from view modal
function editFromView() {
    // Hide view modal
    bootstrap.Modal.getInstance(document.getElementById('viewStudentModal')).hide();
    
    // Show edit modal with current student data
    setTimeout(() => {
        editStudent(window.currentViewingStudent);
    }, 300);
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>

<?php include_once '../includes/footer.php'; ?>