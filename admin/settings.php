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
$message = '';
$error = '';

// Get current admin information
$stmt = $conn->prepare("SELECT * FROM admins WHERE id = :admin_id");
$stmt->execute(['admin_id' => $admin_id]);
$current_admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_admin) {
    header('Location: login.php?role=admin');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_admin':
                $username = trim($_POST['username']);
                $email = trim($_POST['email']);
                $password = trim($_POST['password']);
                $confirm_password = trim($_POST['confirm_password']);
                $full_name = trim($_POST['full_name']);
                
                // Validation
                if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
                    $error = "All fields are required.";
                } elseif ($password !== $confirm_password) {
                    $error = "Passwords do not match.";
                } elseif (strlen($password) < 6) {
                    $error = "Password must be at least 6 characters long.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email format.";
                } else {
                    // Check if username or email already exists
                    $check_stmt = $conn->prepare("SELECT id FROM admins WHERE username = :username OR email = :email");
                    $check_stmt->execute(['username' => $username, 'email' => $email]);
                    
                    if ($check_stmt->fetch()) {
                        $error = "Username or email already exists.";
                    } else {
                        // Insert new admin
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $insert_stmt = $conn->prepare("
                            INSERT INTO admins (username, email, password, full_name, created_at) 
                            VALUES (:username, :email, :password, :full_name, NOW())
                        ");
                        
                        if ($insert_stmt->execute([
                            'username' => $username,
                            'email' => $email,
                            'password' => $hashed_password,
                            'full_name' => $full_name
                        ])) {
                            $message = "New admin user created successfully!";
                        } else {
                            $error = "Failed to create admin user.";
                        }
                    }
                }
                break;
                
            case 'change_password':
                $current_password = trim($_POST['current_password']);
                $new_password = trim($_POST['new_password']);
                $confirm_new_password = trim($_POST['confirm_new_password']);
                
                // Validation
                if (empty($current_password) || empty($new_password) || empty($confirm_new_password)) {
                    $error = "All password fields are required.";
                } elseif ($new_password !== $confirm_new_password) {
                    $error = "New passwords do not match.";
                } elseif (strlen($new_password) < 6) {
                    $error = "New password must be at least 6 characters long.";
                } elseif (!password_verify($current_password, $current_admin['password'])) {
                    $error = "Current password is incorrect.";
                } else {
                    // Update password
                    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE admins SET password = :password WHERE id = :admin_id");
                    
                    if ($update_stmt->execute(['password' => $hashed_new_password, 'admin_id' => $admin_id])) {
                        $message = "Password changed successfully!";
                    } else {
                        $error = "Failed to change password.";
                    }
                }
                break;
                
            case 'update_profile':
                $full_name = trim($_POST['full_name']);
                $email = trim($_POST['email']);
                
                // Validation
                if (empty($full_name) || empty($email)) {
                    $error = "Full name and email are required.";
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Invalid email format.";
                } else {
                    // Check if email already exists for another admin
                    $check_stmt = $conn->prepare("SELECT id FROM admins WHERE email = :email AND id != :admin_id");
                    $check_stmt->execute(['email' => $email, 'admin_id' => $admin_id]);
                    
                    if ($check_stmt->fetch()) {
                        $error = "Email already exists for another admin.";
                    } else {
                        // Update profile
                        $update_stmt = $conn->prepare("UPDATE admins SET full_name = :full_name, email = :email WHERE id = :admin_id");
                        
                        if ($update_stmt->execute(['full_name' => $full_name, 'email' => $email, 'admin_id' => $admin_id])) {
                            $message = "Profile updated successfully!";
                            // Refresh current admin data
                            $stmt = $conn->prepare("SELECT * FROM admins WHERE id = :admin_id");
                            $stmt->execute(['admin_id' => $admin_id]);
                            $current_admin = $stmt->fetch(PDO::FETCH_ASSOC);
                        } else {
                            $error = "Failed to update profile.";
                        }
                    }
                }
                break;
                
            case 'delete_admin':
                $delete_admin_id = (int)$_POST['delete_admin_id'];
                
                // Prevent self-deletion
                if ($delete_admin_id === $admin_id) {
                    $error = "You cannot delete your own account.";
                } else {
                    $delete_stmt = $conn->prepare("DELETE FROM admins WHERE id = :admin_id");
                    
                    if ($delete_stmt->execute(['admin_id' => $delete_admin_id])) {
                        $message = "Admin user deleted successfully!";
                    } else {
                        $error = "Failed to delete admin user.";
                    }
                }
                break;
        }
    }
}

// Get all admin users
$stmt = $conn->prepare("SELECT id, username, email, full_name, created_at, last_login FROM admins ORDER BY created_at DESC");
$stmt->execute();
$all_admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<?php include_once '../includes/header.php'; ?>

<style>
.settings-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 20px;
    padding: 30px;
    margin-bottom: 30px;
}

.settings-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin-bottom: 30px;
    transition: transform 0.3s ease;
}

.settings-card:hover {
    transform: translateY(-2px);
}

.card-header-custom {
    background: linear-gradient(135deg,rgba(75, 81, 87, 0.64) 0%,rgba(181, 199, 218, 0.52) 100%);
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    border-radius: 15px 15px 0 0 !important;
}

.form-control, .form-select {
    border-radius: 10px;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn-custom {
    border-radius: 10px;
    padding: 10px 20px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.admin-list-item {
    border: 1px solid #e9ecef;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}

.admin-list-item:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
}

.admin-avatar {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    font-weight: bold;
}

.system-info-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
}

.alert-custom {
    border-radius: 10px;
    border: none;
}
</style>

<main class="container-fluid py-4">
    <!-- Settings Header -->
    <div class="settings-header">
        <div class="row align-items-center">
            <div class="col-md-2">
                <div class="admin-avatar mx-auto">
                    <i class="fas fa-cogs"></i>
                </div>
            </div>
            <div class="col-md-10 text-start">
                <h2 class="mb-1">System Settings</h2>
                <p class="mb-1">Manage admin users, change passwords, and system configuration</p>
                <p class="mb-0">Logged in as: <?= htmlspecialchars($current_admin['full_name']) ?></p>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-custom alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-custom alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-user-edit text-primary"></i> Update Profile</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   value="<?= htmlspecialchars($current_admin['full_name']) ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= htmlspecialchars($current_admin['email']) ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="username_display" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username_display" 
                                   value="<?= htmlspecialchars($current_admin['username']) ?>" disabled>
                            <div class="form-text">Username cannot be changed</div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-custom">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="col-lg-6 mb-4">
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-key text-warning"></i> Change Password</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" 
                                   name="current_password" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" 
                                   name="new_password" required minlength="6">
                            <div class="form-text">Password must be at least 6 characters long</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_new_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_new_password" 
                                   name="confirm_new_password" required minlength="6">
                        </div>
                        
                        <button type="submit" class="btn btn-warning btn-custom">
                            <i class="fas fa-lock"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Add New Admin -->
    <div class="row">
        <div class="col-lg-8 mb-4">
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-user-plus text-success"></i> Add New Admin User</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_admin">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="new_username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="new_username" name="username" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="new_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="new_email" name="email" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_full_name" class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="new_full_name" name="full_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="new_password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="new_password" 
                                       name="password" required minlength="6">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="new_confirm_password" class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" id="new_confirm_password" 
                                       name="confirm_password" required minlength="6">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success btn-custom">
                            <i class="fas fa-user-plus"></i> Create Admin User
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- System Information -->
        <div class="col-lg-4 mb-4">
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-info-circle text-info"></i> System Information</h5>
                </div>
                <div class="card-body">
                    <div class="system-info-card">
                        <div class="row text-center">
                            <div class="col-12 mb-3">
                                <i class="fas fa-server text-primary fa-2x mb-2"></i>
                                <h6>System Status</h6>
                                <span class="badge bg-success">Online</span>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="mb-2">
                            <strong>Server Time:</strong><br>
                            <small><?= date('Y-m-d H:i:s') ?></small>
                        </div>
                        
                        <div class="mb-2">
                            <strong>PHP Version:</strong><br>
                            <small><?= PHP_VERSION ?></small>
                        </div>
                        
                        <div class="mb-2">
                            <strong>Total Admins:</strong><br>
                            <small><?= count($all_admins) ?> registered</small>
                        </div>
                        
                        <div class="mb-2">
                            <strong>Your Last Login:</strong><br>
                            <small><?= $current_admin['last_login'] ? date('M j, Y g:i A', strtotime($current_admin['last_login'])) : 'First time login' ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Users List -->
    <div class="row">
        <div class="col-12">
            <div class="card settings-card">
                <div class="card-header card-header-custom">
                    <h5 class="mb-0"><i class="fas fa-users text-secondary"></i> All Admin Users</h5>
                </div>
                <div class="card-body">
                    <?php if ($all_admins): ?>
                        <?php foreach ($all_admins as $admin): ?>
                            <div class="admin-list-item">
                                <div class="row align-items-center">
                                    <div class="col-md-1">
                                        <div class="admin-avatar" style="width: 40px; height: 40px; font-size: 1rem;">
                                            <?= strtoupper(substr($admin['full_name'], 0, 1)) ?>
                                        </div>
                                    </div>
                                    <div class="col-md-7">
                                        <h6 class="mb-1"><?= htmlspecialchars($admin['full_name']) ?></h6>
                                        <p class="mb-1 text-muted">@<?= htmlspecialchars($admin['username']) ?></p>
                                        <small class="text-muted"><?= htmlspecialchars($admin['email']) ?></small>
                                    </div>
                                    <div class="col-md-2">
                                        <small class="text-muted">
                                            <strong>Created:</strong><br>
                                            <?= date('M j, Y', strtotime($admin['created_at'])) ?>
                                        </small>
                                    </div>
                                    <div class="col-md-2 text-end">
                                        <?php if ($admin['id'] !== $admin_id): ?>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this admin user?')">
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="delete_admin_id" value="<?= $admin['id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge bg-primary">You</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-users fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No admin users found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Back to Dashboard -->
    <div class="row mt-4">
        <div class="col-12 text-center">
            <a href="dashboard.php" class="btn btn-outline-primary btn-lg btn-custom">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</main>

<script>
// Password confirmation validation
document.getElementById('new_confirm_password').addEventListener('input', function() {
    const password = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    
    if (password !== confirmPassword) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

document.getElementById('confirm_new_password').addEventListener('input', function() {
    const password = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    
    if (password !== confirmPassword) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>