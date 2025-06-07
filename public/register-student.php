<?php
session_start();
require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Include the FaceRecognitionManager class
require_once '../includes/FaceRecognitionManager.php';

$errors = [];
$success = '';
$debug_info = [];

// Enable debugging (set to false in production)
$debug_mode = true;

// Fetch faculties and semesters for dropdowns
$faculties = $conn->query("SELECT id, name FROM faculties ORDER BY name")->fetchAll();
$semesters = ['first', 'second'];

// Fetch levels from courses (to avoid hardcoding)
$levels = $conn->query("SELECT DISTINCT level FROM courses ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $surname = trim($_POST['surname']);
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $reg_number = trim($_POST['reg_number']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $faculty_id = $_POST['faculty_id'];
    $department_id = $_POST['department_id'];
    $level = $_POST['level'];
    $session_year = $_POST['session_year'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $image_data = $_POST['captured_image'] ?? '';
    $image_path = null;
    $face_encoding = null;

    // Basic validation
    if (empty($surname) || empty($firstname) || empty($reg_number) || empty($email) || empty($password)) {
        $errors[] = "All required fields must be filled.";
    }

    if (empty($image_data)) {
        $errors[] = "Please capture your photograph.";
    }

    // Process image and encode face if image is provided
    if ($image_data && strpos($image_data, 'data:image') === 0 && empty($errors)) {
        $img_dir = '../uploads/students/';

        // Create directory with proper error checking
        if (!is_dir($img_dir)) {
            if (!mkdir($img_dir, 0755, true)) {
                $errors[] = "Failed to create upload directory. Please contact administrator.";
                // if ($debug_mode) {
                //     $debug_info[] = "Directory creation failed: " . $img_dir;
                // }
            }
        }

        // Verify directory exists and is writable before proceeding
        if (!is_dir($img_dir) || !is_writable($img_dir)) {
            $errors[] = "Upload directory is not accessible. Please contact administrator.";
            // if ($debug_mode) {
            //     $debug_info[] = "Directory not writable: " . $img_dir . " (exists: " . (is_dir($img_dir) ? 'yes' : 'no') . ")";
            // }
        }

        if (empty($errors)) {
            // Sanitize registration number for filename (remove special characters)
            $safe_reg_number = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reg_number);
            $img_name = 'student_' . $safe_reg_number . '_' . uniqid() . '.png';
            $full_img_path = $img_dir . $img_name; // Full server path for processing
            $relative_img_path = 'uploads/students/' . $img_name; // Relative path for database storage
            $image_base64 = explode(',', $image_data)[1];

            // if ($debug_mode) {
            //     $debug_info[] = "Image processing: " . $img_name;
            //     $debug_info[] = "Full path: " . $full_img_path;
            //     $debug_info[] = "Relative path: " . $relative_img_path;
            // }

            // Validate base64 data
            if (empty($image_base64) || !base64_decode($image_base64, true)) {
                $errors[] = "Invalid image data received. Please retake the photo.";
            } else {
                // Save the image file
                if (file_put_contents($full_img_path, base64_decode($image_base64)) !== false) {
                    // Verify the file was actually created and has content
                    if (!file_exists($full_img_path) || filesize($full_img_path) == 0) {
                        $errors[] = "Image file was not properly saved. Please try again.";
                        if (file_exists($full_img_path)) {
                            unlink($full_img_path);
                        }
                    } else {
                        // if ($debug_mode) {
                        //     $debug_info[] = "Image saved successfully: " . filesize($full_img_path) . " bytes";
                        // }

                        // Initialize face recognition manager and ONLY encode face (no recognition)
                        try {
                            $faceManager = new FaceRecognitionManager($conn, debug:true, api_url:'https://facerecognitionapi-24ec.onrender.com');

                            $encodingResult = $faceManager->processStudentRegistration($full_img_path, $reg_number);

                            // if ($debug_mode) {
                            //     $debug_info[] = "Face encoding result: " . json_encode($encodingResult);
                            // }

                            if ($encodingResult['success']) {
                                // Extract the face encoding from the result
                                if (isset($encodingResult['data']['face_encoding'])) {
                                    $face_encoding = $encodingResult['data']['face_encoding'];
                                    
                                    // Ensure face_encoding is a string (JSON format)
                                    if (is_array($face_encoding)) {
                                        $face_encoding = json_encode($face_encoding);
                                    }

                                    // Validate the encoding is not empty
                                    if (empty($face_encoding) || $face_encoding === 'null' || $face_encoding === '[]') {
                                        $errors[] = "Face encoding failed - no face detected in the image. Please retake your photo in better lighting.";
                                        // Clean up the uploaded file
                                        if (file_exists($full_img_path)) {
                                            unlink($full_img_path);
                                        }
                                    } else {
                                        // Set the relative path for database storage
                                        $image_path = $relative_img_path;

                                        // if ($debug_mode) {
                                        //     $debug_info[] = "Valid face encoding obtained, proceeding with registration";
                                        // }
                                    }
                                } else {
                                    $errors[] = "Face encoding processing failed - no encoding data returned. Please retake your photo.";
                                    // if ($debug_mode) {
                                    //     $debug_info[] = "Face encoding not found in result data";
                                    //     $debug_info[] = "Available keys: " . implode(', ', array_keys($encodingResult ?? []));
                                    // }
                                    // Clean up the uploaded file
                                    if (file_exists($full_img_path)) {
                                        unlink($full_img_path);
                                    }
                                }
                            } else {
                                // Handle face encoding failure - BLOCK REGISTRATION
                                $error_message = "Face encoding failed: " . ($encodingResult['message'] ?? 'Unknown error');
                                $errors[] = $error_message . " Please retake your photo with better lighting and clear face visibility.";

                                // if ($debug_mode) {
                                //     $debug_info[] = "Face encoding failed, blocking registration";
                                //     $debug_info[] = $error_message;
                                // }

                                // Clean up the uploaded file
                                if (file_exists($full_img_path)) {
                                    unlink($full_img_path);
                                }
                            }
                        } catch (Exception $e) {
                            $error_message = "Face encoding system error: " . $e->getMessage();
                            $errors[] = $error_message . " Please try again or contact administrator.";

                            // if ($debug_mode) {
                            //     $debug_info[] = "Face encoding exception occurred";
                            //     $debug_info[] = $error_message;
                            //     $debug_info[] = "Exception trace: " . $e->getTraceAsString();
                            // }

                            // Clean up the uploaded file
                            if (file_exists($full_img_path)) {
                                unlink($full_img_path);
                            }
                        }
                    }
                } else {
                    $errors[] = "Failed to save image file. Please check server permissions or try again.";
                    // if ($debug_mode) {
                    //     $debug_info[] = "file_put_contents failed for: " . $full_img_path;
                    //     $debug_info[] = "Directory permissions: " . decoct(fileperms($img_dir) & 0777);
                    // }
                }
            }
        }
    }

    // Check for existing registration number or email
    if (empty($errors)) {
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM students WHERE reg_number = ? OR email = ?");
        $checkStmt->execute([$reg_number, $email]);
        if ($checkStmt->fetchColumn() > 0) {
            $errors[] = "Registration number or email already exists.";
        }
    }

    // Insert into database ONLY if no errors AND face encoding was successful
    if (!empty($face_encoding)) {
        $stmt = $conn->prepare("
            INSERT INTO students (
                surname, firstname, middlename, reg_number, email, phone, 
                faculty_id, department_id, level, session_year, password, 
                image_path, face_encoding
            ) VALUES (
                :surname, :firstname, :middlename, :reg_number, :email, :phone,
                :faculty_id, :department_id, :level, :session_year, :password,
                :image_path, :face_encoding
            )
        ");

        try {
            // Debug: Log what we're trying to insert
            // if ($debug_mode) {
            //     $debug_info[] = "Attempting to insert:";
            //     $debug_info[] = "- Image path: " . ($image_path ?: 'NULL');
            //     $debug_info[] = "- Face encoding: YES (length: " . strlen($face_encoding) . ")";
            //     $debug_info[] = "- Face encoding preview: " . substr($face_encoding, 0, 100) . '...';
            // }

            $stmt->execute([
                'surname' => $surname,
                'firstname' => $firstname,
                'middlename' => $middlename,
                'reg_number' => $reg_number,
                'email' => $email,
                'phone' => $phone,
                'faculty_id' => $faculty_id,
                'department_id' => $department_id,
                'level' => $level,
                'session_year' => $session_year,
                'password' => $password,
                'image_path' => $image_path,
                'face_encoding' => $face_encoding
            ]);

            $success = "Registration successful with face encoding. You can now login.";

            // Verify the data was actually inserted
            if ($debug_mode) {
                $checkStmt = $conn->prepare("SELECT face_encoding, image_path FROM students WHERE reg_number = ?");
                $checkStmt->execute([$reg_number]);
                $insertedData = $checkStmt->fetch();
                $debug_info[] = "Database verification:";
                $debug_info[] = "- Face encoding saved: YES (" . strlen($insertedData['face_encoding']) . " chars)";
                $debug_info[] = "- Image path saved: " . $insertedData['image_path'];
            }

            header("Location: login.php?role=student&success=1");
            exit;

        } catch (PDOException $e) {
            // Clean up uploaded file on database error
            if ($image_path && file_exists($full_img_path)) {
                unlink($full_img_path);
            }

            if (str_contains($e->getMessage(), 'reg_number')) {
                $errors[] = "Registration number already exists.";
            } elseif (str_contains($e->getMessage(), 'email')) {
                $errors[] = "Email already exists.";
            } else {
                $errors[] = "Database error occurred. Please try again.";
                if ($debug_mode) {
                    $debug_info[] = "Full database error: " . $e->getMessage();
                }
            }
        }
    } elseif (empty($errors) && empty($face_encoding)) {
        // This should not happen if our validation above is working correctly
        $errors[] = "Registration cannot be completed without valid face encoding. Please retake your photo.";
        if ($debug_mode) {
            $debug_info[] = "Registration blocked: No face encoding available";
        }
    }
}

// AJAX for departments by faculty
if (isset($_GET['fetch_departments']) && isset($_GET['faculty_id'])) {
    $fid = $_GET['faculty_id'];
    $stmt = $conn->prepare("SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name");
    $stmt->execute([$fid]);
    echo json_encode($stmt->fetchAll());
    exit;
}

include_once '../includes/header.php';
?>

<main class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow p-4">
                <h3 class="mb-4 text-center">Student Registration</h3>

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

                <form method="POST" id="registrationForm">
                    <div class="row mb-3">
                        <div class="col">
                            <label>Surname</label>
                            <input type="text" name="surname" class="form-control"
                                value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>" required>
                        </div>
                        <div class="col">
                            <label>First Name</label>
                            <input type="text" name="firstname" class="form-control"
                                value="<?= htmlspecialchars($_POST['firstname'] ?? '') ?>" required>
                        </div>
                        <div class="col">
                            <label>Middle Name</label>
                            <input type="text" name="middlename" class="form-control"
                                value="<?= htmlspecialchars($_POST['middlename'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label>Registration Number</label>
                        <input type="text" name="reg_number" class="form-control"
                            value="<?= htmlspecialchars($_POST['reg_number'] ?? '') ?>" required>
                    </div>

                    <div class="row mb-3">
                        <div class="col">
                            <label>Email</label>
                            <input type="email" name="email" class="form-control"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>
                        <div class="col">
                            <label>Phone</label>
                            <input type="text" name="phone" class="form-control"
                                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col">
                            <label>Faculty</label>
                            <select name="faculty_id" id="faculty" class="form-select" required>
                                <option value="">Select Faculty</option>
                                <?php foreach ($faculties as $faculty): ?>
                                    <option value="<?= $faculty['id'] ?>" <?= ($_POST['faculty_id'] ?? '') == $faculty['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($faculty['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label>Department</label>
                            <select name="department_id" id="department" class="form-select" required>
                                <option value="">Select Department</option>
                            </select>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col">
                            <label>Level</label>
                            <select name="level" class="form-select" required>
                                <option value="">Select Level</option>
                                <?php foreach ($levels as $lvl): ?>
                                    <option value="<?= $lvl ?>" <?= ($_POST['level'] ?? '') == $lvl ? 'selected' : '' ?>>
                                        <?= $lvl ?> Level
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col">
                            <label>Session Year</label>
                            <input type="text" name="session_year" class="form-control"
                                value="<?= htmlspecialchars($_POST['session_year'] ?? '') ?>"
                                placeholder="e.g. 2023/2024" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label>Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Passport Photograph <span class="text-danger">*</span></label>
                        <div class="d-flex flex-column align-items-start">
                        <button type="button" id="switchCameraBtn" class="btn btn-outline-secondary btn-sm" style="display: none;">
    <i class="bi bi-camera-video"></i> Back Camera
</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm mb-2" id="openCameraBtn">
                                <i class="bi bi-camera-fill"></i> Open Camera
                            </button>

                            <div id="cameraContainer" style="display: none;">
                                <div id="my_camera" style="width: 320px; height: 240px; border: 1px solid #ccc;"></div>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-primary"
                                        id="captureBtn">Capture</button>
                                    <button type="button" class="btn btn-sm btn-secondary" id="closeCameraBtn">Close
                                        Camera</button>
                                </div>
                            </div>

                            <input type="hidden" name="captured_image" id="captured_image">

                            <div id="preview" class="mt-2"></div>
                            <div id="encoding_status" class="mt-2"></div>

                            <small class="form-text text-muted mt-2">
                                ⚠️ <strong>Important:</strong> Please take photo in a well-lit area, facing natural
                                light.
                                Avoid strong shadows or backlight. Look directly at the camera with a neutral
                                expression.
                                A plain background is ideal for better face detection.
                                <br><strong class="text-danger">Note: Face encoding is required for registration. Your
                                    face features will be extracted and stored for future attendance
                                    verification.</strong>
                            </small>
                        </div>
                    </div>

                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span id="submitText">Register</span>
                            <span id="submitSpinner" class="spinner-border spinner-border-sm ms-2"
                                style="display: none;"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<!-- Loading Modal -->
<div class="modal fade" id="processingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h5 id="processingText">Processing your registration...</h5>
                <p class="text-muted mb-0">Please wait while we extract your facial features for future attendance
                    verification.</p>
            </div>
        </div>
    </div>
</div>

<script>
    // Faculty-Department dropdown handler
    document.getElementById('faculty').addEventListener('change', function () {
        const facultyId = this.value;
        const deptSelect = document.getElementById('department');
        deptSelect.innerHTML = '<option value="">Loading...</option>';

        if (facultyId) {
            fetch(`register-student.php?fetch_departments=1&faculty_id=${facultyId}`)
                .then(res => res.json())
                .then(data => {
                    deptSelect.innerHTML = '<option value="">Select Department</option>';
                    data.forEach(d => {
                        deptSelect.innerHTML += `<option value="${d.id}">${d.name}</option>`;
                    });
                })
                .catch(err => {
                    deptSelect.innerHTML = '<option value="">Error loading departments</option>';
                });
        } else {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
        }
    });

    // Form submission handler with processing modal
    document.getElementById('registrationForm').addEventListener('submit', function (e) {
        const capturedImage = document.getElementById('captured_image').value;

        if (!capturedImage) {
            e.preventDefault();
            alert('Please capture your photograph before submitting.');
            return;
        }

        // Show processing modal
        const modal = new bootstrap.Modal(document.getElementById('processingModal'));
        modal.show();

        // Update submit button
        const submitBtn = document.getElementById('submitBtn');
        const submitText = document.getElementById('submitText');
        const submitSpinner = document.getElementById('submitSpinner');

        submitBtn.disabled = true;
        submitText.textContent = 'Processing...';
        submitSpinner.style.display = 'inline-block';
    });
</script>

<!-- WebcamJS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.26/webcam.min.js"></script>
<script>
    let cameraOn = false;

    const cameraContainer = document.getElementById('cameraContainer');
    const openCameraBtn = document.getElementById('openCameraBtn');
    const captureBtn = document.getElementById('captureBtn');
    const closeCameraBtn = document.getElementById('closeCameraBtn');
    const preview = document.getElementById('preview');
    const switchCameraBtn = document.getElementById('switchCameraBtn');
    const encodingStatus = document.getElementById('encoding_status');

let currentFacingMode = 'environment'; // Track current camera mode

// Single button that cycles through states
openCameraBtn.addEventListener('click', () => {
    if (!cameraOn) {
        // First click: Open with front camera
        currentFacingMode = 'user';
        startCamera();
        openCameraBtn.textContent = 'Switch to Back';
    } else if (currentFacingMode === 'user') {
        // Second click: Switch to back camera
        stopCamera(); // Stop current camera first
        currentFacingMode = 'environment';
        startCamera();
        openCameraBtn.textContent = 'Close Camera';
    } else {
        // Third click: Close camera
        stopCamera();
        openCameraBtn.textContent = 'Open Camera';
    }
});

// Helper function to start camera
function startCamera() {
    try {
        Webcam.set({
            width: 320,
            height: 240,
            image_format: 'png',
            png_quality: 90,
            force_flash: false,
            flip_horiz: true,
            fps: 45,
            constraints: { facingMode: currentFacingMode }
        });
        
        Webcam.attach('#my_camera');
        cameraContainer.style.display = 'block';
        cameraOn = true;
        
        console.log('Camera started with facing mode:', currentFacingMode);
    } catch (error) {
        console.error('Error starting camera:', error);
        alert('Failed to start camera. Please check permissions.');
    }
}

// Helper function to stop camera
function stopCamera() {
    try {
        Webcam.reset(); // This stops the camera stream
        cameraContainer.style.display = 'none';
        cameraOn = false;
        console.log('Camera stopped');
    } catch (error) {
        console.error('Error stopping camera:', error);
    }
}

// Close camera button (separate from cycling button)
closeCameraBtn.addEventListener('click', () => {
    if (cameraOn) {
        stopCamera();
        openCameraBtn.textContent = 'Open Camera';
        openCameraBtn.style.display = 'inline-block';
    }
});


// Clean up when page is about to unload
window.addEventListener('beforeunload', () => {
    if (cameraOn) {
        stopCamera();
    }
});
    captureBtn.addEventListener('click', () => {
        if (cameraOn) {
            encodingStatus.innerHTML = '<div class="alert alert-warning"><small><i class="bi bi-clock"></i> Image captured. Face encoding will be processed during registration.</small></div>';

            Webcam.snap(function (data_uri) {
                document.getElementById('captured_image').value = data_uri;
                preview.innerHTML = `
                    <div class="border rounded p-2 bg-light">
                        <img src="${data_uri}" class="img-thumbnail" style="max-width: 150px;">
                        <div class="mt-2">
                            <small class="text-success"><i class="bi bi-check-circle"></i> Photo captured successfully</small>
                            <br>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="retakePhoto()">
                                <i class="bi bi-arrow-clockwise"></i> Retake
                            </button>
                        </div>
                    </div>
                `;

                // Close camera after successful capture
                Webcam.reset();
                cameraContainer.style.display = 'none';
                openCameraBtn.style.display = 'inline-block';
                cameraOn = false;
            });
        }
    });

    function retakePhoto() {
        document.getElementById('captured_image').value = '';
        preview.innerHTML = '';
        encodingStatus.innerHTML = '';

        // Reopen camera
        openCameraBtn.click();
    }

    // Handle camera errors
    Webcam.on('error', function (err) {
        console.error('Camera error:', err);
        encodingStatus.innerHTML = '<div class="alert alert-danger"><small><i class="bi bi-exclamation-triangle"></i> Camera error: ' + err + '</small></div>';

        // Reset camera state
        cameraContainer.style.display = 'none';
        openCameraBtn.style.display = 'inline-block';
        cameraOn = false;
    });
</script>

<?php include_once '../includes/footer.php'; ?>