<?php
/**
 * Database Configuration Class
 * University of Uyo Facial Attendance System
 */

class Database {
    private $host = 'localhost';
    private $db_name = 'facial_attendance';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';
    private $pdo;
    
    /**
     * Get database connection
     * @return PDO
     */
    public function getConnection() {
        $this->pdo = null;
        
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("Database connection failed. Please try again later.");
        }
        
        return $this->pdo;
    }
    
    /**
     * Test database connection
     * @return bool
     */
    public function testConnection() {
        try {
            $this->getConnection();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
?>