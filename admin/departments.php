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
        case 'add_department':
            try {
                $stmt = $conn->prepare("
                    INSERT INTO departments (faculty_id, name, code, created_at) 
                    VALUES (:faculty_id, :name, :code, NOW())
                ");
                
                $stmt->execute([
                    'faculty_id' => $_POST['faculty_id'],
                    'name' => $_POST['name'],
                    'code' => strtoupper($_POST['code'])
                ]);
                
                $message = 'Department added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Department name or code already exists!';
                } else {
                    $error = 'Error adding department: ' . $e->getMessage();
                }
            }
            break;
            
        case 'update_department':
            try {
                $stmt = $conn->prepare("
                    UPDATE departments SET 
                        faculty_id = :faculty_id,
                        name = :name, 
                        code = :code
                    WHERE id = :department_id
                ");
                
                $stmt->execute([
                    'faculty_id' => $_POST['faculty_id'],
                    'name' => $_POST['name'],
                    'code' => strtoupper($_POST['code']),
                    'department_id' => $_POST['department_id']
                ]);
                
                $message = 'Department updated successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Department name or code already exists!';
                } else {
                    $error = 'Error updating department: ' . $e->getMessage();
                }
            }
            break;
            
        case 'delete_department':
            try {
                // Check if department has students
                $check_stmt = $conn->prepare("SELECT COUNT(*) as student_count FROM students WHERE department_id = :department_id");
                $check_stmt->execute(['department_id' => $_POST['department_id']]);
                $student_count = $check_stmt->fetch()['student_count'];
                
                if ($student_count > 0) {
                    $error = 'Cannot delete department. It has ' . $student_count . ' students assigned to it.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM departments WHERE id = :department_id");
                    $stmt->execute(['department_id' => $_POST['department_id']]);
                    $message = 'Department deleted successfully!';
                }
            } catch (PDOException $e) {
                $error = 'Error deleting department: ' . $e->getMessage();
            }
            break;
    }
}

// Get filters
$search = $_GET['search'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(d.name LIKE :search OR d.code LIKE :search)";
    $params['search'] = "%$search%";
}

if ($faculty_filter) {
    $where_conditions[] = "d.faculty_id = :faculty_filter";
    $params['faculty_filter'] = $faculty_filter;
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get departments with pagination
$page = $_GET['page'] ?? 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT 
        d.*,
        f.name as faculty_name,
        f.code as faculty_code,
        COUNT(DISTINCT s.id) as student_count
    FROM departments d
    LEFT JOIN faculties f ON d.faculty_id = f.id
    LEFT JOIN students s ON d.id = s.department_id
    $where_clause
    GROUP BY d.id
    ORDER BY d.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT d.id) as total 
    FROM departments d
    LEFT JOIN faculties f ON d.faculty_id = f.id
    $where_clause
");
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_departments = $count_stmt->fetch()['total'];
$total_pages = ceil($total_departments / $limit);

// Get department statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT d.id) as total_departments,
        COUNT(DISTINCT d.faculty_id) as faculties_with_departments,
        COUNT(DISTINCT s.id) as total_students,
        AVG(student_count.student_count) as avg_students_per_department
    FROM departments d
    LEFT JOIN students s ON d.id = s.department_id
    LEFT JOIN (
        SELECT department_id, COUNT(*) as student_count 
        FROM students 
        GROUP BY department_id
    ) student_count ON d.id = student_count.department_id
");
$stats_stmt->execute();
$department_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get faculties for dropdown
$faculties_stmt = $conn->prepare("SELECT id, name, code FROM faculties ORDER BY name");
$faculties_stmt->execute();
$faculties = $faculties_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include_once '../includes/header.php'; ?>

<style>
.department-card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    height: 100%;
}

.department-card:hover {
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

.stat-card.faculties {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-card.students {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.stat-card.average {
    background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
}

.filter-section {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.department-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 20px;
    margin: 0 auto 15px;
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

.department-code {
    background: rgba(102, 126, 234, 0.1);
    color: #667eea;
    padding: 4px 8px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
}

.faculty-badge {
    background: rgba(76, 175, 254, 0.1);
    color: #4cafff;
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
</style>

<main class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-building text-primary"></i> Department Management</h2>
            <p class="text-muted">Manage university departments and their information</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
            <i class="fas fa-plus"></i> Add New Department
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
                <i class="fas fa-building fa-2x mb-2"></i>
                <h3><?= number_format($department_stats['total_departments']) ?></h3>
                <p class="mb-0">Total Departments</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card faculties">
                <i class="fas fa-university fa-2x mb-2"></i>
                <h3><?= number_format($department_stats['faculties_with_departments']) ?></h3>
                <p class="mb-0">Faculties with Departments</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card students">
                <i class="fas fa-user-graduate fa-2x mb-2"></i>
                <h3><?= number_format($department_stats['total_students']) ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card average">
                <i class="fas fa-chart-line fa-2x mb-2"></i>
                <h3><?= number_format($department_stats['avg_students_per_department'] ?? 0, 1) ?></h3>
                <p class="mb-0">Avg Students/Department</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Search Departments</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by department name or code...">
            </div>
            <div class="col-md-5">
                <label class="form-label">Filter by Faculty</label>
                <select class="form-select" name="faculty">
                    <option value="">All Faculties</option>
                    <?php foreach ($faculties as $faculty): ?>
                        <option value="<?= $faculty['id'] ?>" <?= $faculty_filter == $faculty['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($faculty['name']) ?> (<?= htmlspecialchars($faculty['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </div>

    <!-- Departments List -->
    <div class="row">
        <?php if ($departments): ?>
            <?php foreach ($departments as $department): ?>
                <div class="col-xl-4 col-lg-6 mb-4">
                    <div class="card department-card">
                        <div class="card-body text-center">
                            <div class="department-icon">
                                <?= strtoupper(substr($department['name'], 0, 2)) ?>
                            </div>
                            
                            <h5 class="card-title mb-2"><?= htmlspecialchars($department['name']) ?></h5>
                            <span class="department-code mb-2 d-inline-block"><?= htmlspecialchars($department['code']) ?></span>
                            
                            <div class="mb-3">
                                <span class="faculty-badge"><?= htmlspecialchars($department['faculty_code']) ?></span>
                                <div class="text-muted small mt-1"><?= htmlspecialchars($department['faculty_name']) ?></div>
                            </div>
                            
                            <div class="stats-row">
                                <div class="row text-center">
                                    <div class="col-12">
                                        <strong><?= $department['student_count'] ?></strong><br>
                                        <small class="text-muted">Students</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Created: <?= date('M d, Y', strtotime($department['created_at'])) ?></small>
                            </div>
                            
                            <div class="d-flex gap-2 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="editDepartment(<?= htmlspecialchars(json_encode($department)) ?>)"
                                        title="Edit Department">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="viewDepartment(<?= htmlspecialchars(json_encode($department)) ?>)"
                                        title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($department['student_count'] == 0): ?>
                                    <button class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteDepartment(<?= $department['id'] ?>, '<?= htmlspecialchars($department['name']) ?>')"
                                            title="Delete Department">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="fas fa-building fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No departments found</h4>
                    <p class="text-muted">Try adjusting your search criteria or add a new department.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                        <i class="fas fa-plus"></i> Add First Department
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Department pagination">
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

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add New Department</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_department">
                    
                    <div class="mb-3">
                        <label class="form-label">Faculty *</label>
                        <select class="form-select" name="faculty_id" required>
                            <option value="">Select Faculty</option>
                            <?php foreach ($faculties as $faculty): ?>
                                <option value="<?= $faculty['id'] ?>">
                                    <?= htmlspecialchars($faculty['name']) ?> (<?= htmlspecialchars($faculty['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Department Name *</label>
                        <input type="text" class="form-control" name="name" required 
                               placeholder="e.g., Computer Science">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Department Code *</label>
                        <input type="text" class="form-control" name="code" required maxlength="10"
                               placeholder="e.g., CS" style="text-transform: uppercase;">
                        <div class="form-text">Short code for the department (max 10 characters)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Department</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_department">
                    <input type="hidden" name="department_id" id="editDepartmentId">
                    
                    <div class="mb-3">
                        <label class="form-label">Faculty *</label>
                        <select class="form-select" name="faculty_id" id="editFacultyId" required>
                            <option value="">Select Faculty</option>
                            <?php foreach ($faculties as $faculty): ?>
                                <option value="<?= $faculty['id'] ?>">
                                    <?= htmlspecialchars($faculty['name']) ?> (<?= htmlspecialchars($faculty['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Department Name *</label>
                        <input type="text" class="form-control" name="name" id="editDepartmentName" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Department Code *</label>
                        <input type="text" class="form-control" name="code" id="editDepartmentCode" required maxlength="10"
                               style="text-transform: uppercase;">
                        <div class="form-text">Short code for the department (max 10 characters)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Department Modal -->
<div class="modal fade" id="viewDepartmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Department Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 text-center mb-4">
                        <div class="department-icon mx-auto mb-3" style="width: 100px; height: 100px; font-size: 28px;" id="viewDepartmentIcon">
                        </div>
                        <h4 id="viewDepartmentName"></h4>
                        <span class="department-code" id="viewDepartmentCode"></span>
                        <div class="mt-2">
                            <span class="faculty-badge" id="viewFacultyCode"></span>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="stats-row text-center">
                                    <h3 id="viewStudentCount" class="text-success mb-1">0</h3>
                                    <p class="mb-0">Students</p>
                                </div>
                            </div>
                            <div class="col-12">
                                <strong>Faculty:</strong><br>
                                <span id="viewFacultyName"></span>
                            </div>
                            <div class="col-12">
                                <strong>Created:</strong><br>
                                <span id="viewCreatedAt"></span>
                            </div>
                            <div class="col-12">
                                <strong>Department ID:</strong><br>
                                <span id="viewDepartmentId"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="editFromView()">
                    <i class="fas fa-edit"></i> Edit Department
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the department "<strong id="deleteDepartmentName"></strong>"?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i> This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline" id="deleteDepartmentForm">
                    <input type="hidden" name="action" value="delete_department">
                    <input type="hidden" name="department_id" id="deleteDepartmentId">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Department
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Function to edit department
function editDepartment(department) {
    document.getElementById('editDepartmentId').value = department.id;
    document.getElementById('editDepartmentName').value = department.name;
    document.getElementById('editDepartmentCode').value = department.code;
    document.getElementById('editFacultyId').value = department.faculty_id;
    
    new bootstrap.Modal(document.getElementById('editDepartmentModal')).show();
}

// Function to view department details
function viewDepartment(department) {
    // Set icon
    const icon = document.getElementById('viewDepartmentIcon');
    icon.textContent = department.name.substring(0, 2).toUpperCase();
    
    // Set basic info
    document.getElementById('viewDepartmentName').textContent = department.name;
    document.getElementById('viewDepartmentCode').textContent = department.code;
    document.getElementById('viewDepartmentId').textContent = department.id;
    document.getElementById('viewFacultyCode').textContent = department.faculty_code;
    document.getElementById('viewFacultyName').textContent = department.faculty_name;
    
    // Set statistics
    document.getElementById('viewStudentCount').textContent = department.student_count;
    
    // Format created date
    const createdDate = new Date(department.created_at);
    document.getElementById('viewCreatedAt').textContent = createdDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Store department data for potential editing
    window.currentViewingDepartment = department;
    
    new bootstrap.Modal(document.getElementById('viewDepartmentModal')).show();
}

// Function to delete department
function deleteDepartment(departmentId, departmentName) {
    document.getElementById('deleteDepartmentId').value = departmentId;
    document.getElementById('deleteDepartmentName').textContent = departmentName;
    
    new bootstrap.Modal(document.getElementById('deleteDepartmentModal')).show();
}

// Function to edit from view modal
function editFromView() {
    // Hide view modal
    bootstrap.Modal.getInstance(document.getElementById('viewDepartmentModal')).hide();
    
    // Show edit modal with current department data
    setTimeout(() => {
        editDepartment(window.currentViewingDepartment);
    }, 300);
}

// Auto-uppercase department code inputs
document.addEventListener('DOMContentLoaded', function() {
    const codeInputs = document.querySelectorAll('input[name="code"]');
    codeInputs.forEach(input => {
        input.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    });
    
    // Initialize tooltips
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

// Clear search when form is reset
document.querySelector('form').addEventListener('reset', function() {
    window.location.href = window.location.pathname;
});
</script>

<?php include_once '../includes/footer.php'; ?>
