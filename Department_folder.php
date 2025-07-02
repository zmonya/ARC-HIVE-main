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

$userRole = $_SESSION['role'];
$userId = $_SESSION['user_id'];
$departmentId = $_GET['department_id'] ?? null;

if (!$departmentId) die("Department ID is required.");

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) die("User not found.");

$stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
$stmt->execute([$departmentId]);
$department = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$department) die("Department not found.");
$departmentName = $department['name'];

$stmt = $pdo->prepare("
    SELECT d.id, d.name 
    FROM departments d 
    JOIN user_department_affiliations uda ON d.id = uda.department_id 
    WHERE uda.user_id = ?
");
$stmt->execute([$userId]);
$userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT d.id AS department_id, d.name AS department_name, sd.id AS sub_department_id, sd.name AS sub_department_name
    FROM user_department_affiliations uda
    LEFT JOIN departments d ON uda.department_id = d.id
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id
    WHERE uda.user_id = ? AND uda.department_id = ?
");
$stmt->execute([$userId, $departmentId]);
$userSubDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (empty($userSubDepartments)) die("You do not have access to this department.");

$stmt = $pdo->prepare("SELECT id, name FROM sub_departments WHERE department_id = ?");
$stmt->execute([$departmentId]);
$allSubDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all document types dynamically
$stmt = $pdo->prepare("SELECT id, name FROM document_types");
$stmt->execute();
$documentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch department files
$stmt = $pdo->prepare("
    SELECT f.*, dt.name AS document_type, u.full_name AS uploader_name,
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
            SELECT 1 FROM file_transfers ft 
            WHERE ft.file_id = f.id AND ft.department_id = ? AND ft.status = 'accepted'
        ) OR (
            uda.department_id = ? AND f.user_id IN (
                SELECT user_id FROM user_department_affiliations WHERE department_id = ?
            )
        )
    )
    ORDER BY f.upload_date DESC
");
$stmt->execute([$departmentId, $departmentId, $departmentId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

$userSubDeptIds = array_column($userSubDepartments, 'sub_department_id');
$accessibleFiles = array_filter($files, function ($file) use ($userSubDeptIds) {
    return $file['sub_department_id'] === null || in_array($file['sub_department_id'], $userSubDeptIds);
});

$sortFilter = $_GET['sort'] ?? 'all';
$subDeptFilter = $_GET['sub_dept'] ?? 'all';
$filteredFiles = array_filter($accessibleFiles, function ($file) use ($userId, $departmentId, $sortFilter, $subDeptFilter) {
    $isUploadedByMe = $file['user_id'] == $userId;
    $isReceived = !$isUploadedByMe;
    $isHardcopyOnly = $file['hard_copy_available'] == 1 && empty($file['file_path']);
    $isSoftcopyOnly = $file['hard_copy_available'] == 0 && !empty($file['file_path']);
    $matchesSubDept = $subDeptFilter === 'all' ||
        ($subDeptFilter === 'department' && $file['sub_department_id'] === null) ||
        ($file['sub_department_id'] !== null && $file['sub_department_id'] == $subDeptFilter);

    return $matchesSubDept && match ($sortFilter) {
        'uploaded-by-me' => $isUploadedByMe,
        'received' => $isReceived,
        'hardcopy' => $isHardcopyOnly,
        'softcopy' => $isSoftcopyOnly,
        default => true
    };
});
$files = $filteredFiles;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($departmentName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="style/client-sidebar.css">
    <link rel="stylesheet" href="style/folder-page.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

</head>

<body>
    <div class="top-nav">
        <h2><?= htmlspecialchars($departmentName); ?></h2>
        <input type="text" placeholder="Search documents..." class="search-bar" id="searchBar">
        <button id="hardcopyStorageButton"><i class="fas fa-archive"></i> Recommend Storage</button>
    </div>

    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>"><i class="fas fa-home"></i><span class="link-text"> Dashboard</span></a>
        <a href="my-report.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-report.php' ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i><span class="link-text"> My Report</span></a>
        <a href="my-folder.php" class="<?= basename($_SERVER['PHP_SELF']) == 'my-folder.php' ? 'active' : '' ?>"><i class="fas fa-folder"></i><span class="link-text"> My Folder</span></a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= $dept['id'] ?>" class="<?= $dept['id'] == $departmentId ? 'active' : '' ?>"><i class="fas fa-folder"></i><span class="link-text"> <?= htmlspecialchars($dept['name']) ?></span></a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content">
        <div class="sub-dept-filter">
            <button class="sub-dept-btn <?= $subDeptFilter === 'all' ? 'active' : '' ?>" data-sub-dept="all">All Files</button>
            <button class="sub-dept-btn <?= $subDeptFilter === 'department' ? 'active' : '' ?>" data-sub-dept="department">Department-Wide</button>
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
                $fileCount = count(array_filter($files, fn($file) => $file['document_type'] === $type['name']));
            ?>
                <div class="ftype-card" onclick="openModal('<?= strtolower($type['name']) ?>')">
                    <p><?= ucfirst($type['name']) ?> (<?= $fileCount ?>)</p>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="masonry-grid">
            <div class="masonry-section">
                <h3>Department Files</h3>
                <div class="file-card-container" id="departmentFiles">
                    <?php foreach (array_slice($files, 0, 4) as $file): ?>
                        <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                            <div class="file-options" onclick="toggleOptions(this)">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                    <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                    <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="view-more"><button onclick="openModal('department')">View More</button></div>
            </div>
        </div>
    </div>

    <div class="popup-questionnaire" id="hardcopyStoragePopup">
        <button class="exit-button" onclick="closePopup('hardcopyStoragePopup')">×</button>
        <h3>File Details</h3>
        <form id="fileDetailsForm">
            <label for="documentType">Document Type:</label>
            <select id="documentType" name="document_type" required>
                <option value="">Select Document Type</option>
                <?php foreach ($documentTypes as $type): ?>
                    <option value="<?= $type['name'] ?>"><?= ucfirst($type['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div id="dynamicFields">
                <label for="fileName">File Name:</label>
                <input type="text" id="fileName" name="file_name" required>
            </div>
            <button type="submit" class="submit-button">Get Storage Suggestion</button>
        </form>
        <div id="storageSuggestion"></div>
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
            <div class="info-item"><span class="info-label">Department:</span><span class="info-value" id="departmentCollege"><?= htmlspecialchars($departmentName) ?></span></div>
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
        <div id="<?= strtolower($type['name']) ?>Modal" class="modal">
            <div class="modal-content">
                <button class="close-modal" onclick="closeModal('<?= strtolower($type['name']) ?>')">✕</button>
                <h2><?= ucfirst($type['name']) ?> Files</h2>
                <div class="modal-grid">
                    <?php foreach (array_filter($files, fn($file) => $file['document_type'] === $type['name']) as $file): ?>
                        <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                            <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                            <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                            <div class="file-options" onclick="toggleOptions(this)">
                                <i class="fas fa-ellipsis-v"></i>
                                <div class="options-menu">
                                    <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                    <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                    <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                    <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div id="departmentModal" class="modal">
        <div class="modal-content">
            <button class="close-modal" onclick="closeModal('department')">✕</button>
            <h2>All Department Files</h2>
            <div class="modal-grid">
                <?php foreach ($files as $file): ?>
                    <div class="file-card" data-file-name="<?= htmlspecialchars($file['file_name']) ?>" onclick="openSidebar(<?= $file['id'] ?>)">
                        <div class="file-icon-container"><i class="<?= getFileIcon($file['file_name']) ?> file-icon"></i></div>
                        <p class="file-name"><?= htmlspecialchars($file['file_name']) ?></p>
                        <div class="file-options" onclick="toggleOptions(this)">
                            <i class="fas fa-ellipsis-v"></i>
                            <div class="options-menu">
                                <div onclick="handleOption('Rename', <?= $file['id'] ?>)">Rename</div>
                                <div onclick="handleOption('Delete', <?= $file['id'] ?>)">Delete</div>
                                <div onclick="handleOption('Make Copy', <?= $file['id'] ?>)">Make Copy</div>
                                <div onclick="handleOption('File Information', <?= $file['id'] ?>)">File Information</div>
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
            } else {
                showAlert(`Handling option: ${option} for file ID: ${fileId}`, 'success');
            }
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
            const fileCards = document.querySelectorAll('#departmentFiles .file-card');
            let hasResults = false;
            fileCards.forEach(card => {
                const fileName = card.dataset.fileName.toLowerCase();
                const isVisible = fileName.includes(searchQuery);
                card.classList.toggle('hidden', !isVisible);
                if (isVisible) hasResults = true;
            });
            const noResults = document.getElementById('noResults');
            if (noResults) noResults.remove();
            if (!hasResults && searchQuery) {
                const container = document.getElementById('departmentFiles');
                container.insertAdjacentHTML('beforeend', '<p id="noResults" style="text-align: center;">No files found</p>');
            }
        }

        document.getElementById('searchBar').addEventListener('input', filterFiles);

        // Sorting buttons
        document.querySelectorAll('.sort-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filter = this.getAttribute('data-filter');
                const subDept = new URLSearchParams(window.location.search).get('sub_dept') || 'all';
                window.location.href = `department_folder.php?department_id=<?php echo $departmentId; ?>&sort=${filter}&sub_dept=${subDept}`;
            });
        });

        // Sub-department filter
        document.querySelectorAll('.sub-dept-btn').forEach(button => {
            button.addEventListener('click', function() {
                const subDept = this.getAttribute('data-sub-dept');
                const sort = new URLSearchParams(window.location.search).get('sort') || 'all';
                window.location.href = `department_folder.php?department_id=<?php echo $departmentId; ?>&sort=${sort}&sub_dept=${subDept}`;
            });
        });

        // Hardcopy storage popup
        document.getElementById('hardcopyStorageButton').addEventListener('click', () => {
            document.getElementById('hardcopyStoragePopup').style.display = 'block';
        });

        function closePopup(popupId) {
            document.getElementById(popupId).style.display = 'none';
            document.getElementById('storageSuggestion').textContent = '';
        }

        // Dynamic fields for document types
        document.getElementById('documentType').addEventListener('change', function() {
            const type = this.value;
            const dynamicFields = document.getElementById('dynamicFields');
            dynamicFields.innerHTML = '<label for="fileName">File Name:</label><input type="text" id="fileName" name="file_name" required>';

            if (type) {
                $.ajax({
                    url: 'get_document_type.php',
                    method: 'GET',
                    data: {
                        document_type_name: type
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.fields.length > 0) {
                            let fieldsHtml = '';
                            data.fields.forEach(field => {
                                const inputType = field.field_type === 'date' ? 'date' :
                                    field.field_type === 'textarea' ? 'textarea' : 'text';
                                const required = field.is_required ? 'required' : '';
                                fieldsHtml += `
                            <label for="${field.field_name}">${field.field_label}:</label>
                            ${inputType === 'textarea' ?
                                `<textarea id="${field.field_name}" name="${field.field_name}" ${required}></textarea>` :
                                `<input type="${inputType}" id="${field.field_name}" name="${field.field_name}" ${required}>`}
                        `;
                            });
                            dynamicFields.innerHTML += fieldsHtml;
                        } else {
                            showAlert(data.message || 'No fields found for this document type.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', status, error);
                        showAlert('Failed to load document fields.', 'error');
                    }
                });
            }
        });

        // Handle form submission for storage suggestion
        document.getElementById('fileDetailsForm').addEventListener('submit', function(event) {
            event.preventDefault();
            const formData = new FormData(this);
            formData.append('department_id', '<?php echo $departmentId; ?>');

            fetch('get_storage_suggestions.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    const suggestionDiv = document.getElementById('storageSuggestion');
                    if (data.success) {
                        suggestionDiv.textContent = data.suggestion;
                        suggestionDiv.style.color = 'green';
                        formData.append('storage_metadata', JSON.stringify(data.storage_metadata));
                        saveFileDetails(formData);
                    } else {
                        suggestionDiv.textContent = data.suggestion;
                        suggestionDiv.style.color = 'red';
                    }
                })
                .catch(error => {
                    console.error("Error:", error);
                    showAlert("An error occurred while fetching storage suggestion.", 'error');
                });
        });

        // Save file details
        function saveFileDetails(formData) {
            fetch('save_file_details.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showAlert('File details and storage location saved successfully!', 'success', () => window.location.reload());
                    } else {
                        showAlert('Failed to save: ' + (data.message || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    console.error("Error:", error);
                    showAlert('An error occurred while saving.', 'error');
                });
        }

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