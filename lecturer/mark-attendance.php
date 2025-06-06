<?php
session_start();
require_once '../config/database.php';
require_once '../includes/FaceRecognitionManager.php';

$db = new Database();
$conn = $db->getConnection();

$lecturer_id = $_SESSION['lecturer_id'];
$message = '';
$message_type = '';
$errors = [];
$success = '';
$debug_info = [];
$recognition_result = null;
// Enable debugging (set to false in production)
$debug_mode = true;

// Get lecturer information
$stmt = $conn->prepare("
    SELECT l.*, f.name as faculty_name, d.name as department_name 
    FROM lecturers l 
    JOIN faculties f ON l.faculty_id = f.id 
    JOIN departments d ON l.department_id = d.id 
    WHERE l.id = :lecturer_id
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

// Define current academic session and semester
$current_session = '2024/2025';
$current_semester = 'first';

// Get lecturer's courses for current session/semester
$stmt = $conn->prepare("
    SELECT 
        c.id,
        c.name as course_name,
        c.code as course_code,
        c.credit_units,
        COUNT(sc.student_id) as enrolled_students
    FROM lecturer_courses lc
    JOIN courses c ON lc.course_id = c.id
    LEFT JOIN student_courses sc ON c.id = sc.course_id
    WHERE lc.lecturer_id = :lecturer_id 
    AND lc.session_year = :session 
    AND lc.semester = :semester
    GROUP BY c.id, c.name, c.code, c.credit_units
    ORDER BY c.code
");
$stmt->execute([
    'lecturer_id' => $lecturer_id,
    'session' => $current_session,
    'semester' => $current_semester
]);
$lecturer_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle starting new attendance session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_session') {
    $course_id = $_POST['course_id'] ?? '';
    $session_type = $_POST['session_type'] ?? '';
    $location = $_POST['location'] ?? '';
    $session_date = $_POST['session_date'] ?? date('Y-m-d');
    $start_time = $_POST['start_time'] ?? date('H:i');

    if (empty($course_id) || empty($session_type)) {
        $errors[] = "Please select a course and session type.";
    } else {
        // Check if there's already an active session for this course today
        $check_session = $conn->prepare("
            SELECT id FROM attendance_sessions 
            WHERE lecturer_id = ? AND course_id = ? AND session_date = ? AND status = 'active'
        ");
        $check_session->execute([$lecturer_id, $course_id, $session_date]);

        if ($check_session->fetch()) {
            $errors[] = "There is already an active attendance session for this course today.";
        } else {
            // Create new attendance session
            $create_session = $conn->prepare("
                INSERT INTO attendance_sessions 
                (lecturer_id, course_id, session_date, start_time, session_type, location, status, session_year, semester) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)
            ");

            try {
                $create_session->execute([
                    $lecturer_id,
                    $course_id,
                    $session_date,
                    $start_time,
                    $session_type,
                    $location,
                    $current_session,
                    $current_semester
                ]);
                $success = "Attendance session started successfully!";

                if ($debug_mode) {
                    $debug_info[] = "New attendance session created with ID: " . $conn->lastInsertId();
                }
            } catch (PDOException $e) {
                $errors[] = "Failed to start attendance session. Please try again.";
                if ($debug_mode) {
                    $debug_info[] = "Session creation error: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle ending attendance session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'end_session') {
    $session_id = $_POST['session_id'] ?? '';

    if (!empty($session_id)) {
        $end_session = $conn->prepare("
            UPDATE attendance_sessions 
            SET status = 'completed', end_time = ? 
            WHERE id = ? AND lecturer_id = ? AND status = 'active'
        ");

        try {
            $end_session->execute([date('H:i:s'), $session_id, $lecturer_id]);
            $success = "Attendance session ended successfully!";
        } catch (PDOException $e) {
            $errors[] = "Failed to end attendance session.";
        }
    }
}

// Get active attendance session for selected course
$active_session = null;
if (!empty($_POST['course_id']) || !empty($_GET['course_id'])) {
    $selected_course_id = $_POST['course_id'] ?? $_GET['course_id'];

    $session_stmt = $conn->prepare("
        SELECT s.*, c.code as course_code, c.name as course_name
        FROM attendance_sessions s
        JOIN courses c ON s.course_id = c.id
        WHERE s.lecturer_id = ? AND s.course_id = ? AND s.session_date = CURDATE() AND s.status = 'active'
        ORDER BY s.created_at DESC
        LIMIT 1
    ");
    $session_stmt->execute([$lecturer_id, $selected_course_id]);
    $active_session = $session_stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle face recognition for attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recognize_student') {
    $course_id = $_POST['course_id'] ?? '';
    $captured_image = $_POST['captured_image'] ?? '';

    // Basic validation
    if (empty($course_id)) {
        $errors[] = "Please select a course.";
    }

    if (empty($captured_image)) {
        $errors[] = "Please capture a student's photograph.";
    }

    // Check if there's an active session
    if (!empty($course_id)) {
        $session_check = $conn->prepare("
            SELECT id FROM attendance_sessions 
            WHERE lecturer_id = ? AND course_id = ? AND session_date = CURDATE() AND status = 'active'
        ");
        $session_check->execute([$lecturer_id, $course_id]);
        $active_session_check = $session_check->fetch();

        if (!$active_session_check) {
            $errors[] = "No active attendance session found. Please start a session first.";
        }
    }

    // Verify course belongs to lecturer
    if (!empty($course_id)) {
        $course_check = $conn->prepare("
            SELECT lc.id, lc.course_id, c.code, c.name 
            FROM lecturer_courses lc 
            JOIN courses c ON lc.course_id = c.id 
            WHERE lc.course_id = ? AND lc.lecturer_id = ?
        ");
        $course_check->execute([$course_id, $lecturer_id]);
        $selected_course = $course_check->fetch();

        if (!$selected_course) {
            $errors[] = "Invalid course selected.";
        }
    }

    // Process image and recognize student if image is provided
    if ($captured_image && strpos($captured_image, 'data:image') === 0 && empty($errors)) {
        $temp_dir = '../temp/';

        // Create temporary directory if it doesn't exist
        if (!is_dir($temp_dir)) {
            if (!mkdir($temp_dir, 0755, true)) {
                $errors[] = "Failed to create temporary directory. Please contact administrator.";
            }
        }

        // Verify directory exists and is writable
        if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
            $errors[] = "Temporary directory is not accessible. Please contact administrator.";
        }

        if (empty($errors)) {
            // Create temporary file for recognition processing
            $temp_filename = 'recognition_' . uniqid() . '_' . time() . '.png';
            $temp_img_path = $temp_dir . $temp_filename;
            $image_base64 = explode(',', $captured_image)[1];

            if ($debug_mode) {
                $debug_info[] = "Processing recognition for course: " . $selected_course['course_id'];
                $debug_info[] = "Temporary file: " . $temp_filename;
            }

            // Validate base64 data
            if (empty($image_base64) || !base64_decode($image_base64, true)) {
                $errors[] = "Invalid image data received. Please retake the photo.";
            } else {
                // Save the temporary image file
                if (file_put_contents($temp_img_path, base64_decode($image_base64)) !== false) {
                    // Verify the file was actually created and has content
                    if (!file_exists($temp_img_path) || filesize($temp_img_path) == 0) {
                        $errors[] = "Image file was not properly saved. Please try again.";
                        if (file_exists($temp_img_path)) {
                            unlink($temp_img_path);
                        }
                    } else {
                        if ($debug_mode) {
                            $debug_info[] = "Temporary image saved: " . filesize($temp_img_path) . " bytes";
                        }

                        // Initialize face recognition manager for student recognition
                        try {
                            $faceManager = new FaceRecognitionManager($conn);

                            // Recognize student from captured image
                            $recognitionResult = $faceManager->recognizeStudentForAttendance($temp_img_path, 'sc.student_id');

                            if ($recognitionResult['success']) {
                                $recognition_result = $recognitionResult;
                                $success = "Student recognized successfully!";

                                if ($debug_mode) {
                                    $debug_info[] = "Student found: " . $recognition_result['matched_student']['reg_number'] . " - " . $recognition_result['matched_student']['full_name'];
                                    $debug_info[] = "Match confidence: " . $recognition_result['confidence'];
                                }
                            } else {
                                $errors[] = "Student recognition failed: " . ($recognitionResult['message'] ?? 'No matching student found');

                                if ($debug_mode) {
                                    $debug_info[] = "Recognition failed: " . ($recognitionResult['message'] ?? 'Unknown error');
                                }
                            }

                        } catch (Exception $e) {
                            $error_message = "Face recognition system error: " . $e->getMessage();
                            $errors[] = $error_message;

                            if ($debug_mode) {
                                $debug_info[] = "Recognition exception: " . $error_message;
                                $debug_info[] = "Exception trace: " . $e->getTraceAsString();
                            }
                        }

                        // Clean up temporary file
                        if (file_exists($temp_img_path)) {
                            unlink($temp_img_path);
                            if ($debug_mode) {
                                $debug_info[] = "Temporary file cleaned up";
                            }
                        }
                    }
                } else {
                    $errors[] = "Failed to save temporary image file. Please try again.";
                    if ($debug_mode) {
                        $debug_info[] = "file_put_contents failed for: " . $temp_img_path;
                    }
                }
            }
        }
    }
}
// Handle marking attendance (add this new block after the face recognition block)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_attendance') {
    $student_id = $_POST['student_id'] ?? '';
    $course_id = $_POST['course_id'] ?? '';
    $session_id = $_POST['session_id'] ?? '';
    $attendance_date = $_POST['attendance_date'] ?? '';
    $confidence = $_POST['confidence'] ?? 0;

    // First, check if student is registered for this course
    $registration_check = $conn->prepare("
        SELECT sc.id 
        FROM student_courses sc 
        WHERE sc.student_id = ? AND sc.course_id = ? 
        AND sc.session_year = ? AND sc.semester = ?
    ");
    $registration_check->execute([$student_id, $course_id, $current_session, $current_semester]);
    $is_registered = $registration_check->fetch();

    if (!$is_registered) {
        $errors[] = "Student is not registered for this course. Please register first before marking attendance.";
        if ($debug_mode) {
            $debug_info[] = "Registration check failed for student ID: $student_id, course ID: $course_id";
        }
    } else {
        // Check if attendance already marked for today
        $attendance_check = $conn->prepare("
            SELECT id FROM attendance 
            WHERE student_id = ? AND attendance_session_id = ? AND attendance_date = ?
        ");
        $attendance_check->execute([$student_id, $session_id, $attendance_date]);
        
        if ($attendance_check->fetch()) {
            $errors[] = "Attendance already marked for this student in this session.";
        } else {
            // Mark attendance
            $mark_attendance = $conn->prepare("
                INSERT INTO attendance 
                (student_id, attendance_session_id, course_id, attendance_date, status, recognition_confidence, marked_at) 
                VALUES (?, ?, ?, ?, 'present', ?, NOW())
            ");
            
            try {
                $mark_attendance->execute([$student_id, $session_id, $course_id, $attendance_date, $confidence]);
                $success = "Attendance marked successfully!";
                $recognition_result = null; // Clear the recognition result
                
                if ($debug_mode) {
                    $debug_info[] = "Attendance marked for student ID: $student_id";
                }
            } catch (PDOException $e) {
                $errors[] = "Failed to mark attendance. Please try again.";
                if ($debug_mode) {
                    $debug_info[] = "Attendance marking error: " . $e->getMessage();
                }
            }
        }
    }
}

include_once '../includes/header.php';
?>

<main class="container py-5">
    <div class="row">
        <div class="col-lg-8">
            <!-- Session Management Card -->
            <div class="card shadow mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> Attendance Session Management</h5>
                </div>
                <div class="card-body">
                    <?php if (!$active_session): ?>
                        <!-- Start Session Form -->
                        <form method="POST" class="row g-3">
                            <input type="hidden" name="action" value="start_session">

                            <div class="col-md-6">
                                <label class="form-label">Course <span class="text-danger">*</span></label>
                                <select name="course_id" class="form-select" required>
                                    <option value="">Select Course...</option>
                                    <?php foreach ($lecturer_courses as $course): ?>
                                        <option value="<?= $course['id'] ?>">
                                            <?= htmlspecialchars($course['course_code']) ?> -
                                            <?= htmlspecialchars($course['course_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Session Type <span class="text-danger">*</span></label>
                                <select name="session_type" class="form-select" required>
                                    <option value="">Select Type...</option>
                                    <option value="lecture">Lecture</option>
                                    <option value="tutorial">Tutorial</option>
                                    <option value="practical">Practical</option>
                                    <option value="seminar">Seminar</option>
                                    <option value="exam">Exam</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Session Date</label>
                                <input type="date" name="session_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Start Time</label>
                                <input type="time" name="start_time" class="form-control" value="<?= date('H:i') ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control"
                                    placeholder="e.g., Lecture Hall A, Lab B, etc.">
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-play-circle"></i> Start Attendance Session
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Active Session Info -->
                        <div class="" id="activeSessionAlert">
                            <h6><i class="bi bi-check-circle"></i> Active Attendance Session</h6>
                            <p class="mb-2">
                                <strong>Course:</strong> <?= htmlspecialchars($active_session['course_code']) ?> -
                                <?= htmlspecialchars($active_session['course_name']) ?><br>
                                <strong>Type:</strong> <?= ucfirst($active_session['session_type']) ?><br>
                                <strong>Date:</strong> <?= date('F j, Y', strtotime($active_session['session_date'])) ?><br>
                                <strong>Started:</strong> <?= date('g:i A', strtotime($active_session['start_time'])) ?><br>
                                <?php if ($active_session['location']): ?>
                                    <strong>Location:</strong> <?= htmlspecialchars($active_session['location']) ?><br>
                                <?php endif; ?>
                            </p>

                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="end_session">
                                <input type="hidden" name="session_id" value="<?= $active_session['id'] ?>">
                                <button type="submit" class="btn btn-warning btn-sm"
                                    onclick="return confirm('Are you sure you want to end this session?')">
                                    <i class="bi bi-stop-circle"></i> End Session
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Face Recognition Card -->
            <?php if ($active_session): ?>
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">Mark Attendance - Face Recognition</h4>
                    </div>
                    <div class="card-body">

                        <?php if ($debug_mode && !empty($debug_info)): ?>
                            <div class="alert alert-info">
                                <strong>Debug Information:</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($debug_info as $info): ?>
                                        <li><small><?= htmlspecialchars($info) ?></small></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($errors): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php elseif ($success): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                        <?php endif; ?>

                        <!-- Face Recognition Form -->
                        <form method="POST" id="attendanceForm">
                            <input type="hidden" name="action" value="recognize_student">
                            <input type="hidden" name="course_id" value="<?= $active_session['course_id'] ?>">

                            <div class="mb-3">
                                <label class="form-label">Current Session</label>
                                <div class="form-control-plaintext">
                                    <strong><?= htmlspecialchars($active_session['course_code']) ?></strong> -
                                    <?= htmlspecialchars($active_session['course_name']) ?>
                                    (<?= ucfirst($active_session['session_type']) ?>)
                                </div>
                            </div>

                            <!-- Face Recognition Section -->
                            <div class="mb-3">
                                <label class="form-label">Student Face Recognition <span
                                        class="text-danger">*</span></label>
                                <div class="d-flex flex-column align-items-start">
                                    <button type="button" class="btn btn-outline-primary btn-sm mb-2" id="openCameraBtn">
                                        <i class="bi bi-camera-fill"></i> Open Camera for Recognition
                                    </button>

                                    <div id="cameraContainer" style="display: none;">
                                        <div id="my_camera"
                                            style="width: 320px; height: 240px; border: 2px solid #007bff; border-radius: 8px;">
                                        </div>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-sm btn-success" id="captureBtn">
                                                <i class="bi bi-camera"></i> Capture & Recognize
                                            </button>
                                            <button type="button" class="btn btn-sm btn-secondary" id="closeCameraBtn">
                                                <i class="bi bi-x-circle"></i> Close Camera
                                            </button>
                                        </div>
                                    </div>

                                    <input type="hidden" name="captured_image" id="captured_image">

                                    <div id="preview" class="mt-2"></div>
                                    <div id="recognition_status" class="mt-2"></div>

                                    <small class="form-text text-muted mt-2">
                                        <i class="bi bi-info-circle"></i> <strong>Instructions:</strong>
                                        <br>• Ensure student is in well-lit area facing the camera
                                        <br>• Student should look directly at camera with neutral expression
                                        <br>• Keep a plain background for better recognition
                                        <br>• Click "Capture & Recognize" to identify the student
                                    </small>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary" id="recognizeBtn">
                                    <i class="bi bi-search"></i>
                                    <span id="recognizeText">Recognize Student</span>
                                    <span id="recognizeSpinner" class="spinner-border spinner-border-sm ms-2"
                                        style="display: none;"></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recognition Results Panel -->
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-person-check"></i> Recognition Result</h5>
                </div>
                <div class="card-body">
                    <?php if ($recognition_result && $recognition_result['success'] && $active_session): ?>
                        <div class="text-center">
                            <?php if (!empty($recognition_result['student']['image_path'])): ?>
                                <img src="../<?= htmlspecialchars($recognition_result['student']['image_path']) ?>"
                                    class="img-thumbnail mb-3" style="max-width: 150px;" alt="Student Photo">
                            <?php endif; ?>

                            <h6 class="text-success mb-3">
                                <i class="bi bi-check-circle-fill"></i> Student Recognized!
                            </h6>

                            <div class="student-details text-start">
                                <p><strong>Name:</strong>
                                    <?= htmlspecialchars($recognition_result['matched_student']['full_name']) ?></p>
                                <p><strong>Reg. Number:</strong>
                                    <?= htmlspecialchars($recognition_result['matched_student']['reg_number']) ?></p>
                                <p><strong>Level:</strong>
                                    <?= htmlspecialchars($recognition_result['matched_student']['level']) ?> Level</p>
                                <p><strong>Faculty:</strong>
                                    <?= htmlspecialchars($recognition_result['matched_student']['faculty_name']) ?></p>
                                <p><strong>Department:</strong>
                                    <?= htmlspecialchars($recognition_result['matched_student']['department_name']) ?></p>

                                <?php if (isset($recognition_result['confidence'])): ?>
                                    <p><strong>Confidence:</strong>
                                        <span
                                            class="badge bg-info"><?= number_format($recognition_result['confidence'], 1) ?>%</span>
                                    </p>
                                <?php endif; ?>
                            </div>

                            <!-- Mark Attendance Form -->
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="action" value="mark_attendance">
                                <input type="hidden" name="student_id"
                                    value="<?= $recognition_result['matched_student']['id'] ?>">
                                <input type="hidden" name="course_id" value="<?= $active_session['course_id'] ?>">
                                <input type="hidden" name="session_id" value="<?= $active_session['id'] ?>">
                                <input type="hidden" name="attendance_date" value="<?= date('Y-m-d') ?>">
                                <input type="hidden" name="confidence"
                                    value="<?= $recognition_result['confidence'] ?? 0 ?>">

                                <button type="submit" class="btn btn-success btn-sm w-100">
                                    <i class="bi bi-check2-square"></i> Mark Attendance
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted">
                            <i class="bi bi-camera" style="font-size: 3rem; opacity: 0.3;"></i>
                            <p class="mt-2">No student recognized yet.</p>
                            <p><small>
                                    <?php if (!$active_session): ?>
                                        Start an attendance session first, then capture a student's photo.
                                    <?php else: ?>
                                        Capture a student's photo to begin recognition.
                                    <?php endif; ?>
                                </small></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Today's Attendance Summary -->
            <?php if ($active_session): ?>
                <div class="card shadow mt-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-calendar-check"></i> Session Summary</h6>
                    </div>
                    <div class="card-body">
                        <?php
                        $summary_stmt = $conn->prepare("
                        SELECT COUNT(*) as total_present 
                        FROM attendance a
                        WHERE a.attendance_session_id = ?
                    ");
                        $summary_stmt->execute([$active_session['id']]);
                        $summary = $summary_stmt->fetch();
                        ?>
                        <p class="mb-1"><strong>Session:</strong> <?= ucfirst($active_session['session_type']) ?></p>
                        <p class="mb-1"><strong>Course:</strong> <?= htmlspecialchars($active_session['course_code']) ?></p>
                        <p class="mb-1"><strong>Date:</strong>
                            <?= date('F j, Y', strtotime($active_session['session_date'])) ?></p>
                        <p class="mb-0"><strong>Present:</strong>
                            <span class="badge bg-success"><?= $summary['total_present'] ?> students</span>
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Processing Modal -->
<div class="modal fade" id="processingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h5 id="processingText">Recognizing student...</h5>
                <p class="text-muted mb-0">Please wait while we process the facial recognition.</p>
            </div>
        </div>
    </div>
</div>

<script>
    // Form submission handler with processing modal
    document.getElementById('attendanceForm')?.addEventListener('submit', function (e) {
        const capturedImage = document.getElementById('captured_image').value;

        if (!capturedImage) {
            e.preventDefault();
            alert('Please capture a student photograph first.');
            return;
        }

        // Show processing modal
        const modal = new bootstrap.Modal(document.getElementById('processingModal'));
        modal.show();

        // Update submit button
        const recognizeBtn = document.getElementById('recognizeBtn');
        const recognizeText = document.getElementById('recognizeText');
        const recognizeSpinner = document.getElementById('recognizeSpinner');

        recognizeBtn.disabled = true;
        recognizeText.textContent = 'Processing...';
        recognizeSpinner.style.display = 'inline-block';
    });
</script>

<!-- WebcamJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/webcam-easy/1.0.5/webcam-easy.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.26/webcam.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // WebcamJS Configuration (don't attach immediately)
        Webcam.set({
            width: 320,
            height: 240,
            image_format: 'png',
            png_quality: 90,
            force_flash: false,
            flip_horiz: true,
            fps: 45
        });

        // Camera controls
        const openCameraBtn = document.getElementById('openCameraBtn');
        const closeCameraBtn = document.getElementById('closeCameraBtn');
        const captureBtn = document.getElementById('captureBtn');
        const cameraContainer = document.getElementById('cameraContainer');
        const preview = document.getElementById('preview');
        const recognitionStatus = document.getElementById('recognition_status');
        const capturedImageInput = document.getElementById('captured_image');

        let cameraOpen = false;

        // Open Camera
        openCameraBtn?.addEventListener('click', function () {
            console.log('Open camera button clicked, current state:', cameraOpen);

            if (!cameraOpen) {
                try {
                    // Clear previous results
                    preview.innerHTML = '';
                    recognitionStatus.innerHTML = '';
                    capturedImageInput.value = '';

                    // Show loading state
                    openCameraBtn.disabled = true;
                    openCameraBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Opening Camera...';

                    // Attach webcam
                    Webcam.attach('#my_camera');

                    // Update UI
                    cameraContainer.style.display = 'block';
                    openCameraBtn.style.display = 'none';
                    cameraOpen = true;

                    console.log('Camera opened successfully');

                    // Show success message
                    recognitionStatus.innerHTML = `
                    <div class="alert alert-success">
                        <i class="bi bi-camera-fill"></i> Camera is now active. Position student in front of camera and click "Capture & Recognize".
                    </div>
                `;

                } catch (error) {
                    console.error('Error opening camera:', error);

                    // Reset button state
                    openCameraBtn.disabled = false;
                    openCameraBtn.innerHTML = '<i class="bi bi-camera-fill"></i> Open Camera for Recognition';

                    // Show error message
                    recognitionStatus.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> Failed to open camera: ${error.message}
                        <br><small>Please check camera permissions and try again.</small>
                    </div>
                `;
                }
            }
        });

        // Close Camera
        closeCameraBtn?.addEventListener('click', function () {
            console.log('Close camera button clicked, current state:', cameraOpen);

            if (cameraOpen) {
                try {
                    // Reset webcam
                    Webcam.reset();

                    // Update UI
                    cameraContainer.style.display = 'none';
                    openCameraBtn.style.display = 'inline-block';
                    openCameraBtn.disabled = false;
                    openCameraBtn.innerHTML = '<i class="bi bi-camera-fill"></i> Open Camera for Recognition';
                    cameraOpen = false;

                    // Clear captured data
                    preview.innerHTML = '';
                    recognitionStatus.innerHTML = '';
                    capturedImageInput.value = '';

                    console.log('Camera closed successfully');

                } catch (error) {
                    console.error('Error closing camera:', error);
                    // Force reset state
                    cameraOpen = false;
                    cameraContainer.style.display = 'none';
                    openCameraBtn.style.display = 'inline-block';
                    openCameraBtn.disabled = false;
                    openCameraBtn.innerHTML = '<i class="bi bi-camera-fill"></i> Open Camera for Recognition';
                }
            }
        });

        // Capture Photo
        captureBtn?.addEventListener('click', function () {
            console.log('Capture button clicked, camera state:', cameraOpen);

            if (!cameraOpen) {
                alert('Please open the camera first.');
                return;
            }

            // Show capture progress
            captureBtn.disabled = true;
            captureBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Capturing...';

            recognitionStatus.innerHTML = '<div class="alert alert-info"><i class="bi bi-camera"></i> Capturing photo...</div>';

            // Capture the image
            Webcam.snap(function (data_uri) {
                try {
                    // Store the captured image data
                    capturedImageInput.value = data_uri;

                    // TURN OFF WEBCAM AFTER CAPTURE
                    Webcam.reset();
                    cameraContainer.style.display = 'none';
                    openCameraBtn.style.display = 'inline-block';
                    openCameraBtn.innerHTML = '<i class="bi bi-camera-fill"></i> Open Camera for Recognition';
                    cameraOpen = false;

                    // Show preview with retake option
                    preview.innerHTML = `
                <div class="mt-2">
                    <label class="form-label text-success"><i class="bi bi-check-circle"></i> Photo Captured Successfully</label>
                    <div class="d-flex align-items-start">
                        <img src="${data_uri}" class="img-thumbnail me-3" style="max-width: 120px;" alt="Captured Photo">
                        <div class="flex-grow-1">
                            <small class="text-muted d-block">Photo ready for recognition</small>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="retakeBtn">
                                <i class="bi bi-arrow-clockwise"></i> Retake
                            </button>
                        </div>
                    </div>
                </div>
            `;

                    // Update recognition status
                    recognitionStatus.innerHTML = `
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> Photo captured successfully! 
                    Click "Recognize Student" to process.
                </div>
            `;

                    // Re-enable capture button (but it's now hidden)
                    captureBtn.disabled = false;
                    captureBtn.innerHTML = '<i class="bi bi-camera"></i> Capture & Recognize';

                    // Add retake functionality - THIS WILL RESTART THE CAMERA
                    document.getElementById('retakeBtn')?.addEventListener('click', function () {
                        preview.innerHTML = '';
                        capturedImageInput.value = '';

                        // Restart camera for retake
                        try {
                            Webcam.attach('#my_camera');
                            cameraContainer.style.display = 'block';
                            openCameraBtn.style.display = 'none';
                            cameraOpen = true;

                            recognitionStatus.innerHTML = `
                        <div class="alert alert-success">
                            <i class="bi bi-camera-fill"></i> Camera restarted. Position student and click "Capture & Recognize".
                        </div>
                    `;

                            captureBtn.innerHTML = '<i class="bi bi-camera"></i> Capture & Recognize';
                        } catch (error) {
                            console.error('Error restarting camera:', error);
                            recognitionStatus.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> Error restarting camera. Please try again.
                        </div>
                    `;
                        }
                    });

                    console.log('Photo captured successfully and camera turned off');

                } catch (error) {
                    console.error('Error processing captured photo:', error);

                    // Re-enable capture button
                    captureBtn.disabled = false;
                    captureBtn.innerHTML = '<i class="bi bi-camera"></i> Capture & Recognize';

                    recognitionStatus.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> Error processing photo: ${error.message}
                </div>
            `;
                }
            });
        });
        // Handle camera errors
        Webcam.on('error', function (err) {
            console.error('Webcam error:', err);

            recognitionStatus.innerHTML = `
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> Camera Error: ${err}
                <br><small>Please check camera permissions and try again.</small>
            </div>
        `;

            // Reset camera state
            if (cameraOpen) {
                Webcam.reset();
                cameraContainer.style.display = 'none';
                openCameraBtn.style.display = 'inline-block';
                openCameraBtn.disabled = false;
                openCameraBtn.innerHTML = '<i class="bi bi-camera-fill"></i> Open Camera for Recognition';
                cameraOpen = false;
            }
        });

        // Handle camera live event (when camera successfully starts)
        Webcam.on('live', function () {
            console.log('Camera is now live');

            // Reset button state in case it was stuck
            openCameraBtn.disabled = false;
            openCameraBtn.innerHTML = '<i class="bi bi-camera-fill"></i> Open Camera for Recognition';
        });

        // Handle page unload - cleanup camera
        window.addEventListener('beforeunload', function () {
            if (cameraOpen) {
                try {
                    Webcam.reset();
                } catch (error) {
                    console.log('Error during cleanup:', error);
                }
            }
        });

        // Handle page visibility change - pause camera when tab is hidden
        document.addEventListener('visibilitychange', function () {
            if (document.hidden && cameraOpen) {
                console.log('Page hidden, pausing camera');
                // Optionally pause camera when tab is not visible
            } else if (!document.hidden && cameraOpen) {
                console.log('Page visible, camera should be active');
            }
        });

        // Auto-hide alerts after success - BUT NOT SESSION CARDS
        setTimeout(function () {
            const alerts = document.querySelectorAll('.alert-success, .alert-info');
            alerts.forEach(function (alert) {
                // Only hide alerts that contain specific success messages
                // DO NOT hide alerts that are part of session management
                const alertText = alert.textContent;
                const isSessionAlert = alert.closest('.card-header') ||
                    alert.closest('.card-body .alert-success') &&
                    (alertText.includes('Active Attendance Session') ||
                        alertText.includes('Session:') ||
                        alertText.includes('Course:') ||
                        alertText.includes('Started:'));

                if (!isSessionAlert &&
                    (alertText.includes('marked successfully') ||
                        alertText.includes('recognized successfully') ||
                        alertText.includes('session started successfully') ||
                        alertText.includes('session ended successfully'))) {

                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function () {
                        if (alert.parentNode) {
                            // Double-check it's not a session card before removing
                            if (!alert.closest('.card-header') &&
                                !alert.textContent.includes('Active Attendance Session')) {
                                alert.parentNode.removeChild(alert);
                            }
                        }
                    }, 500);
                }
            });
        }, 5000);

        // Enhanced form validation
        const attendanceForm = document.getElementById('attendanceForm');
        attendanceForm?.addEventListener('submit', function (e) {
            const capturedImage = document.getElementById('captured_image').value;
            const recognizeBtn = document.getElementById('recognizeBtn');
            const recognizeText = document.getElementById('recognizeText');
            const recognizeSpinner = document.getElementById('recognizeSpinner');

            if (!capturedImage) {
                e.preventDefault();

                // Show error in recognition status
                recognitionStatus.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> Please capture a student photograph first.
                </div>
            `;

                // Scroll to camera section
                if (cameraContainer) {
                    cameraContainer.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }

                return false;
            }

            // Show processing state
            const modal = new bootstrap.Modal(document.getElementById('processingModal'));
            modal.show();

            // Update submit button
            recognizeBtn.disabled = true;
            recognizeText.textContent = 'Processing...';
            recognizeSpinner.style.display = 'inline-block';

            // Update recognition status
            recognitionStatus.innerHTML = `
            <div class="alert alert-info">
                <div class="d-flex align-items-center">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                    <span><i class="bi bi-search"></i> Processing facial recognition...</span>
                </div>
            </div>
        `;

            // Set timeout for long processing
            setTimeout(function () {
                if (recognizeBtn.disabled) {
                    document.getElementById('processingText').textContent = 'Still processing... This may take a moment.';
                }
            }, 5000);
        });

        // Reset form state on page load if there were errors
        window.addEventListener('load', function () {
            const recognizeBtn = document.getElementById('recognizeBtn');
            const recognizeText = document.getElementById('recognizeText');
            const recognizeSpinner = document.getElementById('recognizeSpinner');

            if (recognizeBtn) {
                recognizeBtn.disabled = false;
                recognizeText.textContent = 'Recognize Student';
                recognizeSpinner.style.display = 'none';
            }

            // Hide processing modal if it's showing
            const processingModal = document.getElementById('processingModal');
            if (processingModal && processingModal.classList.contains('show')) {
                const modal = bootstrap.Modal.getInstance(processingModal);
                if (modal) {
                    modal.hide();
                }
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            // Spacebar to capture when camera is open
            if (e.code === 'Space' && cameraOpen && captureBtn && !captureBtn.disabled) {
                e.preventDefault();
                captureBtn.click();
            }

            // Escape to close camera
            if (e.code === 'Escape' && cameraOpen) {
                e.preventDefault();
                closeCameraBtn?.click();
            }
        });

        // Add keyboard shortcut hints
        if (openCameraBtn) {
            const shortcutHint = document.createElement('small');
            shortcutHint.className = 'text-muted d-block mt-1';
            shortcutHint.innerHTML = '<i class="bi bi-keyboard"></i> Shortcuts: <kbd>Space</kbd> to capture, <kbd>Esc</kbd> to close camera';
            openCameraBtn.parentNode.appendChild(shortcutHint);
        }

        // REMOVED: Camera permission check that was auto-starting camera
        // The camera permission will be checked only when user clicks the button

        console.log('Attendance system with face recognition initialized (camera will start only when requested)');
    });

    // Global error handler for webcam issues
    window.addEventListener('error', function (e) {
        if (e.message && e.message.includes('webcam')) {
            console.error('Webcam error detected:', e);
            const recognitionStatus = document.getElementById('recognition_status');
            if (recognitionStatus) {
                recognitionStatus.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> 
                    Camera system error. Please refresh the page and try again.
                </div>
            `;
            }
        }
    });

    // Global error handler for webcam issues
    window.addEventListener('error', function (e) {
        if (e.message && e.message.includes('webcam')) {
            console.error('Webcam error detected:', e);
            const recognitionStatus = document.getElementById('recognition_status');
            if (recognitionStatus) {
                recognitionStatus.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> 
                    Camera system error. Please refresh the page and try again.
                </div>
            `;
            }
        }
    });
</script>

<!-- Additional CSS for better camera interface -->
<style>
    #my_camera {
        border-radius: 8px;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        background: #f8f9fa;
    }

    .img-thumbnail {
        border: 2px solid #28a745;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .spinner-border-sm {
        width: 1rem;
        height: 1rem;
    }

    .modal-content {
        border-radius: 15px;
    }

    .btn:disabled {
        opacity: 0.6;
    }

    kbd {
        padding: 2px 4px;
        font-size: 85%;
        color: #fff;
        background-color: #212529;
        border-radius: 3px;
    }

    .alert {
        border-radius: 8px;
    }

    .card {
        border-radius: 12px;
    }

    .card-header {
        border-radius: 12px 12px 0 0 !important;
    }

    @media (max-width: 768px) {
        #my_camera {
            width: 100% !important;
            max-width: 320px;
        }
    }

    
</style>

<?php include_once '../includes/footer.php'; ?>