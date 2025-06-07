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

// Get current session year (you might need to adjust this logic)
$current_session = date('Y') . '/' . (date('Y') + 1);

// Handle form submissions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'register_student_course') {
    try {
        $course_id = $_POST['course_id'];
        $student_id = $_POST['student_id'];
        $session_year = $_POST['session_year'] ?? $current_session;
        $semester = $_POST['semester'];
        
        // First, get student details to check eligibility
        $student_stmt = $conn->prepare("
            SELECT s.*, d.id as department_id, d.faculty_id, s.level as student_level
            FROM students s 
            LEFT JOIN departments d ON s.department_id = d.id 
            WHERE s.id = :student_id
        ");
        $student_stmt->execute(['student_id' => $student_id]);
        $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$student) {
            throw new Exception('Student not found!');
        }

        // Get course details with department and faculty information
        $course_stmt = $conn->prepare("
            SELECT c.*, d.faculty_id as course_faculty_id, d.id as course_department_id 
            FROM courses c 
            LEFT JOIN departments d ON c.department_id = d.id 
            WHERE c.id = :course_id AND c.is_active = 1
        ");
        $course_stmt->execute(['course_id' => $course_id]);
        $course = $course_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            throw new Exception('Course not found or inactive!');
        }

        // Check if student is already registered for this course
        $existing_stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM student_courses 
            WHERE student_id = :student_id AND course_id = :course_id AND session_year = :session_year
        ");
        $existing_stmt->execute([
            'student_id' => $student_id,
            'course_id' => $course_id,
            'session_year' => $session_year
        ]);

        if ($existing_stmt->fetch()['count'] > 0) {
            throw new Exception('Student is already registered for this course in the selected session!');
        }

        // Check course eligibility rules
        $eligibility_stmt = $conn->prepare("
            SELECT * FROM course_eligibility 
            WHERE course_id = :course_id
        ");
        $eligibility_stmt->execute(['course_id' => $course_id]);
        $eligibility = $eligibility_stmt->fetch(PDO::FETCH_ASSOC);

        $eligible = false;
        $eligibility_message = '';

        if ($eligibility) {
            // ELIGIBILITY LOGIC BASED ON COURSE TYPE
            
    
// UPDATED ELIGIBILITY LOGIC FOR GENERAL COURSES
// Replace the existing general course logic in your code with this improved version

// 1. GENERAL COURSES - Anyone can take (regardless of department/faculty)
if ($eligibility['is_general'] == 1) {
    // Get the course level (this should be the level the course is designed for)
    $course_level = $eligibility['level'] ?? 1; // Default to level 1 if not specified
    $min_level = $eligibility['min_level'] ?? $course_level; // Minimum level to take the course
    $max_level = $eligibility['max_level'] ?? 8; // Maximum level allowed (typically final year)
    
    // For general courses, students can take them from the course level onwards
    // up to the maximum level specified
    if ($student['student_level'] >= $min_level && $student['student_level'] <= $max_level) {
        $eligible = true;
        
        if ($student['student_level'] == $course_level) {
            $eligibility_message = "General course - student is at the target level ({$course_level}).";
        } elseif ($student['student_level'] > $course_level) {
            $eligibility_message = "General course - student is eligible at higher level ({$student['student_level']} taking Level {$course_level} course).";
        } else {
            // This case shouldn't happen due to the condition above, but included for completeness
            $eligibility_message = "General course - student meets minimum level requirement.";
        }
    } else {
        $eligible = false;
        
        if ($student['student_level'] < $min_level) {
            $eligibility_message = "Student level ({$student['student_level']}) is below the minimum required level ({$min_level}) for this general course.";
        } else {
            $eligibility_message = "Student level ({$student['student_level']}) exceeds the maximum allowed level ({$max_level}) for this general course.";
        }
    }
}
            // 2. FACULTY COURSES - All students within the same faculty can take
            elseif ($eligibility['faculty_id'] && !$eligibility['department_id']) {
                // Course is restricted to faculty level (all departments in faculty)
                if ($student['faculty_id'] == $eligibility['faculty_id']) {
                    // Check level requirements
                    $min_level = $eligibility['min_level'] ?? 1;
                    $max_level = $eligibility['max_level'] ?? 8;
                    
                    if ($student['student_level'] >= $min_level && $student['student_level'] <= $max_level) {
                        $eligible = true;
                        $eligibility_message = 'Faculty course - accessible to all students in the faculty at appropriate level.';
                    } else {
                        $eligible = false;
                        $eligibility_message = "Student level ({$student['student_level']}) does not meet faculty course requirements (Level {$min_level} - {$max_level}).";
                    }
                } else {
                    $eligible = false;
                    $eligibility_message = 'Student faculty does not match course faculty requirements.';
                }
            }
            
            // 3. DEPARTMENTAL COURSES - Only students from specific department
            elseif ($eligibility['department_id']) {
                // Course is restricted to specific department
                if ($student['department_id'] == $eligibility['department_id']) {
                    // Check level requirements
                    $min_level = $eligibility['min_level'] ?? 1;
                    $max_level = $eligibility['max_level'] ?? 8;
                    
                    // For departmental courses, also check if it matches student's current level
                    if ($eligibility['level'] && $eligibility['level'] == $student['student_level']) {
                        // Exact level match for departmental course
                        $eligible = true;
                        $eligibility_message = 'Departmental course - matches student level exactly.';
                    } elseif (!$eligibility['level'] && $student['student_level'] >= $min_level && $student['student_level'] <= $max_level) {
                        // No specific level requirement, check range
                        $eligible = true;
                        $eligibility_message = 'Departmental course - student level within acceptable range.';
                    } elseif ($eligibility['is_carryover_allowed'] == 1 && $student['student_level'] <= $max_level) {
                        // Allow carryover students (who may be at higher level than course level)
                        $eligible = true;
                        $eligibility_message = 'Departmental course - eligible as carryover student.';
                    } else {
                        $eligible = false;
                        $eligibility_message = "Student level ({$student['student_level']}) does not match departmental course level requirements.";
                    }
                } else {
                    $eligible = false;
                    $eligibility_message = 'Student department does not match course department requirements.';
                }
            }
            
            // 4. FALLBACK - If no specific eligibility rules found
            else {
                // Default eligibility check based on course and student information
                if ($course['course_faculty_id'] == $student['faculty_id']) {
                    $eligible = true;
                    $eligibility_message = 'Default eligibility - same faculty.';
                } else {
                    $eligible = false;
                    $eligibility_message = 'No specific eligibility rules found and different faculty.';
                }
            }
            
        } else {
            // No eligibility rules found - default to faculty-based eligibility
            if ($course['course_faculty_id'] == $student['faculty_id']) {
                $eligible = true;
                $eligibility_message = 'No eligibility restrictions - same faculty access granted.';
            } else {
                $eligible = false;
                $eligibility_message = 'No eligibility rules defined and different faculty.';
            }
        }

        // Final eligibility check
        if (!$eligible) {
            throw new Exception('Student is not eligible for this course: ' . $eligibility_message);
        }

        // Register the student for the course
        $register_stmt = $conn->prepare("
            INSERT INTO student_courses (student_id, course_id, session_year, semester, created_at) 
            VALUES (:student_id, :course_id, :session_year, :semester, NOW())
        ");

        $register_stmt->execute([
            'student_id' => $student_id,
            'course_id' => $course_id,
            'session_year' => $session_year,
            'semester' => $semester
        ]);

        $message = 'Student successfully registered for the course! ' . $eligibility_message;

        try {
            $audit_stmt->execute([
                'student_id' => $student_id,
                'course_id' => $course_id,
                'session_year' => $session_year,
                'eligibility_reason' => $eligibility_message
            ]);
        } catch (PDOException $e) {
            // Audit logging failed, but don't break the registration process
            error_log("Audit logging failed: " . $e->getMessage());
        }
        
    } catch (PDOException $e) {
        $error = 'Database error during registration: ' . $e->getMessage();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch students for the dropdown
try {
    $students_stmt = $conn->prepare("
        SELECT s.*, d.name as department_name, d.code as department_code, f.name as faculty_name
        FROM students s 
        LEFT JOIN departments d ON s.department_id = d.id 
        LEFT JOIN faculties f ON d.faculty_id = f.id
        WHERE s.is_active = 1
        ORDER BY s.firstname, s.surname
    ");
    $students_stmt->execute();
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
    $error = 'Error fetching students: ' . $e->getMessage();
}

// Get course information if passed via GET parameters
$course_name = $_GET['course_name'] ?? '';
$course_id = $_GET['course_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Student for Course</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-user-graduate"></i> Register Student for Course
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="registerStudentForm">
                            <input type="hidden" name="action" value="register_student_course">
                            <input type="hidden" name="course_id" value="<?= htmlspecialchars($course_id) ?>">
                            
                            <?php if ($course_name): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Course:</label>
                                    <p class="text-muted mb-3"><?= htmlspecialchars($course_name) ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label for="student_search" class="form-label">Search Students</label>
                                <input type="text" id="student_search" class="form-control mb-2" placeholder="Search students...">
                            </div>
                            
                            <div class="mb-3">
                                <label for="student_id" class="form-label">Select Student</label>
                                <select class="form-select" name="student_id" id="student_id" required>
                                    <option value="">Choose a student...</option>
                                    <?php foreach ($students as $student): ?>
                                        <option value="<?= $student['id'] ?>" 
                                                data-level="<?= $student['level'] ?>"
                                                data-department="<?= htmlspecialchars($student['department_name']) ?>">
                                            <?= htmlspecialchars($student['id'] . ' - ' . $student['firstname'] . ' ' . $student['surname']) ?>
                                            (Level <?= $student['level'] ?>, <?= htmlspecialchars($student['department_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="session_year" class="form-label">Session Year</label>
                                    <input type="text" class="form-control" name="session_year" 
                                           id="session_year" value="<?= $current_session ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="semester" class="form-label">Semester</label>
                                    <select class="form-select" name="semester" id="semester" required>
                                        <option value="">Select Semester</option>
                                        <option value="1">First Semester</option>
                                        <option value="2">Second Semester</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <div id="eligibility_info" class="alert alert-info" style="display: none;">
                                    <strong>Student Information:</strong>
                                    <div id="eligibility_details"></div>
                                </div>
                            </div>
                            
                            <div class="mt-4 d-flex gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-user-plus"></i> Register Student
                                </button>
                                <a href="courses.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left"></i> Back
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const studentSelect = document.getElementById('student_id');
            const searchInput = document.getElementById('student_search');
            const eligibilityInfo = document.getElementById('eligibility_info');
            const eligibilityDetails = document.getElementById('eligibility_details');
            
            // Store original options
            let originalOptions = Array.from(studentSelect.options).slice(1); // Skip first empty option
            
            // Search functionality
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                
                // Clear current options (except first)
                studentSelect.innerHTML = '<option value="">Choose a student...</option>';
                
                // Filter and add matching options
                originalOptions
                    .filter(option => option.textContent.toLowerCase().includes(searchTerm))
                    .forEach(option => studentSelect.appendChild(option.cloneNode(true)));
            });
            
            // Show student info when selected
            studentSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                
                if (selectedOption.value) {
                    const level = selectedOption.dataset.level;
                    const department = selectedOption.dataset.department;
                    
                    eligibilityDetails.innerHTML = 
                        `<strong>Student Level:</strong> ${level}<br>
                         <strong>Department:</strong> ${department}`;
                    
                    eligibilityInfo.style.display = 'block';
                } else {
                    eligibilityInfo.style.display = 'none';
                }
            });
            
            // Form validation
            const registerForm = document.getElementById('registerStudentForm');
            registerForm.addEventListener('submit', function(e) {
                const studentId = studentSelect.value;
                const semester = document.getElementById('semester').value;
                
                if (!studentId) {
                    e.preventDefault();
                    alert('Please select a student');
                    return false;
                }
                
                if (!semester) {
                    e.preventDefault();
                    alert('Please select a semester');
                    return false;
                }
                
                return confirm('Are you sure you want to register this student for the course?');
            });
        });
    </script>
</body>
</html>