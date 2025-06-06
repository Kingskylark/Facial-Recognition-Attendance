<?php

include_once 'constants.php';
/**
 * Session Management
 * University of Uyo Facial Attendance System
 */

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

class SessionManager {
    
    /**
     * Check if user is logged in
     * @return bool
     */
    public static function isLoggedIn() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
    }
    
    /**
     * Check if session is valid (not expired)
     * @return bool
     */
    public static function isValidSession() {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity'])) {
            $timeout = SESSION_TIMEOUT * 60; // Convert to seconds
            if (time() - $_SESSION['last_activity'] > $timeout) {
                self::destroy();
                return false;
            }
        }
        
        // Update last activity time
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    /**
     * Login user and create session
     * @param int $user_id
     * @param string $role
     * @param array $user_data
     */
    public static function login($user_id, $role, $user_data = []) {
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_role'] = $role;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        
        // Store additional user data
        if (!empty($user_data)) {
            $_SESSION['user_data'] = $user_data;
        }
        
        // Store user's IP address for security
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
    }
    
    /**
     * Logout user and destroy session
     */
    public static function logout() {
        self::destroy();
        header('Location: ' . BASE_URL . 'public/login.php');
        exit();
    }
    
    /**
     * Destroy session
     */
    public static function destroy() {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
    
    /**
     * Get current user ID
     * @return int|null
     */
    public static function getUserId() {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user role
     * @return string|null
     */
    public static function getUserRole() {
        return $_SESSION['user_role'] ?? null;
    }
    
    /**
     * Get user data
     * @param string $key
     * @return mixed
     */
    public static function getUserData($key = null) {
        if ($key === null) {
            return $_SESSION['user_data'] ?? [];
        }
        return $_SESSION['user_data'][$key] ?? null;
    }
    
    /**
     * Set user data
     * @param string $key
     * @param mixed $value
     */
    public static function setUserData($key, $value) {
        if (!isset($_SESSION['user_data'])) {
            $_SESSION['user_data'] = [];
        }
        $_SESSION['user_data'][$key] = $value;
    }
    /**
 * Get current user information
 * @return array|null Returns user data array or null if not logged in
 */
public static function getCurrentUser() {
    if (!self::isLoggedIn()) {
        return null;
    }
    
    return [
        'user_id' => self::getUserId(),
        'role' => self::getUserRole(),
        'login_time' => $_SESSION['login_time'] ?? null,
        'last_activity' => $_SESSION['last_activity'] ?? null,
        'user_ip' => $_SESSION['user_ip'] ?? null,
        'user_data' => self::getUserData()
    ];
}
    /**
     * Check if user has specific role
     * @param string $role
     * @return bool
     */
    public static function hasRole($role) {
        return self::getUserRole() === $role;
    }
    
    /**
     * Check if user has any of the specified roles
     * @param array $roles
     * @return bool
     */
    public static function hasAnyRole($roles) {
        $user_role = self::getUserRole();
        return in_array($user_role, $roles);
    }
    
    /**
     * Require login - redirect to login page if not logged in
     */
    public static function requireLogin() {
        if (!self::isValidSession()) {
            header('Location: ' . BASE_URL . 'public/login.php');
            exit();
        }
    }
    
    /**
     * Require specific role - redirect with error if user doesn't have role
     * @param string $required_role
     */
    public static function requireRole($required_role) {
        self::requireLogin();
        
        if (!self::hasRole($required_role)) {
            $_SESSION['error'] = 'Access denied. You do not have permission to access this page.';
            header('Location: ' . self::getDashboardUrl());
            exit();
        }
    }
    
    /**
     * Require any of the specified roles
     * @param array $required_roles
     */
    public static function requireAnyRole($required_roles) {
        self::requireLogin();
        
        if (!self::hasAnyRole($required_roles)) {
            $_SESSION['error'] = 'Access denied. You do not have permission to access this page.';
            header('Location: ' . self::getDashboardUrl());
            exit();
        }
    }
    
    /**
     * Get dashboard URL based on user role
     * @return string
     */
    public static function getDashboardUrl() {
        $role = self::getUserRole();
        switch ($role) {
            case ROLE_ADMIN:
                return BASE_URL . 'admin/';
            case ROLE_LECTURER:
                return BASE_URL . 'lecturer/';
            case ROLE_STUDENT:
                return BASE_URL . 'student/';
            default:
                return BASE_URL . 'public/';
        }
    }
    
    /**
     * Set flash message
     * @param string $message
     * @param string $type
     */
    public static function setFlashMessage($message, $type = MSG_INFO) {
        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_type'] = $type;
    }
    
    /**
     * Get and clear flash message
     * @return array|null
     */
    public static function getFlashMessage() {
        if (isset($_SESSION['flash_message'])) {
            $message = [
                'message' => $_SESSION['flash_message'],
                'type' => $_SESSION['flash_type'] ?? MSG_INFO
            ];
            unset($_SESSION['flash_message']);
            unset($_SESSION['flash_type']);
            return $message;
        }
        return null;
    }
    
    /**
     * Check if there's a flash message
     * @return bool
     */
    public static function hasFlashMessage() {
        return isset($_SESSION['flash_message']);
    }
    
    /**
     * Get session remaining time in seconds
     * @return int
     */
    public static function getSessionRemainingTime() {
        if (!self::isLoggedIn() || !isset($_SESSION['last_activity'])) {
            return 0;
        }
        
        $timeout = SESSION_TIMEOUT * 60;
        $elapsed = time() - $_SESSION['last_activity'];
        return max(0, $timeout - $elapsed);
    }
}

// Auto-check session validity on every page load
if (SessionManager::isLoggedIn() && !SessionManager::isValidSession()) {
    SessionManager::setFlashMessage('Your session has expired. Please login again.', MSG_WARNING);
}
?>