<?php
/**
 * FHIR Bundle Test Page
 * 
 * Simple web interface to test FHIR Bundle POST endpoint
 * Accessible after login at: interface/main/fhir_bundle_test.php
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . '/../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Get base URL for API - use relative path for internal calls
$site_id = $_SESSION['site_id'] ?? 'default';

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('FHIR Bundle 測試'); ?></title>
    <style>
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            border-bottom: 3px solid #4CAF50;
            padding-bottom: 10px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        textarea {
            width: 100%;
            min-height: 300px;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            resize: vertical;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }
        
        .btn-primary {
            background-color: #4CAF50;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #45a049;
        }
        
        .btn-secondary {
            background-color: #2196F3;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #0b7dda;
        }
        
        .btn-danger {
            background-color: #f44336;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #da190b;
        }
        
        .example-link {
            color: #2196F3;
            text-decoration: none;
            margin: 0 5px;
        }
        
        .example-link:hover {
            text-decoration: underline;
        }
        
        .info-box {
            background-color: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .result-container {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
            background-color: #f5f5f5;
        }
        
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .result-header h3 {
            margin: 0;
        }
        
        .status-badge {
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .status-success {
            background-color: #4CAF50;
            color: white;
        }
        
        .status-error {
            background-color: #f44336;
            color: white;
        }
        
        .result-content {
            background-color: white;
            padding: 15px;
            border-radius: 4px;
            border: 1px solid #ddd;
            max-height: 500px;
            overflow: auto;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 12px;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4CAF50;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo xlt('FHIR Bundle 測試介面'); ?></h1>
        <p class="subtitle"><?php echo xlt('直接測試 FHIR Bundle POST 端點'); ?></p>
        
        <div class="info-box">
            <strong><?php echo xlt('API 端點'); ?>：</strong> /apis/<?php echo htmlspecialchars($site_id); ?>/fhir/Bundle<br>
            <strong><?php echo xlt('方法'); ?>：</strong> POST<br>
            <strong><?php echo xlt('支援方式'); ?>：</strong> 直接 POST JSON 或上傳 .json 檔案<br>
            <strong><?php echo xlt('提示'); ?>：</strong> 使用範例時，系統會自動處理 Bundle 內的資源引用（如 Patient、Encounter、Observation 之間的引用）。
        </div>
        
        <form id="bundleForm" enctype="multipart/form-data">
            <div class="form-group">
                <label>
                    <?php echo xlt('上傳方式'); ?>
                </label>
                <div style="margin-bottom: 15px;">
                    <label style="display: inline-flex; align-items: center; margin-right: 20px; cursor: pointer;">
                        <input type="radio" name="uploadType" value="json" checked onchange="toggleUploadType()" style="margin-right: 5px;">
                        <?php echo xlt('直接輸入 JSON'); ?>
                    </label>
                    <label style="display: inline-flex; align-items: center; cursor: pointer;">
                        <input type="radio" name="uploadType" value="file" onchange="toggleUploadType()" style="margin-right: 5px;">
                        <?php echo xlt('上傳 .json 檔案'); ?>
                    </label>
                </div>
            </div>
            
            <div class="form-group" id="jsonInputGroup">
                <label for="bundleJson">
                    <?php echo xlt('Bundle JSON'); ?> 
                    <span style="margin-left: 10px;">
                        <a href="#" class="example-link" onclick="loadExample('patient'); return false;"><?php echo xlt('範例：Patient'); ?></a> | 
                        <a href="#" class="example-link" onclick="loadExample('patient_observation'); return false;"><?php echo xlt('範例：Patient + Observation'); ?></a> |
                        <a href="#" class="example-link" onclick="loadExample('patient_encounter_observation'); return false;"><?php echo xlt('範例：Patient + Encounter + Observation'); ?></a> |
                        <a href="#" class="example-link" onclick="loadExample('multiple_patients'); return false;"><?php echo xlt('範例：多個 Patient'); ?></a>
                    </span>
                </label>
                <textarea id="bundleJson" name="bundleJson" placeholder='<?php echo xlt('請輸入 FHIR Bundle JSON'); ?>'></textarea>
            </div>
            
            <div class="form-group" id="fileInputGroup" style="display: none;">
                <label for="bundleFile">
                    <?php echo xlt('選擇 .json 檔案（可多選）'); ?>
                </label>
                <input type="file" id="bundleFile" name="file" accept=".json,application/json" multiple style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 100%;">
                <small style="color: #666; display: block; margin-top: 5px;">
                    <?php echo xlt('僅支援 .json 格式檔案，可一次選擇多個檔案，系統會逐一處理'); ?>
                </small>
                <div id="fileList" style="margin-top: 10px; display: none;">
                    <strong><?php echo xlt('已選擇的檔案'); ?>：</strong>
                    <ul id="fileListItems" style="margin: 5px 0; padding-left: 20px;"></ul>
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" class="btn btn-primary"><?php echo xlt('發送請求'); ?></button>
                <button type="button" class="btn btn-secondary" onclick="formatJson()" id="formatBtn"><?php echo xlt('格式化 JSON'); ?></button>
                <button type="button" class="btn btn-danger" onclick="clearForm()"><?php echo xlt('清除'); ?></button>
            </div>
        </form>
        
        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p style="margin-top: 10px;"><?php echo xlt('處理中...'); ?></p>
            <div id="progressInfo" style="margin-top: 10px; font-size: 14px; color: #666;"></div>
        </div>
        
        <div id="resultContainer" style="display: none;">
            <div class="result-container">
                <div class="result-header">
                    <h3><?php echo xlt('回應結果'); ?></h3>
                    <span class="status-badge" id="statusBadge"></span>
                </div>
                <div class="result-content" id="resultContent"></div>
            </div>
        </div>
        
        <div id="multiFileResults" style="display: none; margin-top: 20px;">
            <h3><?php echo xlt('批次處理結果'); ?></h3>
            <div id="multiFileResultsContent"></div>
        </div>
    </div>
    
    <script>
        const siteId = <?php echo js_escape($site_id); ?>;
        const csrfToken = <?php echo js_escape(CsrfUtils::collectCsrfToken('api')); ?>;
        
        function toggleUploadType() {
            const uploadType = document.querySelector('input[name="uploadType"]:checked').value;
            const jsonInputGroup = document.getElementById('jsonInputGroup');
            const fileInputGroup = document.getElementById('fileInputGroup');
            const formatBtn = document.getElementById('formatBtn');
            
            if (uploadType === 'json') {
                jsonInputGroup.style.display = 'block';
                fileInputGroup.style.display = 'none';
                formatBtn.style.display = 'inline-block';
            } else {
                jsonInputGroup.style.display = 'none';
                fileInputGroup.style.display = 'block';
                formatBtn.style.display = 'none';
            }
        }
        
        // 顯示已選擇的檔案列表
        document.getElementById('bundleFile').addEventListener('change', function(e) {
            const fileList = document.getElementById('fileList');
            const fileListItems = document.getElementById('fileListItems');
            const files = e.target.files;
            
            if (files && files.length > 0) {
                fileList.style.display = 'block';
                fileListItems.innerHTML = '';
                for (let i = 0; i < files.length; i++) {
                    const li = document.createElement('li');
                    li.textContent = files[i].name + ' (' + (files[i].size / 1024).toFixed(2) + ' KB)';
                    fileListItems.appendChild(li);
                }
            } else {
                fileList.style.display = 'none';
            }
        });
        
        document.getElementById('bundleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const uploadType = document.querySelector('input[name="uploadType"]:checked').value;
            
            // Show loading
            document.getElementById('loading').style.display = 'block';
            document.getElementById('resultContainer').style.display = 'none';
            
            try {
                let response;
                
                if (uploadType === 'file') {
                    // Handle file upload (support multiple files)
                    const fileInput = document.getElementById('bundleFile');
                    if (!fileInput.files || !fileInput.files.length) {
                        alert(<?php echo xlj('請至少選擇一個 .json 檔案'); ?>);
                        document.getElementById('loading').style.display = 'none';
                        return;
                    }
                    
                    const files = Array.from(fileInput.files);
                    const results = [];
                    let successCount = 0;
                    let failCount = 0;
                    
                    // Hide single result container, show multi-file results
                    document.getElementById('resultContainer').style.display = 'none';
                    document.getElementById('multiFileResults').style.display = 'none';
                    const multiFileResultsContent = document.getElementById('multiFileResultsContent');
                    multiFileResultsContent.innerHTML = '';
                    
                    // Process each file sequentially
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        const progressInfo = document.getElementById('progressInfo');
                        progressInfo.textContent = <?php echo xlj('處理中'); ?> + `: ${i + 1}/${files.length} - ${file.name}`;
                        
                        try {
                            const formData = new FormData();
                            formData.append('file', file);
                            
                            const fileResponse = await fetch(`/apis/${siteId}/fhir/Bundle`, {
                                method: 'POST',
                                headers: {
                                    'APICSRFTOKEN': csrfToken
                                },
                                body: formData
                            });
                            
                            let parsedData;
                            const contentType = fileResponse.headers.get('content-type');
                            if (contentType && contentType.includes('application/json')) {
                                parsedData = await fileResponse.json();
                            } else {
                                const text = await fileResponse.text();
                                parsedData = {
                                    raw: text,
                                    parseError: text ? 'Response is not valid JSON' : 'Empty response'
                                };
                            }
                            
                            const isSuccess = fileResponse.ok;
                            if (isSuccess) {
                                successCount++;
                            } else {
                                failCount++;
                            }
                            
                            results.push({
                                fileName: file.name,
                                fileSize: (file.size / 1024).toFixed(2) + ' KB',
                                status: fileResponse.status,
                                statusText: fileResponse.statusText,
                                success: isSuccess,
                                data: parsedData
                            });
                            
                            // Add result to display
                            const resultDiv = document.createElement('div');
                            resultDiv.style.cssText = 'margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;';
                            resultDiv.innerHTML = `
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <strong style="font-size: 16px;">${file.name}</strong>
                                    <span class="status-badge ${isSuccess ? 'status-success' : 'status-error'}" style="padding: 4px 12px; border-radius: 4px; font-size: 12px;">
                                        ${isSuccess ? <?php echo xlj('成功'); ?> : <?php echo xlj('失敗'); ?>} (${fileResponse.status})
                                    </span>
                                </div>
                                <div style="background: #fff; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                                    <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; font-size: 12px;">${JSON.stringify(parsedData, null, 2)}</pre>
                                </div>
                            `;
                            multiFileResultsContent.appendChild(resultDiv);
                            
                            // Small delay to prevent overwhelming the server
                            if (i < files.length - 1) {
                                await new Promise(resolve => setTimeout(resolve, 100));
                            }
                            
                        } catch (error) {
                            failCount++;
                            results.push({
                                fileName: file.name,
                                fileSize: (file.size / 1024).toFixed(2) + ' KB',
                                status: 'Error',
                                statusText: error.message,
                                success: false,
                                data: { error: error.message }
                            });
                            
                            const resultDiv = document.createElement('div');
                            resultDiv.style.cssText = 'margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;';
                            resultDiv.innerHTML = `
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <strong style="font-size: 16px;">${file.name}</strong>
                                    <span class="status-badge status-error" style="padding: 4px 12px; border-radius: 4px; font-size: 12px;">
                                        <?php echo xlt('錯誤'); ?>
                                    </span>
                                </div>
                                <div style="background: #fff; padding: 10px; border-radius: 4px;">
                                    <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word; font-size: 12px; color: #d32f2f;">${JSON.stringify({ error: error.message, stack: error.stack }, null, 2)}</pre>
                                </div>
                            `;
                            multiFileResultsContent.appendChild(resultDiv);
                        }
                    }
                    
                    // Show summary
                    const summaryDiv = document.createElement('div');
                    summaryDiv.style.cssText = 'margin-bottom: 20px; padding: 15px; border: 2px solid #4caf50; border-radius: 4px; background: #e8f5e9;';
                    summaryDiv.innerHTML = `
                        <h4 style="margin: 0 0 10px 0;"><?php echo xlt('處理完成'); ?></h4>
                        <p style="margin: 5px 0;">
                            <strong><?php echo xlt('總檔案數'); ?>：</strong> ${files.length}<br>
                            <strong style="color: #4caf50;"><?php echo xlt('成功'); ?>：</strong> ${successCount}<br>
                            <strong style="color: #f44336;"><?php echo xlt('失敗'); ?>：</strong> ${failCount}
                        </p>
                    `;
                    multiFileResultsContent.insertBefore(summaryDiv, multiFileResultsContent.firstChild);
                    
                    document.getElementById('multiFileResults').style.display = 'block';
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('progressInfo').textContent = '';
                    return;
                } else {
                    // Handle direct JSON POST
                    const bundleJson = document.getElementById('bundleJson').value.trim();
                    if (!bundleJson) {
                        alert(<?php echo xlj('請輸入 Bundle JSON'); ?>);
                        document.getElementById('loading').style.display = 'none';
                        return;
                    }
                    
                    // Validate JSON
                    try {
                        JSON.parse(bundleJson);
                    } catch (error) {
                        alert(<?php echo xlj('JSON 格式錯誤'); ?> + ': ' + error.message);
                        document.getElementById('loading').style.display = 'none';
                        return;
                    }
                    
                    response = await fetch(`/apis/${siteId}/fhir/Bundle`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'APICSRFTOKEN': csrfToken
                        },
                        body: bundleJson
                    });
                }
                
                let parsedData;
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    parsedData = await response.json();
                } else {
                    const text = await response.text();
                    parsedData = {
                        raw: text,
                        parseError: text ? 'Response is not valid JSON' : 'Empty response'
                    };
                }
                
                // Hide loading
                document.getElementById('loading').style.display = 'none';
                
                // Show result
                const resultContainer = document.getElementById('resultContainer');
                const resultContent = document.getElementById('resultContent');
                const statusBadge = document.getElementById('statusBadge');
                
                resultContainer.style.display = 'block';
                
                // Set status badge
                if (response.ok) {
                    statusBadge.textContent = <?php echo xlj('成功'); ?> + ' (' + response.status + ')';
                    statusBadge.className = 'status-badge status-success';
                } else {
                    statusBadge.textContent = <?php echo xlj('錯誤'); ?> + ' (' + response.status + ' ' + response.statusText + ')';
                    statusBadge.className = 'status-badge status-error';
                }
                
                // Format and display result
                resultContent.textContent = JSON.stringify(parsedData, null, 2);
                
            } catch (error) {
                console.error('Fetch error:', error);
                document.getElementById('loading').style.display = 'none';
                document.getElementById('resultContainer').style.display = 'block';
                document.getElementById('statusBadge').textContent = <?php echo xlj('請求失敗'); ?>;
                document.getElementById('statusBadge').className = 'status-badge status-error';
                document.getElementById('resultContent').textContent = JSON.stringify({
                    error: error.message,
                    stack: error.stack,
                    name: error.name
                }, null, 2);
            }
        });
        
        function formatJson() {
            const textarea = document.getElementById('bundleJson');
            const text = textarea.value.trim();
            if (!text) {
                alert(<?php echo xlj('請先輸入 JSON'); ?>);
                return;
            }
            
            try {
                const json = JSON.parse(text);
                textarea.value = JSON.stringify(json, null, 2);
            } catch (error) {
                alert(<?php echo xlj('JSON 格式錯誤，無法格式化'); ?> + ': ' + error.message);
            }
        }
        
        function clearForm() {
            if (confirm(<?php echo xlj('確定要清除所有內容嗎？'); ?>)) {
                document.getElementById('bundleJson').value = '';
                const fileInput = document.getElementById('bundleFile');
                if (fileInput) {
                    fileInput.value = '';
                }
                document.getElementById('resultContainer').style.display = 'none';
            }
        }
        
        function loadExample(type) {
            let example;
            
            if (type === 'patient') {
                example = {
                    "resourceType": "Bundle",
                    "type": "transaction",
                    "entry": [
                        {
                            "fullUrl": "urn:uuid:patient-001",
                            "resource": {
                                "resourceType": "Patient",
                                "name": [
                                    {
                                        "use": "official",
                                        "family": "Wang",
                                        "given": ["Xiao", "Ming"]
                                    }
                                ],
                                "gender": "male",
                                "birthDate": "1990-01-01",
                                "telecom": [
                                    {
                                        "system": "phone",
                                        "value": "0912345678"
                                    },
                                    {
                                        "system": "email",
                                        "value": "test@example.com"
                                    }
                                ],
                                "address": [
                                    {
                                        "line": ["123 Main Street"],
                                        "city": "Taipei",
                                        "postalCode": "100",
                                        "country": "TW"
                                    }
                                ]
                            }
                        }
                    ]
                };
            } else if (type === 'patient_observation') {
                example = {
                    "resourceType": "Bundle",
                    "type": "transaction",
                    "entry": [
                        {
                            "fullUrl": "urn:uuid:patient-001",
                            "resource": {
                                "resourceType": "Patient",
                                "name": [
                                    {
                                        "use": "official",
                                        "family": "Lee",
                                        "given": ["Mei", "Ling"]
                                    }
                                ],
                                "gender": "female",
                                "birthDate": "1985-05-20",
                                "telecom": [
                                    {
                                        "system": "phone",
                                        "value": "0923456789"
                                    }
                                ]
                            }
                        },
                        {
                            "resource": {
                                "resourceType": "Observation",
                                "status": "final",
                                "code": {
                                    "coding": [
                                        {
                                            "system": "http://loinc.org",
                                            "code": "8480-6",
                                            "display": "Systolic blood pressure"
                                        }
                                    ],
                                    "text": "Systolic blood pressure"
                                },
                                "subject": {
                                    "reference": "urn:uuid:patient-001",
                                    "display": "Patient"
                                },
                                "effectiveDateTime": "2024-01-15T10:30:00Z",
                                "valueQuantity": {
                                    "value": 120,
                                    "unit": "mmHg",
                                    "system": "http://unitsofmeasure.org",
                                    "code": "mm[Hg]"
                                },
                                "category": [
                                    {
                                        "coding": [
                                            {
                                                "system": "http://terminology.hl7.org/CodeSystem/observation-category",
                                                "code": "vital-signs",
                                                "display": "Vital Signs"
                                            }
                                        ]
                                    }
                                ]
                            }
                        }
                    ]
                };
            } else if (type === 'patient_encounter_observation') {
                example = {
                    "resourceType": "Bundle",
                    "type": "transaction",
                    "entry": [
                        {
                            "fullUrl": "urn:uuid:patient-001",
                            "resource": {
                                "resourceType": "Patient",
                                "name": [
                                    {
                                        "use": "official",
                                        "family": "Chen",
                                        "given": ["Wei", "Ming"]
                                    }
                                ],
                                "gender": "male",
                                "birthDate": "1992-03-15",
                                "telecom": [
                                    {
                                        "system": "phone",
                                        "value": "0934567890"
                                    }
                                ]
                            }
                        },
                        {
                            "fullUrl": "urn:uuid:encounter-001",
                            "resource": {
                                "resourceType": "Encounter",
                                "status": "finished",
                                "class": {
                                    "system": "http://terminology.hl7.org/CodeSystem/v3-ActCode",
                                    "code": "AMB",
                                    "display": "ambulatory"
                                },
                                "subject": {
                                    "reference": "urn:uuid:patient-001"
                                },
                                "period": {
                                    "start": "2024-01-15T10:00:00Z"
                                },
                                "reasonCode": [
                                    {
                                        "text": "Routine checkup"
                                    }
                                ]
                            }
                        },
                        {
                            "resource": {
                                "resourceType": "Observation",
                                "status": "final",
                                "code": {
                                    "coding": [
                                        {
                                            "system": "http://loinc.org",
                                            "code": "8480-6",
                                            "display": "Systolic blood pressure"
                                        }
                                    ],
                                    "text": "Systolic blood pressure"
                                },
                                "subject": {
                                    "reference": "urn:uuid:patient-001"
                                },
                                "encounter": {
                                    "reference": "urn:uuid:encounter-001"
                                },
                                "effectiveDateTime": "2024-01-15T10:30:00Z",
                                "valueQuantity": {
                                    "value": 120,
                                    "unit": "mmHg",
                                    "system": "http://unitsofmeasure.org",
                                    "code": "mm[Hg]"
                                },
                                "category": [
                                    {
                                        "coding": [
                                            {
                                                "system": "http://terminology.hl7.org/CodeSystem/observation-category",
                                                "code": "vital-signs",
                                                "display": "Vital Signs"
                                            }
                                        ]
                                    }
                                ]
                            }
                        }
                    ]
                };
            } else if (type === 'multiple_patients') {
                example = {
                    "resourceType": "Bundle",
                    "type": "transaction",
                    "entry": [
                        {
                            "fullUrl": "urn:uuid:patient-001",
                            "resource": {
                                "resourceType": "Patient",
                                "name": [
                                    {
                                        "use": "official",
                                        "family": "Wang",
                                        "given": ["Xiao", "Ming"]
                                    }
                                ],
                                "gender": "male",
                                "birthDate": "1990-01-01",
                                "telecom": [
                                    {
                                        "system": "phone",
                                        "value": "0912345678"
                                    }
                                ],
                                "address": [
                                    {
                                        "line": ["123 Main Street"],
                                        "city": "Taipei",
                                        "postalCode": "100",
                                        "country": "TW"
                                    }
                                ]
                            }
                        },
                        {
                            "fullUrl": "urn:uuid:patient-002",
                            "resource": {
                                "resourceType": "Patient",
                                "name": [
                                    {
                                        "use": "official",
                                        "family": "Lee",
                                        "given": ["Mei", "Ling"]
                                    }
                                ],
                                "gender": "female",
                                "birthDate": "1985-05-20",
                                "telecom": [
                                    {
                                        "system": "phone",
                                        "value": "0923456789"
                                    }
                                ],
                                "address": [
                                    {
                                        "line": ["456 Second Street"],
                                        "city": "Kaohsiung",
                                        "postalCode": "800",
                                        "country": "TW"
                                    }
                                ]
                            }
                        },
                        {
                            "fullUrl": "urn:uuid:patient-003",
                            "resource": {
                                "resourceType": "Patient",
                                "name": [
                                    {
                                        "use": "official",
                                        "family": "Chen",
                                        "given": ["Wei", "Ming"]
                                    }
                                ],
                                "gender": "male",
                                "birthDate": "1992-03-15",
                                "telecom": [
                                    {
                                        "system": "phone",
                                        "value": "0934567890"
                                    }
                                ],
                                "address": [
                                    {
                                        "line": ["789 Third Street"],
                                        "city": "Taichung",
                                        "postalCode": "400",
                                        "country": "TW"
                                    }
                                ]
                            }
                        }
                    ]
                };
            }
            
            if (example) {
                document.getElementById('bundleJson').value = JSON.stringify(example, null, 2);
            }
        }
    </script>
</body>
</html>

