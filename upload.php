<?php
require 'config.php';

if(!isset($_SESSION['user'])){
    header("Location: login.php");
    exit;
}

$message = '';
$messageType = '';

if(isset($_POST['upload'])){

    if(isset($_FILES['excel']) && $_FILES['excel']['error'] == 0){

        $tmpFile = $_FILES['excel']['tmp_name'];
        $fileName = $_FILES['excel']['name'];

        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExt !== 'csv') {
            $message = "⚠️ Please upload a valid .csv file.";
            $messageType = 'warning';
        } else {
            try {
                $csvFile = "../uploads/SSLProject_import.csv";

                // Ensure the uploads directory exists
                $uploadDir = dirname($csvFile);
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                if (move_uploaded_file($tmpFile, $csvFile)) {
                    $message = "✅ CSV uploaded successfully! Dashboard has been updated with data from <strong>" . htmlspecialchars($fileName) . "</strong>.";
                    $messageType = 'success';
                } else {
                    $message = "❌ Error: Could not save the uploaded file.";
                    $messageType = 'danger';
                }
            } catch(Exception $e){
                $message = "❌ Error: " . htmlspecialchars($e->getMessage());
                $messageType = 'danger';
            }
        }
    } else {
        $message = "⚠️ Please select a valid CSV file.";
        $messageType = 'warning';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>📤 Upload File — SSL Dashboard</title>
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔐</text></svg>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
:root {
    --font-main: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    --bg-body: #f0f2f5;
    --bg-card: #ffffff;
    --bg-header: rgba(255,255,255,0.85);
    --text-primary: #1e293b;
    --text-secondary: #64748b;
    --text-muted: #94a3b8;
    --border-color: #e2e8f0;
    --gradient-total: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --gradient-success: linear-gradient(135deg, #2dce89 0%, #2dcecc 100%);
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 16px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
    --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

[data-theme="dark"] {
    --bg-body: #0f172a;
    --bg-card: #1e293b;
    --bg-header: rgba(15,23,42,0.9);
    --text-primary: #f1f5f9;
    --text-secondary: #cbd5e1;
    --text-muted: #94a3b8;
    --border-color: #334155;
}
[data-theme="dark"] body { background: var(--bg-body); color: var(--text-primary); }
[data-theme="dark"] .upload-card { background: var(--bg-card); border-color: var(--border-color); }
[data-theme="dark"] .drop-zone { background: rgba(102,126,234,0.05); border-color: var(--border-color); }
[data-theme="dark"] .drop-zone:hover { border-color: #667eea; }

* { box-sizing: border-box; }
body {
    font-family: var(--font-main);
    background: var(--bg-body);
    color: var(--text-primary);
    -webkit-font-smoothing: antialiased;
    margin: 0;
    min-height: 100vh;
}

/* Header */
.top-header {
    position: sticky;
    top: 0;
    z-index: 1000;
    background: var(--bg-header);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--border-color);
    padding: 14px 24px;
}
.top-header h2 {
    font-size: 1.35rem;
    font-weight: 800;
    margin: 0;
    background: var(--gradient-total);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.upload-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    padding: 36px;
    max-width: 600px;
    margin: 40px auto;
    box-shadow: var(--shadow-md);
    border: 1px solid var(--border-color);
    animation: fadeInUp 0.5s ease-out;
}

.upload-card h3 {
    font-size: 1.3rem;
    font-weight: 700;
    margin-bottom: 6px;
}

.upload-card .subtitle {
    color: var(--text-muted);
    font-size: 0.88rem;
    margin-bottom: 28px;
}

/* Drop Zone */
.drop-zone {
    border: 2px dashed var(--border-color);
    border-radius: var(--radius-lg);
    padding: 48px 24px;
    text-align: center;
    background: #f8fafc;
    transition: var(--transition);
    cursor: pointer;
    position: relative;
}

.drop-zone:hover,
.drop-zone.dragover {
    border-color: #667eea;
    background: rgba(102, 126, 234, 0.04);
    transform: scale(1.01);
}

.drop-zone .drop-icon {
    font-size: 3rem;
    margin-bottom: 12px;
    display: block;
}

.drop-zone .drop-text {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 6px;
}

.drop-zone .drop-hint {
    color: var(--text-muted);
    font-size: 0.82rem;
}

.drop-zone .browse-link {
    color: #667eea;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
}

/* File Info Preview */
.file-info {
    display: none;
    background: rgba(102, 126, 234, 0.06);
    border: 1px solid rgba(102, 126, 234, 0.2);
    border-radius: var(--radius-md);
    padding: 14px 18px;
    margin-top: 16px;
    animation: fadeInUp 0.3s ease-out;
}
.file-info .file-name {
    font-weight: 700;
    font-size: 0.92rem;
    color: var(--text-primary);
}
.file-info .file-meta {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-top: 2px;
}

/* Buttons */
.btn-upload {
    background: var(--gradient-total);
    border: none;
    color: #fff;
    padding: 13px 28px;
    border-radius: var(--radius-sm);
    font-size: 0.92rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: var(--transition);
    margin-top: 20px;
}
.btn-upload:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.35);
    color: #fff;
}
.btn-upload:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.btn-back {
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    padding: 13px 28px;
    border-radius: var(--radius-sm);
    font-size: 0.92rem;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: var(--transition);
    margin-top: 20px;
    text-decoration: none;
    display: inline-block;
}
.btn-back:hover {
    border-color: #667eea;
    color: #667eea;
    transform: translateY(-1px);
}

/* Message alert */
.upload-alert {
    border-radius: var(--radius-md);
    padding: 14px 18px;
    font-size: 0.9rem;
    margin-bottom: 24px;
    animation: fadeInUp 0.4s ease-out;
}

/* Success animation */
.success-checkmark {
    display: none;
    text-align: center;
    padding: 30px;
    animation: scaleIn 0.4s ease-out;
}
.success-checkmark .check-icon {
    font-size: 3rem;
    margin-bottom: 12px;
}
.success-checkmark .check-text {
    font-size: 1rem;
    font-weight: 600;
    color: #2dce89;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.8); }
    to   { opacity: 1; transform: scale(1); }
}

/* Footer */
.upload-footer {
    text-align: center;
    padding: 20px;
    margin-top: 30px;
    font-size: 0.78rem;
    color: var(--text-muted);
}
</style>
</head>
<body>

<!-- Header -->
<div class="top-header">
    <div class="d-flex justify-content-between align-items-center">
        <h2>📤 Upload Spreadsheet</h2>
        <div class="d-flex gap-2 align-items-center">
            <span style="font-size:0.82rem;color:var(--text-muted);">👤 <?= htmlspecialchars($_SESSION['user']) ?></span>
            <a href="1newdashboard.php" class="btn btn-sm btn-outline-primary" style="border-radius:var(--radius-sm);font-weight:600;font-size:0.82rem;">← Dashboard</a>
        </div>
    </div>
</div>

<div class="upload-card">

    <h3>📊 Upload SSL Data File</h3>
    <p class="subtitle">Upload an Excel (.xlsx, .xls) or CSV (.csv) file to update the SSL certificate data on the dashboard.</p>

    <?php if($message): ?>
        <?php if($messageType === 'success'): ?>
            <div class="success-checkmark" style="display:block;">
                <div class="check-icon">✅</div>
                <div class="check-text">Upload Successful!</div>
                <p style="color:var(--text-muted);font-size:0.85rem;margin-top:8px;"><?= $message ?></p>
                <a href="1newdashboard.php" class="btn-upload" style="display:inline-block;text-decoration:none;margin-top:16px;">View Dashboard →</a>
            </div>
        <?php else: ?>
            <div class="alert alert-<?= $messageType ?> upload-alert">
                <?= $message ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if($messageType !== 'success'): ?>
    <form method="post" enctype="multipart/form-data" id="uploadForm">

        <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click();">
            <span class="drop-icon">📂</span>
            <div class="drop-text">Drag & drop your Excel or CSV file here</div>
            <div class="drop-hint">or <span class="browse-link">browse files</span> · Accepts .xlsx, .xls, .csv</div>
            <input type="file" name="excel" id="fileInput" accept=".csv,.xlsx,.xls" required style="display:none;">
        </div>

        <div class="file-info" id="fileInfo">
            <div class="d-flex align-items-center gap-2">
                <span style="font-size:1.4rem;">📋</span>
                <div>
                    <div class="file-name" id="fileName"></div>
                    <div class="file-meta" id="fileMeta"></div>
                </div>
                <button type="button" onclick="clearFile()" style="margin-left:auto;background:none;border:none;cursor:pointer;font-size:1.1rem;color:var(--text-muted);" title="Remove file">✕</button>
            </div>
        </div>

        <div class="d-flex gap-3 flex-wrap">
            <button type="submit" name="upload" class="btn-upload" id="uploadBtn" disabled>
                📤 Upload File
            </button>
            <a href="1newdashboard.php" class="btn-back">← Back to Dashboard</a>
        </div>

    </form>
    <?php endif; ?>

</div>

<div class="upload-footer">
    🔐 SSL Renewal Dashboard · Developed by <strong>RAKESH G</strong>
</div>

<script>
// Apply saved dark mode
(function(){
    document.documentElement.dataset.theme = localStorage.getItem('theme') || '';
})();

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileInfo = document.getElementById('fileInfo');
const uploadBtn = document.getElementById('uploadBtn');

if(dropZone) {
    // Drag & Drop
    ['dragenter','dragover'].forEach(evt => {
        dropZone.addEventListener(evt, e => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
    });

    ['dragleave','drop'].forEach(evt => {
        dropZone.addEventListener(evt, e => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        });
    });

    dropZone.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if(files.length) {
            fileInput.files = files;
            showFileInfo(files[0]);
        }
    });

    fileInput.addEventListener('change', function(){
        if(this.files.length) {
            showFileInfo(this.files[0]);
        }
    });
}

function showFileInfo(file) {
    const name = file.name;
    const size = (file.size / 1024).toFixed(1);
    document.getElementById('fileName').textContent = name;
    document.getElementById('fileMeta').textContent = size + ' KB · ' + file.type.split('.').pop();
    fileInfo.style.display = 'block';
    dropZone.style.display = 'none';
    uploadBtn.disabled = false;
}

function clearFile() {
    fileInput.value = '';
    fileInfo.style.display = 'none';
    dropZone.style.display = 'block';
    uploadBtn.disabled = true;
}

const form = document.getElementById('uploadForm');
if (form) {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const file = fileInput.files[0];
        if (!file) return;

        uploadBtn.disabled = true;
        uploadBtn.textContent = '⏳ Processing...';

        const ext = file.name.split('.').pop().toLowerCase();
        let uploadFile = file;

        if (ext === 'xlsx' || ext === 'xls') {
            try {
                const data = await file.arrayBuffer();
                const workbook = XLSX.read(data);
                const firstSheetName = workbook.SheetNames[0];
                const worksheet = workbook.Sheets[firstSheetName];
                const csvString = XLSX.utils.sheet_to_csv(worksheet);
                uploadFile = new File([csvString], file.name.replace(/\.[^/.]+$/, "") + ".csv", {type: "text/csv"});
            } catch (err) {
                alert("Error parsing Excel file. Please try a different file.");
                uploadBtn.disabled = false;
                uploadBtn.textContent = '📤 Upload File';
                return;
            }
        }

        const formData = new FormData();
        formData.append('excel', uploadFile);
        formData.append('upload', '1');

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const html = await response.text();
            document.open();
            document.write(html);
            document.close();
        } catch (err) {
            alert("Upload failed.");
            uploadBtn.disabled = false;
            uploadBtn.textContent = '📤 Upload File';
        }
    });
}
</script>

</body>
</html>