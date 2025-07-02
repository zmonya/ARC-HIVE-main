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
 * Generates a JSON response with appropriate HTTP status (used for AJAX).
 *
 * @param bool $success
 * @param string $message
 * @param array $data
 * @param int $statusCode
 * @return void
 */
function sendJsonResponse(bool $success, string $message, array $data, int $statusCode): void
{
    header('Content-Type: application/json');
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

/**
 * Validates user session and admin role.
 *
 * @return int User ID
 * @throws Exception If user is not authenticated or not an admin
 */
function validateAdminSession(): int
{
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Access denied: Admin privileges required.');
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Validates input data for user management.
 *
 * @param array $data
 * @param bool $isAdd
 * @return string Error message or empty string if valid
 */
function validateInput(array $data, bool $isAdd): string
{
    if (empty($data['username']) || empty($data['full_name']) || empty($data['position']) || empty($data['role'])) {
        return 'All required fields must be filled.';
    }
    if ($isAdd && empty($data['password'])) {
        return 'Password is required for adding a new user.';
    }
    if (empty($data['department_ids'])) {
        return 'At least one department must be selected.';
    }
    return '';
}

/**
 * Processes and saves profile picture.
 *
 * @param string $croppedImage
 * @return string|null File path or null
 * @throws Exception If image processing fails
 */
function processProfilePicture(string $croppedImage): ?string
{
    if (empty($croppedImage)) {
        return null;
    }
    $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $croppedImage));
    if ($imageData === false) {
        throw new Exception('Invalid image data.');
    }
    $targetDir = 'uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    $filename = uniqid() . '.png';
    $targetFile = $targetDir . $filename;
    if (!file_put_contents($targetFile, $imageData)) {
        throw new Exception('Failed to save profile picture.');
    }
    return $targetFile;
}

/**
 * Fetches all users with their department affiliations.
 *
 * @param PDO $pdo
 * @return array
 */
function fetchAllUsers(PDO $pdo): array
{
    $stmt = $pdo->prepare("
        SELECT u.User_id AS id, u.Username AS username, u.Username AS full_name, u.Position AS position, u.Role AS role, u.Profile_pic AS profile_pic,
               GROUP_CONCAT(DISTINCT d.Department_name SEPARATOR ', ') AS department_names
        FROM users u
        LEFT JOIN users_department ud ON u.User_id = ud.User_id
        LEFT JOIN departments d ON ud.Department_id = d.Department_id
        GROUP BY u.User_id
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetches all departments.
 *
 * @param PDO $pdo
 * @return array
 */
function fetchAllDepartments(PDO $pdo): array
{
    $stmt = $pdo->prepare("SELECT Department_id AS id, Department_name AS name FROM departments ORDER BY Department_name");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $adminId = validateAdminSession();
    $error = '';

    // Handle CSRF token
    $csrfToken = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $csrfToken;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid CSRF token.');
        }

        $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING) ?? '';
        $username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $password = trim(filter_input(INPUT_POST, 'password', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $full_name = trim(filter_input(INPUT_POST, 'full_name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $position = trim(filter_input(INPUT_POST, 'position', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $department_ids = array_filter(array_map('intval', explode(',', trim($_POST['departments'] ?? ''))));
        $cropped_image = trim($_POST['cropped_image'] ?? '');
        $role = trim(filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING) ?? '');

        $error = validateInput([
            'username' => $username,
            'password' => $password,
            'full_name' => $full_name,
            'position' => $position,
            'role' => $role,
            'department_ids' => $department_ids
        ], $action === 'add');

        if (empty($error)) {
            global $pdo;
            $stmt = $pdo->prepare("SELECT User_id FROM users WHERE Username = ?");
            $stmt->execute([$username]);
            $existingUser = $stmt->fetch();

            if ($action === 'add' && $existingUser) {
                $error = 'Username already exists.';
            } elseif ($action === 'edit' && $existingUser && $existingUser['User_id'] != ($_POST['user_id'] ?? '')) {
                $error = 'Username is already taken by another user.';
            } else {
                $profile_pic = processProfilePicture($cropped_image);
                $pdo->beginTransaction();

                if ($action === 'add') {
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("
                        INSERT INTO users (Username, Password, Role, Profile_pic, Position, Created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $hashedPassword, $role, $profile_pic, $position]);
                    $user_id = $pdo->lastInsertId();
                    $logMessage = "Added user: $username";
                } elseif ($action === 'edit') {
                    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
                    if (!$user_id) {
                        throw new Exception('Invalid user ID.');
                    }
                    $stmt = $pdo->prepare("
                        UPDATE users SET Username = ?, Position = ?, Role = ?, Profile_pic = COALESCE(?, Profile_pic)
                        WHERE User_id = ?
                    ");
                    $stmt->execute([$username, $position, $role, $profile_pic, $user_id]);
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE users SET Password = ? WHERE User_id = ?");
                        $stmt->execute([$hashedPassword, $user_id]);
                    }
                    $logMessage = "Edited user: $username";
                }

                // Update department affiliations
                $stmt = $pdo->prepare("DELETE FROM users_department WHERE User_id = ?");
                $stmt->execute([$user_id]);

                foreach ($department_ids as $dept_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO users_department (User_id, Department_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([$user_id, $dept_id]);
                }

                // Log action in transaction table
                $stmt = $pdo->prepare("
                    INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
                    VALUES (?, 'completed', 22, NOW(), ?)
                ");
                $stmt->execute([$adminId, $logMessage]);

                $pdo->commit();
                header('Location: user_management.php');
                exit;
            }
        }
    }

    if (isset($_GET['delete'])) {
        $user_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
        if (!$user_id) {
            throw new Exception('Invalid user ID for deletion.');
        }
        global $pdo;
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT Username FROM users WHERE User_id = ?");
        $stmt->execute([$user_id]);
        $username = $stmt->fetchColumn();

        $stmt = $pdo->prepare("DELETE FROM users WHERE User_id = ?");
        $stmt->execute([$user_id]);

        // Log deletion in transaction table
        $stmt = $pdo->prepare("
            INSERT INTO transaction (User_id, Transaction_status, Transaction_type, Time, Massage)
            VALUES (?, 'completed', 22, NOW(), ?)
        ");
        $stmt->execute([$adminId, "Deleted user: $username"]);

        $pdo->commit();
        header('Location: user_management.php');
        exit;
    }

    global $pdo;
    $users = fetchAllUsers($pdo);
    $departments = fetchAllDepartments($pdo);
} catch (Exception $e) {
    error_log("Error in user_management.php: " . $e->getMessage());
    $error = 'Server error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <style>
        .main-content {
            padding: 20px;
        }

        .error {
            color: red;
            font-weight: bold;
        }

        .department-item,
        .selected-department {
            cursor: pointer;
            padding: 5px;
        }

        .selected-department {
            background-color: #e0e0e0;
            margin: 5px;
            display: inline-block;
        }

        .remove-department {
            color: red;
            margin-left: 5px;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php"><i class="fas fa-exchange-alt"></i><span class="link-text">Switch to Client View</span></a>
        <a href="admin_dashboard.php"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="admin_search.php"><i class="fas fa-search"></i><span class="link-text">View All Files</span></a>
        <a href="user_management.php" class="active"><i class="fas fa-users"></i><span class="link-text">User Management</span></a>
        <a href="department_management.php"><i class="fas fa-building"></i><span class="link-text">Department Management</span></a>
        <a href="physical_storage_management.php"><i class="fas fa-archive"></i><span class="link-text">Physical Storage</span></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content sidebar-expanded">
        <button id="open-modal-btn" class="open-modal-btn">Add/Edit User</button>
        <div class="modal" id="user-modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2><?php echo isset($_GET['edit']) ? 'Edit User' : 'Add User'; ?></h2>
                <?php if (!empty($error)): ?>
                    <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <form method="POST" action="user_management.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="<?php echo isset($_GET['edit']) ? 'edit' : 'add'; ?>">
                    <?php if (isset($_GET['edit'])): ?>
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($_GET['edit'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <div class="profile-pic-upload">
                        <img id="profile-pic-preview" src="placeholder.jpg" alt="Profile Picture">
                        <input type="file" name="profile_pic" id="profile-pic-input" accept="image/*">
                        <label for="profile-pic-input">Upload Profile Picture</label>
                    </div>
                    <input type="text" name="username" placeholder="Username" value="<?php echo isset($user) ? htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                    <input type="password" name="password" placeholder="Password" <?php echo isset($_GET['edit']) ? '' : 'required'; ?>>
                    <input type="text" name="full_name" placeholder="Full Name" value="<?php echo isset($user) ? htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                    <input type="text" name="position" placeholder="Position" value="<?php echo isset($user) ? htmlspecialchars($user['position'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                    <div class="department-selection">
                        <label>Departments (Required):</label>
                        <input type="text" id="dept-search" placeholder="Search departments..." class="search-input">
                        <div class="department-list">
                            <?php foreach ($departments as $dept): ?>
                                <div class="department-item" data-id="<?php echo htmlspecialchars($dept['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <span><?php echo htmlspecialchars($dept['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="selected-departments" class="selected-departments"></div>
                    <input type="hidden" name="departments" id="selected-departments-input">
                    <select name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin" <?php echo isset($user) && $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="client" <?php echo isset($user) && $user['role'] === 'client' ? 'selected' : ''; ?>>Client</option>
                    </select>
                    <input type="hidden" name="cropped_image" id="cropped-image-input">
                    <button type="submit"><?php echo isset($_GET['edit']) ? 'Update User' : 'Add User'; ?></button>
                </form>
            </div>
        </div>

        <div class="table-container">
            <div class="toggle-buttons">
                <button id="toggle-all" class="active">All Users</button>
                <button id="toggle-admins">Admins</button>
                <button id="toggle-clients">Clients</button>
            </div>
            <table id="user-table">
                <thead>
                    <tr>
                        <th>Profile</th>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Position</th>
                        <th>Departments</th>
                        <th>Role</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr data-role="<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>">
                            <td><img src="<?php echo htmlspecialchars($user['profile_pic'] ?? 'placeholder.jpg', ENT_QUOTES, 'UTF-8'); ?>" alt="Profile"></td>
                            <td><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['position'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['department_names'] ?? 'No departments', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="action-buttons">
                                <a href="user_management.php?edit=<?php echo htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8'); ?>"><button class="edit-btn">Edit</button></a>
                                <a href="user_management.php?delete=<?php echo htmlspecialchars($user['id'], ENT_QUOTES, 'UTF-8'); ?>" onclick="return confirm('Are you sure you want to delete this user?')"><button class="delete-btn">Delete</button></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cropper-popup">
            <div class="cropper-container">
                <img id="cropper-image" />
                <button id="crop-button">Crop Image</button>
                <button id="cancel-button">Cancel</button>
            </div>
        </div>

        <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
        <script>
            const profilePicInput = document.getElementById('profile-pic-input');
            const profilePicPreview = document.getElementById('profile-pic-preview');
            const cropperPopup = document.querySelector('.cropper-popup');
            const cropperImage = document.getElementById('cropper-image');
            const cropButton = document.getElementById('crop-button');
            const cancelButton = document.getElementById('cancel-button');
            const croppedImageInput = document.getElementById('cropped-image-input');
            const toggleAll = document.getElementById('toggle-all');
            const toggleAdmins = document.getElementById('toggle-admins');
            const toggleClients = document.getElementById('toggle-clients');
            const userTable = document.getElementById('user-table').getElementsByTagName('tbody')[0].rows;
            const modal = document.getElementById('user-modal');
            const openModalBtn = document.getElementById('open-modal-btn');
            const closeModal = modal.querySelector('.close');
            let cropper;

            // Profile picture upload and cropping
            profilePicInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        cropperPopup.style.display = 'flex';
                        cropperImage.src = e.target.result;
                        if (cropper) cropper.destroy();
                        cropper = new Cropper(cropperImage, {
                            aspectRatio: 1,
                            viewMode: 1,
                            autoCropArea: 0.8,
                        });
                    };
                    reader.readAsDataURL(file);
                }
            });

            cropButton.addEventListener('click', function() {
                if (cropper) {
                    const croppedCanvas = cropper.getCroppedCanvas({
                        width: 150,
                        height: 150
                    });
                    const croppedImage = croppedCanvas.toDataURL('image/png');
                    profilePicPreview.src = croppedImage;
                    croppedImageInput.value = croppedImage;
                    cropperPopup.style.display = 'none';
                    cropper.destroy();
                    cropper = null;
                }
            });

            cancelButton.addEventListener('click', function() {
                cropperPopup.style.display = 'none';
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            });

            // Modal handling
            openModalBtn.addEventListener('click', () => {
                modal.style.display = 'block';
                resetForm();
            });

            closeModal.addEventListener('click', () => modal.style.display = 'none');

            window.addEventListener('click', (event) => {
                if (event.target === modal) modal.style.display = 'none';
            });

            // Department selection
            const deptSearch = document.getElementById('dept-search');
            const departmentItems = document.querySelectorAll('.department-item');
            const selectedDepartmentsContainer = document.getElementById('selected-departments');
            const selectedDepartmentsInput = document.getElementById('selected-departments-input');
            let selectedDepartments = new Set();

            deptSearch.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                departmentItems.forEach(item => {
                    const name = item.querySelector('span').textContent.toLowerCase();
                    item.style.display = name.includes(query) ? 'block' : 'none';
                });
            });

            departmentItems.forEach(item => {
                item.addEventListener('click', () => {
                    const deptId = item.getAttribute('data-id');
                    toggleSelection(deptId, selectedDepartments, item, 'department');
                });
            });

            function toggleSelection(id, set, item, type) {
                if (set.has(id)) {
                    set.delete(id);
                    item.classList.remove('selected');
                    selectedDepartmentsContainer.querySelector(`[data-${type}-id="${id}"]`)?.remove();
                } else {
                    set.add(id);
                    item.classList.add('selected');
                    const selectedItem = document.createElement('div');
                    selectedItem.className = `selected-${type}`;
                    selectedItem.setAttribute(`data-${type}-id`, id);
                    selectedItem.innerHTML = `${item.querySelector('span').textContent} <span class="remove-${type}">×</span>`;
                    selectedDepartmentsContainer.appendChild(selectedItem);

                    selectedItem.querySelector(`.remove-${type}`).addEventListener('click', () => {
                        set.delete(id);
                        item.classList.remove('selected');
                        selectedItem.remove();
                        updateSelectedInputs();
                    });
                }
                updateSelectedInputs();
            }

            function updateSelectedInputs() {
                selectedDepartmentsInput.value = Array.from(selectedDepartments).join(',');
            }

            function resetForm() {
                selectedDepartments.clear();
                departmentItems.forEach(item => item.classList.remove('selected'));
                selectedDepartmentsContainer.innerHTML = '';
                selectedDepartmentsInput.value = '';
                document.querySelector('form').reset();
                profilePicPreview.src = 'placeholder.jpg';
                croppedImageInput.value = '';
            }

            // Table filtering
            function filterTable(role) {
                for (let row of userTable) {
                    row.style.display = (role === 'all' || row.getAttribute('data-role') === role) ? '' : 'none';
                }
            }

            toggleAll.addEventListener('click', () => {
                filterTable('all');
                toggleAll.classList.add('active');
                toggleAdmins.classList.remove('active');
                toggleClients.classList.remove('active');
            });

            toggleAdmins.addEventListener('click', () => {
                filterTable('admin');
                toggleAll.classList.remove('active');
                toggleAdmins.classList.add('active');
                toggleClients.classList.remove('active');
            });

            toggleClients.addEventListener('click', () => {
                filterTable('client');
                toggleAll.classList.remove('active');
                toggleAdmins.classList.remove('active');
                toggleClients.classList.add('active');
            });

            // Sidebar toggle
            document.querySelector('.toggle-btn').addEventListener('click', () => {
                document.querySelector('.sidebar').classList.toggle('minimized');
                document.querySelector('.main-content').classList.toggle('sidebar-expanded');
                document.querySelector('.main-content').classList.toggle('sidebar-minimized');
            });

            // Edit user prefill
            <?php if (isset($_GET['edit'])): ?>
                modal.style.display = 'block';
                fetch(`get_user_data.php?user_id=<?php echo htmlspecialchars($_GET['edit'], ENT_QUOTES, 'UTF-8'); ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.querySelector('input[name="username"]').value = data.user.username;
                            document.querySelector('input[name="full_name"]').value = data.user.full_name;
                            document.querySelector('input[name="position"]').value = data.user.position;
                            document.querySelector('select[name="role"]').value = data.user.role;
                            if (data.user.profile_pic) profilePicPreview.src = data.user.profile_pic;

                            data.user.departments.forEach(dept => {
                                const item = document.querySelector(`.department-item[data-id="${dept.department_id}"]`);
                                if (item) {
                                    selectedDepartments.add(dept.department_id);
                                    item.classList.add('selected');
                                    const selectedItem = document.createElement('div');
                                    selectedItem.className = 'selected-department';
                                    selectedItem.setAttribute('data-department-id', dept.department_id);
                                    selectedItem.innerHTML = `${item.querySelector('span').textContent} <span class="remove-department">×</span>`;
                                    selectedDepartmentsContainer.appendChild(selectedItem);
                                    selectedItem.querySelector('.remove-department').addEventListener('click', () => {
                                        selectedDepartments.delete(dept.department_id);
                                        item.classList.remove('selected');
                                        selectedItem.remove();
                                        updateSelectedInputs();
                                    });
                                }
                            });

                            updateSelectedInputs();
                        }
                    });
            <?php endif; ?>
        </script>
</body>

</html>
?>