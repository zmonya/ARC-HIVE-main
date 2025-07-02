<?php
session_start();
require 'db_connection.php';
require 'log_activity.php';
require 'vendor/autoload.php'; // Load Composer autoloader for phpdotenv

use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('error_log', __DIR__ . '/logs/error_log.log');
error_reporting(E_ALL);

/**
 * Generates a JSON response with appropriate HTTP status.
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session and department access.
 *
 * @param int $departmentId
 * @return int User ID
 * @throws Exception If user is not authenticated or lacks department access
 */
function validateUserSessionAndDepartment(int $departmentId): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated.');
    }
    $userId = (int)$_SESSION['user_id'];

    global $pdo;
    $stmt = $pdo->prepare("SELECT Users_Department_id FROM users_department WHERE User_id = ? AND Department_id = ?");
    $stmt->execute([$userId, $departmentId]);
    if (!$stmt->fetch()) {
        throw new Exception('User does not have access to this department.');
    }
    return $userId;
}

try {
    $departmentId = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId) {
        sendResponse(false, 'Department ID not provided.', [], 400);
    }

    $userId = validateUserSessionAndDepartment($departmentId);

    global $pdo;

    // Query hardcopy files using transaction and files.Copy_type
    $stmt = $pdo->prepare("
        SELECT f.File_id AS id, f.File_name AS file_name
        FROM files f
        JOIN transaction t ON f.File_id = t.File_id
        JOIN users_department ud ON t.Users_Department_id = ud.Users_Department_id
        WHERE ud.Department_id = ?
        AND f.Copy_type = 'hard'
        AND f.File_status != 'deleted'
        AND t.Transaction_type = 8
        ORDER BY f.Upload_date DESC
    ");
    $stmt->execute([$departmentId]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 8, NOW(), ?)
    ");
    $stmt->execute([$userId, "Fetched hardcopy files for department: $departmentId"]);

    sendResponse(true, 'Hardcopy files retrieved successfully.', ['files' => $files], 200);
} catch (Exception $e) {
    error_log("Error in fetch_hardcopy_files.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
