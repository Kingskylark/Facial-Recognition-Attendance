<?php
session_start();
require_once '../config/database.php';

$message = '';
$error = '';
$step = isset($_POST['step']) ? $_POST['step'] : 'select_role';
$role = isset($_POST['role']) ? $_POST['role'] : (isset($_GET['role']) ? $_GET['role'] : '');


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['select_role'])) {
        $role = $_POST['role'];
        $step = 'verify_identity';
    } 
    elseif (isset($_POST['verify_identity'])) {
        $role = $_POST['role'];
        $email = trim($_POST['email']);
        
        try {
            $db = new Database();
            $conn = $db->getConnection();
            $table = $role . 's';
            
            if ($role === 'student') {
                $reg_number = trim($_POST['reg_number']);
                $phone = trim($_POST['phone']);
                
                $sql = "SELECT id, email, surname, firstname FROM $table WHERE email = :email AND reg_number = :reg_number AND phone = :phone LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    'email' => $email,
                    'reg_number' => $reg_number,
                    'phone' => $phone
                ]);
            } else { // lecturer
                $staff_id = trim($_POST['staff_id']);
                
                $sql = "SELECT id, email, surname, firstname FROM $table WHERE email = :email AND staff_id = :staff_id LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    'email' => $email,
                    'staff_id' => $staff_id
                ]);
            }
            
            $user = $stmt->fetch();
            
            if ($user) {
                // Store user info in session for password reset
                $_SESSION['reset_user'] = [
                    'id' => $user['id'],
                    'role' => $role,
                    'email' => $user['email'],
                    'name' => $user['surname'] . ' ' . $user['firstname']
                ];
                $step = 'reset_password';
            } else {
                $error = "The provided information doesn't match our records. Please check and try again.";
                $step = 'verify_identity';
            }
            
        } catch (Exception $e) {
            $error = "An error occurred. Please try again later.";
            $step = 'verify_identity';
        }
    }
    elseif (isset($_POST['reset_password'])) {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validate passwords
        if (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters long.";
            $step = 'reset_password';
        } elseif ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
            $step = 'reset_password';
        } else {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                $user = $_SESSION['reset_user'];
                
                // Hash the new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update user password
                $table = $user['role'] . 's';
                $sql = "UPDATE $table SET password = :password WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    'password' => $hashed_password,
                    'id' => $user['id']
                ]);
                
                // Clear session
                unset($_SESSION['reset_user']);
                
                $message = "Password reset successfully! You can now login with your new password.";
                $step = 'success';
                
            } catch (Exception $e) {
                $error = "An error occurred while resetting the password. Please try again.";
                $step = 'reset_password';
            }
        }
    }
}

// Check if we have reset user in session
if ($step === 'reset_password' && !isset($_SESSION['reset_user'])) {
    $step = 'select_role';
    $error = "Session expired. Please start over.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px 15px 0 0 !important;
            color: white;
            text-align: center;
            padding: 2rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
        }
        .btn-outline-secondary {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .alert {
            border-radius: 10px;
        }
        .role-card {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .role-card:hover, .role-card.selected {
            border-color: #667eea;
            background-color: #f8f9ff;
            transform: translateY(-2px);
        }
        .role-card i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h3 class="mb-0">
                            <i class="fas fa-key me-2"></i>
                            Reset Password
                        </h3>
                        <p class="mb-0 mt-2 opacity-75">Recover your account access</p>
                    </div>
                    <div class="card-body p-4">
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo $error; ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($message): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo $message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Step 1: Select Role -->
                        <?php if ($step === 'select_role'): ?>
                            <form method="POST">
                                <input type="hidden" name="step" value="select_role">
                                <div class="mb-4">
                                    <h5 class="text-center mb-4">Select Your Role</h5>
                                    <div class="row">
                                        <div class="col-6">
                                            <label class="role-card" for="student-role">
                                                <input type="radio" name="role" value="student" id="student-role" class="d-none" required>
                                                <i class="fas fa-user-graduate"></i>
                                                <h6>Student</h6>
                                            </label>
                                        </div>
                                        <div class="col-6">
                                            <label class="role-card" for="lecturer-role">
                                                <input type="radio" name="role" value="lecturer" id="lecturer-role" class="d-none" required>
                                                <i class="fas fa-chalkboard-teacher"></i>
                                                <h6>Lecturer</h6>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <button type="submit" name="select_role" class="btn btn-primary w-100">
                                    <i class="fas fa-arrow-right me-2"></i>Continue
                                </button>
                            </form>
                        
                        <!-- Step 2: Verify Identity -->
                        <?php elseif ($step === 'verify_identity'): ?>
                            <form method="POST">
                                <input type="hidden" name="step" value="verify_identity">
                                <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">
                                
                                <div class="text-center mb-4">
                                    <i class="fas fa-<?php echo $role === 'student' ? 'user-graduate' : 'chalkboard-teacher'; ?> text-primary" style="font-size: 2rem;"></i>
                                    <h5 class="mt-2"><?php echo ucfirst($role); ?> Identity Verification</h5>
                                    <p class="text-muted">Please provide the following information to verify your identity</p>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="fas fa-envelope me-2"></i>Email Address
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" required>
                                </div>
                                
                                <?php if ($role === 'student'): ?>
                                    <div class="mb-3">
                                        <label for="reg_number" class="form-label">
                                            <i class="fas fa-id-card me-2"></i>Registration Number
                                        </label>
                                        <input type="text" class="form-control" id="reg_number" name="reg_number" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">
                                            <i class="fas fa-phone me-2"></i>Phone Number
                                        </label>
                                        <input type="tel" class="form-control" id="phone" name="phone" required>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-3">
                                        <label for="staff_id" class="form-label">
                                            <i class="fas fa-id-badge me-2"></i>Staff ID
                                        </label>
                                        <input type="text" class="form-control" id="staff_id" name="staff_id" required>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" name="verify_identity" class="btn btn-primary">
                                        <i class="fas fa-shield-alt me-2"></i>Verify Identity
                                    </button>
                                    <a href="forgot-password.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </a>
                                </div>
                            </form>
                        
                        <!-- Step 3: Reset Password -->
                        <?php elseif ($step === 'reset_password' && isset($_SESSION['reset_user'])): ?>
                            <div class="text-center mb-4">
                                <i class="fas fa-user-circle text-primary" style="font-size: 3rem;"></i>
                                <h5 class="mt-2"><?php echo htmlspecialchars($_SESSION['reset_user']['name']); ?></h5>
                                <p class="text-muted"><?php echo htmlspecialchars($_SESSION['reset_user']['email']); ?></p>
                                <small class="text-muted">
                                    <i class="fas fa-user-tag me-1"></i>
                                    <?php echo ucfirst($_SESSION['reset_user']['role']); ?>
                                </small>
                            </div>
                            
                            <form method="POST">
                                <input type="hidden" name="step" value="reset_password">
                                
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">
                                        <i class="fas fa-key me-2"></i>New Password
                                    </label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                                    <small class="text-muted">Password must be at least 6 characters long</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">
                                        <i class="fas fa-check-double me-2"></i>Confirm New Password
                                    </label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                                </div>
                                
                                <button type="submit" name="reset_password" class="btn btn-primary w-100">
                                    <i class="fas fa-save me-2"></i>Reset Password
                                </button>
                            </form>
                        
                        <!-- Step 4: Success -->
                        <?php elseif ($step === 'success'): ?>
                            <div class="text-center">
                                <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">Password Reset Successful!</h5>
                                <p class="text-muted">Your password has been updated successfully. You can now login with your new password.</p>
                                
                                <div class="mt-4">
                                    <a href="login.php" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i>Go to Login
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <a href="login.php" class="text-decoration-none">
                                <i class="fas fa-arrow-left me-2"></i>Back to Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle role selection
        document.querySelectorAll('input[name="role"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.role-card').forEach(card => {
                    card.classList.remove('selected');
                });
                this.closest('.role-card').classList.add('selected');
            });
        });
        
        // Add click handler for role cards
        document.querySelectorAll('.role-card').forEach(card => {
            card.addEventListener('click', function() {
                const radio = this.querySelector('input[type="radio"]');
                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change'));
                }
            });
        });
        
        // Password confirmation validation
        document.getElementById('confirm_password')?.addEventListener('input', function() {
            const password = document.getElementById('new_password').value;
            const confirm = this.value;
            
            if (confirm && password !== confirm) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>