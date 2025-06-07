<?php
class FaceRecognitionManager
{
    private $conn;
    private $debug_mode;
    private $api_base_url;
    private $api_timeout;

    public function __construct($database_connection, $debug = false, $api_url = 'https://facerecognitionapi-24ec.onrender.com/')
    {
        $this->conn = $database_connection;
        $this->debug_mode = $debug;
        $this->api_base_url = rtrim($api_url, '/'); // Remove trailing slash
        $this->api_timeout = 30; // 30 seconds timeout for API calls

        if ($this->debug_mode) {
            error_log("Face recognition manager initialized with API: " . $this->api_base_url);
        }
    }

    /**
     * Process student registration with API face detection
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

            // Call API for face processing using the correct endpoint
            $face_data = $this->callFaceRecognitionAPI($image_path, $reg_number, '/register');

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
     * Process lecturer registration with API face detection
     */
    public function processLecturerRegistration($imagePath, $staff_id)
    {
        try {
            if ($this->debug_mode) {
                error_log("Processing face registration for lecturer: " . $staff_id);
                error_log("Image path: " . $imagePath);
            }

            // Check if image file exists
            if (!file_exists($imagePath)) {
                return [
                    'success' => false,
                    'message' => 'Image file not found: ' . $imagePath
                ];
            }

            // Call API for face processing (using same register endpoint for lecturers)
            $face_data = $this->callFaceRecognitionAPI($imagePath, $staff_id, '/register');

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
     * Call Face Recognition API with proper endpoint mapping
     */
    private function callFaceRecognitionAPI($image_path, $student_id, $endpoint = '/register')
    {
        try {
            if ($this->debug_mode) {
                error_log("Calling Face Recognition API for: " . $student_id);
                error_log("API URL: " . $this->api_base_url . $endpoint);
            }

            // Create cURL file upload
            $cfile = new CURLFile($image_path, mime_content_type($image_path), basename($image_path));
            $safe_student_id = str_replace(['/', '\\'], '_', $student_id);
            $post_data = [
                'file' => $cfile,  // Changed from 'image' to 'file' to match FastAPI
                'student_id' => $safe_student_id
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_base_url . $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->api_timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false, // For local development
                CURLOPT_SSL_VERIFYHOST => false, // For local development
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return [
                    'success' => false,
                    'message' => 'API connection error: ' . $curl_error
                ];
            }

            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => 'API returned HTTP ' . $http_code . ': ' . $response
                ];
            }

            if ($this->debug_mode) {
                error_log("API Response: " . $response);
            }

            // Parse JSON response
            $result = json_decode($response, true);

            if ($result === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from API: ' . $response
                ];
            }

            return $result;

        } catch (Exception $e) {
            error_log("API call error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'API call error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Alternative API call method using base64 encoding
     */
    private function callFaceRecognitionAPIBase64($image_path, $student_id)
    {
        try {
            if ($this->debug_mode) {
                error_log("Calling Face Recognition API (Base64) for: " . $student_id);
            }

            // Convert image to base64
            $image_data = file_get_contents($image_path);
            $image_base64 = base64_encode($image_data);

            $post_data = json_encode([
                'image_data' => $image_base64,
                'student_id' => $student_id
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_base_url . '/register/base64',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->api_timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return [
                    'success' => false,
                    'message' => 'API connection error: ' . $curl_error
                ];
            }

            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => 'API returned HTTP ' . $http_code . ': ' . $response
                ];
            }

            $result = json_decode($response, true);

            if ($result === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from API: ' . $response
                ];
            }

            return $result;

        } catch (Exception $e) {
            error_log("API call error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'API call error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Recognize student for attendance using face comparison
     */
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

            // Extract features from the uploaded image using the /extract-features endpoint
            $face_data = $this->extractFaceFeatures($image_path);

            if (!$face_data['success']) {
                return [
                    'success' => false,
                    'message' => 'Face detection failed: ' . $face_data['message']
                ];
            }

            // Get the face features from the response
            $incoming_encoding = $face_data['data']['features'] ?? null;

            if (!$incoming_encoding || !is_array($incoming_encoding)) {
                return [
                    'success' => false,
                    'message' => 'No valid face features extracted from image.'
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
            $highest_similarity = 0;

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

                // Use API to compare faces for better accuracy
                $comparison_result = $this->compareFaceFeatures($incoming_encoding, $stored_encoding);
                
                if ($comparison_result['success']) {
                    $similarity = $comparison_result['data']['similarity_score'];
                    $student_name = trim($student['surname'] . ' ' . $student['firstname'] . ' ' . $student['middlename']);
                    
                    $student_comparison = [
                        'id' => $student['id'],
                        'reg_number' => $student['reg_number'],
                        'full_name' => $student_name,
                        'department_name' => $student['department_name'] ?? 'Unknown Department',
                        'faculty_name' => $student['faculty_name'] ?? 'Unknown Faculty',
                        'department_id' => $student['department_id'],
                        'faculty_id' => $student['faculty_id'],
                        'level' => $student['level'],
                        'similarity_score' => $similarity,
                        'comparison_details' => $comparison_result['data']
                    ];

                    $comparisons[] = $student_comparison;

                    if ($this->debug_mode) {
                        error_log("Student {$student['reg_number']}: Similarity={$similarity}");
                    }

                    // Track the best match
                    if ($similarity > $highest_similarity) {
                        $highest_similarity = $similarity;
                        $best_match = $student_comparison;
                    }
                }
            }

            // Sort comparisons by similarity (higher is better)
            usort($comparisons, function($a, $b) {
                return $b['similarity_score'] <=> $a['similarity_score'];
            });

            // Threshold for recognition (adjustable)
            $recognition_threshold = 0.6; // 60% similarity required

            if ($best_match && $highest_similarity >= $recognition_threshold) {
                return [
                    'success' => true,
                    'message' => 'Student recognized',
                    'matched_student' => $best_match,
                    'confidence' => round($highest_similarity * 100, 2),
                    'recognition_method' => 'api_comparison',
                    'all_comparisons' => array_slice($comparisons, 0, 5) // Top 5 matches
                ];
            }

            return [
                'success' => false,
                'message' => 'No matching student found',
                'best_similarity' => $highest_similarity,
                'threshold_used' => $recognition_threshold,
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
     * Extract face features from image using API
     */
    private function extractFaceFeatures($image_path)
    {
        try {
            $cfile = new CURLFile($image_path, mime_content_type($image_path), basename($image_path));
            
            $post_data = [
                'file' => $cfile
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_base_url . '/extract-features',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->api_timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return [
                    'success' => false,
                    'message' => 'API connection error: ' . $curl_error
                ];
            }

            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => 'API returned HTTP ' . $http_code . ': ' . $response
                ];
            }

            $result = json_decode($response, true);

            if ($result === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from API: ' . $response
                ];
            }

            return $result;

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Feature extraction error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Compare two face feature arrays using API
     */
    private function compareFaceFeatures($features1, $features2, $threshold = 0.6)
    {
        try {
            $post_data = json_encode([
                'features1' => $features1,
                'features2' => $features2,
                'threshold' => $threshold
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_base_url . '/compare',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->api_timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return [
                    'success' => false,
                    'message' => 'API connection error: ' . $curl_error
                ];
            }

            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => 'API returned HTTP ' . $http_code . ': ' . $response
                ];
            }

            $result = json_decode($response, true);

            if ($result === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from API: ' . $response
                ];
            }

            return [
                'success' => true,
                'data' => $result
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Face comparison error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate image using API
     */
    public function validateImage($image_path)
    {
        try {
            $cfile = new CURLFile($image_path, mime_content_type($image_path), basename($image_path));
            
            $post_data = [
                'file' => $cfile
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_base_url . '/validate',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->api_timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return [
                    'success' => false,
                    'message' => 'API connection error: ' . $curl_error
                ];
            }

            if ($http_code !== 200) {
                return [
                    'success' => false,
                    'message' => 'API returned HTTP ' . $http_code . ': ' . $response
                ];
            }

            $result = json_decode($response, true);

            if ($result === null) {
                return [
                    'success' => false,
                    'message' => 'Invalid JSON response from API: ' . $response
                ];
            }

            return [
                'success' => $result['is_valid'],
                'message' => $result['message']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Image validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Calculate cosine similarity between two vectors (fallback method)
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
     * Enhanced Euclidean distance with normalization (fallback method)
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

    /**
     * Set API configuration
     */
    public function setApiConfig($base_url, $timeout = 30)
    {
        $this->api_base_url = rtrim($base_url, '/');
        $this->api_timeout = $timeout;
        
        if ($this->debug_mode) {
            error_log("API config updated: " . $this->api_base_url . ", timeout: " . $timeout);
        }
    }
    public function getApiInfo()
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->api_base_url . '/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                return [
                    'success' => false,
                    'message' => 'Connection error: ' . $curl_error
                ];
            }

            if ($http_code === 200) {
                $result = json_decode($response, true);
                return [
                    'success' => true,
                    'message' => 'API info retrieved successfully',
                    'data' => $result
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'API returned HTTP ' . $http_code . ': ' . $response
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to get API info: ' . $e->getMessage()
            ];
        }
    }
}
?>