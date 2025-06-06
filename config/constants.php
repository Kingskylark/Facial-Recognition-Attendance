<?php
/**
 * System Constants and Settings
 * University of Uyo Facial Attendance System
 */

// Prevent direct access
if (!defined('FACIAL_ATTENDANCE_SYSTEM')) {
    define('FACIAL_ATTENDANCE_SYSTEM', true);
}

// System Information
define('SYSTEM_NAME', 'University of Uyo Facial Attendance System');
define('SYSTEM_VERSION', '1.0.0');
define('UNIVERSITY_NAME', 'University of Uyo');
define('UNIVERSITY_SHORT', 'UNIUYO');

// File and Directory Paths
define('ROOT_PATH', dirname(dirname(__FILE__)));
define('UPLOAD_PATH', ROOT_PATH . '/assets/uploads/');
define('STUDENT_UPLOAD_PATH', UPLOAD_PATH . 'students/');
define('LECTURER_UPLOAD_PATH', UPLOAD_PATH . 'lecturers/');
define('TEMP_UPLOAD_PATH', UPLOAD_PATH . 'temp/');

// URL Paths
define('BASE_URL', 'http://localhost/facial_attendance_system/');
define('ASSETS_URL', BASE_URL . 'assets/');
define('UPLOAD_URL', ASSETS_URL . 'uploads/');

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_LECTURER', 'lecturer');
define('ROLE_STUDENT', 'student');

// Session Configuration
define('SESSION_TIMEOUT', 30); // minutes
define('SESSION_NAME', 'uniuyo_attendance_session');

// File Upload Settings
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('MAX_IMAGE_WIDTH', 800);
define('MAX_IMAGE_HEIGHT', 600);

// Face Recognition Settings
define('FACE_RECOGNITION_THRESHOLD', 0.6);
define('PYTHON_SCRIPT_PATH', ROOT_PATH . '/python/');

// Attendance Settings
define('ATTENDANCE_WINDOW_MINUTES', 15); // Minutes after class start when attendance is allowed
define('LATE_ATTENDANCE_MINUTES', 10); // Minutes after start time to mark as late

// Database Settings
define('DB_HOST', 'localhost');
define('DB_NAME', 'facial_attendance');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Security Settings
define('PASSWORD_MIN_LENGTH', 8);
define('LOGIN_ATTEMPTS_LIMIT', 5);
define('LOGIN_LOCKOUT_TIME', 15); // minutes

// Email Settings (if needed for notifications)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');

// Academic Settings
define('CURRENT_SESSION', '2024/2025');
define('CURRENT_SEMESTER', 'second');

// University Colors (for styling)
define('PRIMARY_COLOR', '#dc3545'); // Red
define('SECONDARY_COLOR', '#28a745'); // Green
define('ACCENT_COLOR', '#ffffff'); // White

// Status Messages
define('MSG_SUCCESS', 'success');
define('MSG_ERROR', 'danger');
define('MSG_WARNING', 'warning');
define('MSG_INFO', 'info');

// Pagination
define('RECORDS_PER_PAGE', 20);

// Time Format
define('TIME_FORMAT', 'H:i');
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'd M Y');
define('DISPLAY_DATETIME_FORMAT', 'd M Y H:i');

// Error Codes
define('ERROR_INVALID_LOGIN', 1001);
define('ERROR_SESSION_EXPIRED', 1002);
define('ERROR_ACCESS_DENIED', 1003);
define('ERROR_FILE_UPLOAD', 1004);
define('ERROR_FACE_RECOGNITION', 1005);
define('ERROR_DATABASE', 1006);

// Success Codes
define('SUCCESS_LOGIN', 2001);
define('SUCCESS_REGISTRATION', 2002);
define('SUCCESS_ATTENDANCE_MARKED', 2003);
define('SUCCESS_PROFILE_UPDATED', 2004);

// Create upload directories if they don't exist
if (!file_exists(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}
if (!file_exists(STUDENT_UPLOAD_PATH)) {
    mkdir(STUDENT_UPLOAD_PATH, 0755, true);
}
if (!file_exists(LECTURER_UPLOAD_PATH)) {
    mkdir(LECTURER_UPLOAD_PATH, 0755, true);
}
if (!file_exists(TEMP_UPLOAD_PATH)) {
    mkdir(TEMP_UPLOAD_PATH, 0755, true);
}

// Timezone setting
date_default_timezone_set('Africa/Lagos');
?>