<?php
session_start();

// Get the user's role before destroying the session (for redirect purposes)
$role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? 'student';

// Clear all session variables
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to the appropriate login page
header("Location: ../public/login.php?role=" . $role . "&logout=success");
exit;
?>