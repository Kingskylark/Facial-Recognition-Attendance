<?php
session_start();
require_once '../config/database.php';

// Check if lecturer is logged in
if (!isset($_SESSION['lecturer_id']) || $_SESSION['role'] !== 'lecturer') {
    header('Location: login.php?role=lecturer');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$lecturer_id = $_SESSION['lecturer_id'];
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $surname = trim($_POST['surname']);
    $firstname = trim($_POST['firstname']);
    $middlename = trim($_POST['middlename']);
    $email = trim($_POST['email']);
    $title = trim($_POST['title']);
    $phone = trim($_POST['phone']);
    $qualification = trim($_POST['qualification']);
    $specialization = trim($_POST['specialization']);
    $date_of_birth = $_POST['date_of_birth'];
    $gender = $_POST['gender'];
    $address = trim($_POST['address']);
    $employment_date = $_POST['employment_date'];
    $employment_type = $_POST['employment_type'];
    $office_location = trim($_POST['office_location']);
    
    // Validate required fields
    if (empty($surname) || empty($firstname) || empty($email) || empty($title)) {
        $error_message = 'Surname, firstname, email, and title are required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } elseif (!empty($date_of_birth) && strtotime($date_of_birth) > time()) {
        $error_message = 'Date of birth cannot be in the future.';
    } elseif (!empty($employment_date) && strtotime($employment_date) > time()) {
        $error_message = 'Employment date cannot be in the future.';
    } else {
        // Check if email is already taken by another lecturer
        $stmt = $conn->prepare("SELECT id FROM lecturers WHERE email = :email AND id != :lecturer_id");
        $stmt->execute(['email' => $email, 'lecturer_id' => $lecturer_id]);
        
        if ($stmt->fetch()) {
            $error_message = 'This email address is already registered by another lecturer.';
        } else {
            // Handle image upload
            $image_path = null;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $file_type = $_FILES['profile_image']['type'];
                
                if (in_array($file_type, $allowed_types)) {
                    $file_size = $_FILES['profile_image']['size'];
                    if ($file_size <= 2 * 1024 * 1024) { // 2MB limit
                        $upload_dir = '../uploads/lecturers/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                        $new_filename = 'lecturer_' . $lecturer_id . '_' . time() . '.' . $file_extension;
                        $upload_path = $upload_dir . $new_filename;
                        
                        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                            $image_path = $new_filename;
                            
                            // Delete old image if exists
                            $stmt = $conn->prepare("SELECT image_path FROM lecturers WHERE id = :lecturer_id");
                            $stmt->execute(['lecturer_id' => $lecturer_id]);
                            $old_image_result = $stmt->fetch();
                            if ($old_image_result && $old_image_result['image_path'] && file_exists($upload_dir . $old_image_result['image_path'])) {
                                unlink($upload_dir . $old_image_result['image_path']);
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
                // Update lecturer information
                if ($image_path) {
                    $stmt = $conn->prepare("
                        UPDATE lecturers 
                        SET surname = :surname, firstname = :firstname, middlename = :middlename, 
                            email = :email, title = :title, image_path = :image_path, phone = :phone,
                            qualification = :qualification, specialization = :specialization,
                            date_of_birth = :date_of_birth, gender = :gender, address = :address,
                            employment_date = :employment_date, employment_type = :employment_type,
                            office_location = :office_location, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :lecturer_id
                    ");
                    $params = [
                        'surname' => $surname,
                        'firstname' => $firstname,
                        'middlename' => $middlename,
                        'email' => $email,
                        'title' => $title,
                        'image_path' => $image_path,
                        'phone' => $phone,
                        'qualification' => $qualification,
                        'specialization' => $specialization,
                        'date_of_birth' => !empty($date_of_birth) ? $date_of_birth : null,
                        'gender' => !empty($gender) ? $gender : null,
                        'address' => $address,
                        'employment_date' => !empty($employment_date) ? $employment_date : null,
                        'employment_type' => $employment_type,
                        'office_location' => $office_location,
                        'lecturer_id' => $lecturer_id
                    ];
                } else {
                    $stmt = $conn->prepare("
                        UPDATE lecturers 
                        SET surname = :surname, firstname = :firstname, middlename = :middlename, 
                            email = :email, title = :title, phone = :phone,
                            qualification = :qualification, specialization = :specialization,
                            date_of_birth = :date_of_birth, gender = :gender, address = :address,
                            employment_date = :employment_date, employment_type = :employment_type,
                            office_location = :office_location, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :lecturer_id
                    ");
                    $params = [
                        'surname' => $surname,
                        'firstname' => $firstname,
                        'middlename' => $middlename,
                        'email' => $email,
                        'title' => $title,
                        'phone' => $phone,
                        'qualification' => $qualification,
                        'specialization' => $specialization,
                        'date_of_birth' => !empty($date_of_birth) ? $date_of_birth : null,
                        'gender' => !empty($gender) ? $gender : null,
                        'address' => $address,
                        'employment_date' => !empty($employment_date) ? $employment_date : null,
                        'employment_type' => $employment_type,
                        'office_location' => $office_location,
                        'lecturer_id' => $lecturer_id
                    ];
                }
                
                $stmt->execute($params);
                $success_message = 'Profile updated successfully!';
            }
        }
    }
}

// Get current lecturer information
$stmt = $conn->prepare("
    SELECT l.*, f.name as faculty_name, d.name as department_name 
    FROM lecturers l 
    JOIN faculties f ON l.faculty_id = f.id 
    JOIN departments d ON l.department_id = d.id 
    WHERE l.id = :lecturer_id
");
$stmt->execute(['lecturer_id' => $lecturer_id]);
$lecturer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lecturer) {
    header('Location: login.php?role=lecturer');
    exit;
}

// Get lecturer's courses for current session
$current_session = date('Y') . '/' . (date('Y') + 1);
$stmt = $conn->prepare("
    SELECT c.name as course_name, c.code as course_code, lc.semester
    FROM lecturer_courses lc
    JOIN courses c ON lc.course_id = c.id
    WHERE lc.lecturer_id = :lecturer_id AND lc.session_year = :session_year
    ORDER BY lc.semester, c.code
");
$stmt->execute(['lecturer_id' => $lecturer_id, 'session_year' => $current_session]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}

.profile-header {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
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
    border-color: #059669;
    box-shadow: 0 0 0 0.2rem rgba(5, 150, 105, 0.25);
}

.btn-update {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    border: none;
    border-radius: 10px;
    padding: 12px 30px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-update:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(5, 150, 105, 0.4);
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
    border-color: #059669;
    background-color: #f0fdf4;
}

.image-upload-area.dragover {
    border-color: #059669;
    background-color: #ecfdf5;
}

.course-badge {
    background: linear-gradient(135deg, #059669 0%, #10b981 100%);
    color: white;
    padding: 4px 8px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: 500;
}

.semester-section {
    background-color: #f8f9fa;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
}

.form-section {
    background-color: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    text-align: center;
    white-space: nowrap;
    vertical-align: baseline;
    border-radius: 50rem;
}

.status-active {
    color: #0a5d2c;
    background-color: #bbfad0;
}

.status-inactive {
    color: #7d2d2d;
    background-color: #f8d7da;
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
            <h2><i class="fas fa-user-tie text-success"></i> My Profile</h2>
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
                    <form method="POST" enctype="multipart/form-data">
                        
                        <!-- Personal Information Section -->
                        <div class="form-section">
                            <h5 class="card-title mb-4"><i class="fas fa-user text-success"></i> Personal Information</h5>
                            
                            <div class="row">
                                <!-- Title -->
                                <div class="col-md-6 mb-3">
                                    <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                    <select class="form-select" id="title" name="title" required>
                                        <option value="">Select Title</option>
                                        <option value="Mr." <?= $lecturer['title'] === 'Mr.' ? 'selected' : '' ?>>Mr.</option>
                                        <option value="Mrs." <?= $lecturer['title'] === 'Mrs.' ? 'selected' : '' ?>>Mrs.</option>
                                        <option value="Ms." <?= $lecturer['title'] === 'Ms.' ? 'selected' : '' ?>>Ms.</option>
                                        <option value="Dr." <?= $lecturer['title'] === 'Dr.' ? 'selected' : '' ?>>Dr.</option>
                                        <option value="Asso. Prof." <?= $lecturer['title'] === 'Asso. Prof.' ? 'selected' : '' ?>>Asso. Prof.</option>
                                        <option value="Prof." <?= $lecturer['title'] === 'Prof.' ? 'selected' : '' ?>>Prof.</option>
                                    </select>
                                </div>

                                <!-- Surname -->
                                <div class="col-md-6 mb-3">
                                    <label for="surname" class="form-label">Surname <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="surname" name="surname" 
                                           value="<?= htmlspecialchars($lecturer['surname']) ?>" required>
                                </div>

                                <!-- First Name -->
                                <div class="col-md-6 mb-3">
                                    <label for="firstname" class="form-label">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="firstname" name="firstname" 
                                           value="<?= htmlspecialchars($lecturer['firstname']) ?>" required>
                                </div>

                                <!-- Middle Name -->
                                <div class="col-md-6 mb-3">
                                    <label for="middlename" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middlename" name="middlename" 
                                           value="<?= htmlspecialchars($lecturer['middlename']) ?>">
                                </div>

                                <!-- Date of Birth -->
                                <div class="col-md-6 mb-3">
                                    <label for="date_of_birth" class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                           value="<?= htmlspecialchars($lecturer['date_of_birth']) ?>">
                                </div>

                                <!-- Gender -->
                                <div class="col-md-6 mb-3">
                                    <label for="gender" class="form-label">Gender</label>
                                    <select class="form-select" id="gender" name="gender">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?= $lecturer['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= $lecturer['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                        <option value="Other" <?= $lecturer['gender'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>

                                <!-- Phone Number -->
                                <div class="col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" 
                                           value="<?= htmlspecialchars($lecturer['phone']) ?>">
                                </div>

                                <!-- Email -->
                                <div class="col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?= htmlspecialchars($lecturer['email']) ?>" required>
                                </div>

                                <!-- Address -->
                                <div class="col-12 mb-3">
                                    <label for="address" class="form-label">Address</label>
                                    <textarea class="form-control" id="address" name="address" rows="3"><?= htmlspecialchars($lecturer['address']) ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Information Section -->
                        <div class="form-section">
                            <h5 class="card-title mb-4"><i class="fas fa-graduation-cap text-success"></i> Academic Information</h5>
                            
                            <div class="row">
                                <!-- Staff ID (Read-only) -->
                                <div class="col-md-6 mb-3">
                                    <label for="staff_id" class="form-label">Staff ID</label>
                                    <input type="text" class="form-control readonly-field" id="staff_id" 
                                           value="<?= htmlspecialchars($lecturer['staff_id']) ?>" readonly>
                                </div>

                                <!-- Faculty (Read-only) -->
                                <div class="col-md-6 mb-3">
                                    <label for="faculty" class="form-label">Faculty</label>
                                    <input type="text" class="form-control readonly-field" id="faculty" 
                                           value="<?= htmlspecialchars($lecturer['faculty_name']) ?>" readonly>
                                </div>

                                <!-- Department (Read-only) -->
                                <div class="col-md-6 mb-3">
                                    <label for="department" class="form-label">Department</label>
                                    <input type="text" class="form-control readonly-field" id="department" 
                                           value="<?= htmlspecialchars($lecturer['department_name']) ?>" readonly>
                                </div>

                                <!-- Qualification -->
                                <div class="col-md-6 mb-3">
                                    <label for="qualification" class="form-label">Qualification</label>
                                    <select class="form-select" id="qualification" name="qualification">
                                        <option value="">Select Qualification</option>
                                        <option value="B.Sc" <?= $lecturer['qualification'] === 'B.Sc' ? 'selected' : '' ?>>B.Sc</option>
                                        <option value="B.A" <?= $lecturer['qualification'] === 'B.A' ? 'selected' : '' ?>>B.A</option>
                                        <option value="M.Sc" <?= $lecturer['qualification'] === 'M.Sc' ? 'selected' : '' ?>>M.Sc</option>
                                        <option value="M.A" <?= $lecturer['qualification'] === 'M.A' ? 'selected' : '' ?>>M.A</option>
                                        <option value="MBA" <?= $lecturer['qualification'] === 'MBA' ? 'selected' : '' ?>>MBA</option>
                                        <option value="Ph.D" <?= $lecturer['qualification'] === 'Ph.D' ? 'selected' : '' ?>>Ph.D</option>
                                        <option value="Other" <?= $lecturer['qualification'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>

                                <!-- Specialization -->
                                <div class="col-12 mb-3">
                                    <label for="specialization" class="form-label">Specialization</label>
                                    <input type="text" class="form-control" id="specialization" name="specialization" 
                                           value="<?= htmlspecialchars($lecturer['specialization']) ?>"
                                           placeholder="e.g., Computer Science, Mathematics, Physics">
                                </div>
                            </div>
                        </div>

                        <!-- Employment Information Section -->
                        <div class="form-section">
                            <h5 class="card-title mb-4"><i class="fas fa-briefcase text-success"></i> Employment Information</h5>
                            
                            <div class="row">
                                <!-- Employment Date -->
                                <div class="col-md-6 mb-3">
                                    <label for="employment_date" class="form-label">Employment Date</label>
                                    <input type="date" class="form-control" id="employment_date" name="employment_date" 
                                           value="<?= htmlspecialchars($lecturer['employment_date']) ?>">
                                </div>

                                <!-- Employment Type -->
                                <div class="col-md-6 mb-3">
                                    <label for="employment_type" class="form-label">Employment Type</label>
                                    <select class="form-select" id="employment_type" name="employment_type">
                                        <option value="Full-time" <?= $lecturer['employment_type'] === 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                                        <option value="Part-time" <?= $lecturer['employment_type'] === 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                                        <option value="Contract" <?= $lecturer['employment_type'] === 'Contract' ? 'selected' : '' ?>>Contract</option>
                                    </select>
                                </div>

                                <!-- Office Location -->
                                <div class="col-12 mb-3">
                                    <label for="office_location" class="form-label">Office Location</label>
                                    <input type="text" class="form-control" id="office_location" name="office_location" 
                                           value="<?= htmlspecialchars($lecturer['office_location']) ?>"
                                           placeholder="e.g., Block A, Room 101">
                                </div>
                            </div>
                        </div>

                        <!-- Profile Image Upload -->
                        <div class="form-section">
                            <h5 class="card-title mb-4"><i class="fas fa-camera text-success"></i> Profile Picture</h5>
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
                            <button type="submit" class="btn btn-success btn-update me-2">
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
            <div class="card profile-card">
                <div class="profile-header">
                    <?php if ($lecturer['image_path']): ?>
                        <img src="../uploads/lecturers/<?= htmlspecialchars($lecturer['image_path']) ?>" 
                             alt="Profile" class="profile-img-preview mb-3" id="imagePreview">
                    <?php else: ?>
                        <div class="profile-img-preview mx-auto bg-white bg-opacity-20 d-flex align-items-center justify-content-center mb-3" id="imagePreview">
                            <i class="fas fa-user-tie fa-3x text-white"></i>
                        </div>
                    <?php endif; ?>
                    <h4><?= htmlspecialchars($lecturer['title']) ?> <?= htmlspecialchars($lecturer['firstname']) ?> <?= htmlspecialchars($lecturer['surname']) ?></h4>
                    <p class="mb-1"><?= htmlspecialchars($lecturer['staff_id']) ?></p>
                    <span class="status-badge <?= $lecturer['is_active'] ? 'status-active' : 'status-inactive' ?>">
                        <?= $lecturer['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
                <div class="card-body">
                    <h6 class="mb-3">Profile Information</h6>
                    
                    <div class="mb-2">
                        <small class="text-muted">Faculty:</small><br>
                        <strong><?= htmlspecialchars($lecturer['faculty_name']) ?></strong>
                    </div>
                    
                    <div class="mb-2">
                        <small class="text-muted">Department:</small><br>
                        <strong><?= htmlspecialchars($lecturer['department_name']) ?></strong>
                    </div>
                    
                    <?php if ($lecturer['qualification']): ?>
                    <div class="mb-2">
                        <small class="text-muted">Qualification:</small><br>
                        <strong><?= htmlspecialchars($lecturer['qualification']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($lecturer['specialization']): ?>
                    <div class="mb-2">
                        <small class="text-muted">Specialization:</small><br>
                        <strong><?= htmlspecialchars($lecturer['specialization']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($lecturer['employment_type']): ?>
                    <div class="mb-2">
                        <small class="text-muted">Employment Type:</small><br>
                        <strong><?= htmlspecialchars($lecturer['employment_type']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($lecturer['office_location']): ?>
                    <div class="mb-2">
                        <small class="text-muted">Office:</small><br>
                        <strong><?= htmlspecialchars($lecturer['office_location']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($lecturer['employment_date']): ?>
                    <div class="mb-0">
                        <small class="text-muted">Employed Since:</small><br>
                        <strong><?= date('M Y', strtotime($lecturer['employment_date'])) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Current Courses -->
            <?php if (!empty($courses)): ?>
            <div class="card profile-card mt-3">
                <div class="card-body">
                    <h6 class="mb-3">Current Courses (<?= $current_session ?>)</h6>
                    
                    <?php
                    $first_semester = array_filter($courses, fn($course) => $course['semester'] === 'first');
                    $second_semester = array_filter($courses, fn($course) => $course['semester'] === 'second');
                    ?>
                    
                    <?php if (!empty($first_semester)): ?>
                    <div class="semester-section">
                        <h6 class="text-success mb-2">First Semester</h6>
                        <?php foreach ($first_semester as $course): ?>
                            <span class="course-badge me-1 mb-1 d-inline-block">
                                <?= htmlspecialchars($course['course_code']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($second_semester)): ?>
                    <div class="semester-section">
                        <h6 class="text-success mb-2">Second Semester</h6>
                        <?php foreach ($second_semester as $course): ?>
                            <span class="course-badge me-1 mb-1 d-inline-block">
                                <?= htmlspecialchars($course['course_code']) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (empty($first_semester) && empty($second_semester)): ?>
                    <p class="text-muted mb-0">No courses assigned for current session.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Account Information -->
            <div class="card profile-card mt-3">
                <div class="card-body">
                    <h6 class="mb-3">Account Information</h6>
                    
                    <?php if ($lecturer['created_at']): ?>
                    <div class="mb-2">
                        <small class="text-muted">Account Created:</small><br>
                        <strong><?= date('M d, Y', strtotime($lecturer['created_at'])) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($lecturer['last_login']): ?>
                    <div class="mb-2">
                        <small class="text-muted">Last Login:</small><br>
                        <strong><?= date('M d, Y H:i', strtotime($lecturer['last_login'])) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($lecturer['updated_at']): ?>
                    <div class="mb-0">
                        <small class="text-muted">Profile Updated:</small><br>
                        <strong><?= date('M d, Y H:i', strtotime($lecturer['updated_at'])) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card profile-card mt-3">
                <div class="card-body">
                    <h6 class="mb-3">Quick Actions</h6>
                    <a href="settings.php" class="btn btn-outline-success btn-sm w-100 mb-2">
                        <i class="fas fa-key"></i> Change Password
                    </a>
                    <a href="courses.php" class="btn btn-outline-primary btn-sm w-100 mb-2">
                        <i class="fas fa-book"></i> Manage Courses
                    </a>
                    <a href="attendance.php" class="btn btn-outline-info btn-sm w-100">
                        <i class="fas fa-calendar-check"></i> Take Attendance
                    </a>
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

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const dateOfBirth = document.getElementById('date_of_birth').value;
    const employmentDate = document.getElementById('employment_date').value;
    
    // Validate date of birth
    if (dateOfBirth && new Date(dateOfBirth) > new Date()) {
        e.preventDefault();
        alert('Date of birth cannot be in the future.');
        return;
    }
    
    // Validate employment date
    if (employmentDate && new Date(employmentDate) > new Date()) {
        e.preventDefault();
        alert('Employment date cannot be in the future.');
        return;
    }
});
</script>

<?php include_once '../includes/footer.php'; ?>