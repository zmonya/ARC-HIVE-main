<?php
session_start();
require 'db_connection.php';

// Security: Validate session and regenerate ID for security
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}
session_regenerate_id(true);

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Sanitize inputs
$userId = filter_var($userId, FILTER_SANITIZE_NUMBER_INT);

// Fetch user details including department and sub-department
$stmt = $pdo->prepare("
    SELECT users.*, 
           d.id AS department_id, 
           d.name AS department_name, 
           sd.id AS sub_department_id, 
           sd.name AS sub_department_name 
    FROM users 
    LEFT JOIN user_department_affiliations uda ON users.id = uda.user_id 
    LEFT JOIN departments d ON uda.department_id = d.id 
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id 
    WHERE users.id = ? 
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch all user department and sub-department affiliations
$stmt = $pdo->prepare("
    SELECT d.id AS department_id, 
           d.name AS department_name, 
           sd.id AS sub_department_id, 
           sd.name AS sub_department_name 
    FROM departments d 
    JOIN user_department_affiliations uda ON d.id = uda.department_id 
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id 
    WHERE uda.user_id = ?
");
$stmt->execute([$userId]);
$userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($userDepartments) && !empty($user['department_id'])) {
    $userDepartments = [[
        'department_id' => $user['department_id'],
        'department_name' => $user['department_name'],
        'sub_department_id' => $user['sub_department_id'],
        'sub_department_name' => $user['sub_department_name']
    ]];
}

// Fetch document types for sorting
$stmt = $pdo->prepare("SELECT name FROM document_types ORDER BY name ASC");
$stmt->execute();
$docTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent files, notifications, activity logs, and all files
$stmt = $pdo->prepare("SELECT * FROM files WHERE user_id = ? ORDER BY upload_date DESC LIMIT 5");
$stmt->execute([$userId]);
$recentFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY timestamp DESC LIMIT 5");
$stmt->execute([$userId]);
$notificationLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? ORDER BY timestamp DESC LIMIT 5");
$stmt->execute([$userId]);
$activityLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT files.*, document_types.name AS document_type 
    FROM files 
    LEFT JOIN document_types ON files.document_type_id = document_types.id 
    WHERE files.user_id = ? 
    ORDER BY files.upload_date DESC
");
$stmt->execute([$userId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files sent to the user
$stmt = $pdo->prepare("
    SELECT DISTINCT files.*, document_types.name AS document_type
    FROM files
    JOIN file_owners fo ON files.id = fo.file_id
    JOIN file_transfers ft ON files.id = ft.file_id
    LEFT JOIN document_types ON files.document_type_id = document_types.id
    WHERE fo.user_id = ? AND fo.ownership_type = 'co-owner'
    ORDER BY files.upload_date DESC
");
$stmt->execute([$userId]);
$filesSentToMe = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files uploaded by the user
$stmt = $pdo->prepare("
    SELECT files.*, document_types.name AS document_type
    FROM files
    JOIN file_owners fo ON files.id = fo.file_id
    LEFT JOIN document_types ON files.document_type_id = document_types.id
    WHERE fo.user_id = ? AND fo.ownership_type = 'original'
    ORDER BY files.upload_date DESC
");
$stmt->execute([$userId]);
$filesUploaded = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch files requested by the user
$stmt = $pdo->prepare("
    SELECT files.*, document_types.name AS document_type
    FROM files
    JOIN access_requests ar ON files.id = ar.file_id
    LEFT JOIN document_types ON files.document_type_id = document_types.id
    WHERE ar.requester_id = ? AND ar.status = 'pending'
    ORDER BY ar.time_requested DESC
");
$stmt->execute([$userId]);
$filesRequested = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch department and sub-department files
$departmentFiles = [];
foreach ($userDepartments as $dept) {
    $deptId = $dept['department_id'];
    $subDeptId = $dept['sub_department_id'];

    // Department-wide files
    $stmt = $pdo->prepare("
        SELECT files.*, document_types.name AS document_type
        FROM files
        JOIN file_transfers ft ON files.id = ft.file_id
        LEFT JOIN document_types ON files.document_type_id = document_types.id
        WHERE ft.department_id = ? AND ft.status = 'accepted'
        ORDER BY files.upload_date DESC
    ");
    $stmt->execute([$deptId]);
    $departmentFiles[$deptId]['department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sub-department files
    if ($subDeptId) {
        $stmt = $pdo->prepare("
            SELECT files.*, document_types.name AS document_type
            FROM files
            JOIN file_transfers ft ON files.id = ft.file_id
            JOIN file_metadata fm ON files.id = fm.file_id
            LEFT JOIN document_types ON files.document_type_id = document_types.id
            WHERE fm.meta_key = 'sub_department_id' AND fm.meta_value = ?
            ORDER BY files.upload_date DESC
        ");
        $stmt->execute([$subDeptId]);
        $departmentFiles[$deptId]['sub_department'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function getFileIcon($fileName)
{
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    switch ($extension) {
        case 'pdf':
            return 'fas fa-file-pdf';
        case 'doc':
        case 'docx':
            return 'fas fa-file-word';
        case 'xls':
        case 'xlsx':
            return 'fas fa-file-excel';
        case 'jpg':
        case 'png':
            return 'fas fa-file-image';
        default:
            return 'fas fa-file';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="X-XSS-Protection" content="1; mode=block">
    <title>Dashboard - Document Archival</title>
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <link rel="stylesheet" href="style/Dashboard.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <style>
        /* Fix for Select2 dropdown z-index */
        .select2-container--open .select2-dropdown {
            z-index: 3000 !important;
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn" data-tooltip="Admin Dashboard"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" data-tooltip="Dashboard"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="my-report.php" data-tooltip="My Report"><i class="fas fa-chart-bar"></i><span class="link-text">My Report</span></a>
        <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>" data-tooltip="My Folder"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <?php if (!empty($userDepartments)): ?>
            <?php foreach ($userDepartments as $dept): ?>
                <a href="department_folder.php?department_id=<?= htmlspecialchars($dept['department_id']) ?>"
                    class="<?= isset($_GET['department_id']) && $_GET['department_id'] == $dept['department_id'] ? 'active' : '' ?>"
                    data-tooltip="<?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?>">
                    <i class="fas fa-folder"></i>
                    <span class="link-text"><?= htmlspecialchars($dept['department_name'] ?? 'Unnamed Department') ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
        <a href="logout.php" class="logout-btn" data-tooltip="Logout"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="top-nav">
        <h2>Dashboard</h2>
        <form action="search.php" method="GET" class="search-container" id="search-form">
            <input type="text" id="searchInput" name="q" placeholder="Search documents..." value="<?= htmlspecialchars($searchQuery ?? '') ?>">
            <select name="type" id="document-type">
                <option value="">All Document Types</option>
                <?php foreach ($docTypes as $type): ?>
                    <option value="<?= htmlspecialchars($type['name']) ?>" <?= ($documentType ?? '') === $type['name'] ? 'selected' : '' ?>><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="folder" id="folder">
                <option value="">All Folders</option>
                <option value="my-folder" <?= ($folderFilter ?? '') === 'my-folder' ? 'selected' : '' ?>>My Folder</option>
                <?php if (!empty($userDepartments)): ?>
                    <?php foreach ($userDepartments as $dept): ?>
                        <option value="department-<?= $dept['department_id'] ?>" <?= ($folderFilter ?? '') === 'department-' . $dept['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($dept['department_name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <button type="submit" aria-label="Search Documents"><i class="fas fa-search"></i></button>
        </form>
        <i class="fas fa-history activity-log-icon" onclick="toggleActivityLog()" title="View Activity Log" aria-label="View Activity Log"></i>
    </div>

    <div class="main-content">
        <div class="user-id-calendar-container">
            <div class="user-id">
                <img src="<?php echo htmlspecialchars($user['profile_pic'] ?? 'user.jpg'); ?>" alt="User Picture" class="user-picture">
                <div class="user-info">
                    <p class="user-name"><?= htmlspecialchars($user['full_name']) ?></p>
                    <p class="user-position"><?= htmlspecialchars($user['position']) ?></p>
                    <p class="user-department"><?= htmlspecialchars($user['department_name'] ?? 'No Department') ?></p>
                </div>
            </div>
            <div class="digital-calendar-clock">
                <p id="currentDate"></p>
                <p id="currentTime"></p>
            </div>
        </div>

        <div class="upload-activity-container">
            <div class="upload-file" id="upload">
                <h3>Send a Document</h3>
                <button id="selectDocumentButton">Select Document</button>
            </div>
            <div class="upload-file" id="fileUpload">
                <h3>Upload File</h3>
                <button id="uploadFileButton">Upload File</button>
            </div>
            <div class="notification-log">
                <h3>Notifications</h3>
                <div class="log-entries">
                    <?php if (!empty($notificationLogs)): ?>
                        <?php foreach ($notificationLogs as $notification): ?>
                            <div class="log-entry notification-item <?= $notification['type'] === 'access_request' && $notification['status'] === 'pending' ? 'pending-access' : ($notification['type'] === 'received' && $notification['status'] === 'pending' ? 'received-pending' : '') ?>"
                                data-notification-id="<?= htmlspecialchars($notification['id']) ?>"
                                data-file-id="<?= htmlspecialchars($notification['file_id']) ?>"
                                data-message="<?= htmlspecialchars($notification['message']) ?>"
                                data-type="<?= htmlspecialchars($notification['type']) ?>"
                                data-status="<?= htmlspecialchars($notification['status']) ?>">
                                <i class="fas fa-bell"></i>
                                <p><?= htmlspecialchars($notification['message']) ?></p>
                                <span><?= date('h:i A', strtotime($notification['timestamp'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="log-entry">
                            <p>No new notifications.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="owned-files-section">
            <h2>My Files</h2>

            <div class="files-grid">
                <div class="file-subsection">
                    <h3>My Documents</h3>
                    <div class="file-controls">
                        <div class="sort-controls">
                            <label>Sort by Name:</label>
                            <select class="sort-personal-name" onchange="sortPersonalFiles()">
                                <option value="">Select</option>
                                <option value="name-asc">A-Z</option>
                                <option value="name-desc">Z-A</option>
                            </select>
                            <label>Sort by Document Type:</label>
                            <select class="sort-personal-type" onchange="sortPersonalFiles()">
                                <option value="">Select</option>
                                <?php foreach ($docTypes as $type): ?>
                                    <option value="type-<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Sort by Source:</label>
                            <select class="sort-personal-source" onchange="sortPersonalFiles()">
                                <option value="">Select</option>
                                <option value="uploaded">Uploaded Files</option>
                                <option value="received">Files Received</option>
                                <option value="requested">Files Requested</option>
                            </select>
                        </div>
                        <div class="filter-controls">
                            <label><input type="checkbox" id="hardCopyPersonalFilter" onchange="filterPersonalFilesByHardCopy()"> Hard Copy Only</label>
                        </div>
                    </div>

                    <div class="file-grid" id="personalFiles">
                        <?php if (!empty($filesUploaded) || !empty($filesSentToMe) || !empty($filesRequested)): ?>
                            <?php foreach (array_merge($filesUploaded, $filesSentToMe, $filesRequested) as $file): ?>
                                <div class="file-item"
                                    data-file-id="<?= htmlspecialchars($file['id']) ?>"
                                    data-file-name="<?= htmlspecialchars($file['file_name']) ?>"
                                    data-document-type="<?= htmlspecialchars($file['document_type']) ?>"
                                    data-upload-date="<?= $file['upload_date'] ?>"
                                    data-hard-copy="<?= $file['hard_copy_available'] ?>"
                                    data-source="<?= in_array($file, $filesUploaded) ? 'uploaded' : (in_array($file, $filesSentToMe) ? 'received' : 'requested') ?>">
                                    <i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i>
                                    <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                    <p class="file-type"><?= htmlspecialchars(ucfirst($file['document_type'])) ?></p>
                                    <p class="file-date"><?= date('M d, Y', strtotime($file['upload_date'])) ?></p>
                                    <span class="hard-copy-indicator"><?= $file['hard_copy_available'] ? '<i class="fas fa-print"></i> Hard Copy' : '' ?></span>
                                    <button class="view-file-button" onclick="viewFile(<?= htmlspecialchars($file['id']) ?>)">View</button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No personal files available.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($userDepartments as $dept): ?>
                    <div class="file-subsection">
                        <h3><?= htmlspecialchars($dept['department_name']) ?> Documents</h3>
                        <div class="file-controls">
                            <div class="sort-controls">
                                <label>Sort by Name:</label>
                                <select class="sort-department-name" data-dept-id="<?= $dept['department_id'] ?>" onchange="sortDepartmentFiles(<?= $dept['department_id'] ?>)">
                                    <option value="">Select</option>
                                    <option value="name-asc">A-Z</option>
                                    <option value="name-desc">Z-A</option>
                                </select>
                                <label>Sort by Document Type:</label>
                                <select class="sort-department-type" data-dept-id="<?= $dept['department_id'] ?>" onchange="sortDepartmentFiles(<?= $dept['department_id'] ?>)">
                                    <option value="">Select</option>
                                    <?php foreach ($docTypes as $type): ?>
                                        <option value="type-<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Sort by Scope:</label>
                                <select class="sort-department-scope" data-dept-id="<?= $dept['department_id'] ?>" onchange="sortDepartmentFiles(<?= $dept['department_id'] ?>)">
                                    <option value="">Select</option>
                                    <option value="department">Department-Wide Files</option>
                                    <?php if ($dept['sub_department_id']): ?>
                                        <option value="sub-department"><?= htmlspecialchars($dept['sub_department_name']) ?> Files</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="filter-controls">
                                <label><input type="checkbox" class="hard-copy-department-filter" data-dept-id="<?= $dept['department_id'] ?>" onchange="filterDepartmentFilesByHardCopy(<?= $dept['department_id'] ?>)"> Hard Copy Only</label>
                            </div>
                        </div>
                        <div class="file-grid department-files-grid" id="departmentFiles-<?= $dept['department_id'] ?>">
                            <?php
                            $deptFiles = array_merge(
                                $departmentFiles[$dept['department_id']]['department'] ?? [],
                                $departmentFiles[$dept['department_id']]['sub_department'] ?? []
                            );
                            if (!empty($deptFiles)): ?>
                                <?php foreach ($deptFiles as $file): ?>
                                    <div class="file-item"
                                        data-file-id="<?= htmlspecialchars($file['id']) ?>"
                                        data-file-name="<?= htmlspecialchars($file['file_name']) ?>"
                                        data-document-type="<?= htmlspecialchars($file['document_type']) ?>"
                                        data-upload-date="<?= $file['upload_date'] ?>"
                                        data-hard-copy="<?= $file['hard_copy_available'] ?>"
                                        data-source="<?= in_array($file, $departmentFiles[$dept['department_id']]['department'] ?? []) ? 'department' : 'sub-department' ?>">
                                        <i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i>
                                        <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                                        <p class="file-type"><?= htmlspecialchars(ucfirst($file['document_type'])) ?></p>
                                        <p class="file-date"><?= date('M d, Y', strtotime($file['upload_date'])) ?></p>
                                        <span class="hard-copy-indicator"><?= $file['hard_copy_available'] ? '<i class="fas fa-print"></i> Hard Copy' : '' ?></span>
                                        <button class="view-file-button" onclick="viewFile(<?= htmlspecialchars($file['id']) ?>)">View</button>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No files available for this department.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Existing Popups -->
        <div class="popup-file-selection" id="fileSelectionPopup">
            <button class="exit-button" onclick="closePopup('fileSelectionPopup')" aria-label="Close Popup">×</button>
            <h3>Select a Document</h3>
            <div class="search-container">
                <input type="text" id="fileSearch" placeholder="Search files..." oninput="filterFiles()">
                <select id="documentTypeFilter" onchange="filterFilesByType()">
                    <option value="">All Document Types</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="view-toggle">
                <button id="thumbnailViewButton" class="active" onclick="switchView('thumbnail')"><i class="fas fa-th-large"></i> Thumbnails</button>
                <button id="listViewButton" onclick="switchView('list')"><i class="fas fa-list"></i> List</button>
            </div>
            <div id="fileDisplay" class="thumbnail-view masonry-grid">
                <?php foreach ($files as $file): ?>
                    <div class="file-item" data-file-id="<?= htmlspecialchars($file['id']) ?>"
                        data-file-name="<?= htmlspecialchars($file['file_name']) ?>"
                        data-document-type="<?= htmlspecialchars($file['document_type']) ?>">
                        <div class="file-icon"><i class="<?= getFileIcon($file['file_name']) ?>"></i></div>
                        <p><?= htmlspecialchars($file['file_name']) ?></p>
                        <button class="select-file-button">Select</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="popup-questionnaire" id="fileDetailsPopup">
            <button class="exit-button" onclick="closePopup('fileDetailsPopup')" aria-label="Close Popup">×</button>
            <h3>Upload File Details</h3>
            <p class="subtitle">Provide details for the document you're uploading.</p>
            <form id="fileDetailsForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="departmentId">Department:</label>
                <select id="departmentId" name="department_id">
                    <option value="">No Department</option>
                    <?php foreach ($userDepartments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept['department_id']) ?>" <?= $dept['department_id'] == $user['department_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="subDepartmentId">Sub-Department (Optional):</label>
                <select id="subDepartmentId" name="sub_department_id">
                    <option value="">No Sub-Department</option>
                    <?php if (!empty($user['sub_department_id'])): ?>
                        <option value="<?= htmlspecialchars($user['sub_department_id']) ?>" selected>
                            <?= htmlspecialchars($user['sub_department_name']) ?>
                        </option>
                    <?php endif; ?>
                </select>
                <label for="documentType">Document Type:</label>
                <select id="documentType" name="document_type" required>
                    <option value="">Select Document Type</option>
                    <?php foreach ($docTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type['name']) ?>"><?= htmlspecialchars(ucfirst($type['name'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="dynamicFields"></div>
                <div class="button-group">
                    <button type="button" class="btn-back" onclick="closePopup('fileDetailsPopup')">Cancel</button>
                    <button type="button" class="btn-next" onclick="proceedToHardcopy()">Next</button>
                </div>
            </form>
        </div>

        <div class="popup-questionnaire" id="sendFilePopup">
            <button class="exit-button" onclick="closePopup('sendFilePopup')" aria-label="Close Popup">×</button>
            <h3>Send File</h3>
            <form id="sendFileForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <label for="recipients">Recipients (Users or Departments):</label>
                <select id="recipientSelect" name="recipients[]" multiple style="width: 100%;">
                    <optgroup label="Users">
                        <?php
                        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != ?");
                        $stmt->execute([$userId]);
                        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($users as $userOption): ?>
                            <option value="user:<?= htmlspecialchars($userOption['id']) ?>"><?= htmlspecialchars($userOption['username']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Departments">
                        <?php
                        $stmt = $pdo->prepare("SELECT id, name FROM departments");
                        $stmt->execute();
                        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($departments as $dept): ?>
                            <option value="department:<?= htmlspecialchars($dept['id']) ?>"><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <div class="button-group">
                    <button type="button" class="btn-back" onclick="closePopup('sendFilePopup')">Cancel</button>
                    <button type="button" class="btn-next" onclick="sendFile()">Send</button>
                </div>
            </form>
        </div>

        <div class="popup-questionnaire" id="hardcopyStoragePopup">
            <button class="exit-button" onclick="closePopup('hardcopyStoragePopup')" aria-label="Close Popup">×</button>
            <h3>Hardcopy Storage</h3>
            <p class="subtitle">Specify if this document has a physical copy and how to manage it.</p>
            <div class="hardcopy-options">
                <label class="checkbox-container">
                    <input type="checkbox" id="hardcopyCheckbox" name="hard_copy_available">
                    <span class="checkmark"></span>
                    This file has a hardcopy
                </label>
                <div class="radio-group" id="hardcopyOptions" style="display: none;">
                    <label class="radio-container">
                        <input type="radio" name="hardcopyOption" value="link" checked>
                        <span class="radio-checkmark"></span>
                        Link to existing hardcopy
                    </label>
                    <label class="radio-container">
                        <input type="radio" name="hardcopyOption" value="new">
                        <span class="radio-checkmark"></span>
                        Suggest new storage location
                    </label>
                    <div class="storage-suggestion" id="storageSuggestion"></div>
                </div>
            </div>
            <div class="button-group">
                <button class="btn-back" onclick="handleHardcopyBack()">Back</button>
                <button class="btn-next" onclick="handleHardcopyNext()">Next</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="linkHardcopyPopup">
            <button class="exit-button" onclick="closePopup('linkHardcopyPopup')" aria-label="Close Popup">×</button>
            <h3>Link to Existing Hardcopy</h3>
            <div class="search-container">
                <input type="text" id="hardcopySearch" placeholder="Search hardcopy files..." oninput="filterHardcopies()">
            </div>
            <div class="file-list" id="hardcopyList"></div>
            <div class="button-group">
                <button class="btn-back" onclick="closePopup('linkHardcopyPopup')">Cancel</button>
                <button id="linkHardcopyButton" class="btn-next" disabled onclick="linkHardcopy()">Link</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="fileAcceptancePopup">
            <button class="exit-button" onclick="closePopup('fileAcceptancePopup')" aria-label="Close Popup">×</button>
            <h3 id="fileAcceptanceTitle">Review File</h3>
            <p id="fileAcceptanceMessage"></p>
            <div class="file-preview" id="filePreview"></div>
            <div class="button-group">
                <button id="acceptFileButton">Accept</button>
                <button id="denyFileButton">Deny</button>
            </div>
        </div>

        <div class="popup-questionnaire" id="alreadyProcessedPopup">
            <button class="exit-button" onclick="closePopup('alreadyProcessedPopup')" aria-label="Close Popup">×</button>
            <h3>Request Status</h3>
            <p id="alreadyProcessedMessage"></p>
        </div>
    </div>

    <div class="activity-log" id="activityLog" style="display: none;">
        <h3>Activity Log</h3>
        <div class="log-entries">
            <?php if (!empty($activityLogs)): ?>
                <?php foreach ($activityLogs as $log): ?>
                    <div class="log-entry"><i class="fas fa-history"></i>
                        <p><?= htmlspecialchars($log['action']) ?></p><span><?= date('h:i A', strtotime($log['timestamp'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="log-entry">
                    <p>No recent activity.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const notyf = new Notyf();
        let selectedFile = null;
        let selectedHardcopyId = null;

        $(document).ready(function() {
            $('#recipientSelect').select2({
                placeholder: "Select users or departments",
                allowClear: true,
                dropdownCssClass: 'select2-high-zindex' // Custom class for styling
            });

            $('.toggle-btn').on('click', function() {
                $('.sidebar').toggleClass('minimized');
                $('.top-nav').toggleClass('resized', $('.sidebar').hasClass('minimized'));
                $('.main-content').toggleClass('resized', $('.sidebar').hasClass('minimized'));
            });

            function updateDateTime() {
                const now = new Date();
                const options = {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                };
                $('#currentDate').text(now.toLocaleDateString('en-US', options));
                $('#currentTime').text(now.toLocaleTimeString('en-US'));
            }
            setInterval(updateDateTime, 1000);
            updateDateTime();

            $('#selectDocumentButton').on('click', function() {
                $('#fileSelectionPopup').show();
            });

            $('#uploadFileButton').on('click', function() {
                const fileInput = $('<input type="file" id="fileInput" style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png,.txt,.zip">');
                $('body').append(fileInput);
                fileInput.trigger('click');
                fileInput.on('change', function() {
                    const file = this.files[0];
                    if (file) {
                        if (file.size > 10 * 1024 * 1024) { // 10MB limit
                            notyf.error('File size exceeds 10MB.');
                            return;
                        }
                        selectedFile = file;
                        $('#fileDetailsPopup').show();
                    }
                    fileInput.remove();
                });
            });

            $("#searchInput").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: "autocomplete.php",
                        dataType: "json",
                        data: {
                            term: request.term,
                            csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                        },
                        success: function(data) {
                            response(data);
                        },
                        error: function() {
                            notyf.error('Error fetching autocomplete suggestions.');
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#searchInput").val(ui.item.value);
                    if (ui.item.document_type) $("#document-type").val(ui.item.document_type.toLowerCase());
                    if (ui.item.department_id) $("#folder").val("department-" + ui.item.department_id);
                    $("#search-form").submit();
                }
            });

            fetchNotifications();
            setInterval(fetchNotifications, 5000);
            setInterval(fetchAccessNotifications, 5000);

            $('#hardcopyCheckbox').on('change', function() {
                $('#hardcopyOptions').toggle(this.checked);
                if (!this.checked) {
                    $('#storageSuggestion').hide().empty();
                } else if ($('input[name="hardcopyOption"]:checked').val() === 'new') {
                    fetchStorageSuggestion();
                }
            });

            $('input[name="hardcopyOption"]').on('change', function() {
                if (this.value === 'new') {
                    fetchStorageSuggestion();
                } else {
                    $('#storageSuggestion').hide().empty();
                }
            });

            function loadSubDepartments(departmentId, selectedSubDeptId = null) {
                const subDeptSelect = $('#subDepartmentId');
                subDeptSelect.empty().append('<option value="">No Sub-Department</option>');

                if (!departmentId) {
                    notyf.error('No department selected.');
                    return;
                }

                $.ajax({
                    url: 'get_sub_departments.php',
                    method: 'POST',
                    data: {
                        department_id: departmentId,
                        csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                    },
                    dataType: 'json',
                    beforeSend: function() {
                        subDeptSelect.prop('disabled', true).append('<option value="">Loading...</option>');
                    },
                    success: function(data) {
                        subDeptSelect.prop('disabled', false).find('option[value=""]').remove(); // Remove "Loading..." option
                        if (data.success && Array.isArray(data.sub_departments) && data.sub_departments.length > 0) {
                            data.sub_departments.forEach(subDept => {
                                const isSelected = subDept.id == selectedSubDeptId ? 'selected' : '';
                                subDeptSelect.append(`<option value="${subDept.id}" ${isSelected}>${subDept.name}</option>`);
                            });
                        } else {
                            notyf.error(data.message || 'No sub-departments found for this department.');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        subDeptSelect.prop('disabled', false).find('option[value=""]').remove(); // Remove "Loading..." option
                        console.error('AJAX Error:', {
                            status: jqXHR.status,
                            statusText: textStatus,
                            errorThrown: errorThrown,
                            responseText: jqXHR.responseText
                        });
                        notyf.error('Failed to load sub-departments. Check console for details.');
                    }
                });
            }

            // Load sub-departments on page load if a department is pre-selected
            const initialDeptId = $('#departmentId').val();
            const initialSubDeptId = <?= json_encode($user['sub_department_id'] ?? null) ?>;
            if (initialDeptId) {
                loadSubDepartments(initialDeptId, initialSubDeptId);
            }

            // Reload sub-departments when department changes
            $('#departmentId').on('change', function() {
                const departmentId = $(this).val();
                loadSubDepartments(departmentId);
            });

            $('#documentType').on('change', function() {
                const docTypeName = $(this).val();
                const dynamicFields = $('#dynamicFields');
                dynamicFields.empty();

                if (docTypeName) {
                    $.ajax({
                        url: 'get_document_type_field.php',
                        method: 'POST',
                        data: {
                            document_type_name: docTypeName,
                            csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (data.success && Array.isArray(data.fields) && data.fields.length > 0) {
                                data.fields.forEach(field => {
                                    const requiredAttr = field.is_required ? 'required' : '';
                                    let inputField = '';
                                    switch (field.field_type) {
                                        case 'text':
                                            inputField = `<input type="text" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                        case 'textarea':
                                            inputField = `<textarea id="${field.field_name}" name="${field.field_name}" ${requiredAttr}></textarea>`;
                                            break;
                                        case 'date':
                                            inputField = `<input type="date" id="${field.field_name}" name="${field.field_name}" ${requiredAttr}>`;
                                            break;
                                    }
                                    dynamicFields.append(`
                            <label for="${field.field_name}">${field.field_label}${field.is_required ? ' *' : ''}:</label>
                            ${inputField}
                        `);
                                });
                            } else if (data.success && data.fields.length === 0) {
                                dynamicFields.append('<p>No metadata fields defined for this document type. Contact an administrator to add fields.</p>');
                            } else {
                                dynamicFields.append(`<p>Error: ${data.message || 'Failed to load metadata fields.'}</p>`);
                                notyf.error(data.message || 'Failed to load metadata fields.');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error('AJAX error:', textStatus, errorThrown, jqXHR.responseText);
                            dynamicFields.append('<p>Error: Failed to load metadata fields. Please try again or contact support.</p>');
                            notyf.error('Failed to load metadata fields. Check console for details.');
                        }
                    });
                } else {
                    dynamicFields.append('<p>Please select a document type.</p>');
                }
            });

            $(document).on('click', '.select-file-button', function() {
                $('.file-item').removeClass('selected');
                const $fileItem = $(this).closest('.file-item');
                $fileItem.addClass('selected');
                const fileId = $fileItem.data('file-id');
                $('#sendFilePopup').data('selected-file-id', fileId);
                $('#fileSelectionPopup').hide();
                $('#sendFilePopup').show();
            });
        });

        function fetchNotifications() {
            $.ajax({
                url: 'fetch_notifications.php',
                method: 'POST',
                data: {
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    const notificationContainer = $('.notification-log .log-entries');
                    if (Array.isArray(data) && data.length > 0) {
                        const currentNotifications = notificationContainer.find('.notification-item').map(function() {
                            return $(this).data('notification-id');
                        }).get();
                        const newNotifications = data.map(n => n.id);

                        if (JSON.stringify(currentNotifications) !== JSON.stringify(newNotifications)) {
                            notificationContainer.empty();
                            data.forEach(notification => {
                                const notificationClass = notification.type === 'access_request' && notification.status === 'pending' ?
                                    'pending-access' :
                                    (notification.type === 'received' && notification.status === 'pending' ? 'received-pending' : 'processed-access');
                                notificationContainer.append(`
                            <div class="log-entry notification-item ${notificationClass}"
                                data-notification-id="${notification.id}"
                                data-file-id="${notification.file_id}"
                                data-message="${notification.message}"
                                data-type="${notification.type}"
                                data-status="${notification.status}">
                                <i class="fas fa-bell"></i>
                                <p>${notification.message}</p>
                                <span>${new Date(notification.timestamp).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</span>
                            </div>
                        `);
                            });
                        }
                    } else if (Array.isArray(data) && data.length === 0 && notificationContainer.find('.notification-item').length === 0) {
                        notificationContainer.empty().append('<div class="log-entry"><p>No new notifications.</p></div>');
                    }
                },
                error: function() {
                    notyf.error('Failed to fetch notifications.');
                }
            });
        }

        function fetchAccessNotifications() {
            // Placeholder for fetching access notifications
        }

        $(document).on('click', '.notification-item', function() {
            const type = $(this).data('type');
            const status = $(this).data('status');
            const fileId = $(this).data('file-id');
            const notificationId = $(this).data('notification-id');
            const message = $(this).data('message');

            if (status !== 'pending') {
                $('#alreadyProcessedMessage').text('This request has already been processed.');
                $('#alreadyProcessedPopup').show();
                return;
            }

            if (type === 'received' || type === 'access_request') {
                $('#fileAcceptanceTitle').text('Review ' + (type === 'received' ? 'Received File' : 'Access Request'));
                $('#fileAcceptanceMessage').text(message);
                $('#fileAcceptancePopup').data('notification-id', notificationId).data('file-id', fileId).show();
                showFilePreview(fileId);
            } else {
                $('#alreadyProcessedMessage').text('This request has already been processed.');
                $('#alreadyProcessedPopup').show();
            }
        });

        function showFilePreview(fileId) {
            $.ajax({
                url: 'get_file_preview.php',
                method: 'GET',
                data: {
                    file_id: fileId
                },
                success: function(data) {
                    $('#filePreview').html(data);
                },
                error: function() {
                    $('#filePreview').html('<p>Unable to load preview.</p>');
                }
            });
        }

        $('#acceptFileButton').on('click', function() {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            handleFileAction(notificationId, fileId, 'accept');
        });

        $('#denyFileButton').on('click', function() {
            const notificationId = $('#fileAcceptancePopup').data('notification-id');
            const fileId = $('#fileAcceptancePopup').data('file-id');
            handleFileAction(notificationId, fileId, 'deny');
        });

        function handleFileAction(notificationId, fileId, action) {
            $.ajax({
                url: 'handle_file_acceptance.php',
                method: 'POST',
                data: {
                    notification_id: notificationId,
                    file_id: fileId,
                    action: action,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#fileAcceptancePopup').hide();
                        $('.notification-item[data-notification-id="' + notificationId + '"]').removeClass('pending-access received-pending')
                            .addClass('processed-access')
                            .off('click')
                            .find('p').text(response.message + ' (Processed)');
                        fetchNotifications();
                    } else {
                        notyf.error(response.message);
                    }
                },
                error: function() {
                    notyf.error('Error processing file action.');
                }
            });
        }

        function closePopup(popupId) {
            $(`#${popupId}`).hide();
            if (popupId === 'sendFilePopup') {
                $('.file-item').removeClass('selected');
                $('#sendFilePopup').removeData('selected-file-id');
            }
            if (popupId === 'fileDetailsPopup') selectedFile = null;
            if (popupId === 'hardcopyStoragePopup') $('#storageSuggestion').empty();
        }

        function toggleActivityLog() {
            $('#activityLog').toggle();
        }

        $(document).on('click', function(event) {
            if (!$(event.target).closest('.activity-log, .activity-log-icon').length) {
                $('#activityLog').hide();
            }
        });

        function proceedToHardcopy() {
            const documentType = $('#documentType').val();
            if (!documentType) {
                notyf.error('Please select a document type.');
                return;
            }
            const departmentId = $('#departmentId').val();
            $('#fileDetailsPopup').hide();
            if (departmentId) {
                $('#hardcopyStoragePopup').show();
                if ($('#hardcopyCheckbox').is(':checked') && $('input[name="hardcopyOption"]:checked').val() === 'new') {
                    fetchStorageSuggestion();
                }
            } else {
                uploadFile();
            }
        }

        function handleHardcopyBack() {
            $('#hardcopyStoragePopup').hide();
            $('#fileDetailsPopup').show();
        }

        function handleHardcopyNext() {
            const hardcopyAvailable = $('#hardcopyCheckbox').is(':checked');
            if (hardcopyAvailable && $('input[name="hardcopyOption"]:checked').val() === 'link') {
                $('#hardcopyStoragePopup').hide();
                $('#linkHardcopyPopup').show();
                fetchHardcopyFiles();
            } else {
                uploadFile();
            }
        }

        function fetchHardcopyFiles() {
            $.ajax({
                url: 'fetch_hardcopy_files.php',
                method: 'POST',
                data: {
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    const hardcopyList = $('#hardcopyList');
                    hardcopyList.empty();
                    data.forEach(file => {
                        hardcopyList.append(`
                    <div class="file-item" data-file-id="${file.id}">
                        <input type="radio" name="hardcopyFile" value="${file.id}">
                        <span>${file.file_name}</span>
                    </div>
                `);
                    });
                    hardcopyList.find('input').on('change', function() {
                        selectedHardcopyId = $(this).val();
                        $('#linkHardcopyButton').prop('disabled', false);
                    });
                },
                error: function() {
                    notyf.error('Failed to fetch hardcopy files.');
                }
            });
        }

        function filterHardcopies() {
            const searchTerm = $('#hardcopySearch').val().toLowerCase();
            $('#hardcopyList .file-item').each(function() {
                const fileName = $(this).find('span').text().toLowerCase();
                $(this).toggle(fileName.includes(searchTerm));
            });
        }

        function linkHardcopy() {
            if (!selectedHardcopyId) {
                notyf.error('Please select a hardcopy to link.');
                return;
            }
            uploadFile();
        }

        function fetchStorageSuggestion() {
            const departmentId = $('#departmentId').val();
            const subDepartmentId = $('#subDepartmentId').val() || null;
            if (!departmentId) {
                $('#storageSuggestion').html('<p>No department selected.</p>').show();
                return;
            }
            $.ajax({
                url: 'get_storage_suggestions.php',
                method: 'POST',
                data: {
                    department_id: departmentId,
                    sub_department_id: subDepartmentId,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        $('#storageSuggestion').html(`<p>Suggested Location: ${data.suggestion}</p><span>Based on department/sub-department selection</span>`).show();
                    } else {
                        $('#storageSuggestion').html(`<p>${data.suggestion || 'No suggestion available'}</p>`).show();
                    }
                },
                error: function() {
                    $('#storageSuggestion').html('<p>Failed to fetch suggestion.</p>').show();
                }
            });
        }

        function uploadFile() {
            const documentType = $('#documentType').val();
            const departmentId = $('#departmentId').val() || null;
            const subDepartmentId = $('#subDepartmentId').val() || null;
            const hardcopyAvailable = $('#hardcopyCheckbox').is(':checked');
            const hardcopyOption = hardcopyAvailable ? $('input[name="hardcopyOption"]:checked').val() : null;

            if (!selectedFile) {
                notyf.error('No file selected.');
                return;
            }

            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('document_type', documentType);
            formData.append('user_id', '<?= htmlspecialchars($userId) ?>'); // Ensure user_id is sent
            formData.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
            if (departmentId) formData.append('department_id', departmentId);
            if (subDepartmentId) formData.append('sub_department_id', subDepartmentId);
            formData.append('hard_copy_available', hardcopyAvailable ? 1 : 0);
            if (hardcopyAvailable && hardcopyOption === 'link' && selectedHardcopyId) {
                formData.append('link_hardcopy_id', selectedHardcopyId);
            } else if (hardcopyAvailable && hardcopyOption === 'new') {
                formData.append('new_storage', 1);
                if (window.storageMetadata) {
                    formData.append('storage_metadata', JSON.stringify(window.storageMetadata));
                }
            }

            $('#fileDetailsForm').find('input, textarea, select').each(function() {
                const name = $(this).attr('name');
                const value = $(this).val();
                if (name && value && name !== 'department_id' && name !== 'document_type' && name !== 'sub_department_id' && name !== 'csrf_token') {
                    formData.append(name, value);
                }
            });

            $.ajax({
                url: 'upload_handler.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    const xhr = new XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            notyf.open({
                                type: 'info',
                                message: `Uploading: ${percent}%`,
                                duration: 0
                            });
                        }
                    }, false);
                    return xhr;
                },
                success: function(data) {
                    try {
                        const response = typeof data === 'string' ? JSON.parse(data) : data;
                        if (response.success) {
                            notyf.success(response.message);
                            $('#hardcopyStoragePopup').hide();
                            $('#linkHardcopyPopup').hide();
                            selectedFile = null;
                            selectedHardcopyId = null;
                            window.storageMetadata = null;
                            window.location.href = response.redirect || 'my-folder.php';
                        } else {
                            notyf.error(response.message || 'Failed to upload file.');
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e, data);
                        notyf.error('Invalid server response.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error("Upload error:", textStatus, errorThrown, jqXHR.responseText);
                    notyf.error('An error occurred while uploading the file.');
                }
            });
        }

        function sendFile() {
            const recipients = $('#recipientSelect').val();
            if (!recipients || recipients.length === 0) {
                notyf.error('Please select at least one recipient.');
                return;
            }

            const fileId = $('.file-item.selected').data('file-id') || $('#sendFilePopup').data('selected-file-id');
            if (!fileId) {
                notyf.error('No file selected to send.');
                return;
            }

            $.ajax({
                url: 'send_file_handler.php',
                method: 'POST',
                data: {
                    file_id: fileId,
                    recipients: recipients,
                    csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token']) ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        notyf.success(response.message);
                        $('#sendFilePopup').hide();
                        $('.file-item').removeClass('selected');
                        $('#sendFilePopup').removeData('selected-file-id');
                    } else {
                        notyf.error(response.message || 'Error sending file.');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    notyf.error('Error sending file: ' + textStatus);
                }
            });
        }

        function viewFile(fileId) {
            showFilePreview(fileId);
            $('#fileAcceptancePopup').data('file-id', fileId).show();
            $('#fileAcceptanceTitle').text('View File');
            $('#fileAcceptanceMessage').text('Previewing file.');
            $('#acceptFileButton').hide();
            $('#denyFileButton').hide();
        }

        function sortPersonalFiles() {
            const nameSort = $('.sort-personal-name').val();
            const typeSort = $('.sort-personal-type').val();
            const sourceSort = $('.sort-personal-source').val();
            const $container = $('#personalFiles');
            const $items = $container.find('.file-item').get();

            $items.sort(function(a, b) {
                const aData = $(a).data();
                const bData = $(b).data();

                // Prioritize sorting by name
                if (nameSort) {
                    if (nameSort === 'name-asc') {
                        return aData.fileName.localeCompare(bData.fileName);
                    } else if (nameSort === 'name-desc') {
                        return bData.fileName.localeCompare(aData.fileName);
                    }
                }

                // Then by document type
                if (typeSort) {
                    const docType = typeSort.replace('type-', '');
                    if (aData.documentType === docType && bData.documentType !== docType) return -1;
                    if (bData.documentType === docType && aData.documentType !== docType) return 1;
                    return 0;
                }

                // Then by source
                if (sourceSort) {
                    if (sourceSort === 'uploaded') {
                        return aData.source === 'uploaded' ? -1 : bData.source === 'uploaded' ? 1 : 0;
                    } else if (sourceSort === 'received') {
                        return aData.source === 'received' ? -1 : bData.source === 'received' ? 1 : 0;
                    } else if (sourceSort === 'requested') {
                        return aData.source === 'requested' ? -1 : bData.source === 'requested' ? 1 : 0;
                    }
                }

                return 0;
            });

            $container.empty().append($items);
        }

        function filterPersonalFilesByHardCopy() {
            const showHardCopyOnly = $('#hardCopyPersonalFilter').is(':checked');
            $('#personalFiles .file-item').each(function() {
                const hasHardCopy = $(this).data('hard-copy') == 1;
                $(this).toggle(!showHardCopyOnly || hasHardCopy);
            });
        }

        function sortDepartmentFiles(deptId) {
            const nameSort = $(`.sort-department-name[data-dept-id="${deptId}"]`).val();
            const typeSort = $(`.sort-department-type[data-dept-id="${deptId}"]`).val();
            const scopeSort = $(`.sort-department-scope[data-dept-id="${deptId}"]`).val();
            const $container = $(`#departmentFiles-${deptId}`);
            const $items = $container.find('.file-item').get();

            $items.sort(function(a, b) {
                const aData = $(a).data();
                const bData = $(b).data();

                // Prioritize sorting by name
                if (nameSort) {
                    if (nameSort === 'name-asc') {
                        return aData.fileName.localeCompare(bData.fileName);
                    } else if (nameSort === 'name-desc') {
                        return bData.fileName.localeCompare(aData.fileName);
                    }
                }

                // Then by document type
                if (typeSort) {
                    const docType = typeSort.replace('type-', '');
                    if (aData.documentType === docType && bData.documentType !== docType) return -1;
                    if (bData.documentType === docType && aData.documentType !== docType) return 1;
                    return 0;
                }

                // Then by scope
                if (scopeSort) {
                    if (scopeSort === 'department') {
                        return aData.source === 'department' ? -1 : bData.source === 'department' ? 1 : 0;
                    } else if (scopeSort === 'sub-department') {
                        return aData.source === 'sub-department' ? -1 : bData.source === 'sub-department' ? 1 : 0;
                    }
                }

                return 0;
            });

            $container.empty().append($items);
        }

        function filterDepartmentFilesByHardCopy(deptId) {
            const showHardCopyOnly = $(`.hard-copy-department-filter[data-dept-id="${deptId}"]`).is(':checked');
            $(`#departmentFiles-${deptId} .file-item`).each(function() {
                const hasHardCopy = $(this).data('hard-copy') == 1;
                $(this).toggle(!showHardCopyOnly || hasHardCopy);
            });
        }

        window.switchView = function(view) {
            const fileDisplay = $('#fileDisplay');
            if (view === 'thumbnail') {
                fileDisplay.removeClass('list-view').addClass('thumbnail-view masonry-grid');
                $('#thumbnailViewButton').addClass('active');
                $('#listViewButton').removeClass('active');
            } else {
                fileDisplay.removeClass('thumbnail-view masonry-grid').addClass('list-view');
                $('#listViewButton').addClass('active');
                $('#thumbnailViewButton').removeClass('active');
            }
        };

        function filterFilesByType() {
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const docType = $(this).data('document-type').toLowerCase();
                $(this).toggle(typeFilter === '' || docType === typeFilter);
            });
        }

        function filterFiles() {
            const searchTerm = $('#fileSearch').val().toLowerCase();
            const typeFilter = $('#documentTypeFilter').val().toLowerCase();
            $('#fileDisplay .file-item').each(function() {
                const fileName = $(this).data('file-name').toLowerCase();
                const docType = $(this).data('document-type').toLowerCase();
                const matchesSearch = fileName.includes(searchTerm);
                const matchesType = typeFilter === '' || docType === typeFilter;
                $(this).toggle(matchesSearch && matchesType);
            });
        }
    </script>
</body>

</html>