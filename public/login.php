<?php
session_start();
require_once '../config/database.php';

// Initialize variables
$error = '';
$role = $_GET['role'] ?? '';
$valid_roles = ['student', 'lecturer', 'admin'];

// Redirect to role selection if role is not valid
if (!in_array($role, $valid_roles)) {
    header("Location: index.php");
    exit;
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $db = new Database();
    $conn = $db->getConnection();

    $table = $role . 's'; // students, lecturers, admins

    $sql = "SELECT * FROM $table WHERE email = :email LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'role' => $role,
            'name' => $role === 'admin' ? $user['username'] : $user['surname'] . ' ' . $user['firstname'],
            'email' => $user['email']
        ];

        // Set role-specific session variables for compatibility
        $_SESSION['role'] = $role;
        
        if ($role === 'student') {
            $_SESSION['student_id'] = $user['id'];
        } elseif ($role === 'lecturer') {
            $_SESSION['lecturer_id'] = $user['id'];
        } elseif ($role === 'admin') {
            $_SESSION['admin_id'] = $user['id'];
        }

        $conn->prepare("UPDATE $table SET last_login = NOW() WHERE id = :id")
            ->execute(['id' => $user['id']]);

        header("Location: ../$role/index.php");
        exit;

    } else {
        $error = "Invalid email or password.";
    }
}
?>

<?php include_once '../includes/header.php'; ?>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg border-0 rounded-4 p-4">
                <h3 class="text-center mb-3 text-capitalize"><?= ucfirst($role) ?> Login</h3>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>

                <form method="POST">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" name="email" id="email" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password" class="form-control" required>
                    </div>

                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-success">Login</button>
                    </div>
                </form>

                <div class="text-center small">
                    <a href="forgot-password.php?role=<?= $role ?>" class="d-block mb-2">Forgot password?</a>

                    <?php if ($role === 'student'): ?>
                        <span>Don't have an account?</span>
                        <a href="register-student.php"> Register as Student</a>
                    <?php elseif ($role === 'lecturer'): ?>
                        <span>Don't have an account?</span>
                        <a href="register-lecturer.php"> Register as Lecturer</a>
                    <?php endif; ?>
                </div>

                <div class="mt-4 text-center">
                    <a href="index.php" class="text-decoration-none">&larr; Back to Role Selection</a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include_once '../includes/footer.php'; ?>