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
        case 'add_faculty':
            try {
                $stmt = $conn->prepare("
                    INSERT INTO faculties (name, code, created_at) 
                    VALUES (:name, :code, NOW())
                ");
                
                $stmt->execute([
                    'name' => $_POST['name'],
                    'code' => strtoupper($_POST['code'])
                ]);
                
                $message = 'Faculty added successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Faculty name or code already exists!';
                } else {
                    $error = 'Error adding faculty: ' . $e->getMessage();
                }
            }
            break;
            
        case 'update_faculty':
            try {
                $stmt = $conn->prepare("
                    UPDATE faculties SET 
                        name = :name, 
                        code = :code
                    WHERE id = :faculty_id
                ");
                
                $stmt->execute([
                    'name' => $_POST['name'],
                    'code' => strtoupper($_POST['code']),
                    'faculty_id' => $_POST['faculty_id']
                ]);
                
                $message = 'Faculty updated successfully!';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = 'Faculty name or code already exists!';
                } else {
                    $error = 'Error updating faculty: ' . $e->getMessage();
                }
            }
            break;
            
        case 'delete_faculty':
            try {
                // Check if faculty has departments
                $check_stmt = $conn->prepare("SELECT COUNT(*) as dept_count FROM departments WHERE faculty_id = :faculty_id");
                $check_stmt->execute(['faculty_id' => $_POST['faculty_id']]);
                $dept_count = $check_stmt->fetch()['dept_count'];
                
                if ($dept_count > 0) {
                    $error = 'Cannot delete faculty. It has ' . $dept_count . ' departments assigned to it.';
                } else {
                    $stmt = $conn->prepare("DELETE FROM faculties WHERE id = :faculty_id");
                    $stmt->execute(['faculty_id' => $_POST['faculty_id']]);
                    $message = 'Faculty deleted successfully!';
                }
            } catch (PDOException $e) {
                $error = 'Error deleting faculty: ' . $e->getMessage();
            }
            break;
    }
}

// Get filters
$search = $_GET['search'] ?? '';

// Build query with filters
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(f.name LIKE :search OR f.code LIKE :search)";
    $params['search'] = "%$search%";
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get faculties with pagination
$page = $_GET['page'] ?? 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$stmt = $conn->prepare("
    SELECT 
        f.*,
        COUNT(DISTINCT d.id) as department_count,
        COUNT(DISTINCT s.id) as student_count
    FROM faculties f
    LEFT JOIN departments d ON f.id = d.faculty_id
    LEFT JOIN students s ON f.id = s.faculty_id
    $where_clause
    GROUP BY f.id
    ORDER BY f.created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue('limit', $limit, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_stmt = $conn->prepare("SELECT COUNT(DISTINCT f.id) as total FROM faculties f $where_clause");
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_faculties = $count_stmt->fetch()['total'];
$total_pages = ceil($total_faculties / $limit);

// Get faculty statistics - FIXED VERSION
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT f.id) as total_faculties,
        COUNT(DISTINCT d.id) as total_departments,
        COUNT(DISTINCT s.id) as total_students,
        AVG(dept_count.dept_count) as avg_departments_per_faculty
    FROM faculties f
    LEFT JOIN departments d ON f.id = d.faculty_id
    LEFT JOIN students s ON f.id = s.faculty_id
    LEFT JOIN (
        SELECT faculty_id, COUNT(*) as dept_count 
        FROM departments 
        GROUP BY faculty_id
    ) dept_count ON f.id = dept_count.faculty_id
");
$stats_stmt->execute();
$faculty_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include_once '../includes/header.php'; ?>

<style>
.faculty-card {
    transition: all 0.3s ease;
    border: none;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(241, 205, 205, 0.1);
    height: 100%;
}

.faculty-card:hover {
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

.stat-card.departments {
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

.faculty-icon {
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

.faculty-code {
    background: rgba(102, 126, 234, 0.1);
    color: #667eea;
    padding: 4px 8px;
    border-radius: 15px;
    font-size: 0.8rem;
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
            <h2><i class="fas fa-university text-primary"></i> Faculty Management</h2>
            <p class="text-muted">Manage university faculties and their information</p>
        </div>
        <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
            <i class="fas fa-plus"></i> Add New Faculty
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
                <i class="fas fa-university fa-2x mb-2"></i>
                <h3><?= number_format($faculty_stats['total_faculties']) ?></h3>
                <p class="mb-0">Total Faculties</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card departments">
                <i class="fas fa-building fa-2x mb-2"></i>
                <h3><?= number_format($faculty_stats['total_departments']) ?></h3>
                <p class="mb-0">Total Departments</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card students">
                <i class="fas fa-user-graduate fa-2x mb-2"></i>
                <h3><?= number_format($faculty_stats['total_students']) ?></h3>
                <p class="mb-0">Total Students</p>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card average">
                <i class="fas fa-chart-line fa-2x mb-2"></i>
                <h3><?= number_format($faculty_stats['avg_departments_per_faculty'] ?? 0, 1) ?></h3>
                <p class="mb-0">Avg Departments/Faculty</p>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET" class="row g-3">
            <div class="col-md-10">
                <label class="form-label">Search Faculties</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Search by faculty name or code...">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </div>

    <!-- Faculties List -->
    <div class="row">
        <?php if ($faculties): ?>
            <?php foreach ($faculties as $faculty): ?>
                <div class="col-xl-4 col-lg-6 mb-4">
                    <div class="card faculty-card">
                        <div class="card-body text-center">
                            <div class="faculty-icon">
                                <?= strtoupper(substr($faculty['name'], 0, 2)) ?>
                            </div>
                            
                            <h5 class="card-title mb-2"><?= htmlspecialchars($faculty['name']) ?></h5>
                            <span class="faculty-code mb-3 d-inline-block"><?= htmlspecialchars($faculty['code']) ?></span>
                            
                            <div class="stats-row">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <strong><?= $faculty['department_count'] ?></strong><br>
                                        <small class="text-muted">Departments</small>
                                    </div>
                                    <div class="col-6">
                                        <strong><?= $faculty['student_count'] ?></strong><br>
                                        <small class="text-muted">Students</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <small class="text-muted">Created: <?= date('M d, Y', strtotime($faculty['created_at'])) ?></small>
                            </div>
                            
                            <div class="d-flex gap-2 justify-content-center">
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="editFaculty(<?= htmlspecialchars(json_encode($faculty)) ?>)"
                                        title="Edit Faculty">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-info" 
                                        onclick="viewFaculty(<?= htmlspecialchars(json_encode($faculty)) ?>)"
                                        title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($faculty['department_count'] == 0 && $faculty['student_count'] == 0): ?>
                                    <button class="btn btn-sm btn-outline-danger" 
                                            onclick="deleteFaculty(<?= $faculty['id'] ?>, '<?= htmlspecialchars($faculty['name']) ?>')"
                                            title="Delete Faculty">
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
                    <i class="fas fa-university fa-4x text-muted mb-3"></i>
                    <h4 class="text-muted">No faculties found</h4>
                    <p class="text-muted">Try adjusting your search criteria or add a new faculty.</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
                        <i class="fas fa-plus"></i> Add First Faculty
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <nav aria-label="Faculty pagination">
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

<!-- Add Faculty Modal -->
<div class="modal fade" id="addFacultyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-plus"></i> Add New Faculty</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_faculty">
                    
                    <div class="mb-3">
                        <label class="form-label">Faculty Name *</label>
                        <input type="text" class="form-control" name="name" required 
                               placeholder="e.g., Faculty of Engineering">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Faculty Code *</label>
                        <input type="text" class="form-control" name="code" required maxlength="10"
                               placeholder="e.g., ENG" style="text-transform: uppercase;">
                        <div class="form-text">Short code for the faculty (max 10 characters)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Faculty</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Faculty Modal -->
<div class="modal fade" id="editFacultyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Faculty</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_faculty">
                    <input type="hidden" name="faculty_id" id="editFacultyId">
                    
                    <div class="mb-3">
                        <label class="form-label">Faculty Name *</label>
                        <input type="text" class="form-control" name="name" id="editFacultyName" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Faculty Code *</label>
                        <input type="text" class="form-control" name="code" id="editFacultyCode" required maxlength="10"
                               style="text-transform: uppercase;">
                        <div class="form-text">Short code for the faculty (max 10 characters)</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Faculty</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Faculty Modal -->
<div class="modal fade" id="viewFacultyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title"><i class="fas fa-eye"></i> Faculty Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 text-center mb-4">
                        <div class="faculty-icon mx-auto mb-3" style="width: 100px; height: 100px; font-size: 28px;" id="viewFacultyIcon">
                        </div>
                        <h4 id="viewFacultyName"></h4>
                        <span class="faculty-code" id="viewFacultyCode"></span>
                    </div>
                    <div class="col-md-8">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="stats-row text-center">
                                    <h3 id="viewDepartmentCount" class="text-primary mb-1">0</h3>
                                    <p class="mb-0">Departments</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="stats-row text-center">
                                    <h3 id="viewStudentCount" class="text-success mb-1">0</h3>
                                    <p class="mb-0">Students</p>
                                </div>
                            </div>
                            <div class="col-12">
                                <strong>Created:</strong><br>
                                <span id="viewCreatedAt"></span>
                            </div>
                            <div class="col-12">
                                <strong>Faculty ID:</strong><br>
                                <span id="viewFacultyId"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="editFromView()">
                    <i class="fas fa-edit"></i> Edit Faculty
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteFacultyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the faculty "<strong id="deleteFacultyName"></strong>"?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-info-circle"></i> This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" class="d-inline" id="deleteFacultyForm">
                    <input type="hidden" name="action" value="delete_faculty">
                    <input type="hidden" name="faculty_id" id="deleteFacultyId">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Faculty
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Function to edit faculty
function editFaculty(faculty) {
    document.getElementById('editFacultyId').value = faculty.id;
    document.getElementById('editFacultyName').value = faculty.name;
    document.getElementById('editFacultyCode').value = faculty.code;
    
    new bootstrap.Modal(document.getElementById('editFacultyModal')).show();
}

// Function to view faculty details
function viewFaculty(faculty) {
    // Set icon
    const icon = document.getElementById('viewFacultyIcon');
    icon.textContent = faculty.name.substring(0, 2).toUpperCase();
    
    // Set basic info
    document.getElementById('viewFacultyName').textContent = faculty.name;
    document.getElementById('viewFacultyCode').textContent = faculty.code;
    document.getElementById('viewFacultyId').textContent = faculty.id;
    
    // Set statistics
    document.getElementById('viewDepartmentCount').textContent = faculty.department_count;
    document.getElementById('viewStudentCount').textContent = faculty.student_count;
    
    // Format created date
    const createdDate = new Date(faculty.created_at);
    document.getElementById('viewCreatedAt').textContent = createdDate.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    // Store faculty data for potential editing
    window.currentViewingFaculty = faculty;
    
    new bootstrap.Modal(document.getElementById('viewFacultyModal')).show();
}

// Function to delete faculty
function deleteFaculty(facultyId, facultyName) {
    document.getElementById('deleteFacultyId').value = facultyId;
    document.getElementById('deleteFacultyName').textContent = facultyName;
    
    new bootstrap.Modal(document.getElementById('deleteFacultyModal')).show();
}

// Function to edit from view modal
function editFromView() {
    // Hide view modal
    bootstrap.Modal.getInstance(document.getElementById('viewFacultyModal')).hide();
    
    // Show edit modal with current faculty data
    setTimeout(() => {
        editFaculty(window.currentViewingFaculty);
    }, 300);
}

// Auto-uppercase faculty code inputs
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