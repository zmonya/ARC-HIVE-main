<?php

declare(strict_types=1);
session_start();
require 'db_connection.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CSRF token generation and validation
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
if ($userId === false) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Function to execute prepared queries safely
function executeQuery(PDO $pdo, string $query, array $params = []): PDOStatement|false
{
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Function to log transactions
function logTransaction(PDO $pdo, int $userId, string $status, int $type, string $message): bool
{
    $stmt = executeQuery(
        $pdo,
        "INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage) VALUES (?, ?, ?, NOW(), ?)",
        [$userId, $status, $type, $message]
    );
    return $stmt !== false;
}

$error = "";
$success = "";

// Handle form submission for adding/editing departments
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($action === 'add_dept' || $action === 'edit_dept') {
        $department_id = isset($_POST['department_id']) ? filter_var($_POST['department_id'], FILTER_VALIDATE_INT) : null;
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $type = trim(filter_input(INPUT_POST, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

        if (empty($name) || empty($type) || !in_array($type, ['college', 'office'])) {
            $error = "Department name and valid type (college or office) are required.";
            logTransaction($pdo, $userId, 'Failure', 8, $error);
        } else {
            // Check for duplicate department name
            $checkStmt = executeQuery(
                $pdo,
                "SELECT Department_id FROM departments WHERE Department_name = ? AND Department_id != ? AND Department_type IN ('college', 'office')",
                [$name, $department_id ?? 0]
            );
            if ($checkStmt && $checkStmt->rowCount() > 0) {
                $error = "Department name already exists.";
                logTransaction($pdo, $userId, 'Failure', 8, $error);
            } else {
                if ($action === 'add_dept') {
                    $stmt = executeQuery(
                        $pdo,
                        "INSERT INTO departments (Department_name, Department_type, Parent_department_id) VALUES (?, ?, NULL)",
                        [$name, $type]
                    );
                    $message = "Added department: $name";
                    $transType = 8;
                } elseif ($action === 'edit_dept' && $department_id) {
                    $stmt = executeQuery(
                        $pdo,
                        "UPDATE departments SET Department_name = ?, Department_type = ? WHERE Department_id = ? AND Department_type IN ('college', 'office')",
                        [$name, $type, $department_id]
                    );
                    $message = "Updated department: $name";
                    $transType = 9;
                }

                if ($stmt) {
                    $success = $message;
                    logTransaction($pdo, $userId, 'Success', $transType, $message);
                    header("Location: department_management.php");
                    exit();
                } else {
                    $error = "Failed to " . ($action === 'add_dept' ? "add" : "update") . " department.";
                    logTransaction($pdo, $userId, 'Failure', $transType, $error);
                }
            }
        }
    } elseif ($action === 'add_subdept') {
        $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
        $parent_dept_id = filter_var($_POST['parent_dept_id'], FILTER_VALIDATE_INT);

        if (empty($name) || !$parent_dept_id) {
            $error = "Subdepartment name and parent department are required.";
            logTransaction($pdo, $userId, 'Failure', 10, $error);
        } else {
            // Validate parent department
            $parentStmt = executeQuery(
                $pdo,
                "SELECT Department_id FROM departments WHERE Department_id = ? AND Department_type IN ('college', 'office')",
                [$parent_dept_id]
            );
            if (!$parentStmt || $parentStmt->rowCount() === 0) {
                $error = "Invalid parent department selected.";
                logTransaction($pdo, $userId, 'Failure', 10, $error);
            } else {
                // Check for duplicate subdepartment name within parent
                $checkStmt = executeQuery(
                    $pdo,
                    "SELECT Department_id FROM departments WHERE Department_name = ? AND Parent_department_id = ? AND Department_type = 'sub_department'",
                    [$name, $parent_dept_id]
                );
                if ($checkStmt && $checkStmt->rowCount() > 0) {
                    $error = "Subdepartment name already exists under this parent department.";
                    logTransaction($pdo, $userId, 'Failure', 10, $error);
                } else {
                    $stmt = executeQuery(
                        $pdo,
                        "INSERT INTO departments (Department_name, Department_type, Parent_department_id) VALUES (?, 'sub_department', ?)",
                        [$name, $parent_dept_id]
                    );
                    if ($stmt) {
                        $message = "Added subdepartment: $name under parent ID: $parent_dept_id";
                        logTransaction($pdo, $userId, 'Success', 10, $message);
                        header("Location: department_management.php");
                        exit();
                    } else {
                        $error = "Failed to add subdepartment.";
                        logTransaction($pdo, $userId, 'Failure', 10, $error);
                    }
                }
            }
        }
    }
}

// Handle department deletion
if (isset($_GET['delete_dept']) && validateCsrfToken($_GET['csrf_token'] ?? '')) {
    $department_id = filter_var($_GET['delete_dept'], FILTER_VALIDATE_INT);
    if ($department_id) {
        // Check if department has subdepartments, users, or files
        $checkSubStmt = executeQuery(
            $pdo,
            "SELECT Department_id FROM departments WHERE Parent_department_id = ?",
            [$department_id]
        );
        $checkUsersStmt = executeQuery(
            $pdo,
            "SELECT Users_Department_id FROM users_department WHERE Department_id = ?",
            [$department_id]
        );
        $checkFilesStmt = executeQuery(
            $pdo,
            "SELECT File_id FROM files WHERE Department_id = ?",
            [$department_id]
        );

        if ($checkSubStmt && $checkSubStmt->rowCount() > 0) {
            $error = "Cannot delete department with subdepartments.";
            logTransaction($pdo, $userId, 'Failure', 11, $error);
        } elseif ($checkUsersStmt && $checkUsersStmt->rowCount() > 0) {
            $error = "Cannot delete department with assigned users.";
            logTransaction($pdo, $userId, 'Failure', 11, $error);
        } elseif ($checkFilesStmt && $checkFilesStmt->rowCount() > 0) {
            $error = "Cannot delete department with assigned files.";
            logTransaction($pdo, $userId, 'Failure', 11, $error);
        } else {
            $stmt = executeQuery(
                $pdo,
                "DELETE FROM departments WHERE Department_id = ?",
                [$department_id]
            );
            if ($stmt) {
                $message = "Deleted department ID: $department_id";
                logTransaction($pdo, $userId, 'Success', 11, $message);
                header("Location: department_management.php");
                exit();
            } else {
                $error = "Failed to delete department.";
                logTransaction($pdo, $userId, 'Failure', 11, $error);
            }
        }
    } else {
        $error = "Invalid department ID.";
        logTransaction($pdo, $userId, 'Failure', 11, $error);
    }
}

// Fetch all parent departments
$departmentsStmt = executeQuery(
    $pdo,
    "SELECT Department_id, Department_name, Department_type FROM departments WHERE Department_type IN ('college', 'office') ORDER BY Department_name ASC"
);
$departments = $departmentsStmt ? $departmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch all subdepartments with parent department names
$subdepartmentsStmt = executeQuery(
    $pdo,
    "SELECT d1.Department_id, d1.Department_name AS subdepartment_name, d1.Parent_department_id, d2.Department_name AS parent_dept_name
     FROM departments d1
     LEFT JOIN departments d2 ON d1.Parent_department_id = d2.Department_id
     WHERE d1.Department_type = 'sub_department'
     ORDER BY d2.Department_name, d1.Department_name ASC"
);
$subdepartments = $subdepartmentsStmt ? $subdepartmentsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Department Management - Arc-Hive</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <style>
        .main-content {
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .error-message,
        .success-message {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
            font-size: 14px;
        }

        .error-message {
            background-color: #ffe6e6;
            color: #d32f2f;
        }

        .success-message {
            background-color: #e6ffe6;
            color: #2e7d32;
        }

        .table-container {
            margin-bottom: 30px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .dept-section {
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fff;
        }

        .dept-header {
            background: #f5f5f5;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 5px 5px 0 0;
        }

        .dept-header h3 {
            margin: 0;
            font-size: 16px;
            color: #333;
        }

        .subdept-table {
            display: none;
            margin: 10px;
        }

        .subdept-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .subdept-table th,
        .subdept-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .subdept-table th {
            background-color: #f8f8f8;
            font-weight: bold;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .edit-btn,
        .delete-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .edit-btn {
            background-color: #50c878;
            color: white;
        }

        .edit-btn:hover {
            background-color: #45a049;
        }

        .delete-btn {
            background-color: #d32f2f;
            color: white;
        }

        .delete-btn:hover {
            background-color: #b71c1c;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .modal-content select,
        .modal-content input {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .modal-content button {
            width: 100%;
            padding: 10px;
            background-color: #50c878;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .modal-content button:hover {
            background-color: #45a049;
        }

        .close {
            float: right;
            font-size: 24px;
            cursor: pointer;
            color: #333;
        }

        .open-modal-btn {
            padding: 10px 20px;
            margin-right: 10px;
            margin-bottom: 15px;
            background-color: #50c878;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.2s;
        }

        .open-modal-btn:hover {
            background-color: #45a049;
        }

        .warning-modal-content {
            text-align: center;
        }

        .warning-modal-content .buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }

        .confirm-btn,
        .cancel-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        .confirm-btn {
            background-color: #d32f2f;
            color: white;
        }

        .confirm-btn:hover {
            background-color: #b71c1c;
        }

        .cancel-btn {
            background-color: #ccc;
            color: #333;
        }

        .cancel-btn:hover {
            background-color: #bbb;
        }
    </style>
</head>

<body>
    <!-- Admin Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php" class="active"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="document_type_management.php"><i class="fas fa-file-alt"></i><span class="link-text">Document Type Management</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <!-- CSRF Token -->
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <!-- Messages -->
        <?php if (!empty($error)) { ?>
            <div class="error-message"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php } ?>
        <?php if (!empty($success)) { ?>
            <div class="success-message"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php } ?>

        <!-- Buttons -->
        <button id="open-dept-modal-btn" class="open-modal-btn">Add Department</button>
        <button id="open-subdept-modal-btn" class="open-modal-btn">Add Subdepartment</button>

        <!-- Department Modal -->
        <div class="modal" id="dept-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2><?php echo isset($_GET['edit_dept']) ? 'Edit Department' : 'Add Department'; ?></h2>
                <form method="POST" action="department_management.php">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit_dept']) ? 'edit_dept' : 'add_dept'; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <?php if (isset($_GET['edit_dept'])) {
                        $editDeptId = filter_var($_GET['edit_dept'], FILTER_VALIDATE_INT);
                        $editStmt = executeQuery($pdo, "SELECT Department_name, Department_type FROM departments WHERE Department_id = ? AND Department_type IN ('college', 'office')", [$editDeptId]);
                        $editDept = $editStmt ? $editStmt->fetch(PDO::FETCH_ASSOC) : null;
                    ?>
                        <input type="hidden" name="department_id" value="<?php echo htmlspecialchars((string)$editDeptId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                        <input type="text" name="name" placeholder="Department Name" value="<?php echo htmlspecialchars($editDept['Department_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                        <select name="type" required>
                            <option value="college" <?php echo ($editDept['Department_type'] ?? '') === 'college' ? 'selected' : ''; ?>>College</option>
                            <option value="office" <?php echo ($editDept['Department_type'] ?? '') === 'office' ? 'selected' : ''; ?>>Office</option>
                        </select>
                    <?php } else { ?>
                        <input type="text" name="name" placeholder="Department Name" required>
                        <select name="type" required>
                            <option value="">Select Type</option>
                            <option value="college">College</option>
                            <option value="office">Office</option>
                        </select>
                    <?php } ?>
                    <button type="submit"><?php echo isset($_GET['edit_dept']) ? 'Update Department' : 'Add Department'; ?></button>
                </form>
            </div>
        </div>

        <!-- Subdepartment Modal -->
        <div class="modal" id="subdept-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Add Subdepartment</h2>
                <form method="POST" action="department_management.php">
                    <input type="hidden" name="action" value="add_subdept">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <input type="text" name="name" placeholder="Subdepartment Name" required>
                    <select name="parent_dept_id" required>
                        <option value="">Select Parent Department</option>
                        <?php foreach ($departments as $dept) { ?>
                            <option value="<?php echo htmlspecialchars((string)$dept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($dept['Department_name'] . ' (' . $dept['Department_type'] . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                            </option>
                        <?php } ?>
                    </select>
                    <button type="submit">Add Subdepartment</button>
                </form>
            </div>
        </div>

        <!-- Departments and Subdepartments Display -->
        <div class="table-container">
            <h3>Departments & Subdepartments</h3>
            <?php if (empty($departments)) { ?>
                <p>No departments found.</p>
            <?php } else { ?>
                <?php foreach ($departments as $dept) { ?>
                    <div class="dept-section">
                        <div class="dept-header">
                            <h3><?php echo htmlspecialchars($dept['Department_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> (<?php echo htmlspecialchars($dept['Department_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)</h3>
                            <div class="action-buttons">
                                <a href="department_management.php?edit_dept=<?php echo htmlspecialchars((string)$dept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>&csrf_token=<?php echo htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                                    <button class="edit-btn">Edit</button>
                                </a>
                                <button class="delete-btn" onclick="confirmDelete(<?php echo htmlspecialchars((string)$dept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)">Delete</button>
                                <i class="fas fa-chevron-down toggle-subdept"></i>
                            </div>
                        </div>
                        <div class="subdept-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Subdepartment Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $subdepts = array_filter($subdepartments, fn($sd) => $sd['Parent_department_id'] == $dept['Department_id']);
                                    if (empty($subdepts)) { ?>
                                        <tr>
                                            <td colspan="3">No subdepartments found.</td>
                                        </tr>
                                    <?php } else { ?>
                                        <?php foreach ($subdepts as $subdept) { ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$subdept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($subdept['subdepartment_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></td>
                                                <td class="action-buttons">
                                                    <button class="delete-btn" onclick="confirmDelete(<?php echo htmlspecialchars((string)$subdept['Department_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>)">Delete</button>
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php } ?>
            <?php } ?>
        </div>

        <!-- Warning Modal for Deletion -->
        <div class="modal warning-modal" id="warning-delete-modal">
            <div class="warning-modal-content">
                <span class="close">&times;</span>
                <h2>Warning</h2>
                <p>Are you sure you want to delete this department/subdepartment? This action cannot be undone.</p>
                <div class="buttons">
                    <button class="confirm-btn" id="confirm-delete">Yes</button>
                    <button class="cancel-btn">No</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Initialize Notyf for notifications
        const notyf = new Notyf({
            duration: 5000,
            position: {
                x: 'right',
                y: 'top'
            },
            ripple: true
        });

        document.addEventListener('DOMContentLoaded', () => {
            // Sidebar toggle
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded');
                mainContent.classList.toggle('sidebar-minimized');
            });

            // Department Modal
            const deptModal = document.getElementById("dept-modal");
            const openDeptModalBtn = document.getElementById("open-dept-modal-btn");
            const closeDeptModalBtn = deptModal.querySelector(".close");

            openDeptModalBtn.onclick = () => deptModal.style.display = "flex";
            closeDeptModalBtn.onclick = () => deptModal.style.display = "none";

            // Subdepartment Modal
            const subdeptModal = document.getElementById("subdept-modal");
            const openSubdeptModalBtn = document.getElementById("open-subdept-modal-btn");
            const closeSubdeptModalBtn = subdeptModal.querySelector(".close");

            openSubdeptModalBtn.onclick = () => subdeptModal.style.display = "flex";
            closeSubdeptModalBtn.onclick = () => subdeptModal.style.display = "none";

            // Warning Modal
            const warningModal = document.getElementById("warning-delete-modal");
            const closeWarningModalBtn = warningModal.querySelector(".close");
            const cancelWarningBtn = warningModal.querySelector(".cancel-btn");

            closeWarningModalBtn.onclick = () => warningModal.style.display = "none";
            cancelWarningBtn.onclick = () => warningModal.style.display = "none";

            // Close modals when clicking outside
            window.onclick = (event) => {
                if (event.target === deptModal) deptModal.style.display = "none";
                if (event.target === subdeptModal) subdeptModal.style.display = "none";
                if (event.target === warningModal) warningModal.style.display = "none";
            };

            // Auto-open modal for editing
            <?php if (isset($_GET['edit_dept'])) { ?>
                deptModal.style.display = "flex";
            <?php } ?>

            // Toggle subdepartment tables
            document.querySelectorAll('.toggle-subdept').forEach(toggle => {
                toggle.addEventListener('click', () => {
                    const subdeptTable = toggle.closest('.dept-section').querySelector('.subdept-table');
                    subdeptTable.style.display = subdeptTable.style.display === 'block' ? 'none' : 'block';
                    toggle.classList.toggle('fa-chevron-down');
                    toggle.classList.toggle('fa-chevron-up');
                });
            });

            // Form validation
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', (e) => {
                    const csrfToken = document.getElementById('csrf_token').value;
                    if (!csrfToken) {
                        e.preventDefault();
                        notyf.error('CSRF token missing');
                    }
                });
            });
        });

        // Deletion confirmation
        let pendingDeptId = null;

        function confirmDelete(deptId) {
            pendingDeptId = deptId;
            document.getElementById('warning-delete-modal').style.display = 'flex';
        }

        document.getElementById('confirm-delete').addEventListener('click', () => {
            if (pendingDeptId !== null) {
                window.location.href = `department_management.php?delete_dept=${pendingDeptId}&csrf_token=${encodeURIComponent(document.getElementById('csrf_token').value)}`;
            }
            document.getElementById('warning-delete-modal').style.display = 'none';
            pendingDeptId = null;
        });
    </script>
</body>

</html>