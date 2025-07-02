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
 * @param string $suggestion
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendResponse(bool $success, string $suggestion, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'suggestion' => $suggestion], $data));
    exit;
}

/**
 * Validates user session.
 *
 * @return int User ID
 * @throws Exception If user is not authenticated
 */
function validateUserSession(): int
{
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in.');
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Validates CSRF token.
 *
 * @param string $csrfToken
 * @return bool
 */
function validateCsrfToken(string $csrfToken): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $csrfToken);
}

try {
    // Validate request method and CSRF token
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method.', [], 405);
    }
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        sendResponse(false, 'Invalid CSRF token.', [], 403);
    }

    $userId = validateUserSession();
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    if (!$departmentId || $departmentId <= 0) {
        sendResponse(false, 'Department ID not provided.', [], 400);
    }

    global $pdo;

    // Verify user belongs to the department
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM users_department 
        WHERE User_id = ? AND Department_id = ?
    ");
    $stmt->execute([$userId, $departmentId]);
    if ($stmt->fetchColumn() == 0) {
        sendResponse(false, 'You do not have permission to access this department.', [], 403);
    }

    // Since storage_locations and cabinets tables are absent, return a fallback suggestion
    $suggestion = 'No physical storage available; using digital storage.';
    $locationId = time(); // Generate a pseudo-ID for storage metadata
    $storageMetadata = [
        'cabinet_id' => null,
        'layer' => null,
        'box' => null,
        'folder' => null,
        'location_id' => $locationId
    ];

    // Log request in transaction table
    $stmt = $pdo->prepare("
        INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
        VALUES (?, 'completed', 16, NOW(), ?)
    ");
    $stmt->execute([$userId, "Storage suggestion for department ID $departmentId: $suggestion"]);

    sendResponse(true, $suggestion, ['storage_metadata' => $storageMetadata], 200);
} catch (Exception $e) {
    error_log("Error in get_storage_suggestions.php: " . $e->getMessage());
    sendResponse(false, 'Server error: ' . $e->getMessage(), [], 500);
}
