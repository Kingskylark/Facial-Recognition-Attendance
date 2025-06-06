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
        case 'add_lecturer':
            try {
                $stmt = $conn->prepare("
                    INSERT INTO lecturers (
                        staff_id, title, firstname, surname, email, phone, 
                        faculty_id, department_id, qualification, specialization, 
                        date_of_birth, gender, address, employment_date, 
                        employment_type, office_location, created_at
                    ) VALUES (
                        :staff_id, :title, :firstname, :surname, :email, :phone,
                        :faculty_id, :department_id, :qualification, :specialization,
                        :date_of_birth, :gender, :address, :employment_date,
                        :employment_type, :office_location, NOW()
                    )
                ");
                
                $stmt->execute([
                    'staff_id' => $_POST['staff_id'],
                    'title' => $_POST['title'],
                    'firstname' => $_POST['firstname'],
                    'surname' => $_POST['surname'],
                    'email' => $_POST['email'],
                    'phone' => $_POST['phone'],
                    'faculty_id' => $_POST['faculty_id'],
                    'department_id' => $_POST['department_id'],
                    'qualification' => $_POST['qualification'],
                    'specialization' => $_POST['specialization'],
                    'date_of_birth' => $_POST['date_of_birth'],
                    'gender' => $_POST['gender'],
                    'address' => $_POST['address'],
                    'employment_date' => $_POST['employment_date'],
                    'employment_type' => $_POST['employment_type'],
                    'office_location' => $_POST['office_location']
                ]);
                
                $message = 'Lecturer added successfully!';
            } catch (PDOException $e) {
                $error = 'Error adding lecturer: ' . $e->getMessage();
            }
            break;
            
        case 'update_lecturer':
            try {
                $stmt = $conn->prepare("
                    UPDATE lecturers SET 
                        title = :title, firstname = :firstname, surname = :surname, 
                        email = :email, phone = :phone, faculty_id = :faculty_id, 
                        department_id = :department_id, qualification = :qualification,
                        specialization = :specialization, date_of_birth = :date_of_birth, 
                        gender = :gender, address = :address, employment_date = :employment_date,
                        employment_type = :employment_type, office_location = :office_location,
                        updated_at = NOW()
                    WHERE id = :lecturer_id
                ");
                
                $stmt->execute([
                    'title' => $_POST['title'],
                    'firstname' => $_POST['firstname'],
                    'surname' => $_POST['surname'],
                    'email' => $_POST['email'],
                    'phone' => $_POST['phone'],
                    'faculty_id' => $_POST['faculty_id'],
                    'department_id' => $_POST['department_id'],
                    'qualification' => $_POST['qualification'],
                    'specialization' => $_POST['specialization'],
                    'date_of_birth' => $_POST['date_of_birth'],
                    'gender' => $_POST['gender'],
                    'address' => $_POST['address'],
                    'employment_date' => $_POST['employment_date'],
                    'employment_type' => $_POST['employment_type'],
                    'office_location' => $_POST['office_location'],
                    'lecturer_id' => $_POST['lecturer_id']
                ]);
                
                $message = 'Lecturer updated successfully!';
            } catch (PDOException $e) {
                $error = 'Error updating lecturer: ' . $e->getMessage();
            }
            break;
            
        case 'toggle_status':
            try {
                $stmt = $conn->prepare("UPDATE lecturers SET is_active = !is_active WHERE id = :lecturer_id");
                $stmt->execute(['lecturer_id' => $_POST['lecturer_id']]);
                $message = 'Lecturer status updated successfully!';
            } catch (PDOException $e) {
                $error = 'Error updating lecturer status: ' . $e->getMessage();
            }
            break;
    }
}

// Get filters
$search = $_GET['search'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$department_filter = $_GET['department'] ?? '';
$qualification_filter = $_GET['qualification'] ?? '';
$employment_type_filter = $_GET['employment_type'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(l.firstname LIKE :search OR l.surname LIKE :search OR l.staff_id LIKE :search OR l.email LIKE :search OR l.specialization LIKE :search)";
    $params['search'] = "%$search%";
}

if ($faculty_filter) {
    $where_conditions[] = "l.faculty_id = :faculty_filter";
    $params['faculty_filter'] = $faculty_filter;
}

if ($department_filter) {
    $where_conditions[] = "l.department_id = :department_filter";
    $params['department_filter'] = $department_filter;
}

if ($qualification_filter) {
    $where_conditions[] = "l.qualification = :qualification_filter";
    $params['qualification_filter'] = $qualification_filter;
}

if ($employment_type_filter) {
    $where_conditions[] = "l.employment_type = :employment_type_filter";
    $params['employment_type_filter'] = $employment_type_filter;
}

if ($status_filter !== '') {
    $where_conditions[] = "l.is_active = :status_filter";
    $params['status_filter'] = $status_filter;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get lecturers with pagination
$page = $_GET['page'] ?? 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT 
        l.*,
        f.name as faculty_name,
        d.name as department_name,
        COUNT(DISTINCT lc.course_id) as assigned_courses
    FROM lecturers l
    LEFT JOIN faculties f ON l.faculty_id = f.id
    LEFT JOIN departments d ON l.department_id = d.id
    LEFT JOIN lecturer_courses lc ON l.id = lc.lecturer_id
    $where_clause
    GROUP BY l.id
    ORDER BY l.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$lecturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT l.id) as total FROM lecturers l $where_clause");
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_lecturers = $count_stmt->fetch()['total'];
$total_pages = ceil($total_lecturers / $limit);

// Get faculties and departments for dropdowns
$faculties_stmt = $conn->prepare("SELECT * FROM faculties ORDER BY name");
$faculties_stmt->execute();
$faculties = $faculties_stmt->fetchAll(PDO::FETCH_ASSOC);

$departments_stmt = $conn->prepare("SELECT d.*, f.name as faculty_name FROM departments d LEFT JOIN faculties f ON d.faculty_id = f.id ORDER BY f.name, d.name");
$departments_stmt->execute();
$departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lecturer statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_lecturers,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_lecturers,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_lecturers,
        COUNT(DISTINCT faculty_id) as faculties_represented,
        SUM(CASE WHEN employment_type = 'Full-time' THEN 1 ELSE 0 END) as fulltime_lecturers,
        SUM(CASE WHEN employment_type = 'Part-time' THEN 1 ELSE 0 END) as parttime_lecturers,
        SUM(CASE WHEN employment_type = 'Contract' THEN 1 ELSE 0 END) as contract_lecturers
    FROM lecturers
");
$stats_stmt->execute();
$lecturer_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

?>

<?php include_once '../includes/header.php'; ?>

<style>
.lecturer-card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.lecturer-card:hover {
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

.stat-card.fulltime {
    background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
    color: #333;
}

.filter-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.lecturer-avatar {
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

.employment-badge {
    position: absolute;
    top: 10px;
    left: 10px;
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

.qualification-badge {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 15px;
}
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-chalkboard-teacher text-primary"></i> Lecturer Management</h2>
            <p class="text-muted">Manage lecturer records, assignments, and information</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addLecturerModal">
            <i class="fas fa-plus"></i> Add New Lecturer
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
                <i class="fas fa-chalkboard-teacher fa-2x mb-2"></i>
                <h3><?= number_format($lecturer_stats['total_lecturers']) ?></h3>
                <p class="mb-0">Total Lecturers</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card inactive">
                <i class="fas fa-user-times fa-2x mb-2"></i>
                <h3><?= number_format($lecturer_stats['inactive_lecturers']) ?></h3>
                <p class="mb-0">Inactive</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card fulltime">
                <i class="fas fa-clock fa-2x mb-2"></i>
                <h3><?= number_format($lecturer_stats['fulltime_lecturers']) ?></h3>
                <p class="mb-0">Full-time</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card faculties">
                <i class="fas fa-university fa-2x mb-2"></i>
                <h3><?= number_format($lecturer_stats['faculties_represented']) ?></h3>
                <p class="mb-0">Faculties</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <i class="fas fa-handshake fa-2x mb-2"></i>
                <h3><?= number_format($lecturer_stats['contract_lecturers']) ?></h3>
                <p class="mb-0">Contract</p>
            </div>
        </div>
        <div class="col-lg-2 col-md-4 col-6 mb-3">
            <div class="stat-card" style="background: linear-gradient(135deg,rgb(152, 225, 235) 0%,rgb(22, 189, 189) 100%);">
                <i class="fas fa-hourglass fa-2x mb-2"></i>
                <h3><?= number_format($lecturer_stats['parttime_lecturers']) ?></h3>
                <p class="mb-0">Part Time</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-2">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Name, staff number, email...">
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
                <label class="form-label">Qualification</label>
                <select class="form-select" name="qualification">
                    <option value="">All Qualifications</option>
                    <option value="PhD" <?= $qualification_filter == 'PhD' ? 'selected' : '' ?>>PhD</option>
                    <option value="Masters" <?= $qualification_filter == 'Masters' ? 'selected' : '' ?>>Masters</option>
                    <option value="Bachelor" <?= $qualification_filter == 'Bachelor' ? 'selected' : '' ?>>Bachelor</option>
                    <option value="Diploma" <?= $qualification_filter == 'Diploma' ? 'selected' : '' ?>>Diploma</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Employment Type</label>
                <select class="form-select" name="employment_type">
                    <option value="">All Types</option>
                    <option value="Full-time" <?= $employment_type_filter == 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                    <option value="Part-time" <?= $employment_type_filter == 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                    <option value="Contract" <?= $employment_type_filter == 'Contract' ? 'selected' : '' ?>>Contract</option>
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
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>

    <!-- Lecturers List -->
    <div class="row">
        <?php if ($lecturers): ?>
            <?php foreach ($lecturers as $lecturer): ?>
                <div class="col-xl-4 col-lg-6 mb-4">
                    <div class="card lecturer-card position-relative">
                        <span class="employment-badge">
                            <?php
                            $employmentClass = match($lecturer['employment_type']) {
                                'Full-time' => 'bg-success',
                                'Part-time' => 'bg-warning',
                                'Contract' => 'bg-info',
                                default => 'bg-secondary'
                            };
                            ?>
                            <span class="badge <?= $employmentClass ?>"><?= htmlspecialchars($lecturer['employment_type']) ?></span>
                        </span>
                        
                        <span class="status-badge">
                            <?php if ($lecturer['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inactive</span>
                            <?php endif; ?>
                        </span>
                        
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-3">
                                <div class="lecturer-avatar me-3">
                                    <?= strtoupper(substr($lecturer['firstname'], 0, 1) . substr($lecturer['surname'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h6 class="mb-1">
                                        <?= htmlspecialchars($lecturer['title'] . ' ' . $lecturer['firstname'] . ' ' . $lecturer['surname']) ?>
                                    </h6>
                                    <small class="text-muted"><?= htmlspecialchars($lecturer['staff_id']) ?></small>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <span class="qualification-badge bg-primary text-white">
                                    <?= htmlspecialchars($lecturer['qualification']) ?>
                                </span>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <small class="text-muted">Faculty</small><br>
                                    <strong><?= htmlspecialchars($lecturer['faculty_name'] ?? 'Not Assigned') ?></strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted">Courses</small><br>
                                    <strong><?= $lecturer['assigned_courses'] ?></strong>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-12">
                                    <small class="text-muted">Department</small><br>
                                    <strong><?= htmlspecialchars($lecturer['department_name'] ?? 'Not Assigned') ?></strong>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Specialization</small><br>
                                <small><?= htmlspecialchars($lecturer['specialization'] ?? 'Not specified') ?></small>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Email</small><br>
                                <small><?= htmlspecialchars($lecturer['email']) ?></small>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Office</small><br>
                                <small><?= htmlspecialchars($lecturer['office_location'] ?? 'Not assigned') ?></small>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-primary flex-fill" 
                                        onclick="editLecturer(<?= htmlspecialchars(json_encode($lecturer)) ?>)">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="viewLecturer(<?= htmlspecialchars(json_encode($lecturer)) ?>)">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="lecturer_id" value="<?= $lecturer['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $lecturer['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                        <i class="fas fa-<?= $lecturer['is_active'] ? 'pause' : 'play' ?>"></i>
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
                    <i class="fas fa-chalkboard-teacher fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No lecturers found</h4>
                    <p class="text-muted">Try adjusting your search criteria or add a new lecturer.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLecturerModal">
                        <i class="fas fa-plus"></i> Add First Lecturer
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Lecturer pagination">
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

<!-- Add Lecturer Modal -->
<div class="modal fade" id="addLecturerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add New Lecturer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_lecturer">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Staff Number *</label>
                            <input type="text" class="form-control" name="staff_id" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Title *</label>
                            <select class="form-select" name="title" required>
                                <option value="">Select Title</option>
                                <option value="Dr.">Dr.</option>
                                <option value="Asso. Prof.">Asso. Prof.</option>
                                <option value="Prof.">Prof.</option>
                                <option value="Mr.">Mr.</option>
                                <option value="Mrs.">Mrs.</option>
                                <option value="Ms.">Ms.</option>
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
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= $department['id'] ?>" data-faculty="<?= $department['faculty_id'] ?>">
                                        <?= htmlspecialchars($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Qualification *</label>
                            <select class="form-select" name="qualification" required>
                                <option value="">Select Qualification</option>
                                <option value="PhD">PhD</option>
                                <option value="Masters">Masters</option>
                                <option value="Bachelor">Bachelor</option>
                                <option value="Diploma">Diploma</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Specialization</label>
                            <input type="text" class="form-control" name="specialization" placeholder="e.g., Computer Science, Mathematics">
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
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2" placeholder="Full address"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employment Date *</label>
                            <input type="date" class="form-control" name="employment_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" required>
                                <option value="">Select Type</option>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Office Location</label>
                            <input type="text" class="form-control" name="office_location" placeholder="e.g., Building A, Room 201">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Lecturer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Lecturer Modal -->
<div class="modal fade" id="editLecturerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Lecturer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_lecturer">
                    <input type="hidden" name="lecturer_id" id="editLecturerId">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Staff Number *</label>
                            <input type="text" class="form-control" name="staff_id" id="editStaffNumber" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Title *</label>
                            <select class="form-select" name="title" id="editTitle" required>
                                <option value="">Select Title</option>
                                <option value="Dr.">Dr.</option>
                                <option value="Asso. Prof.">Asso. Prof.</option>
                                <option value="Prof.">Prof.</option>
                                <option value="Mr.">Mr.</option>
                                <option value="Mrs.">Mrs.</option>
                                <option value="Ms.">Ms.</option>
                            </select>
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
                            <select class="form-select" name="faculty_id" id="editFacultySelect" required>
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= $faculty['id'] ?>"><?= htmlspecialchars($faculty['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department *</label>
                            <select class="form-select" name="department_id" id="editDepartmentSelect" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $department): ?>
                                    <option value="<?= $department['id'] ?>" data-faculty="<?= $department['faculty_id'] ?>">
                                        <?= htmlspecialchars($department['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Qualification *</label>
                            <select class="form-select" name="qualification" id="editQualification" required>
                                <option value="">Select Qualification</option>
                                <option value="PhD">PhD</option>
                                <option value="Masters">Masters</option>
                                <option value="Bachelor">Bachelor</option>
                                <option value="Diploma">Diploma</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Specialization</label>
                            <input type="text" class="form-control" name="specialization" id="editSpecialization">
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
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" id="editAddress" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employment Date *</label>
                            <input type="date" class="form-control" name="employment_date" id="editEmploymentDate" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Employment Type *</label>
                            <select class="form-select" name="employment_type" id="editEmploymentType" required>
                                <option value="">Select Type</option>
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Office Location</label>
                            <input type="text" class="form-control" name="office_location" id="editOfficeLocation">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Lecturer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Lecturer Modal -->
<div class="modal fade" id="viewLecturerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Lecturer Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-12 text-center mb-4">
                        <div class="lecturer-avatar mx-auto" style="width: 80px; height: 80px; font-size: 24px;" id="viewAvatar"></div>
                        <h4 class="mt-3" id="viewFullName"></h4>
                        <p class="text-muted" id="viewStaffNumber"></p>
                        <div id="viewStatusBadge"></div>
                    </div>
                    
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
                        <strong>Qualification:</strong><br>
                        <span id="viewQualification"></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Specialization:</strong><br>
                        <span id="viewSpecialization"></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Employment Type:</strong><br>
                        <span id="viewEmploymentType"></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Employment Date:</strong><br>
                        <span id="viewEmploymentDate"></span>
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
                        <strong>Office Location:</strong><br>
                        <span id="viewOfficeLocation"></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Assigned Courses:</strong><br>
                        <span id="viewAssignedCourses"></span>
                    </div>
                    <div class="col-md-12">
                        <strong>Address:</strong><br>
                        <span id="viewAddress"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="editLecturerFromView()">
                    <i class="fas fa-edit"></i> Edit Lecturer
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Faculty-Department filtering
function filterDepartments(facultySelect, departmentSelect) {
    const facultyId = facultySelect.value;
    const departmentOptions = departmentSelect.querySelectorAll('option[data-faculty]');
    
    // Show all departments if no faculty is selected
    if (!facultyId) {
        departmentOptions.forEach(option => {
            option.style.display = 'block';
        });
        return;
    }
    
    // Filter departments by faculty
    departmentOptions.forEach(option => {
        if (option.getAttribute('data-faculty') === facultyId) {
            option.style.display = 'block';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Reset department selection if current selection is not valid for the new faculty
    if (departmentSelect.value) {
        const selectedOption = departmentSelect.querySelector(`option[value="${departmentSelect.value}"]`);
        if (selectedOption && selectedOption.getAttribute('data-faculty') !== facultyId) {
            departmentSelect.value = '';
        }
    }
}

// Initialize department filtering for Add form
document.getElementById('addFacultySelect').addEventListener('change', function() {
    filterDepartments(this, document.getElementById('addDepartmentSelect'));
});

// Initialize department filtering for Edit form
document.getElementById('editFacultySelect').addEventListener('change', function() {
    filterDepartments(this, document.getElementById('editDepartmentSelect'));
});

// Edit lecturer function
function editLecturer(lecturer) {
    // Populate the edit form with lecturer data
    document.getElementById('editLecturerId').value = lecturer.id;
    document.getElementById('editStaffNumber').value = lecturer.staff_id;
    document.getElementById('editTitle').value = lecturer.title;
    document.getElementById('editFirstname').value = lecturer.firstname;
    document.getElementById('editSurname').value = lecturer.surname;
    document.getElementById('editEmail').value = lecturer.email;
    document.getElementById('editPhone').value = lecturer.phone || '';
    document.getElementById('editFacultySelect').value = lecturer.faculty_id || '';
    document.getElementById('editDepartmentSelect').value = lecturer.department_id || '';
    document.getElementById('editQualification').value = lecturer.qualification;
    document.getElementById('editSpecialization').value = lecturer.specialization || '';
    document.getElementById('editDateOfBirth').value = lecturer.date_of_birth || '';
    document.getElementById('editGender').value = lecturer.gender || '';
    document.getElementById('editAddress').value = lecturer.address || '';
    document.getElementById('editEmploymentDate').value = lecturer.employment_date;
    document.getElementById('editEmploymentType').value = lecturer.employment_type;
    document.getElementById('editOfficeLocation').value = lecturer.office_location || '';
    
    // Filter departments based on selected faculty
    filterDepartments(
        document.getElementById('editFacultySelect'),
        document.getElementById('editDepartmentSelect')
    );
    
    // Show the modal
    new bootstrap.Modal(document.getElementById('editLecturerModal')).show();
}

// Global variable to store current lecturer data for view modal
let currentLecturerData = null;

// View lecturer function
function viewLecturer(lecturer) {
    currentLecturerData = lecturer;
    
    // Populate the view modal with lecturer data
    const avatar = document.getElementById('viewAvatar');
    avatar.textContent = (lecturer.firstname.charAt(0) + lecturer.surname.charAt(0)).toUpperCase();
    
    document.getElementById('viewFullName').textContent = `${lecturer.title} ${lecturer.firstname} ${lecturer.surname}`;
    document.getElementById('viewStaffNumber').textContent = lecturer.staff_id;
    
    // Status badge
    const statusBadge = document.getElementById('viewStatusBadge');
    if (lecturer.is_active == 1) {
        statusBadge.innerHTML = '<span class="badge bg-success">Active</span>';
    } else {
        statusBadge.innerHTML = '<span class="badge bg-danger">Inactive</span>';
    }
    
    document.getElementById('viewEmail').textContent = lecturer.email;
    document.getElementById('viewPhone').textContent = lecturer.phone || 'Not provided';
    document.getElementById('viewFaculty').textContent = lecturer.faculty_name || 'Not assigned';
    document.getElementById('viewDepartment').textContent = lecturer.department_name || 'Not assigned';
    document.getElementById('viewQualification').textContent = lecturer.qualification;
    document.getElementById('viewSpecialization').textContent = lecturer.specialization || 'Not specified';
    document.getElementById('viewEmploymentType').textContent = lecturer.employment_type;
    document.getElementById('viewEmploymentDate').textContent = lecturer.employment_date ? new Date(lecturer.employment_date).toLocaleDateString() : 'Not provided';
    document.getElementById('viewDateOfBirth').textContent = lecturer.date_of_birth ? new Date(lecturer.date_of_birth).toLocaleDateString() : 'Not provided';
    document.getElementById('viewGender').textContent = lecturer.gender || 'Not specified';
    document.getElementById('viewOfficeLocation').textContent = lecturer.office_location || 'Not assigned';
    document.getElementById('viewAssignedCourses').textContent = lecturer.assigned_courses + ' courses';
    document.getElementById('viewAddress').textContent = lecturer.address || 'Not provided';
    
    // Show the modal
    new bootstrap.Modal(document.getElementById('viewLecturerModal')).show();
}

// Function to edit lecturer from view modal
function editLecturerFromView() {
    // Close view modal
    bootstrap.Modal.getInstance(document.getElementById('viewLecturerModal')).hide();
    
    // Open edit modal with current lecturer data
    setTimeout(() => {
        editLecturer(currentLecturerData);
    }, 300); // Small delay to ensure view modal is closed
}

// Auto-dismiss alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert.classList.contains('show')) {
                bootstrap.Alert.getOrCreateInstance(alert).close();
            }
        }, 5000);
    });
});

// Search functionality enhancement
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.closest('form').submit();
            }
        });
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>