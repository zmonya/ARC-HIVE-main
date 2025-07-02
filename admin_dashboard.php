<?php
session_start();
require 'db_connection.php';

// Redirect to login if not authenticated or not an admin
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: unauthorized.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Fetch admin details
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch system statistics
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalFiles = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = 0")->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'")->fetchColumn();

// Fetch incoming and outgoing files from file_transfers
$incomingFiles = $pdo->query("
    SELECT COUNT(*) AS incoming_count 
    FROM file_transfers 
    WHERE recipient_id = $userId AND status = 'pending'
")->fetchColumn();

$outgoingFiles = $pdo->query("
    SELECT COUNT(*) AS outgoing_count 
    FROM file_transfers 
    WHERE sender_id = $userId AND status = 'pending'
")->fetchColumn();

// Fetch file upload trends (Last 7 Days) with uploader and department details
$fileUploadTrends = $pdo->query("
    SELECT 
        f.file_name AS document_name,
        dt.name AS document_type,
        f.upload_date AS upload_date,
        u.username AS uploader_name,
        d.name AS uploader_department,
        sd.name AS uploader_subdepartment,
        ft.department_id AS target_department_id,
        td.name AS target_department_name
    FROM files f
    LEFT JOIN document_types dt ON f.document_type_id = dt.id
    LEFT JOIN users u ON f.user_id = u.id
    LEFT JOIN user_department_affiliations uda ON u.id = uda.user_id
    LEFT JOIN departments d ON uda.department_id = d.id
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id
    LEFT JOIN file_transfers ft ON f.id = ft.file_id AND ft.time_sent = f.upload_date
    LEFT JOIN departments td ON ft.department_id = td.id
    WHERE f.upload_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY f.upload_date ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch file distribution by document type (only files with activity)
$fileDistributionByType = $pdo->query("
    SELECT 
        f.file_name AS document_name,
        dt.name AS document_type,
        us.username AS sender_name,
        ur.username AS receiver_name,
        ft.time_sent AS time_sent,
        ft.time_received AS time_received,
        ft.time_accepted AS time_accepted,
        ft.time_denied AS time_denied,
        uq.username AS requester_name,
        uo.username AS owner_name,
        ar.time_requested AS time_requested,
        ar.time_approved AS time_approved,
        ar.time_rejected AS time_rejected,
        d.name AS department_name,
        sd.name AS sub_department_name
    FROM files f
    JOIN document_types dt ON f.document_type_id = dt.id
    LEFT JOIN file_transfers ft ON f.id = ft.file_id
    LEFT JOIN users us ON ft.sender_id = us.id
    LEFT JOIN users ur ON ft.recipient_id = ur.id
    LEFT JOIN access_requests ar ON f.id = ar.file_id
    LEFT JOIN users uq ON ar.requester_id = uq.id
    LEFT JOIN users uo ON ar.owner_id = uo.id
    LEFT JOIN user_department_affiliations uda ON ft.sender_id = uda.user_id
    LEFT JOIN departments d ON uda.department_id = d.id
    LEFT JOIN sub_departments sd ON uda.sub_department_id = sd.id
    WHERE f.is_deleted = 0
    AND (ft.id IS NOT NULL OR ar.id IS NOT NULL)
    GROUP BY f.id, ft.id, ar.id
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch users per department
$usersPerDepartment = $pdo->query("
    SELECT 
        d.name AS department_name,
        COUNT(DISTINCT uda.user_id) AS user_count
    FROM departments d
    LEFT JOIN user_department_affiliations uda ON d.id = uda.department_id
    GROUP BY d.name
")->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for Users Per Department chart
$departmentLabels = array_column($usersPerDepartment, 'department_name');
$departmentData = array_column($usersPerDepartment, 'user_count');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css">
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin-sidebar.css">
    <link rel="stylesheet" href="admin-interface.css">
    <style>
        /* Popup Styling */
        .popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .popup-content {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            width: 90%;
            max-width: 950px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.3);
            position: relative;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .popup-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .popup-header h2 {
            margin: 0;
            font-size: 26px;
            color: #333;
            font-weight: 600;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #888;
            transition: color 0.2s;
        }

        .close-btn:hover {
            color: #333;
        }

        .popup-actions {
            margin: 15px 0;
            text-align: right;
        }

        .popup-actions button {
            padding: 10px 20px;
            margin-left: 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background-color: #50c878;
            color: white;
            font-weight: 500;
            transition: background-color 0.3s, transform 0.2s;
        }

        .popup-actions button:hover {
            background-color: #45b069;
            transform: translateY(-2px);
        }

        .popup-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 13px;
        }

        .popup-table th,
        .popup-table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }

        .popup-table th {
            background-color: #f8f8f8;
            color: #444;
            font-weight: bold;
            /* Bold headers */
        }

        .popup-table td {
            color: #555;
        }

        .popup-table td:nth-child(odd) {
            background-color: #e6f4ea;
            /* Pastel Emerald Green */
        }

        /* Stat Card Enhancements */
        .stat-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* Chart Container Enhancements */
        .chart-container {
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .chart-container:hover {
            transform: scale(1.03);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .chart-container h3 {
            padding: 10px;
            margin: 0;
            background: #f9f9f9;
            font-size: 18px;
            color: #333;
        }
    </style>
</head>

<body>
    <!-- Admin Sidebar -->
    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <h2 class="sidebar-title">Admin Panel</h2>
        <a href="dashboard.php" class="client-btn">
            <i class="fas fa-exchange-alt"></i>
            <span class="link-text">Switch to Client View</span>
        </a>
        <a href="admin_dashboard.php" class="active">
            <i class="fas fa-home"></i>
            <span class="link-text">Dashboard</span>
        </a>
        <a href="admin_search.php">
            <i class="fas fa-search"></i>
            <span class="link-text">View All Files</span>
        </a>
        <a href="user_management.php">
            <i class="fas fa-users"></i>
            <span class="link-text">User Management</span>
        </a>
        <a href="department_management.php">
            <i class="fas fa-building"></i>
            <span class="link-text">Department Management</span>
        </a>
        <a href="physical_storage_management.php">
            <i class="fas fa-archive"></i>
            <span class="link-text">Physical Storage</span>
        </a>
        <a href="document_type_management.php">
            <i class="fas fa-file-alt"></i>
            <span class="link-text">Document Type Management</span>
        </a>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="link-text">Logout</span>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content sidebar-expanded">
        <!-- System Statistics -->
        <div class="admin-stats">
            <div class="stat-card">
                <h3>Total Users</h3>
                <p><?= $totalUsers ?></p>
            </div>
            <div class="stat-card">
                <h3>Total Files</h3>
                <p><?= $totalFiles ?></p>
            </div>
            <div class="stat-card">
                <h3>Pending Requests</h3>
                <p><?= $pendingRequests ?></p>
            </div>
            <div class="stat-card">
                <h3>Incoming Files</h3>
                <p><?= $incomingFiles ?></p>
            </div>
            <div class="stat-card">
                <h3>Outgoing Files</h3>
                <p><?= $outgoingFiles ?></p>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="chart-grid">
            <!-- File Upload Trends -->
            <div class="chart-container" onclick="openPopup('fileUploadChart', 'File Upload Trends (Last 7 Days)', 'FileUploadTrends')">
                <h3>File Upload Trends (Last 7 Days)</h3>
                <canvas id="fileUploadChart"></canvas>
            </div>

            <!-- File Distribution by Document Type -->
            <div class="chart-container" onclick="openPopup('fileDistributionChart', 'File Distribution by Document Type', 'FileDistribution')">
                <h3>File Distribution by Document Type</h3>
                <canvas id="fileDistributionChart"></canvas>
            </div>

            <!-- Users Per Department -->
            <div class="chart-container" onclick="openPopup('usersPerDepartmentChart', 'Users Per Department', 'UsersPerDepartment')">
                <h3>Users Per Department</h3>
                <canvas id="usersPerDepartmentChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Popup Overlay -->
    <div id="popupOverlay" class="popup-overlay">
        <div class="popup-content">
            <div class="popup-header">
                <h2 id="popupTitle"></h2>
                <button class="close-btn" onclick="closePopup()">×</button>
            </div>
            <canvas id="popupChart"></canvas>
            <div class="popup-actions">
                <button onclick="downloadChart()">Download PDF</button>
                <button onclick="printChart()">Print</button>
            </div>
            <table id="popupTable" class="popup-table"></table>
        </div>
    </div>

    <script>
        // Toggle Sidebar and Popup Close
        document.addEventListener('DOMContentLoaded', () => {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.main-content');
            const toggleBtn = document.querySelector('.toggle-btn');
            const popupOverlay = document.getElementById('popupOverlay');

            if (sidebar.classList.contains('minimized')) {
                mainContent.classList.remove('sidebar-expanded');
                mainContent.classList.add('sidebar-minimized');
            } else {
                mainContent.classList.add('sidebar-expanded');
                mainContent.classList.remove('sidebar-minimized');
            }

            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('minimized');
                mainContent.classList.toggle('sidebar-expanded');
                mainContent.classList.toggle('sidebar-minimized');
            });

            popupOverlay.addEventListener('click', (e) => {
                if (e.target === popupOverlay) {
                    closePopup();
                }
            });

            initCharts();
        });

        // Chart instances
        let fileUploadChart, fileDistributionChart, usersPerDepartmentChart, popupChartInstance;

        function initCharts() {
            const fileUploadTrends = <?= json_encode($fileUploadTrends) ?>;
            const uploadLabels = fileUploadTrends.map(entry => new Date(entry.upload_date).toLocaleDateString());
            const uploadData = fileUploadTrends.map(() => 1);

            fileUploadChart = new Chart(document.getElementById('fileUploadChart'), {
                type: 'bar',
                data: {
                    labels: uploadLabels,
                    datasets: [{
                        label: 'File Uploads',
                        data: uploadData,
                        backgroundColor: 'rgba(80, 200, 120, 0.2)',
                        borderColor: '#50c878',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            const fileDistributionByType = <?= json_encode($fileDistributionByType) ?>;
            const documentTypeLabels = [...new Set(fileDistributionByType.map(entry => entry.document_type))];
            const documentTypeData = documentTypeLabels.map(type =>
                fileDistributionByType.filter(entry => entry.document_type === type).length
            );

            fileDistributionChart = new Chart(document.getElementById('fileDistributionChart'), {
                type: 'pie',
                data: {
                    labels: documentTypeLabels,
                    datasets: [{
                        label: 'File Distribution',
                        data: documentTypeData,
                        backgroundColor: ['#50c878', '#34495e', '#dc3545', '#ffc107', '#17a2b8', '#6610f2']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });

            usersPerDepartmentChart = new Chart(document.getElementById('usersPerDepartmentChart'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($departmentLabels) ?>,
                    datasets: [{
                        label: 'Users',
                        data: <?= json_encode($departmentData) ?>,
                        backgroundColor: '#50c878',
                        borderColor: '#50c878',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Users'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Departments'
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
        }

        // Popup handling
        let currentChartType;

        function openPopup(chartId, title, chartType) {
            currentChartType = chartType;
            const popupOverlay = document.getElementById('popupOverlay');
            const popupTitle = document.getElementById('popupTitle');
            const popupTable = document.getElementById('popupTable');
            const popupCanvas = document.getElementById('popupChart');

            if (popupChartInstance) popupChartInstance.destroy();

            popupTitle.textContent = title;
            popupOverlay.style.display = 'flex';

            let chartConfig;
            if (chartId === 'fileUploadChart') {
                chartConfig = fileUploadChart.config;
            } else if (chartId === 'fileDistributionChart') {
                chartConfig = fileDistributionChart.config;
            } else if (chartId === 'usersPerDepartmentChart') {
                chartConfig = usersPerDepartmentChart.config;
            }

            popupChartInstance = new Chart(popupCanvas, chartConfig);

            const chartData = getChartData(chartType);
            let tableHTML = '';

            if (chartType === 'FileUploadTrends') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Document Type</th>
                            <th>Uploader</th>
                            <th>Uploader's Department</th>
                            <th>Intended Destination</th>
                            <th>Upload Date/Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => {
                            const dept = entry.uploader_department ? `${entry.uploader_department}${entry.uploader_subdepartment ? ' - ' + entry.uploader_subdepartment : ''}` : 'Unknown';
                            const destination = entry.target_department_name ? entry.target_department_name : '(NONE) Personal Document';
                            return `
                                <tr>
                                    <td>${entry.document_name}</td>
                                    <td>${entry.document_type}</td>
                                    <td>${entry.uploader_name}</td>
                                    <td>${dept}</td>
                                    <td>${destination}</td>
                                    <td>${new Date(entry.upload_date).toLocaleString()}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                `;
            } else if (chartType === 'FileDistribution') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Document Type</th>
                            <th>Sender</th>
                            <th>Recipient</th>
                            <th>Time Sent</th>
                            <th>Time Received</th>
                            <th>Department/Subdepartment</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.map(entry => {
                            const dept = entry.department_name ? `${entry.department_name}${entry.sub_department_name ? ' - ' + entry.sub_department_name : ''}` : 'None';
                            return `
                                <tr>
                                    <td>${entry.document_name}</td>
                                    <td>${entry.document_type}</td>
                                    <td>${entry.sender_name || 'None'}</td>
                                    <td>${entry.receiver_name || 'None'}</td>
                                    <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'Not Sent'}</td>
                                    <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'Not Received'}</td>
                                    <td>${dept}</td>
                                </tr>
                                ${entry.requester_name ? `
                                <tr>
                                    <td colspan="2">Access Request</td>
                                    <td>${entry.requester_name}</td>
                                    <td>${entry.owner_name || 'None'}</td>
                                    <td>${entry.time_requested ? new Date(entry.time_requested).toLocaleString() : 'Not Requested'}</td>
                                    <td>${entry.time_approved ? new Date(entry.time_approved).toLocaleString() : 'Not Approved'}</td>
                                    <td>-</td>
                                </tr>
                                ` : ''}
                            `;
                        }).join('')}
                    </tbody>
                `;
            } else if (chartType === 'UsersPerDepartment') {
                tableHTML = `
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Users</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${chartData.labels.map((label, index) => `
                            <tr>
                                <td>${label}</td>
                                <td>${chartData.data[index]}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                `;
            }

            popupTable.innerHTML = tableHTML;
        }

        function closePopup() {
            const popupOverlay = document.getElementById('popupOverlay');
            popupOverlay.style.display = 'none';
            if (popupChartInstance) {
                popupChartInstance.destroy();
                popupChartInstance = null;
            }
        }

        function getChartData(chartType) {
            if (chartType === 'FileUploadTrends') {
                return <?= json_encode($fileUploadTrends) ?>;
            } else if (chartType === 'FileDistribution') {
                return <?= json_encode($fileDistributionByType) ?>;
            } else if (chartType === 'UsersPerDepartment') {
                return {
                    labels: <?= json_encode($departmentLabels) ?>,
                    data: <?= json_encode($departmentData) ?>
                };
            }
            return [];
        }

        // Download Chart as PDF (Chart sizing copied from printChart)
        function downloadChart() {
            const chartData = getChartData(currentChartType);
            const {
                jsPDF
            } = window.jspdf;
            const pdf = new jsPDF('p', 'mm', 'a4');
            const pageWidth = pdf.internal.pageSize.getWidth();
            const pageHeight = pdf.internal.pageSize.getHeight();
            const margin = 5; // Kept from original
            const maxWidth = pageWidth - 2 * margin; // 200mm
            let yPos = 10;

            // Title
            pdf.setFontSize(16);
            pdf.setTextColor(33, 33, 33);
            pdf.text(`Report: ${currentChartType}`, margin, yPos);
            yPos += 8;

            // Chart Image (Copied sizing approach from printChart)
            const chartCanvas = document.getElementById('popupChart');
            html2canvas(chartCanvas, {
                scale: 2
            }).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const imgProps = pdf.getImageProperties(chartImage);
                const chartWidth = maxWidth; // Full width like printChart
                const chartHeight = (imgProps.height * chartWidth) / imgProps.width; // Maintain aspect ratio
                pdf.addImage(chartImage, 'PNG', margin, yPos, chartWidth, chartHeight);
                yPos += chartHeight + 8;

                // Table Header
                pdf.setFontSize(12);
                pdf.text('Data Table', margin, yPos);
                yPos += 6;

                // Table Content (Original design retained)
                pdf.setFontSize(8);
                const lineHeight = 4;
                const startX = margin;

                function getMaxLines(texts, widths) {
                    return Math.max(...texts.map((text, i) =>
                        pdf.splitTextToSize(text || '', widths[i] - 4).length));
                }

                function drawCell(x, y, width, height, isHeader = false, isOdd = false) {
                    if (isHeader) {
                        pdf.setFillColor(240, 240, 240); // #f0f0f0
                        pdf.rect(x, y, width, height, 'F');
                    } else if (isOdd) {
                        pdf.setFillColor(230, 244, 234); // #e6f4ea
                        pdf.rect(x, y, width, height, 'F');
                    }
                    pdf.setDrawColor(150, 150, 150); // Gray borders
                    pdf.rect(x, y, width, height);
                }

                function drawText(x, y, text, width, height) {
                    const lines = pdf.splitTextToSize(text || '', width - 4);
                    const textHeight = lines.length * lineHeight;
                    const yOffset = (height - textHeight) / 2 + lineHeight;
                    lines.forEach((line, j) => {
                        const textWidth = pdf.getTextWidth(line);
                        const xOffset = (width - textWidth) / 2;
                        pdf.text(line, x + xOffset, y + yOffset + j * lineHeight);
                    });
                }

                if (currentChartType === 'FileUploadTrends') {
                    const columnWidths = [40, 24, 24, 35, 40, 37]; // 200mm total
                    let xPos = startX;
                    for (let i = 0; i < 6; i++) {
                        drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                        xPos += columnWidths[i];
                    }
                    pdf.setTextColor(50, 50, 50);
                    pdf.setFont('helvetica', 'bold');
                    xPos = startX;
                    drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                    xPos += columnWidths[0];
                    drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                    xPos += columnWidths[1];
                    drawText(xPos, yPos, 'Uploader', columnWidths[2], lineHeight + 2);
                    xPos += columnWidths[2];
                    drawText(xPos, yPos, 'Dept', columnWidths[3], lineHeight + 2);
                    xPos += columnWidths[3];
                    drawText(xPos, yPos, 'Destination', columnWidths[4], lineHeight + 2);
                    xPos += columnWidths[4];
                    drawText(xPos, yPos, 'Upload Date', columnWidths[5], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.forEach(entry => {
                        const dept = entry.uploader_department ? `${entry.uploader_department}${entry.uploader_subdepartment ? ' - ' + entry.uploader_subdepartment : ''}` : 'Unknown';
                        const destination = entry.target_department_name ? entry.target_department_name : '(NONE) Personal Document';
                        const texts = [
                            entry.document_name || '',
                            entry.document_type || '',
                            entry.uploader_name || '',
                            dept,
                            destination,
                            new Date(entry.upload_date).toLocaleString()
                        ];
                        const maxLines = getMaxLines(texts, columnWidths);
                        const rowHeight = maxLines * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 6; i++) {
                                drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                                xPos += columnWidths[i];
                            }
                            pdf.setTextColor(50, 50, 50);
                            pdf.setFont('helvetica', 'bold');
                            xPos = startX;
                            drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                            xPos += columnWidths[0];
                            drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                            xPos += columnWidths[1];
                            drawText(xPos, yPos, 'Uploader', columnWidths[2], lineHeight + 2);
                            xPos += columnWidths[2];
                            drawText(xPos, yPos, 'Dept', columnWidths[3], lineHeight + 2);
                            xPos += columnWidths[3];
                            drawText(xPos, yPos, 'Destination', columnWidths[4], lineHeight + 2);
                            xPos += columnWidths[4];
                            drawText(xPos, yPos, 'Upload Date', columnWidths[5], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 6; i++) {
                            drawCell(xPos, yPos, columnWidths[i], rowHeight, false, i % 2 === 0);
                            xPos += columnWidths[i];
                        }
                        pdf.setTextColor(0, 0, 0);
                        xPos = startX;
                        texts.forEach((text, i) => {
                            drawText(xPos, yPos, text, columnWidths[i], rowHeight);
                            xPos += columnWidths[i];
                        });
                        yPos += rowHeight;
                    });
                } else if (currentChartType === 'FileDistribution') {
                    const columnWidths = [29, 24, 29, 29, 33, 33, 23]; // 200mm total
                    let xPos = startX;
                    for (let i = 0; i < 7; i++) {
                        drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                        xPos += columnWidths[i];
                    }
                    pdf.setTextColor(50, 50, 50);
                    pdf.setFont('helvetica', 'bold');
                    xPos = startX;
                    drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                    xPos += columnWidths[0];
                    drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                    xPos += columnWidths[1];
                    drawText(xPos, yPos, 'Sender', columnWidths[2], lineHeight + 2);
                    xPos += columnWidths[2];
                    drawText(xPos, yPos, 'Recipient', columnWidths[3], lineHeight + 2);
                    xPos += columnWidths[3];
                    drawText(xPos, yPos, 'Sent', columnWidths[4], lineHeight + 2);
                    xPos += columnWidths[4];
                    drawText(xPos, yPos, 'Received', columnWidths[5], lineHeight + 2);
                    xPos += columnWidths[5];
                    drawText(xPos, yPos, 'Dept', columnWidths[6], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.forEach(entry => {
                        const dept = entry.department_name ? `${entry.department_name}${entry.sub_department_name ? ' - ' + entry.sub_department_name : ''}` : 'None';
                        const mainTexts = [
                            entry.document_name || '',
                            entry.document_type || '',
                            entry.sender_name || 'None',
                            entry.receiver_name || 'None',
                            entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'Not Sent',
                            entry.time_received ? new Date(entry.time_received).toLocaleString() : 'Not Received',
                            dept
                        ];
                        const requestTexts = entry.requester_name ? [
                            'Access Request',
                            '',
                            entry.requester_name,
                            entry.owner_name || 'None',
                            entry.time_requested ? new Date(entry.time_requested).toLocaleString() : 'Not Requested',
                            entry.time_approved ? new Date(entry.time_approved).toLocaleString() : 'Not Approved',
                            '-'
                        ] : null;

                        const mainMaxLines = getMaxLines(mainTexts, columnWidths);
                        const requestMaxLines = requestTexts ? getMaxLines(requestTexts, columnWidths) : 0;
                        const rowHeight = (mainMaxLines + (requestTexts ? requestMaxLines : 0)) * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 7; i++) {
                                drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                                xPos += columnWidths[i];
                            }
                            pdf.setTextColor(50, 50, 50);
                            pdf.setFont('helvetica', 'bold');
                            xPos = startX;
                            drawText(xPos, yPos, 'File Name', columnWidths[0], lineHeight + 2);
                            xPos += columnWidths[0];
                            drawText(xPos, yPos, 'Doc Type', columnWidths[1], lineHeight + 2);
                            xPos += columnWidths[1];
                            drawText(xPos, yPos, 'Sender', columnWidths[2], lineHeight + 2);
                            xPos += columnWidths[2];
                            drawText(xPos, yPos, 'Recipient', columnWidths[3], lineHeight + 2);
                            xPos += columnWidths[3];
                            drawText(xPos, yPos, 'Sent', columnWidths[4], lineHeight + 2);
                            xPos += columnWidths[4];
                            drawText(xPos, yPos, 'Received', columnWidths[5], lineHeight + 2);
                            xPos += columnWidths[5];
                            drawText(xPos, yPos, 'Dept', columnWidths[6], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 7; i++) {
                            drawCell(xPos, yPos, columnWidths[i], mainMaxLines * lineHeight + 2, false, i % 2 === 0);
                            xPos += columnWidths[i];
                        }
                        pdf.setTextColor(0, 0, 0);
                        xPos = startX;
                        mainTexts.forEach((text, i) => {
                            drawText(xPos, yPos, text, columnWidths[i], mainMaxLines * lineHeight + 2);
                            xPos += columnWidths[i];
                        });
                        yPos += mainMaxLines * lineHeight + 1;

                        if (requestTexts) {
                            xPos = startX;
                            for (let i = 0; i < 7; i++) {
                                drawCell(xPos, yPos, columnWidths[i], requestMaxLines * lineHeight + 2, false, i % 2 === 0);
                                xPos += columnWidths[i];
                            }
                            xPos = startX;
                            requestTexts.forEach((text, i) => {
                                drawText(xPos, yPos, text, columnWidths[i], requestMaxLines * lineHeight + 2);
                                xPos += columnWidths[i];
                            });
                            yPos += requestMaxLines * lineHeight + 1;
                        }
                    });
                } else if (currentChartType === 'UsersPerDepartment') {
                    const columnWidths = [135, 65]; // 200mm total
                    let xPos = startX;
                    for (let i = 0; i < 2; i++) {
                        drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                        xPos += columnWidths[i];
                    }
                    pdf.setTextColor(50, 50, 50);
                    pdf.setFont('helvetica', 'bold');
                    xPos = startX;
                    drawText(xPos, yPos, 'Department', columnWidths[0], lineHeight + 2);
                    xPos += columnWidths[0];
                    drawText(xPos, yPos, 'Users', columnWidths[1], lineHeight + 2);
                    yPos += lineHeight + 3;
                    pdf.setFont('helvetica', 'normal');

                    chartData.labels.forEach((label, index) => {
                        const texts = [label, String(chartData.data[index])];
                        const maxLines = getMaxLines(texts, columnWidths);
                        const rowHeight = maxLines * lineHeight + 2;

                        if (yPos + rowHeight > pageHeight - margin) {
                            pdf.addPage();
                            yPos = margin;
                            xPos = startX;
                            for (let i = 0; i < 2; i++) {
                                drawCell(xPos, yPos, columnWidths[i], lineHeight + 2, true);
                                xPos += columnWidths[i];
                            }
                            pdf.setTextColor(50, 50, 50);
                            pdf.setFont('helvetica', 'bold');
                            xPos = startX;
                            drawText(xPos, yPos, 'Department', columnWidths[0], lineHeight + 2);
                            xPos += columnWidths[0];
                            drawText(xPos, yPos, 'Users', columnWidths[1], lineHeight + 2);
                            yPos += lineHeight + 3;
                            pdf.setFont('helvetica', 'normal');
                        }

                        xPos = startX;
                        for (let i = 0; i < 2; i++) {
                            drawCell(xPos, yPos, columnWidths[i], rowHeight, false, i % 2 === 0);
                            xPos += columnWidths[i];
                        }
                        pdf.setTextColor(0, 0, 0);
                        xPos = startX;
                        texts.forEach((text, i) => {
                            drawText(xPos, yPos, text, columnWidths[i], rowHeight);
                            xPos += columnWidths[i];
                        });
                        yPos += rowHeight;
                    });
                }

                pdf.save(`${currentChartType}_Report.pdf`);
            }).catch(error => {
                console.error('Error generating PDF:', error);
                alert('Failed to generate PDF. Please try again.');
            });
        }

        // Print Chart (Table design fully copied from downloadChart with forced background colors)
        function printChart() {
            const chartData = getChartData(currentChartType);
            const chartCanvas = document.getElementById('popupChart');

            html2canvas(chartCanvas, {
                scale: 2
            }).then(canvas => {
                const chartImage = canvas.toDataURL('image/png');
                const printWindow = window.open('', '_blank');
                let tableRows = '';

                if (currentChartType === 'FileUploadTrends') {
                    tableRows = chartData.map(entry => {
                        const dept = entry.uploader_department ? `${entry.uploader_department}${entry.uploader_subdepartment ? ' - ' + entry.uploader_subdepartment : ''}` : 'Unknown';
                        const destination = entry.target_department_name ? entry.target_department_name : '(NONE) Personal Document';
                        return `
                            <tr>
                                <td>${entry.document_name}</td>
                                <td>${entry.document_type}</td>
                                <td>${entry.uploader_name}</td>
                                <td>${dept}</td>
                                <td>${destination}</td>
                                <td>${new Date(entry.upload_date).toLocaleString()}</td>
                            </tr>
                        `;
                    }).join('');
                } else if (currentChartType === 'FileDistribution') {
                    tableRows = chartData.map(entry => {
                        const dept = entry.department_name ? `${entry.department_name}${entry.sub_department_name ? ' - ' + entry.sub_department_name : ''}` : 'None';
                        return `
                            <tr>
                                <td>${entry.document_name}</td>
                                <td>${entry.document_type}</td>
                                <td>${entry.sender_name || 'None'}</td>
                                <td>${entry.receiver_name || 'None'}</td>
                                <td>${entry.time_sent ? new Date(entry.time_sent).toLocaleString() : 'Not Sent'}</td>
                                <td>${entry.time_received ? new Date(entry.time_received).toLocaleString() : 'Not Received'}</td>
                                <td>${dept}</td>
                            </tr>
                            ${entry.requester_name ? `
                            <tr>
                                <td colspan="2">Access Request</td>
                                <td>${entry.requester_name}</td>
                                <td>${entry.owner_name || 'None'}</td>
                                <td>${entry.time_requested ? new Date(entry.time_requested).toLocaleString() : 'Not Requested'}</td>
                                <td>${entry.time_approved ? new Date(entry.time_approved).toLocaleString() : 'Not Approved'}</td>
                                <td>-</td>
                            </tr>
                            ` : ''}
                        `;
                    }).join('');
                } else if (currentChartType === 'UsersPerDepartment') {
                    tableRows = chartData.labels.map((label, index) => `
                        <tr>
                            <td>${label}</td>
                            <td>${chartData.data[index]}</td>
                        </tr>
                    `).join('');
                }

                printWindow.document.write(`
                    <html>
                        <head>
                            <title>${currentChartType} Report</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 20px; }
                                h1 { font-size: 24px; text-align: center; margin-bottom: 10px; }
                                h2 { font-size: 18px; margin-top: 20px; }
                                img { max-width: 100%; height: auto; display: block; margin: 0 auto; }
                                table { width: 100%; max-width: 800px; border-collapse: collapse; margin: 20px auto; font-size: 8pt; }
                                th, td { border: 1px solid #969696; padding: 4px; text-align: center; }
                                th { background-color: #f0f0f0 !important; font-weight: bold; color: #323232; }
                                td { color: #000000; }
                                /* Alternating colors for odd-numbered columns */
                                td:nth-child(1), td:nth-child(3), td:nth-child(5) { background-color: #e6f4ea !important; }
                                /* Ensure even-numbered columns have no background */
                                td:nth-child(2), td:nth-child(4), td:nth-child(6), td:nth-child(7) { background-color: transparent !important; }
                                @media print { 
                                    body { margin: 0; } 
                                    img { max-width: 100%; } 
                                    table { font-size: 8pt; }
                                    th { background-color: #f0f0f0 !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                    td:nth-child(1), td:nth-child(3), td:nth-child(5) { background-color: #e6f4ea !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                    td:nth-child(2), td:nth-child(4), td:nth-child(6), td:nth-child(7) { background-color: transparent !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
                                }
                            </style>
                        </head>
                        <body>
                            <h1>${currentChartType} Report</h1>
                            <img src="${chartImage}" alt="${currentChartType} Chart">
                            <h2>Data Table</h2>
                            <table>
                                <thead>
                                    <tr>
                                        ${
                                            currentChartType === 'FileUploadTrends' ? `
                                                <th>File Name</th>
                                                <th>Document Type</th>
                                                <th>Uploader</th>
                                                <th>Uploader's Department</th>
                                                <th>Intended Destination</th>
                                                <th>Upload Date/Time</th>
                                            ` : currentChartType === 'FileDistribution' ? `
                                                <th>File Name</th>
                                                <th>Document Type</th>
                                                <th>Sender</th>
                                                <th>Recipient</th>
                                                <th>Time Sent</th>
                                                <th>Time Received</th>
                                                <th>Department/Subdepartment</th>
                                            ` : `
                                                <th>Department</th>
                                                <th>Users</th>
                                            `
                                        }
                                    </tr>
                                </thead>
                                <tbody>${tableRows}</tbody>
                            </table>
                        </body>
                    </html>
                `);

                printWindow.document.close();
                printWindow.onload = function() {
                    printWindow.focus();
                    printWindow.print();
                };
            }).catch(error => {
                console.error('Error generating print content:', error);
                alert('Failed to generate print preview. Please try again.');
            });
        }
    </script>
</body>

</html>