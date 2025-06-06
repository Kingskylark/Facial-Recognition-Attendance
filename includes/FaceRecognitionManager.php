<?php
class FaceRecognitionManager
{
    private $conn;
    private $debug_mode;
    private $python_script_path;

    public function __construct($database_connection, $debug = false)
    {
        $this->conn = $database_connection;
        $this->debug_mode = $debug;

        // Fixed path separators for cross-platform compatibility
        $this->python_script_path = __DIR__ . DIRECTORY_SEPARATOR . 'python_scripts' . DIRECTORY_SEPARATOR . 'opencv_face_encoder.py';

        // Check if Python script exists
        if (!file_exists($this->python_script_path)) {
            throw new Exception("OpenCV Python script not found at: " . $this->python_script_path);
        }

        if ($this->debug_mode) {
            error_log("Face recognition manager initialized with OpenCV");
        }
    }

    /**
     * Process student registration with OpenCV face detection
     */
    public function processStudentRegistration($image_path, $reg_number)
    {
        try {
            if ($this->debug_mode) {
                error_log("Processing face registration for: " . $reg_number);
                error_log("Image path: " . $image_path);
            }

            // Check if image file exists
            if (!file_exists($image_path)) {
                return [
                    'success' => false,
                    'message' => 'Image file not found: ' . $image_path
                ];
            }

            // Call Python script for face processing
            $face_data = $this->callPythonFaceProcessor($image_path, $reg_number);

            if (!$face_data['success']) {
                return [
                    'success' => false,
                    'message' => $face_data['message']
                ];
            }

            // Store the face encoding for database (JSON string)
            $encoding_for_db = isset($face_data['data']['face_features_json']) ?
                $face_data['data']['face_features_json'] :
                json_encode($face_data['data']['face_encoding']);

            return [
                'success' => true,
                'message' => 'Face encoding successful',
                'data' => $face_data['data'],
                'face_encoding_for_db' => $encoding_for_db,
                'face_encoding_array' => $face_data['data']['face_encoding'] ?? null
            ];

        } catch (Exception $e) {
            error_log("Face processing error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Face processing failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Call Python script to process face - Updated for XAMPP
     */
    private function callPythonFaceProcessor($image_path, $student_id)
    {
        try {
            // Escape arguments for shell command
            $escaped_image_path = escapeshellarg($image_path);
            $escaped_student_id = escapeshellarg($student_id);

            // Use 'py' command since it's working in your setup
            $python_commands = ['py', 'python3', 'python'];
            $output = null;
            $success = false;

            foreach ($python_commands as $python_cmd) {
                $command = "{$python_cmd} \"{$this->python_script_path}\" {$escaped_image_path} {$escaped_student_id} 2>&1";

                if ($this->debug_mode) {
                    error_log("Executing command: " . $command);
                }

                $output = shell_exec($command);

                if ($output && !empty(trim($output))) {
                    // Check if output looks like valid JSON
                    $decoded = json_decode(trim($output), true);
                    if ($decoded !== null) {
                        $success = true;
                        break;
                    }
                }
            }



            if (!$success || empty($output)) {
                return [
                    'success' => false,
                    'message' => 'Failed to execute Python script. Output: ' . ($output ?? 'No output')
                ];
            }

            if ($this->debug_mode) {
                error_log("Python script output: " . $output);
            }

            // Parse JSON response
            $result = json_decode(trim($output), true);

            if ($result === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from face processor: ' . $output
                ];
            }

            return $result;

        } catch (Exception $e) {
            error_log("Python script execution error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Script execution error: ' . $e->getMessage()
            ];
        }
    }


    public function processLecturerRegistration($imagePath, $staff_id)
    {
        try {
            if ($this->debug_mode) {
                error_log("Processing face registration for: " . $staff_id);
                error_log("Image path: " . $imagePath);
            }

            // Check if image file exists
            if (!file_exists($imagePath)) {
                return [
                    'success' => false,
                    'message' => 'Image file not found: ' . $imagePath
                ];
            }

            // Call Python script for face processing
            $face_data = $this->callPythonFaceProcessor($imagePath, $staff_id);

            if (!$face_data['success']) {
                return [
                    'success' => false,
                    'message' => $face_data['message']
                ];
            }

            // Store the face encoding for database (JSON string)
            $encoding_for_db = isset($face_data['data']['face_features_json']) ?
                $face_data['data']['face_features_json'] :
                json_encode($face_data['data']['face_encoding']);

            return [
                'success' => true,
                'message' => 'Face encoding successful',
                'data' => $face_data['data'],
                'face_encoding_for_db' => $encoding_for_db,
                'face_encoding_array' => $face_data['data']['face_encoding'] ?? null
            ];

        } catch (Exception $e) {
            error_log("Face processing error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Face processing failed: ' . $e->getMessage()
            ];
        }
    }

public function recognizeStudentForAttendance($image_path, $reg_number = null)
{
    try {
        if ($this->debug_mode) {
            error_log("Starting student face recognition for attendance...");
            error_log("Image path: " . $image_path);
        }

        if (!file_exists($image_path)) {
            return [
                'success' => false,
                'message' => 'Image file not found: ' . $image_path
            ];
        }

        // Get face encoding from the uploaded image
        $face_data = $this->processStudentRegistration($image_path, 'attendance_check');

        if (!$face_data['success']) {
            return [
                'success' => false,
                'message' => 'Face detection failed: ' . $face_data['message']
            ];
        }

        // Get the correct face encoding (not base64 image)
        $incoming_encoding = $face_data['data']['face_encoding'] ?? null;
        
        // Fallback to face_encoding_array if face_encoding is not available
        if (!$incoming_encoding && isset($face_data['face_encoding_array'])) {
            $incoming_encoding = $face_data['face_encoding_array'];
        }

        if (!$incoming_encoding || !is_array($incoming_encoding)) {
            return [
                'success' => false,
                'message' => 'No valid face encoding extracted from image. Available keys: ' . 
                             implode(', ', array_keys($face_data['data'] ?? []))
            ];
        }

        if ($this->debug_mode) {
            error_log("Incoming encoding length: " . count($incoming_encoding));
            error_log("First few values: " . implode(', ', array_slice($incoming_encoding, 0, 5)));
        }

        // Get all students with face encodings
        $stmt = $this->conn->prepare("
            SELECT 
                s.*,
                d.name as department_name,
                f.name as faculty_name
            FROM students s
            LEFT JOIN departments d ON s.department_id = d.id
            LEFT JOIN faculties f ON s.faculty_id = f.id
            WHERE s.face_encoding IS NOT NULL 
            AND s.face_encoding != ''
        ");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) {
            return [
                'success' => false,
                'message' => 'No registered students with face encodings found in database'
            ];
        }

        $comparisons = [];
        $best_match = null;
        $lowest_distance = PHP_FLOAT_MAX;

        foreach ($students as $student) {
            $stored_encoding = json_decode($student['face_encoding'], true);

            if (!is_array($stored_encoding)) {
                if ($this->debug_mode) {
                    error_log("Invalid encoding for student: " . $student['reg_number']);
                }
                continue;
            }

            if (count($stored_encoding) !== count($incoming_encoding)) {
                if ($this->debug_mode) {
                    error_log("Encoding length mismatch for {$student['reg_number']}: " . 
                             count($stored_encoding) . " vs " . count($incoming_encoding));
                }
                continue;
            }

            // Calculate similarity using multiple methods
            $euclidean_distance = $this->calculateEuclideanDistance($incoming_encoding, $stored_encoding);
            $cosine_similarity = $this->calculateCosineSimilarity($incoming_encoding, $stored_encoding);
            
            // Convert cosine similarity to distance (1 - similarity)
            $cosine_distance = 1 - $cosine_similarity;

            $student_name = trim($student['surname'] . ' ' . $student['firstname'] . ' ' . $student['middlename']);
            
            $comparison_result = [
                'id' => $student['id'],
                    'reg_number' => $student['reg_number'],
                    'full_name' => $student_name,
                    'department_name' => $student['department_name'] ?? 'Unknown Department',
                    'faculty_name' => $student['faculty_name'] ?? 'Unknown Faculty',
                    'department_id' => $student['department_id'],
                    'faculty_id' => $student['faculty_id'],
                    'level' => $student['level'],
                    'euclidean_distance' => $euclidean_distance,
                    'cosine_distance' => $cosine_distance,
                    'cosine_similarity' => $cosine_similarity
            ];

            $comparisons[] = $comparison_result;

            if ($this->debug_mode) {
                error_log("Student {$student['reg_number']}: Euclidean={$euclidean_distance}, Cosine Sim={$cosine_similarity}");
            }

            // Use cosine distance for better face recognition results  
            if ($cosine_distance < $lowest_distance) {
                $lowest_distance = $cosine_distance;
                $best_match = [
                    'id' => $student['id'],
                    'reg_number' => $student['reg_number'],
                    'full_name' => $student_name,
                    'department_name' => $student['department_name'] ?? 'Unknown Department',
                    'faculty_name' => $student['faculty_name'] ?? 'Unknown Faculty',
                    'department_id' => $student['department_id'],
                    'faculty_id' => $student['faculty_id'],
                    'level' => $student['level'],
                    'euclidean_distance' => $euclidean_distance,
                    'cosine_distance' => $cosine_distance,
                    'cosine_similarity' => $cosine_similarity
                ];
            }
        }

        // Sort comparisons by cosine distance (lower is better)
        usort($comparisons, function($a, $b) {
            return $a['cosine_distance'] <=> $b['cosine_distance'];
        });

        // Adjustable thresholds - you may need to tune these
        $cosine_threshold = 0.4; // Lower is more strict
        $euclidean_threshold = 0.8; // Higher is more lenient

        if ($best_match && $lowest_distance < $cosine_threshold) {
            return [
                'success' => true,
                'message' => 'Student recognized',
                'matched_student' => $best_match,
                'confidence' => round($best_match['cosine_similarity'] * 100, 2),
                'recognition_method' => 'cosine_similarity',
                'all_comparisons' => array_slice($comparisons, 0, 5) // Top 5 matches
            ];
        }

        // Try with euclidean distance as fallback
        if ($best_match && $best_match['euclidean_distance'] < $euclidean_threshold) {
            return [
                'success' => true,
                'message' => 'Student recognized (euclidean fallback)',
                'matched_student' => $best_match,
                'confidence' => round((1 - ($best_match['euclidean_distance'] / 2)) * 100, 2),
                'recognition_method' => 'euclidean_distance',
                'all_comparisons' => array_slice($comparisons, 0, 5)
            ];
        }

        return [
            'success' => false,
            'message' => 'No matching student found',
            'best_match_distance' => $lowest_distance,
            'threshold_used' => $cosine_threshold,
            'total_students_checked' => count($students),
            'all_comparisons' => array_slice($comparisons, 0, 5)
        ];

    } catch (Exception $e) {
        error_log("Recognition error: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Recognition failed: ' . $e->getMessage()
        ];
    }
}

/**
 * Calculate cosine similarity between two vectors
 * Better for face recognition than Euclidean distance
 */
private function calculateCosineSimilarity($vec1, $vec2)
{
    if (count($vec1) !== count($vec2)) {
        return 0;
    }

    $dot_product = 0;
    $norm_a = 0;
    $norm_b = 0;

    for ($i = 0; $i < count($vec1); $i++) {
        $dot_product += $vec1[$i] * $vec2[$i];
        $norm_a += $vec1[$i] * $vec1[$i];
        $norm_b += $vec2[$i] * $vec2[$i];
    }

    $norm_a = sqrt($norm_a);
    $norm_b = sqrt($norm_b);

    if ($norm_a == 0 || $norm_b == 0) {
        return 0;
    }

    return $dot_product / ($norm_a * $norm_b);
}

/**
 * Enhanced Euclidean distance with normalization
 */
private function calculateEuclideanDistance($vec1, $vec2)
{
    if (count($vec1) !== count($vec2)) {
        return PHP_FLOAT_MAX;
    }

    $sum = 0.0;
    for ($i = 0; $i < count($vec1); $i++) {
        $sum += pow($vec1[$i] - $vec2[$i], 2);
    }

    // Normalize by vector length for better comparison
    return sqrt($sum) / count($vec1);
}


}



?>