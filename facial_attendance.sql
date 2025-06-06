CREATE DATABASE facial_attendance;

USE facial_attendance;

-- ADMINS TABLE (for system administrators)
CREATE TABLE admins (
  id INT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(50) UNIQUE NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL
);

-- FACULTIES
CREATE TABLE faculties (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL UNIQUE,
  code VARCHAR(10) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- DEPARTMENTS
CREATE TABLE departments (
  id INT PRIMARY KEY AUTO_INCREMENT,
  faculty_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(10) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE CASCADE,
  UNIQUE KEY unique_dept_per_faculty (faculty_id, name)
);

-- COURSES
CREATE TABLE courses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  department_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(20) NOT NULL UNIQUE,
  level VARCHAR(20) NOT NULL,
  semester ENUM('first', 'second') NOT NULL,
  credit_units INT DEFAULT 3,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
);

-- STUDENTS
CREATE TABLE students (
  id INT PRIMARY KEY AUTO_INCREMENT,
  surname VARCHAR(100) NOT NULL,
  firstname VARCHAR(100) NOT NULL,
  middlename VARCHAR(100) NULL,
  reg_number VARCHAR(100) UNIQUE NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  faculty_id INT NOT NULL,
  department_id INT NOT NULL,
  level VARCHAR(20) NOT NULL,
  session_year VARCHAR(20) NOT NULL, -- e.g., "2023/2024"
  image_path VARCHAR(255) NULL,
  face_encoding LONGBLOB NULL,
  password VARCHAR(255) NOT NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL,
  FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE RESTRICT,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
);alt

-- LECTURERS
CREATE TABLE lecturers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  surname VARCHAR(100) NOT NULL,
  firstname VARCHAR(100) NOT NULL,
  middlename VARCHAR(100) NULL,
  staff_id VARCHAR(100) UNIQUE NOT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  faculty_id INT NOT NULL,
  department_id INT NOT NULL,
  title VARCHAR(50) DEFAULT 'Mr.', -- Dr., Prof., Mr., Mrs., etc.
  image_path VARCHAR(255) NULL,
  face_encoding LONGBLOB NULL,
  password VARCHAR(255) NOT NULL,
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_login TIMESTAMP NULL,
  FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE RESTRICT,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT
);

-- LECTURER_COURSES (many-to-many)
CREATE TABLE lecturer_courses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  lecturer_id INT NOT NULL,
  course_id INT NOT NULL,
  session_year VARCHAR(20) NOT NULL, -- e.g., "2023/2024"
  semester ENUM('first', 'second') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_lecturer_course_session (lecturer_id, course_id, session_year, semester)
);

-- STUDENT_COURSES (many-to-many)
CREATE TABLE student_courses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  student_id INT NOT NULL,
  course_id INT NOT NULL,
  session_year VARCHAR(20) NOT NULL, -- e.g., "2023/2024"
  semester ENUM('first', 'second') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_student_course_session (student_id, course_id, session_year, semester)
);



-- Attendance Sessions Table
CREATE TABLE attendance_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    lecturer_id INT NOT NULL,
    course_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NULL,
    session_type ENUM('lecture', 'tutorial', 'practical', 'seminar', 'exam') NOT NULL,
    location VARCHAR(100),
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    session_year VARCHAR(20) NOT NULL,
    semester ENUM('first', 'second') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Attendance Records Table
CREATE TABLE attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    attendance_session_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('present', 'late', 'absent') NOT NULL,
    marked_at TIMESTAMP NOT NULL,
    method ENUM('face_recognition', 'manual', 'auto') DEFAULT 'manual',
    confidence DECIMAL(3,2) NULL, -- For face recognition confidence (0.00-1.00)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (attendance_session_id) REFERENCES attendance_sessions(id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    UNIQUE KEY unique_attendance (attendance_session_id, student_id)
);

ALTER TABLE attendance
ADD COLUMN course_id INT NOT NULL AFTER attendance_session_id,
ADD CONSTRAINT fk_attendance_course FOREIGN KEY (course_id) REFERENCES courses(id);
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,


-- SYSTEM SETTINGS (for app configuration)
CREATE TABLE system_settings (
  id INT PRIMARY KEY AUTO_INCREMENT,
  setting_key VARCHAR(100) UNIQUE NOT NULL,
  setting_value TEXT NOT NULL,
  description TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default admin user (password: admin123)
INSERT INTO admins (username, email, password, full_name) 
VALUES ('admin', 'admin@uniuyo.edu.ng', '$2b$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/LewdBdoVs0rPd/2tu', 'System Administrator');

-- Insert some default system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('face_recognition_threshold', '0.6', 'Minimum confidence threshold for facial recognition'),
('session_timeout_minutes', '30', 'User session timeout in minutes'),
('max_image_size_mb', '5', 'Maximum allowed image upload size in MB'),
('attendance_window_minutes', '15', 'Minutes after class start time when attendance is still allowed');

-- Sample data for University of Uyo
INSERT INTO faculties (name, code) VALUES
('Faculty of Science', 'SCI'),
('Faculty of Engineering', 'ENG'),
('Faculty of Arts', 'ARTS'),
('Faculty of Social Sciences', 'SSC'),
('Faculty of Education', 'EDU');

-- Sample departments for Faculty of Science
INSERT INTO departments (faculty_id, name, code) VALUES
(1, 'Computer Science', 'CSC'),
(1, 'Mathematics', 'MTH'),
(1, 'Physics', 'PHY'),
(1, 'Chemistry', 'CHM');

-- Sample courses for Computer Science Department
INSERT INTO courses (department_id, name, code, level, semester, credit_units) VALUES
(1, 'Introduction to Programming', 'CSC111', '100', 'first', 3),
(1, 'Computer Fundamentals', 'CSC112', '100', 'second', 2),
(1, 'Data Structures and Algorithms', 'CSC211', '200', 'first', 3),
(1, 'Database Systems', 'CSC311', '300', 'second', 3),
(1, 'Software Engineering', 'CSC411', '400', 'first', 3);


-- Optional additions to your existing database
-- (Run these if you want to add these features)

-- Add phone column to students (for contact)
ALTER TABLE students ADD COLUMN phone VARCHAR(15) NULL AFTER email;

-- Add phone column to lecturers (for contact)
ALTER TABLE lecturers ADD COLUMN phone VARCHAR(15) NULL AFTER email;

-- Add location column to attendance_sessions (to specify classroom/venue)
ALTER TABLE attendance_sessions ADD COLUMN location VARCHAR(100) NULL AFTER session_title;

-- Add remarks column to attendance (for special notes)
ALTER TABLE attendance ADD COLUMN remarks TEXT NULL AFTER confidence_score;

-- Create indexes for better performance
CREATE INDEX idx_students_reg_number ON students(reg_number);
CREATE INDEX idx_lecturers_staff_id ON lecturers(staff_id);
CREATE INDEX idx_attendance_session_date ON attendance_sessions(session_date);
CREATE INDEX idx_attendance_marked_at ON attendance(marked_at);

-- Update admin password to use PHP's password_hash() format
-- Password: password


-- Add missing columns to the courses table

-- Add lecturer_id column (foreign key to lecturers table)
ALTER TABLE courses 
ADD COLUMN lecturer_id INT NULL,
ADD FOREIGN KEY (lecturer_id) REFERENCES lecturers(id) ON DELETE SET NULL ON UPDATE CASCADE;

-- Add is_active column (boolean to enable/disable courses)
ALTER TABLE courses 
ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;

-- Add description column (text field for course description)
ALTER TABLE courses 
ADD COLUMN description TEXT NULL;

-- Optional: Add indexes for better performance
CREATE INDEX idx_courses_lecturer_id ON courses(lecturer_id);
CREATE INDEX idx_courses_is_active ON courses(is_active);

-- Optional: Update existing courses to be active by default (if needed)
-- UPDATE courses SET is_active = 1 WHERE is_active IS NULL;

-- Quick setup for ALL existing courses (use with caution!)
-- This will make all courses available to their respective departments
-- FIXED VERSION: Removed faculty_id since it doesn't exist in courses table

INSERT INTO course_eligibility (
    course_id, 
    department_id, 
    faculty_id, 
    level, 
    min_level, 
    max_level, 
    is_general, 
    is_carryover_allowed, 
    created_at
)
SELECT 
    c.id,
    c.department_id,
    NULL,                       -- Set faculty_id to NULL or get it from department table
    c.level,
    c.level,                    -- min_level = course level
    6,                          -- max_level = 6 (allow carryover)
    CASE 
        WHEN c.code LIKE 'GST%' OR c.code LIKE 'USE%' OR c.code LIKE 'GNS%' 
        THEN 1 
        ELSE 0 
    END,                        -- is_general
    1,                          -- is_carryover_allowed
    NOW()
FROM courses c
WHERE c.is_active = 1
AND NOT EXISTS (
    SELECT 1 FROM course_eligibility ce 
    WHERE ce.course_id = c.id 
    AND COALESCE(ce.department_id, 0) = COALESCE(c.department_id, 0)
);

-- Alternative: If you need faculty_id, get it from the departments table
INSERT INTO course_eligibility (
    course_id, 
    department_id, 
    faculty_id, 
    level, 
    min_level, 
    max_level, 
    is_general, 
    is_carryover_allowed, 
    created_at
)
SELECT 
    c.id,
    c.department_id,
    d.faculty_id,               -- Get faculty_id from departments table
    c.level,
    c.level,                    -- min_level = course level
    6,                          -- max_level = 6 (allow carryover)
    CASE 
        WHEN c.code LIKE 'GST%' OR c.code LIKE 'USE%' OR c.code LIKE 'GNS%' 
        THEN 1 
        ELSE 0 
    END,                        -- is_general
    1,                          -- is_carryover_allowed
    NOW()
FROM courses c
LEFT JOIN departments d ON c.department_id = d.id
WHERE c.is_active = 1
AND NOT EXISTS (
    SELECT 1 FROM course_eligibility ce 
    WHERE ce.course_id = c.id 
    AND COALESCE(ce.department_id, 0) = COALESCE(c.department_id, 0)
);

-- SIMPLE QUICK FIX: Make all active courses visible to all students
-- This version also removes the faculty_id issue

INSERT INTO course_eligibility (
    course_id, 
    department_id, 
    faculty_id, 
    level, 
    min_level, 
    max_level, 
    is_general, 
    is_carryover_allowed, 
    created_at
)
SELECT 
    id,             -- course_id
    NULL,           -- department_id (NULL means available to all)
    NULL,           -- faculty_id (NULL means available to all)
    level,          -- level
    1,              -- min_level (available from level 1)
    6,              -- max_level (available up to level 6)
    1,              -- is_general (treat all as general for now)
    1,              -- is_carryover_allowed
    NOW()           -- created_at
FROM courses 
WHERE is_active = 1;

-- Step 1: Add faculty_id column to courses table
ALTER TABLE courses 
ADD COLUMN faculty_id INT NULL,
ADD INDEX idx_courses_faculty_id (faculty_id);

-- Step 2: Update existing courses with faculty_id from their departments
UPDATE courses c 
JOIN departments d ON c.department_id = d.id 
SET c.faculty_id = d.faculty_id 
WHERE d.faculty_id IS NOT NULL;

-- Step 3: Optional - Add foreign key constraint for data integrity
ALTER TABLE courses 
ADD CONSTRAINT fk_courses_faculty 
FOREIGN KEY (faculty_id) REFERENCES faculties(id) 
ON DELETE SET NULL ON UPDATE CASCADE;

-- Step 4: Check the update worked
SELECT c.code, c.name, c.department_id, c.faculty_id, d.name as dept_name, f.name as faculty_name
FROM courses c 
LEFT JOIN departments d ON c.department_id = d.id
LEFT JOIN faculties f ON c.faculty_id = f.id
LIMIT 10;

-- Now you can run the original course eligibility setup
INSERT INTO course_eligibility (
    course_id, 
    department_id, 
    faculty_id, 
    level, 
    min_level, 
    max_level, 
    is_general, 
    is_carryover_allowed, 
    created_at
)
SELECT 
    c.id,
    c.department_id,
    c.faculty_id,               -- Now this column exists!
    c.level,
    c.level,                    -- min_level = course level
    6,                          -- max_level = 6 (allow carryover)
    CASE 
        WHEN c.code LIKE 'GST%' OR c.code LIKE 'USE%' OR c.code LIKE 'GNS%' 
        THEN 1 
        ELSE 0 
    END,                        -- is_general
    1,                          -- is_carryover_allowed
    NOW()
FROM courses c
WHERE c.is_active = 1
AND NOT EXISTS (
    SELECT 1 FROM course_eligibility ce 
    WHERE ce.course_id = c.id 
    AND COALESCE(ce.department_id, 0) = COALESCE(c.department_id, 0)
);


-- Course Eligibility entries for the new courses
-- Using actual course IDs: 6-13, 34, 35

INSERT INTO course_eligibility (course_id, department_id, faculty_id, level, min_level, max_level, is_general, is_carryover_allowed, created_at) VALUES

-- GST Courses - These are general courses available to all students
-- GST 101: Use of English I
(6, NULL, NULL, 100, 100, 400, 1, 1, NOW()),
-- GST 102: Use of English II
(7, NULL, NULL, 100, 100, 400, 1, 1, NOW()),
-- GST 103: Nigerian Peoples and Culture
(8, NULL, NULL, 100, 100, 400, 1, 1, NOW()),
-- GST 201: History and Philosophy of Science
(9, NULL, NULL, 200, 200, 400, 1, 1, NOW()),

-- Mathematics Department Courses - Available to Math dept and related science faculties
-- MTH 101: Calculus I
(10, 3, 2, 100, 100, 300, 0, 1, NOW()),
-- MTH 102: Linear Algebra I
(11, 3, 2, 100, 100, 300, 0, 1, NOW()),

-- Physics Department Courses - Available to Physics dept and related science students
-- PHY 101: General Physics I
(12, 4, 2, 100, 100, 300, 0, 1, NOW()),
-- PHY 201: General Physics III
(13, 4, 2, 200, 200, 400, 0, 1, NOW()),

-- Business Administration Course - Available to Business dept and management students
-- BUS 201: Principles of Management
(34, 5, 3, 200, 200, 400, 0, 1, NOW()),

-- Law Faculty Course - Strictly for Law students only
-- LAW 201: Constitutional Law I
(35, 6, 4, 200, 200, 300, 0, 0, NOW());

-- To get the actual course IDs, run this query first:
-- SELECT id, code, name FROM courses WHERE code IN ('GST 101', 'GST 102', 'GST 103', 'GST 201', 'MTH 101', 'MTH 102', 'PHY 101', 'PHY 201', 'BUS 201', 'LAW 201') ORDER BY id;