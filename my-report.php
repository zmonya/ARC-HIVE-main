<?php
session_start();
require 'db_connection.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? 'user';

try {
    // Fetch user departments
    $stmt = $pdo->prepare("
        SELECT d.id, d.name 
        FROM departments d
        JOIN user_department_affiliations uda ON d.id = uda.department_id 
        WHERE uda.user_id = ?
    ");
    $stmt->execute([$userId]);
    $userDepartments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch user details
    $stmt = $pdo->prepare("
        SELECT u.*, GROUP_CONCAT(d.name SEPARATOR ', ') AS department_names 
        FROM users u 
        LEFT JOIN user_department_affiliations uda ON u.id = uda.user_id 
        LEFT JOIN departments d ON uda.department_id = d.id 
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        die("Error: User not found for ID $userId.");
    }
} catch (PDOException $e) {
    die("Error fetching user data: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style/my-report.css">
    <link rel="stylesheet" href="style/client-sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

</head>

<body>
    <div class="top-nav">
        <h2>Generate Report</h2>
        <div class="filter-container">
            <select id="interval" onchange="updateChart()">
                <option value="day">Daily</option>
                <option value="week">Weekly</option>
                <option value="month">Monthly</option>
                <option value="range">Custom Range</option>
            </select>
            <div id="dateRange" style="display: none;">
                <label>Start Date: <input type="date" id="startDate"></label>
                <label>End Date: <input type="date" id="endDate"></label>
            </div>
            <button onclick="updateChart()">Apply</button>
        </div>
    </div>

    <div class="sidebar">
        <button class="toggle-btn" title="Toggle Sidebar"><i class="fas fa-bars"></i></button>
        <h2 class="sidebar-title">Document Archival</h2>
        <?php if ($userRole === 'admin'): ?>
            <a href="admin_dashboard.php" class="admin-dashboard-btn"><i class="fas fa-user-shield"></i><span class="link-text">Admin Dashboard</span></a>
        <?php endif; ?>
        <a href="dashboard.php"><i class="fas fa-home"></i><span class="link-text"> Dashboard</span></a>
        <a href="my-report.php" class="active"><i class="fas fa-chart-bar"></i><span class="link-text"> My Report</span></a>
        <a href="my-folder.php"><i class="fas fa-folder"></i><span class="link-text"> My Folder</span></a>
        <?php foreach ($userDepartments as $dept): ?>
            <a href="department_folder.php?department_id=<?= $dept['id'] ?>"><i class="fas fa-folder"></i><span class="link-text"> <?= htmlspecialchars($dept['name']) ?></span></a>
        <?php endforeach; ?>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i><span class="link-text">Logout</span></a>
    </div>

    <div class="main-content">
        <h2>My Report</h2>
        <div id="print-header">
            <h2>User: <?= htmlspecialchars($user['full_name']) ?></h2>
            <h2>Department: <?= htmlspecialchars($user['department_names'] ?: 'None') ?></h2>
            <h2>Report Date Range: <span id="report-date-range"></span></h2>
        </div>

        <div class="chart-container">
            <canvas id="fileActivityChart"></canvas>
            <div class="chart-actions">
                <button onclick="downloadChart()">Download PDF</button>
                <button onclick="printChart()">Print</button>
            </div>
        </div>

        <div class="files-table">
            <h3>File Details</h3>
            <div class="table-controls">
                <select id="sortBy" onchange="sortTable()" style="color: black; background-color: white;">
                    <option value="newest" selected style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Sort by Newest
                    </option>
                    <option value="oldest" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Sort by Oldest
                    </option>
                </select>
                <select id="filterDirection" onchange="filterTable()" style="color: black; background-color: white;">
                    <option value="all" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        All Directions
                    </option>
                    <option value="Sent" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Sent
                    </option>
                    <option value="Received" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Received
                    </option>
                    <option value="Received (Department)" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Received (Department)
                    </option>
                    <option value="Requested" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Requested
                    </option>
                    <option value="Request Approved" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Request Approved
                    </option>
                    <option value="Request Denied" style="color: black;" onmouseover="this.style.color='white'; this.style.backgroundColor='black'" onmouseout="this.style.color='black'; this.style.backgroundColor='white'">
                        Request Denied
                    </option>
                </select>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Document Type</th>
                        <th>Date</th>
                        <th>Department</th>
                        <th>Uploader</th>
                        <th>Direction</th>
                    </tr>
                </thead>
                <tbody id="fileTableBody"></tbody>
            </table>
        </div>
    </div>

    <script>
        let fileActivityChart;
        let tableData = [];
        const ctx = document.getElementById('fileActivityChart').getContext('2d');
        const {
            jsPDF
        } = window.jspdf;

        function updateChart() {
            const interval = document.getElementById('interval').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            document.getElementById('dateRange').style.display = interval === 'range' ? 'block' : 'none';

            let url = `fetch_incoming_outgoing.php?interval=${interval}`;
            if (interval === 'range' && startDate && endDate) {
                if (new Date(startDate) > new Date(endDate)) {
                    alert('Start date must be before end date.');
                    return;
                }
                url += `&startDate=${startDate}&endDate=${endDate}`;
                document.getElementById('report-date-range').textContent = `${startDate} to ${endDate}`;
            } else {
                document.getElementById('report-date-range').textContent = interval.charAt(0).toUpperCase() + interval.slice(1);
            }

            fetch(url)
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP error! Status: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    console.log('Fetched data:', data);

                    if (data.error) {
                        alert(data.error);
                        return;
                    }

                    const labels = data.labels || [];
                    const filesSent = (data.datasets?.files_sent || []).map(val => parseInt(val) || 0);
                    const filesReceived = (data.datasets?.files_received || []).map(val => parseInt(val) || 0);
                    const filesRequested = (data.datasets?.files_requested || []).map(val => parseInt(val) || 0);
                    const filesReceivedFromRequest = (data.datasets?.files_received_from_request || []).map(val => parseInt(val) || 0);

                    const chartData = {
                        labels: labels,
                        datasets: [{
                                label: 'Files Sent',
                                data: filesSent,
                                backgroundColor: '#36A2EB',
                                stack: 'files',
                                borderWidth: 1,
                                borderColor: '#2A80B9'
                            },
                            {
                                label: 'Files Received',
                                data: filesReceived,
                                backgroundColor: '#FF6384',
                                stack: 'files',
                                borderWidth: 1,
                                borderColor: '#CC4F67'
                            },
                            {
                                label: 'Files Requested',
                                data: filesRequested,
                                backgroundColor: '#FFCE56',
                                stack: 'files',
                                borderWidth: 1,
                                borderColor: '#CCAB45'
                            },
                            {
                                label: 'Files Received (Request)',
                                data: filesReceivedFromRequest,
                                backgroundColor: '#4BC0C0',
                                stack: 'files',
                                borderWidth: 1,
                                borderColor: '#3A9999'
                            }
                        ]
                    };

                    if (fileActivityChart) fileActivityChart.destroy();
                    fileActivityChart = new Chart(ctx, {
                        type: 'bar',
                        data: chartData,
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    position: 'top',
                                    labels: {
                                        font: {
                                            size: 14
                                        },
                                        padding: 15
                                    }
                                },
                                title: {
                                    display: true,
                                    text: `File Activity (${interval.charAt(0).toUpperCase() + interval.slice(1)})`,
                                    font: {
                                        size: 18
                                    }
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: true,
                                    padding: 10
                                }
                            },
                            scales: {
                                x: {
                                    stacked: true,
                                    title: {
                                        display: true,
                                        text: interval === 'range' ? 'Date' : interval.charAt(0).toUpperCase() + interval.slice(1),
                                        font: {
                                            size: 14
                                        }
                                    },
                                    grid: {
                                        display: false
                                    }
                                },
                                y: {
                                    stacked: true,
                                    title: {
                                        display: true,
                                        text: 'Number of Files',
                                        font: {
                                            size: 14
                                        }
                                    },
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1,
                                        font: {
                                            size: 12
                                        }
                                    },
                                    grid: {
                                        color: '#e0e0e0'
                                    }
                                }
                            }
                        }
                    });

                    tableData = data.tableData || [];
                    updateTable(tableData);
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    alert('Failed to load report data: ' + error.message);
                });
        }

        function updateTable(data) {
            const tbody = document.getElementById('fileTableBody');
            tbody.innerHTML = '';
            console.log('Table data:', data);

            if (!Array.isArray(data) || data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6">No file activity data available.</td></tr>';
                return;
            }

            data.forEach(file => {
                const row = document.createElement('tr');
                const date = file.upload_date ? new Date(file.upload_date) : null;
                const dateStr = date ? date.toLocaleString() : 'N/A';
                row.innerHTML = `
                    <td>${file.file_name || 'N/A'}</td>
                    <td>${file.document_type || 'N/A'}</td>
                    <td data-date="${file.upload_date || ''}">${dateStr}</td>
                    <td>${file.department_name || 'N/A'}</td>
                    <td>${file.uploader || 'N/A'}</td>
                    <td>${file.direction || 'N/A'}</td>
                `;
                row.dataset.direction = file.direction || 'N/A';
                tbody.appendChild(row);
            });

            sortTable();
            filterTable();
        }

        function sortTable() {
            const sortBy = document.getElementById('sortBy').value;
            const tbody = document.getElementById('fileTableBody');
            const rows = Array.from(tbody.getElementsByTagName('tr'));

            rows.sort((a, b) => {
                const dateA = new Date(a.querySelector('td[data-date]').dataset.date || '0');
                const dateB = new Date(b.querySelector('td[data-date]').dataset.date || '0');
                return sortBy === 'oldest' ? dateA - dateB : dateB - dateA;
            });

            rows.forEach(row => tbody.appendChild(row));
        }

        function filterTable() {
            const filterDirection = document.getElementById('filterDirection').value;
            const tbody = document.getElementById('fileTableBody');
            const rows = tbody.getElementsByTagName('tr');

            Array.from(rows).forEach(row => {
                const direction = row.dataset.direction;
                row.style.display = (filterDirection === 'all' || direction === filterDirection) ? '' : 'none';
            });
        }

        async function downloadChart() {
            const canvas = document.getElementById('fileActivityChart');
            const table = document.querySelector('.files-table');
            const header = document.getElementById('print-header');
            try {
                header.style.display = 'block';
                const [headerImg, chartImg, tableImg] = await Promise.all([
                    html2canvas(header, {
                        scale: 2
                    }),
                    html2canvas(canvas, {
                        scale: 2
                    }),
                    html2canvas(table, {
                        scale: 2
                    })
                ]);
                header.style.display = 'none';

                const pdf = new jsPDF('p', 'mm', 'a4');
                const width = pdf.internal.pageSize.getWidth();
                const headerHeight = (headerImg.height * (width - 20)) / headerImg.width;
                const chartHeight = (chartImg.height * (width - 20)) / chartImg.width;
                const tableHeight = (tableImg.height * (width - 20)) / tableImg.width;

                let yPos = 10;
                pdf.addImage(headerImg.toDataURL('image/png'), 'PNG', 10, yPos, width - 20, headerHeight);
                yPos += headerHeight + 10;
                pdf.addImage(chartImg.toDataURL('image/png'), 'PNG', 10, yPos, width - 20, chartHeight);
                yPos += chartHeight + 10;

                if (yPos + tableHeight > pdf.internal.pageSize.getHeight()) {
                    pdf.addPage();
                    yPos = 10;
                }
                pdf.addImage(tableImg.toDataURL('image/png'), 'PNG', 10, yPos, width - 20, tableHeight);

                pdf.save('FileActivityReport.pdf');
            } catch (error) {
                console.error('PDF generation error:', error);
                alert('Failed to generate PDF: ' + error.message);
            }
        }

        async function printChart() {
            const canvas = document.getElementById('fileActivityChart');
            const table = document.querySelector('.files-table');
            const header = document.getElementById('print-header');
            try {
                header.style.display = 'block';
                const [headerImg, chartImg, tableImg] = await Promise.all([
                    html2canvas(header, {
                        scale: 2
                    }),
                    html2canvas(canvas, {
                        scale: 2
                    }),
                    html2canvas(table, {
                        scale: 2
                    })
                ]);
                header.style.display = 'none';

                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head><title>File Activity Report</title>
                            <style>
                                body { font-family: 'Montserrat', sans-serif; }
                                img { max-width: 100%; page-break-inside: avoid; }
                                table { width: 100%; border-collapse: collapse; }
                                th, td { border: 1px solid #000; padding: 8px; }
                                h2 { margin: 5px 0; }
                            </style>
                        </head>
                        <body>
                            <div>${header.innerHTML}</div>
                            <img src="${chartImg.toDataURL('image/png')}">
                            <h3>File Details</h3>
                            <img src="${tableImg.toDataURL('image/png')}">
                        </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.print();
            } catch (error) {
                console.error('Print error:', error);
                alert('Failed to print report: ' + error.message);
            }
        }

        updateChart();
    </script>
</body>

</html>