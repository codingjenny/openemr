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
            <strong><?php echo xlt('內容類型'); ?>：</strong> application/json<br>
            <strong><?php echo xlt('提示'); ?>：</strong> Observation 資源需要引用已存在的 Patient。如果使用 Patient + Observation 範例，請先執行一次只包含 Patient 的 Bundle，然後用返回的 Patient UUID 更新 Observation 的 subject 欄位。
        </div>
        
        <form id="bundleForm">
            <div class="form-group">
                <label for="bundleJson">
                    <?php echo xlt('Bundle JSON'); ?> 
                    <span style="margin-left: 10px;">
                        <a href="#" class="example-link" onclick="loadExample('patient'); return false;"><?php echo xlt('範例：Patient'); ?></a> | 
                        <a href="#" class="example-link" onclick="loadExample('observation'); return false;"><?php echo xlt('範例：Observation'); ?></a> | 
                        <a href="#" class="example-link" onclick="loadExample('patient_observation'); return false;"><?php echo xlt('範例：Patient + Observation'); ?></a>
                    </span>
                </label>
                <textarea id="bundleJson" name="bundleJson" placeholder='<?php echo xlt('請輸入 FHIR Bundle JSON'); ?>'></textarea>
            </div>
            
            <div class="button-group">
                <button type="submit" class="btn btn-primary"><?php echo xlt('發送請求'); ?></button>
                <button type="button" class="btn btn-secondary" onclick="formatJson()"><?php echo xlt('格式化 JSON'); ?></button>
                <button type="button" class="btn btn-danger" onclick="clearForm()"><?php echo xlt('清除'); ?></button>
            </div>
        </form>
        
        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p style="margin-top: 10px;"><?php echo xlt('處理中...'); ?></p>
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
    </div>
    
    <script>
        const siteId = <?php echo js_escape($site_id); ?>;
        const csrfToken = <?php echo js_escape(CsrfUtils::collectCsrfToken('api')); ?>;
        
        document.getElementById('bundleForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const bundleJson = document.getElementById('bundleJson').value.trim();
            if (!bundleJson) {
                alert(<?php echo xlj('請輸入 Bundle JSON'); ?>);
                return;
            }
            
            // Validate JSON
            let jsonData;
            try {
                jsonData = JSON.parse(bundleJson);
            } catch (error) {
                alert(<?php echo xlj('JSON 格式錯誤'); ?> + ': ' + error.message);
                return;
            }
            
            // Show loading
            document.getElementById('loading').style.display = 'block';
            document.getElementById('resultContainer').style.display = 'none';
            
            try {
                const response = await fetch(`/apis/${siteId}/fhir/Bundle`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'APICSRFTOKEN': csrfToken
                    },
                    body: bundleJson
                });
                
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
                            "request": {
                                "method": "POST",
                                "url": "/fhir/Patient"
                            },
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
            } else if (type === 'observation') {
                example = {
                    "resourceType": "Bundle",
                    "type": "transaction",
                    "entry": [
                        {
                            "request": {
                                "method": "POST",
                                "url": "/fhir/Observation"
                            },
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
                                    "reference": "Patient/REPLACE_WITH_PATIENT_UUID",
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
            } else if (type === 'patient_observation') {
                example = {
                    "resourceType": "Bundle",
                    "type": "transaction",
                    "entry": [
                        {
                            "request": {
                                "method": "POST",
                                "url": "/fhir/Patient"
                            },
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
                                "birthDate": "1985-05-20"
                            }
                        },
                        {
                            "request": {
                                "method": "POST",
                                "url": "/fhir/Observation"
                            },
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
                                    "reference": "Patient/REPLACE_WITH_PATIENT_UUID",
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
            }
            
            if (example) {
                document.getElementById('bundleJson').value = JSON.stringify(example, null, 2);
            }
        }
    </script>
</body>
</html>

