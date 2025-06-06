<?php
session_start();
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if lecturer is logged in
if (!isset($_SESSION['lecturer_id']) || $_SESSION['role'] !== 'lecturer') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['session_id'])) {
        throw new Exception('Session ID is required');
    }
    
    $session_id = $input['session_id'];
    $end_time = $input['end_time'] ?? date('Y-m-d H:i:s');
    
    // Initialize database connection
    $db = new Database();
    $conn = $db->getConnection();
    
    // Begin transaction
    $conn->beginTransaction();
    
    try {
        // Verify session belongs to the logged-in lecturer and is active
        $stmt = $conn->prepare("
            SELECT as_tbl.id, as_tbl.course_id, as_tbl.start_time, as_tbl.session_date,
                   c.name as course_name, c.code as course_code
            FROM attendance_sessions as_tbl
            JOIN courses c ON as_tbl.course_id = c.id
            WHERE as_tbl.id = :session_id 
            AND as_tbl.lecturer_id = :lecturer_id 
            AND as_tbl.status = 'active'
        ");
        $stmt->execute([
            'session_id' => $session_id,
            'lecturer_id' => $_SESSION['lecturer_id']
        ]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$session) {
            throw new Exception('Invalid or inactive session');
        }
        
        // Get attendance statistics for the session
        $stmt = $conn->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                method
            FROM attendance_records 
            WHERE session_id = :session_id 
            GROUP BY status, method
        ");
        $stmt->execute(['session_id' => $session_id]);
        $attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total enrolled students for this course
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total_enrolled
            FROM student_courses 
            WHERE course_id = :course_id
        ");
        $stmt->execute(['course_id' => $session['course_id']]);
        $total_enrolled = $stmt->fetch(PDO::FETCH_ASSOC)['total_enrolled'];
        
        // Calculate summary statistics
        $stats_summary = [
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'total_enrolled' => $total_enrolled,
            'methods' => []
        ];
        
        foreach ($attendance_stats as $stat) {
            $stats_summary[$stat['status']] = $stat['count'];
            
            if (!isset($stats_summary['methods'][$stat['method']])) {
                $stats_summary['methods'][$stat['method']] = 0;
            }
            $stats_summary['methods'][$stat['method']] += $stat['count'];
        }
        
        // Calculate session duration
        $start_time = new DateTime($session['session_date'] . ' ' . $session['start_time']);
        $end_time_obj = new DateTime($end_time);
        $duration = $start_time->diff($end_time_obj);
        $duration_minutes = ($duration->h * 60) + $duration->i;
        
        // Update session status to ended
        $stmt = $conn->prepare("
            UPDATE attendance_sessions 
            SET status = 'ended', 
                end_time = :end_time,
                duration_minutes = :duration_minutes,
                total_present = :total_present,
                total_late = :total_late,
                total_absent = :total_absent,
                updated_at = NOW()
            WHERE id = :session_id
        ");
        $stmt->execute([
            'session_id' => $session_id,
            'end_time' => $end_time,
            'duration_minutes' => $duration_minutes,
            'total_present' => $stats_summary['present'],
            'total_late' => $stats_summary['late'],
            'total_absent' => $stats_summary['absent']
        ]);
        
        // Mark all unmarked students as absent
        $stmt = $conn->prepare("
            INSERT INTO attendance_records (session_id, student_id, status, method, marked_at, created_at)
            SELECT :session_id, sc.student_id, 'absent', 'auto', :end_time, NOW()
            FROM student_courses sc
            WHERE sc.course_id = :course_id
            AND sc.student_id NOT IN (
                SELECT student_id FROM attendance_records WHERE session_id = :session_id
            )
        ");
        $stmt->execute([
            'session_id' => $session_id,
            'course_id' => $session['course_id'],
            'end_time' => $end_time
        ]);
        
        $auto_absent_count = $stmt->rowCount();
        
        // Update absent count if we auto-marked students
        if ($auto_absent_count > 0) {
            $stats_summary['absent'] += $auto_absent_count;
            $stmt = $conn->prepare("
                UPDATE attendance_sessions 
                SET total_absent = :total_absent
                WHERE id = :session_id
            ");
            $stmt->execute([
                'session_id' => $session_id,
                'total_absent' => $stats_summary['absent']
            ]);
        }
        
        // Log session end
        error_log("Session ended - ID: $session_id, Course: {$session['course_code']}, Duration: {$duration_minutes} minutes");
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Session ended successfully',
            'data' => [
                'session_id' => $session_id,
                'course_name' => $session['course_name'],
                'course_code' => $session['course_code'],
                'duration_minutes' => $duration_minutes,
                'auto_absent_count' => $auto_absent_count,
                'statistics' => $stats_summary,
                'end_time' => $end_time
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("End session error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>