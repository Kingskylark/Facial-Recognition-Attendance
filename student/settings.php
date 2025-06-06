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
$success_message = '';
$error_message = '';

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

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = 'All password fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'New password and confirmation do not match.';
    } elseif (strlen($new_password) < 6) {
        $error_message = 'New password must be at least 6 characters long.';
    } else {
        // Verify current password
        if (password_verify($current_password, $student['password'])) {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE students SET password = :password WHERE id = :student_id");
            
            if ($stmt->execute(['password' => $hashed_password, 'student_id' => $student_id])) {
                $success_message = 'Password changed successfully!';
            } else {
                $error_message = 'Failed to update password. Please try again.';
            }
        } else {
            $error_message = 'Current password is incorrect.';
        }
    }
}


?>

<?php include_once '../includes/header.php'; ?>

<style>
.settings-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
}

.settings-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 25px;
    transition: transform 0.3s ease;
}

.settings-card:hover {
    transform: translateY(-2px);
}

.card-header-custom {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    border-radius: 15px 15px 0 0 !important;
}

.form-control:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
}

.btn-primary {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border: none;
    border-radius: 10px;
    padding: 10px 25px;
    font-weight: 500;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #3730a3 0%, #6d28d9 100%);
    transform: translateY(-1px);
}

.alert {
    border-radius: 10px;
    border: none;
}

.profile-summary {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
}

.security-icon {
    font-size: 2rem;
    color: #dc3545;
    margin-bottom: 15px;
}

.preferences-icon {
    font-size: 2rem;
    color: #17a2b8;
    margin-bottom: 15px;
}

.password-strength {
    height: 5px;
    border-radius: 3px;
    margin-top: 5px;
    transition: all 0.3s ease;
}

.strength-weak { background-color: #dc3545; width: 33%; }
.strength-medium { background-color: #ffc107; width: 66%; }
.strength-strong { background-color: #28a745; width: 100%; }
</style>

<main class="container-fluid py-4">
    <!-- Settings Header -->
    <div class="settings-header text-center">
        <div class="row align-items-center">
            <div class="col-12">
                <h2 class="mb-2"><i class="fas fa-cog"></i> Account Settings</h2>
                <p class="mb-0">Manage your account security and preferences</p>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Summary -->
        <div class="col-md-4 mb-4">
            <div class="profile-summary">
                <div class="text-center">
                    <?php if ($student['image_path']): ?>
                        <img src="../uploads/students/<?= htmlspecialchars($student['image_path']) ?>" 
                             alt="Profile" class="rounded-circle mb-3" 
                             style="width: 100px; height: 100px; object-fit: cover;">
                    <?php else: ?>
                        <div class="bg-primary rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" 
                             style="width: 100px; height: 100px;">
                            <i class="fas fa-user fa-2x text-white"></i>
                        </div>
                    <?php endif; ?>
                    <h5><?= htmlspecialchars($student['firstname'] . ' ' . $student['surname']) ?></h5>
                    <p class="text-muted mb-1"><?= htmlspecialchars($student['reg_number']) ?></p>
                    <p class="text-muted mb-0"><?= htmlspecialchars($student['department_name']) ?></p>
                </div>
            </div>
            
            <!-- Quick Links -->
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h6 class="mb-0"><i class="fas fa-link"></i> Quick Links</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="index.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                        <a href="profile.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-user-edit"></i> Edit Profile
                        </a>
                        <a href="courses.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-book"></i> My Courses
                        </a>
                        <a href="attendance.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-calendar-check"></i> Attendance
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Forms -->
        <div class="col-md-8">
            <!-- Change Password -->
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0">
                        <i class="fas fa-lock text-danger"></i> Change Password
                    </h5>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="security-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <p class="text-muted">Keep your account secure by using a strong password</p>
                    </div>
                    
                    <form method="POST" id="passwordForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="current_password" class="form-label">Current Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="current_password" 
                                           name="current_password" required>
                                    <button class="btn btn-outline-secondary" type="button" 
                                            onclick="togglePassword('current_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="new_password" class="form-label">New Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="new_password" 
                                           name="new_password" required minlength="6"
                                           onkeyup="checkPasswordStrength()">
                                    <button class="btn btn-outline-secondary" type="button" 
                                            onclick="togglePassword('new_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength mt-2" id="password-strength"></div>
                                <small class="text-muted">Password must be at least 6 characters long</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="confirm_password" 
                                           name="confirm_password" required minlength="6">
                                    <button class="btn btn-outline-secondary" type="button" 
                                            onclick="togglePassword('confirm_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Account Information -->
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle text-secondary"></i> Account Information
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Registration Number:</strong><br>
                               <span class="text-muted"><?= htmlspecialchars($student['reg_number']) ?></span></p>
                            <p><strong>Email:</strong><br>
                               <span class="text-muted"><?= htmlspecialchars($student['email']) ?></span></p>
                            <p><strong>Faculty:</strong><br>
                               <span class="text-muted"><?= htmlspecialchars($student['faculty_name']) ?></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Department:</strong><br>
                               <span class="text-muted"><?= htmlspecialchars($student['department_name']) ?></span></p>
                            <p><strong>Level:</strong><br>
                               <span class="text-muted">Level <?= htmlspecialchars($student['level']) ?></span></p>
                            <p><strong>Phone:</strong><br>
                               <span class="text-muted"><?= $student['phone']  ?></span></p>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        To update your personal information, please visit the 
                        <a href="profile.php" class="alert-link">Profile page</a>.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Back to Dashboard Button -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="index.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</main>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling;
    const icon = button.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function checkPasswordStrength() {
    const password = document.getElementById('new_password').value;
    const strengthBar = document.getElementById('password-strength');
    
    let strength = 0;
    if (password.length >= 6) strength++;
    if (password.match(/[a-z]/)) strength++;
    if (password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    if (password.match(/[^a-zA-Z0-9]/)) strength++;
    
    strengthBar.className = 'password-strength mt-2';
    if (strength <= 2) {
        strengthBar.classList.add('strength-weak');
    } else if (strength <= 3) {
        strengthBar.classList.add('strength-medium');
    } else {
        strengthBar.classList.add('strength-strong');
    }
}

// Password confirmation validation
document.getElementById('passwordForm').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword !== confirmPassword) {
        e.preventDefault();
        alert('New password and confirmation do not match!');
        return false;
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>