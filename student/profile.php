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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $surname = trim($_POST['surname']);
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $date_of_birth = trim($_POST['date_of_birth']);
    $gender = trim($_POST['gender']);
    $address = trim($_POST['address']);
    $guardian_name = trim($_POST['guardian_name']);
    $guardian_phone = trim($_POST['guardian_phone']);
    
    // Validate required fields
    if (empty($surname) || empty($firstname) || empty($email)) {
        $error_message = 'Surname, firstname, and email are required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (!empty($date_of_birth) && !DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $error_message = 'Please enter a valid date of birth.';
    } else {
        // Check if email is already taken by another student
        $stmt = $conn->prepare("SELECT id FROM students WHERE email = :email AND id != :student_id");
        $stmt->execute(['email' => $email, 'student_id' => $student_id]);
        
        if ($stmt->fetch()) {
            $error_message = 'This email address is already registered by another student.';
        } else {
            // Handle image upload
            $image_path = null;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $file_size = $_FILES['profile_image']['size'];
                    if ($file_size <= 2 * 1024 * 1024) { // 2MB limit
                        $upload_dir = '../uploads/students/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                        $new_filename = 'student_' . $student_id . '_' . time() . '.' . $file_extension;
                        $upload_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                            $image_path = $new_filename;
                            
                            // Delete old image if exists
                            $stmt = $conn->prepare("SELECT image_path FROM students WHERE id = :student_id");
                            $stmt->execute(['student_id' => $student_id]);
                            $old_image = $stmt->fetch()['image_path'];
                            if ($old_image && file_exists($upload_dir . $old_image)) {
                                unlink($upload_dir . $old_image);
                            }
                        } else {
                            $error_message = 'Failed to upload image. Please try again.';
                        }
                    } else {
                        $error_message = 'Image size must be less than 2MB.';
                    }
                } else {
                    $error_message = 'Only JPEG, PNG, and GIF images are allowed.';
                }
            }
            
            if (empty($error_message)) {
                // Update student information
                if ($image_path) {
                    $stmt = $conn->prepare("
                        UPDATE students 
                        SET surname = :surname, firstname = :firstname, middlename = :middlename, 
                            email = :email, phone = :phone, date_of_birth = :date_of_birth,
                            gender = :gender, address = :address, guardian_name = :guardian_name,
                            guardian_phone = :guardian_phone, image_path = :image_path, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :student_id
                    ");
                    $params = [
                        'surname' => $surname,
                        'firstname' => $firstname,
                        'middlename' => $middlename,
                        'email' => $email,
                        'phone' => $phone,
                        'date_of_birth' => !empty($date_of_birth) ? $date_of_birth : null,
                        'gender' => !empty($gender) ? $gender : null,
                        'address' => $address,
                        'guardian_name' => $guardian_name,
                        'guardian_phone' => $guardian_phone,
                        'image_path' => $image_path,
                        'student_id' => $student_id
                    ];
                } else {
                    $stmt = $conn->prepare("
                        UPDATE students 
                        SET surname = :surname, firstname = :firstname, middlename = :middlename, 
                            email = :email, phone = :phone, date_of_birth = :date_of_birth,
                            gender = :gender, address = :address, guardian_name = :guardian_name,
                            guardian_phone = :guardian_phone, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :student_id
                    ");
                    $params = [
                        'surname' => $surname,
                        'firstname' => $firstname,
                        'middlename' => $middlename,
                        'email' => $email,
                        'phone' => $phone,
                        'date_of_birth' => !empty($date_of_birth) ? $date_of_birth : null,
                        'gender' => !empty($gender) ? $gender : null,
                        'address' => $address,
                        'guardian_name' => $guardian_name,
                        'guardian_phone' => $guardian_phone,
                        'student_id' => $student_id
                    ];
                }
                
                $stmt->execute($params);
                $success_message = 'Profile updated successfully!';
            }
        }
    }
}

// Get current student information
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

// Calculate age if date of birth is available
$age = null;
if ($student['date_of_birth']) {
    $birth_date = new DateTime($student['date_of_birth']);
    $today = new DateTime();
    $age = $birth_date->diff($today)->y;
}
?>

<?php include_once '../includes/header.php'; ?>

<style>
.profile-img-preview {
    width: 150px;
    height: 150px;
    object-fit: cover;
    border-radius: 50%;
    border: 4px solid #fff;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.profile-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.profile-header {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: white;
    border-radius: 15px 15px 0 0;
    padding: 30px;
    text-align: center;
}

.form-control, .form-select {
    border-radius: 10px;
    border: 2px solid #e9ecef;
    padding: 12px 15px;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #4f46e5;
    box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
}

.btn-update {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    border: none;
    border-radius: 10px;
    padding: 12px 30px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-update:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(79, 70, 229, 0.4);
}

.readonly-field {
    background-color: #f8f9fa;
    border-color: #dee2e6;
    color: #6c757d;
}

.image-upload-area {
    border: 2px dashed #dee2e6;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
}

.image-upload-area:hover {
    border-color: #4f46e5;
    background-color: #f8f9ff;
}

.image-upload-area.dragover {
    border-color: #4f46e5;
    background-color: #f0f0ff;
}

.section-header {
    border-bottom: 2px solid #f8f9fa;
    padding-bottom: 10px;
    margin-bottom: 20px;
    color: #4f46e5;
    font-weight: 600;
}

.info-item {
    padding: 8px 0;
    border-bottom: 1px solid #f1f3f4;
}

.info-item:last-child {
    border-bottom: none;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85em;
    font-weight: 500;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
}

.status-inactive {
    background-color: #f8d7da;
    color: #721c24;
}
</style>

<main class="container py-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Profile</li>
                </ol>
            </nav>
            <h2><i class="fas fa-user-edit text-primary"></i> My Profile</h2>
            <p class="text-muted">Update your personal information and profile picture</p>
        </div>
    </div>

    <!-- Alert Messages -->
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
        <!-- Profile Form -->
        <div class="col-lg-8">
            <div class="card profile-card">
                <div class="card-body p-4">
                    <h5 class="section-header"><i class="fas fa-user text-primary"></i> Personal Information</h5>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <!-- Basic Information -->
                            <div class="col-md-6 mb-3">
                                <label for="surname" class="form-label">Surname <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="surname" name="surname" 
                                       value="<?= htmlspecialchars($student['surname']) ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="firstname" class="form-label">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="firstname" name="firstname" 
                                       value="<?= htmlspecialchars($student['firstname']) ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="middlename" class="form-label">Middle Name</label>
                                <input type="text" class="form-control" id="middlename" name="middlename" 
                                       value="<?= htmlspecialchars($student['middlename']) ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="reg_number" class="form-label">Registration Number</label>
                                <input type="text" class="form-control readonly-field" id="reg_number" 
                                       value="<?= htmlspecialchars($student['reg_number']) ?>" readonly>
                            </div>

                            <!-- Personal Details -->
                            <div class="col-md-6 mb-3">
                                <label for="date_of_birth" class="form-label">Date of Birth</label>
                                <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                       value="<?= htmlspecialchars($student['date_of_birth']) ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="gender" class="form-label">Gender</label>
                                <select class="form-select" id="gender" name="gender">
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?= $student['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= $student['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>

                            <!-- Contact Information -->
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?= htmlspecialchars($student['email']) ?>" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?= htmlspecialchars($student['phone']) ?>">
                            </div>

                            <!-- Address -->
                            <div class="col-12 mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3" 
                                          placeholder="Enter your full address"><?= htmlspecialchars($student['address']) ?></textarea>
                            </div>

                            <!-- Guardian Information -->
                            <div class="col-12 mb-3">
                                <h6 class="section-header"><i class="fas fa-users text-primary"></i> Guardian Information</h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="guardian_name" class="form-label">Guardian Name</label>
                                <input type="text" class="form-control" id="guardian_name" name="guardian_name" 
                                       value="<?= htmlspecialchars($student['guardian_name']) ?>" 
                                       placeholder="Full name of parent/guardian">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="guardian_phone" class="form-label">Guardian Phone</label>
                                <input type="tel" class="form-control" id="guardian_phone" name="guardian_phone" 
                                       value="<?= htmlspecialchars($student['guardian_phone']) ?>" 
                                       placeholder="Guardian's phone number">
                            </div>

                            <!-- Academic Information (Read-only) -->
                            <div class="col-12 mb-3">
                                <h6 class="section-header"><i class="fas fa-graduation-cap text-primary"></i> Academic Information</h6>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="faculty" class="form-label">Faculty</label>
                                <input type="text" class="form-control readonly-field" id="faculty" 
                                       value="<?= htmlspecialchars($student['faculty_name']) ?>" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="department" class="form-label">Department</label>
                                <input type="text" class="form-control readonly-field" id="department" 
                                       value="<?= htmlspecialchars($student['department_name']) ?>" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="level" class="form-label">Current Level</label>
                                <input type="text" class="form-control readonly-field" id="level" 
                                       value="<?= htmlspecialchars($student['level']) ?>" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="session_year" class="form-label">Session Year</label>
                                <input type="text" class="form-control readonly-field" id="session_year" 
                                       value="<?= htmlspecialchars($student['session_year']) ?>" readonly>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="admission_year" class="form-label">Admission Year</label>
                                <input type="text" class="form-control readonly-field" id="admission_year" 
                                       value="<?= htmlspecialchars($student['admission_year']) ?>" readonly>
                            </div>
                        </div>

                        <!-- Profile Image Upload -->
                        <div class="mb-4">
                            <h6 class="section-header"><i class="fas fa-camera text-primary"></i> Profile Picture</h6>
                            <div class="image-upload-area" onclick="document.getElementById('profile_image').click()">
                                <input type="file" class="d-none" id="profile_image" name="profile_image" 
                                       accept="image/jpeg,image/png,image/gif" onchange="previewImage(this)">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                <p class="mb-0">Click to upload or drag and drop</p>
                                <small class="text-muted">JPEG, PNG, GIF up to 2MB</small>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-update me-2">
                                <i class="fas fa-save"></i> Update Profile
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Profile Preview -->
        <div class="col-lg-4">
            <!-- Profile Summary Card -->
            <div class="card profile-card">
                <div class="profile-header">
                    <?php if ($student['image_path']): ?>
                        <img src="../uploads/students/<?= htmlspecialchars($student['image_path']) ?>" 
                             alt="Profile" class="profile-img-preview mb-3" id="imagePreview">
                    <?php else: ?>
                        <div class="profile-img-preview mx-auto bg-white bg-opacity-20 d-flex align-items-center justify-content-center mb-3" id="imagePreview">
                            <i class="fas fa-user fa-3x text-white"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?= htmlspecialchars($student['firstname']) ?> <?= htmlspecialchars($student['surname']) ?></h4>
                    <p class="mb-1"><?= htmlspecialchars($student['reg_number']) ?></p>
                    <span class="status-badge <?= $student['is_active'] ? 'status-active' : 'status-inactive' ?>">
                        <?= $student['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="card-body">
                    <h6 class="mb-3">Personal Details</h6>
                    
                    <?php if ($student['date_of_birth']): ?>
                    <div class="info-item">
                        <small class="text-muted">Age:</small><br>
                        <strong><?= $age ?> years old</strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($student['gender']): ?>
                    <div class="info-item">
                        <small class="text-muted">Gender:</small><br>
                        <strong><?= htmlspecialchars($student['gender']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($student['phone']): ?>
                    <div class="info-item">
                        <small class="text-muted">Phone:</small><br>
                        <strong><?= htmlspecialchars($student['phone']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <div class="info-item">
                        <small class="text-muted">Email:</small><br>
                        <strong><?= htmlspecialchars($student['email']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Academic Information Card -->
            <div class="card profile-card">
                <div class="card-body">
                    <h6 class="mb-3">Academic Information</h6>
                    <div class="info-item">
                        <small class="text-muted">Faculty:</small><br>
                        <strong><?= htmlspecialchars($student['faculty_name']) ?></strong>
                    </div>
                    <div class="info-item">
                        <small class="text-muted">Department:</small><br>
                        <strong><?= htmlspecialchars($student['department_name']) ?></strong>
                    </div>
                    <div class="info-item">
                        <small class="text-muted">Level:</small><br>
                        <strong><?= htmlspecialchars($student['level']) ?></strong>
                    </div>
                    <div class="info-item">
                        <small class="text-muted">Session:</small><br>
                        <strong><?= htmlspecialchars($student['session_year']) ?></strong>
                    </div>
                    <div class="info-item">
                        <small class="text-muted">Admitted:</small><br>
                        <strong><?= htmlspecialchars($student['admission_year']) ?></strong>
                    </div>
                </div>
            </div>

            <!-- Guardian Information Card -->
            <?php if ($student['guardian_name'] || $student['guardian_phone']): ?>
            <div class="card profile-card">
                <div class="card-body">
                    <h6 class="mb-3">Guardian Information</h6>
                    <?php if ($student['guardian_name']): ?>
                    <div class="info-item">
                        <small class="text-muted">Name:</small><br>
                        <strong><?= htmlspecialchars($student['guardian_name']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['guardian_phone']): ?>
                    <div class="info-item">
                        <small class="text-muted">Phone:</small><br>
                        <strong><?= htmlspecialchars($student['guardian_phone']) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="card profile-card">
                <div class="card-body">
                    <h6 class="mb-3">Quick Actions</h6>
                    <a href="settings.php" class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="fas fa-key"></i> Change Password
                    </a>
                    <a href="courses.php" class="btn btn-outline-success btn-sm w-100 mb-2">
                        <i class="fas fa-book"></i> Manage Courses
                    </a>
                    <a href="attendance.php" class="btn btn-outline-info btn-sm w-100">
                        <i class="fas fa-calendar-check"></i> View Attendance
                    </a>
                </div>
            </div>

            <!-- Account Information -->
            <div class="card profile-card">
                <div class="card-body">
                    <h6 class="mb-3">Account Information</h6>
                    <div class="info-item">
                        <small class="text-muted">Member Since:</small><br>
                        <strong><?= date('F j, Y', strtotime($student['created_at'])) ?></strong>
                    </div>
                    <?php if ($student['last_login']): ?>
                    <div class="info-item">
                        <small class="text-muted">Last Login:</small><br>
                        <strong><?= date('M j, Y g:i A', strtotime($student['last_login'])) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($student['updated_at']): ?>
                    <div class="info-item">
                        <small class="text-muted">Profile Updated:</small><br>
                        <strong><?= date('M j, Y g:i A', strtotime($student['updated_at'])) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('imagePreview');
            preview.innerHTML = `<img src="${e.target.result}" alt="Preview" class="profile-img-preview">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

// Drag and drop functionality
const uploadArea = document.querySelector('.image-upload-area');
const fileInput = document.getElementById('profile_image');

uploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadArea.classList.add('dragover');
});

uploadArea.addEventListener('dragleave', () => {
    uploadArea.classList.remove('dragover');
});

uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        previewImage(fileInput);
    }
});

// Auto-hide success messages after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => {
            successAlert.classList.remove('show');
            setTimeout(() => successAlert.remove(), 150);
        }, 5000);
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>