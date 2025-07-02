<?php
session_start();
require_once 'db_connection.php';
require_once 'log_activity.php';
require_once 'notification.php';

global $pdo;

function getFileIcon($fileName)
{
    $iconClasses = [
        'pdf' => 'fas fa-file-pdf',
        'doc' => 'fas fa-file-word',
        'docx' => 'fas fa-file-word',
        'xls' => 'fas fa-file-excel',
        'xlsx' => 'fas fa-file-excel',
        'ppt' => 'fas fa-file-powerpoint',
        'pptx' => 'fas fa-file-powerpoint',
        'txt' => 'fas fa-file-alt',
        'jpg' => 'fas fa-file-image',
        'jpeg' => 'fas fa-file-image',
        'png' => 'fas fa-file-image',
        'default' => 'fas fa-file'
    ];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $iconClasses[$extension] ?? $iconClasses['default'];
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User not found.");

$stmt = $pdo->prepare("
    SELECT d.id, d.name 
    FROM departments d 
    JOIN user_department_affiliations uda ON d.id = uda.department_id 
    WHERE uda.user_id = ?
");
$stmt->execute([$userId]);
$userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all document types dynamically
$stmt = $pdo->prepare("SELECT id, name FROM document_types");
$stmt->execute();
$documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all files (uploaded and received)
$stmt = $pdo->prepare("
    SELECT DISTINCT f.*, dt.name AS document_type, u.full_name AS uploader_name,
           sd.name AS sub_department_name, sd.id AS sub_department_id,
           c.cabinet_name, sl.layer, sl.box, sl.folder
    FROM files f 
    LEFT JOIN document_types dt ON f.document_type_id = dt.id 
    LEFT JOIN users u ON f.user_id = u.id
    LEFT JOIN file_storage fs ON f.id = fs.file_id
    LEFT JOIN storage_locations sl ON fs.storage_location_id = sl.id
    LEFT JOIN cabinets c ON sl.cabinet_id = c.id
    LEFT JOIN user_department_affiliations uda ON uda.user_id = f.user_id
    LEFT JOIN sub_departments sd ON sd.id = uda.sub_department_id
    WHERE f.is_deleted = 0 AND (
        EXISTS (
            SELECT 1 FROM file_owners fo 
            WHERE fo.file_id = f.id AND fo.user_id = ? AND fo.ownership_type = 'original'
        ) OR EXISTS (
            SELECT 1 FROM file_transfers ft 
            WHERE ft.file_id = f.id AND ft.recipient_id = ? AND ft.status = 'accepted'
        ) OR EXISTS (
            SELECT 1 FROM file_owners fo 
            WHERE fo.file_id = f.id AND fo.user_id = ? AND fo.ownership_type = 'co-owner'
        ) OR EXISTS (
            SELECT 1 FROM access_requests ar 
            WHERE ar.file_id = f.id AND ar.requester_id = ? AND ar.status = 'approved'
        )
    )
    ORDER BY f.upload_date DESC
");
$stmt->execute([$userId, $userId, $userId, $userId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter files based on sort and sub-department
$sortFilter = $_GET['sort'] ?? 'all';
$subDeptFilter = $_GET['sub_dept'] ?? 'all';
$filteredFiles = array_filter($files, function ($file) use ($userId, $sortFilter, $subDeptFilter) {
    $isUploadedByMe = $file['user_id'] == $userId;
    $isReceived = !$isUploadedByMe;
    $isHardcopyOnly = $file['hard_copy_available'] == 1 && empty($file['file_path']);
    $isSoftcopyOnly = $file['hard_copy_available'] == 0 && !empty($file['file_path']);
    $matchesSubDept = $subDeptFilter === 'all' ||
        ($subDeptFilter === 'none' && $file['sub_department_id'] === null) ||
        ($file['sub_department_id'] !== null && $file['sub_department_id'] == $subDeptFilter);

    return $matchesSubDept && match ($sortFilter) {
        'uploaded-by-me' => $isUploadedByMe,
        'received' => $isReceived,
        'hardcopy' => $isHardcopyOnly,
        'softcopy' => $isSoftcopyOnly,
        default => true
    };
});

// Separate uploaded and received files for display
$uploadedFiles = array_filter($filteredFiles, fn($file) => $file['user_id'] == $userId);
$receivedFiles = array_filter($filteredFiles, fn($file) => $file['user_id'] != $userId);

// Fetch sub-departments for filter
$stmt = $pdo->prepare("
    SELECT sd.id AS sub_department_id, sd.name AS sub_department_name
    FROM user_department_affiliations uda
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id
    WHERE uda.user_id = ?
");
$stmt->execute([$userId]);
$userSubDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Folder</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>
    <div class="top-nav">
        <h2><?= htmlspecialchars($user['full_name']) ?>'s Folder</h2>
        <input type="text" placeholder="Search documents..." class="search-bar" id="searchBar">
    </div>

    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>"><i class="fas fa-home"></i><span class="link-text">Dashboard</span></a>
        <a href="my-report.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-report.php' ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i><span class="link-text">My Report</span></a>
        <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>"><i class="fas fa-folder"></i><span class="link-text">My Folder</span></a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= $dept['id'] ?>" class="<?= isset($_GET['department_id']) && $_GET['department_id'] == $dept['id'] ? 'active' : '' ?>"><i class="fas fa-folder"></i><span class="link-text"><?= htmlspecialchars($dept['name']) ?></span></a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content">
        <div class="sub-dept-filter">
            <button class="sub-dept-btn <?= $subDeptFilter === 'all' ? 'active' : '' ?>" data-sub-dept="all">All Files</button>
            <button class="sub-dept-btn <?= $subDeptFilter === 'none' ? 'active' : '' ?>" data-sub-dept="none">No Sub-Department</button>
            <?php foreach ($userSubDepartments as $subDept): ?>
                <?php if ($subDept['sub_department_id']): ?>
                    <button class="sub-dept-btn <?= $subDeptFilter == $subDept['sub_department_id'] ? 'active' : '' ?>" data-sub-dept="<?= $subDept['sub_department_id'] ?>"><?= htmlspecialchars($subDept['sub_department_name']) ?></button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="sorting-buttons">
            <button class="sort-btn <?= $sortFilter === 'all' ? 'active' : '' ?>" data-filter="all">All Files</button>
            <button class="sort-btn <?= $sortFilter === 'uploaded-by-me' ? 'active' : '' ?>" data-filter="uploaded-by-me">Uploaded by Me</button>
            <button class="sort-btn <?= $sortFilter === 'received' ? 'active' : '' ?>" data-filter="received">Files Received</button>
            <button class="sort-btn <?= $sortFilter === 'hardcopy' ? 'active' : '' ?>" data-filter="hardcopy">Hardcopy</button>
            <button class="sort-btn <?= $sortFilter === 'softcopy' ? 'active' : '' ?>" data-filter="softcopy">Softcopy</button>
        </div>

        <div class="ftypes">
            <?php foreach ($documentTypes as $type):
                $fileCount = count(array_filter($filteredFiles, fn($file) => $file['document_type'] === $type['name']));
            ?>
                <div class="ftype-card" onclick="openModal('<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name']))) ?>')">
                    <p><?= ucfirst($type['name']) ?> (<?= $fileCount ?>)</p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="masonry-grid">
            <div class="masonry-section">
                <h3>Uploaded Files</h3>
                <div class="file-card-container" id="uploadedFiles">
                    <?php foreach (array_slice($uploadedFiles, 0, 4) as $file): ?>
                        <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                            <div class="file-options" onclick="toggleOptions(this)">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                    <?php if ($file['user_id'] == $userId): ?>
                                        <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                    <?php endif; ?>
                                    <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                    <?php if ($file['user_id'] != $userId): ?>
                                        <div onclick="requestAccess(<?= $file['id'] ?>)">Request Document</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($uploadedFiles) > 4): ?>
                    <div class="view-more"><button onclick="openModal('uploaded')">View More</button></div>
                <?php endif; ?>
            </div>
            <div class="masonry-section">
                <h3>Received Files</h3>
                <div class="file-card-container" id="receivedFiles">
                    <?php foreach (array_slice($receivedFiles, 0, 4) as $file): ?>
                        <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                            <div class="file-options" onclick="toggleOptions(this)">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                    <?php if ($file['user_id'] == $userId): ?>
                                        <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                    <?php endif; ?>
                                    <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                    <?php if ($file['user_id'] != $userId): ?>
                                        <div onclick="requestAccess(<?= $file['id'] ?>)">Request Document</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($receivedFiles) > 4): ?>
                    <div class="view-more"><button onclick="openModal('received')">View More</button></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="file-info-sidebar">
        <div class="file-name-container">
            <div class="file-name-title" id="sidebarFileName">File Name</div>
            <button class="close-sidebar-btn" onclick="closeSidebar()">×</button>
        </div>
        <div class="file-preview" id="filePreview"></div>
        <div class="file-info-header">
            <div class="file-info-location active" onclick="showSection('locationSection')">
                <h4>Location</h4>
            </div>
            <div class="file-info-details" onclick="showSection('detailsSection')">
                <h4>Details</h4>
            </div>
        </div>
        <div class="info-section active" id="locationSection">
            <div class="info-item"><span class="info-label">Department:</span><span class="info-value" id="departmentCollege">N/A</span></div>
            <div class="info-item"><span class="info-label">Sub-Department:</span><span class="info-value" id="subDepartment">N/A</span></div>
            <div class="info-item"><span class="info-label">Physical Location:</span><span class="info-value" id="physicalLocation">Not assigned</span></div>
            <div class="info-item"><span class="info-label">Cabinet:</span><span class="info-value" id="cabinet">N/A</span></div>
            <div class="info-item"><span class="info-label">Layer/Box/Folder:</span><span class="info-value" id="storageDetails">N/A</span></div>
        </div>
        <div class="info-section" id="detailsSection">
            <div class="access-log">
                <h3>Who Has Access</h3>
                <div class="access-users" id="accessUsers"></div>
                <p class="access-info" id="accessInfo"></p>
            </div>
            <div class="file-details">
                <h3>File Details</h3>
                <div class="info-item"><span class="info-label">Uploader:</span><span class="info-value" id="uploader">N/A</span></div>
                <div class="info-item"><span class="info-label">File Type:</span><span class="info-value" id="fileType">N/A</span></div>
                <div class="info-item"><span class="info-label">File Size:</span><span class="info-value" id="fileSize">N/A</span></div>
                <div class="info-item"><span class="info-label">Category:</span><span class="info-value" id="fileCategory">N/A</span></div>
                <div class="info-item"><span class="info-label">Date Uploaded:</span><span class="info-value" id="dateUpload">N/A</span></div>
                <div class="info-item"><span class="info-label">Pages:</span><span class="info-value" id="pages">N/A</span></div>
                <div class="info-item"><span class="info-label">Purpose:</span><span class="info-value" id="purpose">N/A</span></div>
                <div class="info-item"><span class="info-label">Subject:</span><span class="info-value" id="subject">N/A</span></div>
            </div>
        </div>
    </div>

    <div class="full-preview-modal" id="fullPreviewModal">
        <div class="full-preview-content">
            <button class="close-full-preview" onclick="closeFullPreview()">✕</button>
            <div id="fullPreviewContent"></div>
        </div>
    </div>

    <?php foreach ($documentTypes as $type): ?>
        <div id="<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name']))) ?>Modal" class="modal">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('<?= htmlspecialchars(strtolower(str_replace(' ', '-', $type['name']))) ?>')">✕</button>
                <h2><?= ucfirst($type['name']) ?> Files</h2>
                <div class="modal-grid">
                    <?php foreach (array_filter($filteredFiles, fn($file) => $file['document_type'] === $type['name']) as $file): ?>
                        <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                            <div class="file-options" onclick="toggleOptions(this)">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                    <?php if ($file['user_id'] == $userId): ?>
                                        <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                    <?php endif; ?>
                                    <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                    <?php if ($file['user_id'] != $userId): ?>
                                        <div onclick="requestAccess(<?= $file['id'] ?>)">Request Document</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div id="uploadedModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('uploaded')">✕</button>
            <h2>All Uploaded Files</h2>
            <div class="modal-grid">
                <?php foreach ($uploadedFiles as $file): ?>
                    <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                        <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                        <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                        <div class="file-options" onclick="toggleOptions(this)">
                            <i class="fas fa-ellipsis-v"></i>
                            <div class="options-menu">
                                <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                <?php if ($file['user_id'] == $userId): ?>
                                    <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                <?php endif; ?>
                                <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                <?php if ($file['user_id'] != $userId): ?>
                                    <div onclick="requestAccess(<?= $file['id'] ?>)">Request Document</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div id="receivedModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('received')">✕</button>
            <h2>All Received Files</h2>
            <div class="modal-grid">
                <?php foreach ($receivedFiles as $file): ?>
                    <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                        <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                        <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                        <div class="file-options" onclick="toggleOptions(this)">
                            <i class="fas fa-ellipsis-v"></i>
                            <div class="options-menu">
                                <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                <?php if ($file['user_id'] == $userId): ?>
                                    <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                <?php endif; ?>
                                <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                <?php if ($file['user_id'] != $userId): ?>
                                    <div onclick="requestAccess(<?= $file['id'] ?>)">Request Document</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle
        document.querySelector('.toggle-btn').addEventListener('click', () => {
            const sidebar = document.querySelector('.sidebar');
            const topNav = document.querySelector('.top-nav');
            sidebar.classList.toggle('minimized');
            topNav.classList.toggle('resized', sidebar.classList.contains('minimized'));
        });

        // File options menu
        function toggleOptions(element) {
            document.querySelectorAll('.options-menu').forEach(menu => {
                if (menu !== element.querySelector('.options-menu')) menu.classList.remove('show');
            });
            element.querySelector('.options-menu').classList.toggle('show');
            event.stopPropagation();
        }

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.file-options')) {
                document.querySelectorAll('.options-menu').forEach(menu => menu.classList.remove('show'));
            }
        });

        // Handle file options
        function handleOption(option, fileId) {
            if (!fileId || isNaN(fileId)) {
                showAlert('Invalid file ID', 'error');
                return;
            }
            if (option === 'File Information') {
                openSidebar(fileId);
            } else if (option === 'Rename') {
                renameFile(fileId);
            } else if (option === 'Delete') {
                deleteFile(fileId);
            } else if (option === 'Make Copy') {
                makeCopy(fileId);
            } else {
                showAlert(`Handling option: ${option} for file ID: ${fileId}`, 'success');
            }
        }

        // File operations
        function renameFile(fileId) {
            const newName = prompt('Enter the new file name:');
            if (newName) {
                fetch('rename_file.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            file_id: fileId,
                            new_name: newName
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showAlert('File renamed successfully', 'success', () => location.reload());
                        } else {
                            showAlert('Failed to rename file: ' + (data.message || 'Unknown error'), 'error');
                        }
                    })
                    .catch(error => showAlert('Server error: ' + error, 'error'));
            }
        }

        function deleteFile(fileId) {
            if (confirm('Are you sure you want to delete this file?')) {
                fetch('delete_file.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            file_id: fileId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showAlert('File deleted successfully', 'success', () => location.reload());
                        } else {
                            showAlert('Failed to delete file: ' + (data.message || 'Unknown error'), 'error');
                        }
                    })
                    .catch(error => showAlert('Server error: ' + error, 'error'));
            }
        }

        function makeCopy(fileId) {
            fetch('make_copy.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        file_id: fileId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('File copy created successfully', 'success', () => location.reload());
                    } else {
                        showAlert('Failed to create copy: ' + (data.message || 'Unknown error'), 'error');
                    }
                })
                .catch(error => showAlert('Server error: ' + error, 'error'));
        }

        function requestAccess(fileId) {
            fetch('handle_access_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        file_id: fileId,
                        action: 'request'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert('Access request sent successfully', 'success');
                    } else {
                        showAlert('Failed to send access request: ' + (data.message || 'Unknown error'), 'error');
                    }
                })
                .catch(error => showAlert('Server error: ' + error, 'error'));
        }

        // Open file info sidebar
        function openSidebar(fileId) {
            fetch(`get_file_info.php?file_id=${fileId}`, {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(`Server error: ${err.error || 'Unknown error'}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        showAlert(`Error: ${data.error}`, 'error');
                        return;
                    }

                    const elements = {
                        sidebarFileName: document.getElementById('sidebarFileName'),
                        departmentCollege: document.getElementById('departmentCollege'),
                        subDepartment: document.getElementById('subDepartment'),
                        physicalLocation: document.getElementById('physicalLocation'),
                        cabinet: document.getElementById('cabinet'),
                        storageDetails: document.getElementById('storageDetails'),
                        uploader: document.getElementById('uploader'),
                        fileType: document.getElementById('fileType'),
                        fileSize: document.getElementById('fileSize'),
                        fileCategory: document.getElementById('fileCategory'),
                        dateUpload: document.getElementById('dateUpload'),
                        pages: document.getElementById('pages'),
                        purpose: document.getElementById('purpose'),
                        subject: document.getElementById('subject'),
                        filePreview: document.getElementById('filePreview')
                    };

                    for (const [key, element] of Object.entries(elements)) {
                        if (!element) {
                            console.error(`Element with ID '${key}' not found`);
                            showAlert(`UI error: Element '${key}' missing`, 'error');
                            return;
                        }
                    }

                    elements.sidebarFileName.textContent = data.file_name || 'Unnamed File';
                    elements.departmentCollege.textContent = data.department_name || 'N/A';
                    elements.subDepartment.textContent = data.sub_department_name || 'N/A';
                    elements.physicalLocation.textContent = data.hard_copy_available ?
                        (data.cabinet_name ? 'Assigned' : 'Not assigned') : 'Digital only';
                    elements.cabinet.textContent = data.cabinet_name || 'N/A';
                    elements.storageDetails.textContent =
                        data.layer && data.box && data.folder ?
                        `${data.layer}/${data.box}/${data.folder}` : 'N/A';
                    elements.uploader.textContent = data.uploader_name || 'N/A';
                    elements.fileType.textContent = data.file_type || 'N/A';
                    elements.fileSize.textContent = data.file_size ? formatFileSize(data.file_size) : 'N/A';
                    elements.fileCategory.textContent = data.document_type || 'N/A';
                    elements.dateUpload.textContent = data.upload_date || 'N/A';
                    elements.pages.textContent = data.pages || 'N/A';
                    elements.purpose.textContent = data.purpose || 'Not specified';
                    elements.subject.textContent = data.subject || 'Not specified';

                    elements.filePreview.innerHTML = '';
                    if (data.file_path) {
                        const ext = data.file_type.toLowerCase();
                        if (ext === 'pdf') {
                            elements.filePreview.innerHTML = `<iframe src="${data.file_path}" title="File Preview"></iframe><p>Click to view full file${data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                            elements.filePreview.querySelector('iframe').addEventListener('click', () => openFullPreview(data.file_path));
                        } else if (['jpg', 'png', 'jpeg', 'gif'].includes(ext)) {
                            elements.filePreview.innerHTML = `<img src="${data.file_path}" alt="File Preview"><p>Click to view full image${data.hard_copy_available ? ' (Hardcopy available)' : ''}</p>`;
                            elements.filePreview.querySelector('img').addEventListener('click', () => openFullPreview(data.file_path));
                        } else {
                            elements.filePreview.innerHTML = '<p>Preview not available for this file type</p>';
                        }
                    } else if (data.hard_copy_available) {
                        elements.filePreview.innerHTML = '<p>Hardcopy - No digital preview available</p>';
                    } else {
                        elements.filePreview.innerHTML = '<p>No preview available (missing file data)</p>';
                    }

                    // Dynamically update file details section
                    const detailsSection = document.querySelector('.file-details');
                    detailsSection.innerHTML = '<h3>File Details</h3>';
                    const baseFields = [{
                            label: 'Uploader',
                            value: data.uploader_name || 'N/A'
                        },
                        {
                            label: 'File Type',
                            value: data.file_type || 'N/A'
                        },
                        {
                            label: 'File Size',
                            value: data.file_size ? formatFileSize(data.file_size) : 'N/A'
                        },
                        {
                            label: 'Category',
                            value: data.document_type || 'N/A'
                        },
                        {
                            label: 'Date Uploaded',
                            value: data.upload_date || 'N/A'
                        },
                        {
                            label: 'Pages',
                            value: data.pages || 'N/A'
                        },
                        {
                            label: 'Purpose',
                            value: data.purpose || 'Not specified'
                        },
                        {
                            label: 'Subject',
                            value: data.subject || 'Not specified'
                        }
                    ];
                    baseFields.forEach(field => {
                        detailsSection.innerHTML += `
                        <div class="info-item">
                            <span class="info-label">${field.label}:</span>
                            <span class="info-value">${field.value}</span>
                        </div>`;
                    });

                    if (data.document_type) {
                        $.ajax({
                            url: 'get_document_type.php',
                            method: 'GET',
                            data: {
                                document_type_name: data.document_type
                            },
                            dataType: 'json',
                            success: function(fieldsData) {
                                if (fieldsData.success && fieldsData.fields) {
                                    fieldsData.fields.forEach(field => {
                                        const value = data[field.field_name] || 'N/A';
                                        detailsSection.innerHTML += `
                                        <div class="info-item">
                                            <span class="info-label">${field.field_label}:</span>
                                            <span class="info-value">${value}</span>
                                        </div>`;
                                    });
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error('Error fetching document type fields:', error);
                            }
                        });
                    }

                    fetchAccessInfo(fileId);
                    document.querySelector('.file-info-sidebar').classList.add('active');
                })
                .catch(error => {
                    console.error('Fetch error:', error.message);
                    showAlert(`Failed to load file information: ${error.message}`, 'error');
                });
        }

        // Fetch access information
        function fetchAccessInfo(fileId) {
            fetch(`get_access_info.php?file_id=${fileId}`)
                .then(response => response.json())
                .then(data => {
                    const accessUsers = document.getElementById('accessUsers');
                    const accessInfo = document.getElementById('accessInfo');
                    if (!accessUsers || !accessInfo) {
                        console.error('Access info elements missing');
                        return;
                    }
                    accessUsers.innerHTML = '';
                    if (data.sub_department_name) {
                        accessUsers.innerHTML = `<div>Restricted to ${data.sub_department_name}</div>`;
                        accessInfo.textContent = 'Sub-department access only';
                    } else if (data.users && data.users.length > 0) {
                        data.users.forEach(user => {
                            accessUsers.innerHTML += `<div>${user.full_name} (${user.role})</div>`;
                        });
                        accessInfo.textContent = `${data.users.length} user(s) have access`;
                    } else {
                        accessUsers.innerHTML = '<div>Department-wide access</div>';
                        accessInfo.textContent = 'All department users have access';
                    }
                })
                .catch(error => {
                    console.error('Error fetching access info:', error);
                    document.getElementById('accessUsers').innerHTML = 'Error loading access info';
                });
        }

        // Modal open/close
        function openModal(type) {
            const modal = document.getElementById(`${type}Modal`);
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('open');
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    setTimeout(() => modalContent.classList.add('open'), 10);
                } else {
                    console.error(`Modal content not found for ${type}Modal`);
                    showAlert('UI error: Modal content missing', 'error');
                }
            } else {
                console.error(`Modal with ID ${type}Modal not found`);
                showAlert(`Error: Modal for ${type} not found`, 'error');
            }
        }

        function closeModal(type) {
            const modal = document.getElementById(`${type}Modal`);
            if (modal) {
                const modalContent = modal.querySelector('.modal-content');
                if (modalContent) {
                    modalContent.classList.remove('open');
                }
                setTimeout(() => {
                    modal.style.display = 'none';
                    modal.classList.remove('open');
                }, 300);
            }
        }

        // Full preview
        function openFullPreview(filePath) {
            const modal = document.getElementById('fullPreviewModal');
            const content = document.getElementById('fullPreviewContent');
            if (!modal || !content) {
                showAlert('Preview modal not found', 'error');
                return;
            }
            const ext = filePath.split('.').pop().toLowerCase();
            content.innerHTML = ['pdf'].includes(ext) ? `<iframe src="${filePath}"></iframe>` : `<img src="${filePath}" style="max-width: 100%; max-height: 80vh;">`;
            modal.style.display = 'flex';
        }

        function closeFullPreview() {
            document.getElementById('fullPreviewModal').style.display = 'none';
        }

        // Sidebar section toggle
        function closeSidebar() {
            document.querySelector('.file-info-sidebar').classList.remove('active');
        }

        function showSection(sectionId) {
            document.querySelectorAll('.info-section').forEach(section => section.classList.remove('active'));
            document.getElementById(sectionId).classList.add('active');
            document.querySelectorAll('.file-info-header div').forEach(div => div.classList.remove('active'));
            document.querySelector(`.file-info-${sectionId === 'locationSection' ? 'location' : 'details'}`).classList.add('active');
        }

        // Search filter
        function filterFiles() {
            const searchQuery = document.getElementById('searchBar').value.toLowerCase();
            const containers = ['uploadedFiles', 'receivedFiles'];
            let hasResults = false;
            containers.forEach(containerId => {
                const fileCards = document.querySelectorAll(`#${containerId} .file-card`);
                fileCards.forEach(card => {
                    const fileName = card.dataset.fileName.toLowerCase();
                    const isVisible = fileName.includes(searchQuery);
                    card.classList.toggle('hidden', !isVisible);
                    if (isVisible) hasResults = true;
                });
                const noResults = document.getElementById(`noResults-${containerId}`);
                if (noResults) noResults.remove();
                if (!hasResults && searchQuery) {
                    const container = document.getElementById(containerId);
                    container.insertAdjacentHTML('beforeend', `<p id="noResults-${containerId}" style="text-align: center;">No files found</p>`);
                }
            });
        }

        document.getElementById('searchBar').addEventListener('input', filterFiles);

        // Sorting and sub-department buttons
        document.querySelectorAll('.sort-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                const subDept = new URLSearchParams(window.location.search).get('sub_dept') || 'all';
                window.location.href = `my-folder.php?sort=${filter}&sub_dept=${subDept}`;
            });
        });

        document.querySelectorAll('.sub-dept-btn').forEach(button => {
            button.addEventListener('click', function() {
                const subDept = this.getAttribute('data-sub-dept');
                const sort = new URLSearchParams(window.location.search).get('sort') || 'all';
                window.location.href = `my-folder.php?sort=${sort}&sub_dept=${subDept}`;
            });
        });

        // Alert function
        function showAlert(message, type, callback = null) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `custom-alert ${type}`;
            alertDiv.innerHTML = `
                <p>${message}</p>
                <button onclick="this.parentElement.remove(); ${callback ? 'callback()' : ''}">OK</button>
            `;
            document.body.appendChild(alertDiv);
        }

        // File size formatter
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Keyboard navigation
        document.querySelectorAll('.file-card, .ftype-card, .sort-btn, .sub-dept-btn').forEach(element => {
            element.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    element.click();
                }
            });
        });
    </script>
</body>

</html>