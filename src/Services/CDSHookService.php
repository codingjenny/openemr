<?php

namespace OpenEMR\Services;

use OpenEMR\Services\PatientService;
use Exception;

class CDSHookService extends BaseService
{
    /**
     * Fallback hardcoded services for when database is not available
     */
    private array $fallbackServices = [
        'patient-greeting' => [
            'url' => 'https://sandbox-services.cds-hooks.org/cds-services/patient-greeting',
            'hook' => 'patient-view',
            'enabled' => true
        ]
    ];

    /**
     * 建構函數
     */
    public function __construct()
    {
        parent::__construct('patient_data'); // 使用 patient_data 表作為基礎
    }

    /**
     * 觸發 patient-view hook
     */
    public function triggerPatientView(int $patientId): array
    {
        error_log("CDS Hook: Starting triggerPatientView for patient ID: $patientId");
        
        // 直接從資料庫獲取患者資料，避免 PatientService 的權限問題
        $patient = $this->getPatientDataDirectly($patientId);
        
        if (empty($patient)) {
            error_log("CDS Hook: No patient data found for ID: $patientId");
            return [];
        }

        error_log("CDS Hook: Patient data found: " . json_encode($patient));
        
        // 從資料庫獲取啟用的 CDS 服務
        $enabledServices = $this->getEnabledServices('patient-view');
        error_log("CDS Hook: Found " . count($enabledServices) . " enabled patient-view services");
        
        $cdsCards = [];
        $serviceResults = [];
        $debugMode = ($GLOBALS['cds_hooks_debug'] ?? false);
        
        foreach ($enabledServices as $service) {
            error_log("CDS Hook: Calling service: " . $service['service_id']);
            $startTime = microtime(true);
            
            // AI Generated: Collect debug info including request JSON
            $debugInfo = [];
            $callResult = $this->callCDSService($service, $patient, $debugInfo);
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            // AI Generated fix: Distinguish between service failure and empty cards
            // A service is successful if it returns an array (even empty), failed if it returns null or false
            $success = ($callResult !== null && is_array($callResult));
            $cards = $success ? $callResult : [];
            
            $serviceResult = [
                'service_id' => $service['service_id'],
                'title' => $service['title'] ?? $service['service_id'],
                'url' => $service['url'],
                'success' => $success,
                'card_count' => count($cards),
                'duration_ms' => $duration
            ];
            
            // AI Generated: Add debug info for console output
            if ($debugMode && !empty($debugInfo)) {
                $serviceResult['debug_info'] = $debugInfo;
            }
            
            // 詳細日誌只在調試模式下記錄
            if ($debugMode) {
                error_log("CDS Hook: Service " . $service['service_id'] . " returned " . count($cards) . " cards in {$duration}ms");
            }
            
            $serviceResults[] = $serviceResult;
            $cdsCards = array_merge($cdsCards, $cards);
        }
        
        // 只在調試模式下添加服務執行摘要
        $debugMode = ($GLOBALS['cds_hooks_debug'] ?? false);
        if (!empty($serviceResults) && $debugMode) {
            $summaryCard = [
                'uuid' => 'service-summary-' . uniqid(),
                'summary' => 'CDS Services Execution Summary (Debug Mode)',
                'source' => ['label' => 'OpenEMR CDS Hook Debug'],
                'indicator' => 'info',
                'detail' => $this->generateServiceSummaryHTML($serviceResults)
            ];
            array_unshift($cdsCards, $summaryCard);
        }
        
        error_log("CDS Hook: Total cards returned: " . count($cdsCards) . " (including summary)");
        return $cdsCards;
    }

    /**
     * 直接從資料庫獲取患者資料
     */
    private function getPatientDataDirectly(int $patientId): array
    {
        $query = "SELECT pid, fname, lname, DOB, sex, status, uuid FROM patient_data WHERE pid = ?";
        $result = sqlQuery($query, [$patientId]);
        
        if ($result === false) {
            return [];
        }
        
        return $result;
    }

    /**
     * 從資料庫獲取啟用的 CDS 服務
     */
    private function getEnabledServices(string $hookType): array
    {
        // 檢查表是否存在
        $tableExists = sqlQuery("SHOW TABLES LIKE 'cds_hooks_services'");
        
        if (!$tableExists) {
            error_log("CDS Hook: cds_hooks_services table does not exist, using fallback services");
            return $this->getFallbackServices($hookType);
        }
        
        // 首先獲取所有啟用的服務來除錯
        $debugQuery = "SELECT service_id, service_title, hook_types, enabled FROM cds_hooks_services";
        $debugResult = sqlStatement($debugQuery);
        error_log("CDS Hook Debug: All services in database:");
        while ($debugRow = sqlFetchArray($debugResult)) {
            error_log("  - {$debugRow['service_id']}: hooks='{$debugRow['hook_types']}', enabled={$debugRow['enabled']}");
        }
        
        $query = "SELECT service_id, service_title, service_description, hook_types, discovery_url 
                  FROM cds_hooks_services 
                  WHERE enabled = 1 AND (hook_types LIKE ? OR hook_types LIKE ? OR hook_types = ?)";
        
        // 嘗試多種匹配模式
        $searchPatterns = [
            "%$hookType%",          // patient-view 在字串中
            "%\"$hookType\"%",      // "patient-view" 作為 JSON 字串
            $hookType               // 完全匹配
        ];
        
        $result = sqlStatement($query, $searchPatterns);
        $services = [];
        
        while ($row = sqlFetchArray($result)) {
            // 檢查 hook_types 是否真的包含我們要的 hook
            $hookTypes = $row['hook_types'];
            $containsHook = false;
            
            // 嘗試解析 JSON 格式
            $hookArray = json_decode($hookTypes, true);
            if (is_array($hookArray)) {
                $containsHook = in_array($hookType, $hookArray);
                error_log("CDS Hook: Service {$row['service_id']} has JSON hooks: " . json_encode($hookArray) . ", contains $hookType: " . ($containsHook ? 'yes' : 'no'));
            } else {
                // 檢查逗號分隔的字串格式（如 "patient-view, order-select"）
                $hookArray = array_map('trim', explode(',', $hookTypes));
                $containsHook = in_array($hookType, $hookArray);
                
                // 如果逗號分隔也沒有，檢查直接字串包含
                if (!$containsHook) {
                    $containsHook = strpos($hookTypes, $hookType) !== false;
                }
                
                error_log("CDS Hook: Service {$row['service_id']} has string hooks: '$hookTypes', parsed as: " . json_encode($hookArray) . ", contains $hookType: " . ($containsHook ? 'yes' : 'no'));
            }
            
            if ($containsHook) {
                // 構建服務 URL
                $serviceUrl = $this->buildServiceUrl($row['discovery_url'], $row['service_id']);
                
                $services[] = [
                    'service_id' => $row['service_id'],
                    'url' => $serviceUrl,
                    'hook' => $hookType,
                    'title' => $row['service_title'],
                    'description' => $row['service_description']
                ];
                
                error_log("CDS Hook: Added service {$row['service_id']} with URL: $serviceUrl");
            }
        }
        
        // 如果沒有從資料庫找到服務，使用備用服務
        if (empty($services)) {
            error_log("CDS Hook: No enabled services found in database for hook '$hookType', using fallback services");
            return $this->getFallbackServices($hookType);
        }
        
        error_log("CDS Hook: Found " . count($services) . " enabled services from database for hook '$hookType'");
        return $services;
    }

    /**
     * 獲取備用服務
     */
    private function getFallbackServices(string $hookType): array
    {
        $services = [];
        foreach ($this->fallbackServices as $serviceId => $service) {
            if ($service['hook'] === $hookType && $service['enabled']) {
                $services[] = [
                    'service_id' => $serviceId,
                    'url' => $service['url'],
                    'hook' => $service['hook'],
                    'title' => 'Patient Greeting Service',
                    'description' => 'A simple greeting service for patients'
                ];
            }
        }
        return $services;
    }

    /**
     * 構建服務 URL
     */
    private function buildServiceUrl(string $discoveryUrl, string $serviceId): string
    {
        // 移除 discovery URL 末尾的路徑，如果有的話
        $baseUrl = preg_replace('#/cds-services/?$#', '', $discoveryUrl);
        return $baseUrl . '/cds-services/' . $serviceId;
    }

    /**
     * 調用 CDS Hook 服務
     * AI Generated fix: Changed return type to ?array to distinguish success/failure
     */
    private function callCDSService(array $service, array $patient, array &$debugInfo = null): ?array
    {
        // 使用真實的資料庫資料，但修復 UUID 格式問題
        $patientUuid = $this->convertUuidToString($patient['uuid'] ?? '1');
        $patientId = $patient['pid'] ?? 1;
        
        // 使用真實的患者資料
        $patientFirstName = $patient['fname'] ?? 'John';
        $patientLastName = $patient['lname'] ?? 'Doe';
        $patientBirthDate = $patient['DOB'] ?? '1980-01-01';
        $patientGender = strtolower($patient['sex'] ?? 'male');
        
        // 構建基本 prefetch 資源
        // AI Generated fix: Use service-specific key format based on discovery info
        $servicePrefetch = $this->getServicePrefetchRequirements($service['service_id']);
        
        // 確定患者資源的正確 key 名稱（有些服務用小寫 patient，有些用大寫 Patient）
        $patientKey = isset($servicePrefetch['patient']) ? 'patient' : 'Patient';
        
        $prefetch = [
            $patientKey => [
                'resourceType' => 'Patient',
                'id' => $patientUuid,
                'active' => true,
                'name' => [[
                    'use' => 'official',
                    'family' => $patientLastName,
                    'given' => [$patientFirstName]
                ]],
                'birthDate' => $patientBirthDate,
                'gender' => $patientGender
            ]
        ];

        // 根據服務的 prefetch 要求添加額外資源
        if (($GLOBALS['cds_hooks_debug'] ?? false)) {
            error_log("CDS Hook Debug: Building prefetch resources for service {$service['service_id']}");
        }
        $prefetch = $this->buildPrefetchResources($service, $patientId, $patientUuid, $prefetch, $servicePrefetch);
        
        if (($GLOBALS['cds_hooks_debug'] ?? false)) {
            error_log("CDS Hook Debug: Final prefetch resources: " . json_encode(array_keys($prefetch)));
        }
        
        $cdsRequest = [
            'hook' => $service['hook'],
            'hookInstance' => 'openemr-' . uniqid(),
            'fhirServer' => 'https://localhost:9300/apis/default/fhir',
            'user' => 'Practitioner/admin',
            'patient' => 'Patient/' . $patientUuid,
            'context' => [
                'patientId' => $patientUuid
            ],
            'prefetch' => $prefetch
        ];

        // 使用配置的超時設定
        $timeout = intval($GLOBALS['cds_hooks_timeout'] ?? 30);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service['url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cdsRequest, JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: OpenEMR/7.0.0 CDS-Hooks-Client/1.0'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 除錯模式：記錄請求和回應
        if (($GLOBALS['cds_hooks_debug'] ?? false)) {
            $requestJson = json_encode($cdsRequest);
            error_log("CDS Hook Request to {$service['url']} (JSON): " . ($requestJson ?: 'JSON_ENCODE_FAILED'));
            error_log("CDS Hook Response (HTTP $httpCode): " . $response);
            if ($curlError) {
                error_log("CDS Hook cURL Error: " . $curlError);
            }
            
            // AI Generated: Collect debug info for console output
            if ($debugInfo !== null) {
                $debugInfo['request_url'] = $service['url'];
                $debugInfo['request_json'] = $cdsRequest;
                $debugInfo['response_http_code'] = $httpCode;
                $debugInfo['response_body'] = $response;
                if ($curlError) {
                    $debugInfo['curl_error'] = $curlError;
                }
            }
        }

        if ($curlError) {
            error_log("CDS Hook cURL Error for service {$service['service_id']}: " . $curlError);
            return null; // AI Generated fix: Return null for real failures
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("CDS Hook JSON decode error for service {$service['service_id']}: " . json_last_error_msg());
                return null; // AI Generated fix: Return null for JSON decode failures
            }
            $cards = $data['cards'] ?? [];
            error_log("CDS Hook SUCCESS for service {$service['service_id']}: received " . count($cards) . " cards");
            return $cards; // Return empty array [] for successful response with no cards
        }

        // 記錄失敗的詳細信息
        $errorMsg = "CDS Hook FAILED for service {$service['service_id']}:";
        $errorMsg .= "\n  - HTTP Code: $httpCode";
        $errorMsg .= "\n  - URL: {$service['url']}";
        $errorMsg .= "\n  - Response: " . substr($response, 0, 200) . (strlen($response) > 200 ? '...' : '');
        
        if ($curlError) {
            $errorMsg .= "\n  - cURL Error: $curlError";
        }
        
        // 根據 HTTP 狀態碼提供更具體的錯誤說明
        switch ($httpCode) {
            case 412:
                $errorMsg .= "\n  - Note: HTTP 412 (Precondition Failed) - The service rejected the request format or content";
                // 在調試模式下輸出完整的請求內容
                if (($GLOBALS['cds_hooks_debug'] ?? false)) {
                    $errorMsg .= "\n  - Request JSON: " . json_encode($cdsRequest, JSON_PRETTY_PRINT);
                }
                break;
            case 404:
                $errorMsg .= "\n  - Note: HTTP 404 (Not Found) - Service endpoint not found";
                break;
            case 500:
                $errorMsg .= "\n  - Note: HTTP 500 (Internal Server Error) - Service encountered an internal error";
                break;
        }
        
        error_log($errorMsg);
        return null; // AI Generated fix: Return null for HTTP failures
    }

    /**
     * 轉換 UUID 為字串格式
     */
    private function convertUuidToString($uuid): string
    {
        // 如果已經是字串且不包含二進制字元，直接返回
        if (is_string($uuid) && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $uuid)) {
            return $uuid;
        }
        
        // 如果是二進制格式，轉換為 UUID 字串
        if (is_resource($uuid) || (is_string($uuid) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $uuid))) {
            $uuidString = bin2hex($uuid);
            return substr($uuidString, 0, 8) . '-' . 
                   substr($uuidString, 8, 4) . '-' . 
                   substr($uuidString, 12, 4) . '-' . 
                   substr($uuidString, 16, 4) . '-' . 
                   substr($uuidString, 20, 12);
        }
        
        return (string)$uuid;
    }

    /**
     * 格式化患者資料為 FHIR 格式
     */
    private function formatPatientForFHIR(array $patient, string $patientUuid): array
    {
        $patientResource = [
            'resourceType' => 'Patient',
            'id' => $patientUuid,
            'meta' => [
                'profile' => ['http://hl7.org/fhir/StructureDefinition/Patient']
            ],
            'active' => true,
            'name' => [[
                'use' => 'official',
                'family' => $patient['lname'] ?? '',
                'given' => array_filter([$patient['fname'] ?? '', $patient['mname'] ?? ''])
            ]]
        ];
        
        if (!empty($patient['DOB'])) {
            $patientResource['birthDate'] = $patient['DOB'];
        }
        
        if (!empty($patient['sex'])) {
            $gender = strtolower($patient['sex']);
            // 確保性別符合FHIR標準
            $validGenders = ['male', 'female', 'other', 'unknown'];
            $patientResource['gender'] = in_array($gender, $validGenders) ? $gender : 'unknown';
        }
        
        return $patientResource;
    }

    /**
     * 生成服務執行摘要的 HTML
     */
    private function generateServiceSummaryHTML(array $serviceResults): string
    {
        $html = '<div class="cds-service-summary" style="background-color: #f8f9fa; border: 1px solid #17a2b8; border-radius: 8px; padding: 15px; margin: 10px 0;">';
        $html .= '<div class="d-flex align-items-center mb-3">';
        $html .= '<h6 class="mb-0" style="color: #495057;">🔧 CDS Services Execution Details</h6>';
        $html .= '<span class="badge badge-info ml-2" style="font-size: 0.7rem;">DEBUG MODE</span>';
        $html .= '</div>';
        $html .= '<small class="text-muted d-block mb-3">此資訊僅在啟用調試模式時顯示，用於開發和故障排除。</small>';
        
        // 統計信息
        $totalServices = count($serviceResults);
        $successCount = 0;
        $failedCount = 0;
        
        foreach ($serviceResults as $result) {
            if ($result['success']) {
                $successCount++;
            } else {
                $failedCount++;
            }
        }
        
        // 摘要統計
        $html .= '<div class="row mb-3">';
        $html .= '<div class="col-3"><small class="text-muted">總計:</small> <strong>' . $totalServices . '</strong></div>';
        $html .= '<div class="col-3"><small class="text-success">成功:</small> <strong class="text-success">' . $successCount . '</strong></div>';
        $html .= '<div class="col-3"><small class="text-warning">失敗:</small> <strong class="text-warning">' . $failedCount . '</strong></div>';
        $html .= '<div class="col-3"><small class="text-muted">成功率:</small> <strong>' . round($successCount / $totalServices * 100, 1) . '%</strong></div>';
        $html .= '</div>';
        
        // 服務詳情表格
        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-sm table-striped mb-0" style="font-size: 0.85rem;">';
        $html .= '<thead class="thead-light"><tr>';
        $html .= '<th style="border-top: none;">Service</th>';
        $html .= '<th style="border-top: none;">Status</th>';
        $html .= '<th style="border-top: none;">Cards</th>';
        $html .= '<th style="border-top: none;">Time</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        
        foreach ($serviceResults as $result) {
            $statusClass = $result['success'] ? 'text-success' : 'text-warning';
            $statusIcon = $result['success'] ? '✓' : '⚠️';
            $statusText = $result['success'] ? 'Success' : 'Failed';
            
            $html .= '<tr>';
            $html .= '<td>';
            $html .= '<strong style="font-size: 0.9rem;">' . htmlspecialchars($result['service_id']) . '</strong>';
            if ($result['title'] !== $result['service_id']) {
                $html .= '<br><small class="text-muted">' . htmlspecialchars($result['title']) . '</small>';
            }
            $html .= '</td>';
            $html .= '<td><span class="' . $statusClass . '">' . $statusIcon . ' ' . $statusText . '</span></td>';
            $html .= '<td><span class="badge badge-' . ($result['card_count'] > 0 ? 'primary' : 'secondary') . '">' . $result['card_count'] . '</span></td>';
            $html .= '<td><small>' . $result['duration_ms'] . 'ms</small></td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
        
        // 展開/收縮按鈕
        $html .= '<div class="mt-2 text-center">';
        $html .= '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleCDSDetails(this)">';
        $html .= '<i class="fas fa-chevron-down"></i> <span>詳細資訊</span>';
        $html .= '</button>';
        $html .= '</div>';
        
        // 詳細信息（默認隱藏）
        $html .= '<div class="cds-detailed-info" style="display: none; margin-top: 15px; padding-top: 15px; border-top: 1px solid #dee2e6;">';
        foreach ($serviceResults as $result) {
            $html .= '<div class="mb-3 p-2" style="background-color: #f1f3f4; border-radius: 4px;">';
            $html .= '<strong>' . htmlspecialchars($result['service_id']) . ':</strong> ';
            $html .= '<small class="text-muted">' . htmlspecialchars($result['url']) . '</small>';
            
            // AI Generated: Add debug info display and console output
            if (isset($result['debug_info'])) {
                $debugInfo = $result['debug_info'];
                $serviceId = $result['service_id'];
                
                $html .= '<div class="mt-2">';
                $html .= '<button type="button" class="btn btn-xs btn-outline-info" onclick="logCDSRequest(\'' . $serviceId . '\')">';
                $html .= '<i class="fas fa-code"></i> 輸出 Request JSON 到 Console';
                $html .= '</button>';
                $html .= '</div>';
                
                // Add JavaScript to output request JSON to console
                $html .= '<script>';
                $html .= 'window.cdsDebugInfo = window.cdsDebugInfo || {};';
                $html .= 'window.cdsDebugInfo["' . $serviceId . '"] = ' . json_encode($debugInfo) . ';';
                $html .= 'function logCDSRequest(serviceId) {';
                $html .= '  const info = window.cdsDebugInfo[serviceId];';
                $html .= '  if (info) {';
                $html .= '    console.group("CDS Hook Debug: " + serviceId);';
                $html .= '    console.log("Request URL:", info.request_url);';
                $html .= '    console.log("Request JSON:", info.request_json);';
                $html .= '    console.log("Response HTTP Code:", info.response_http_code);';
                $html .= '    console.log("Response Body:", info.response_body);';
                $html .= '    if (info.curl_error) console.error("cURL Error:", info.curl_error);';
                $html .= '    console.groupEnd();';
                $html .= '  }';
                $html .= '}';
                $html .= '</script>';
            }
            
            $html .= '</div>';
        }
        $html .= '</div>';
        
        $html .= '</div>';
        
        // 添加 JavaScript
        $html .= '<script>';
        $html .= 'function toggleCDSDetails(btn) {';
        $html .= '  const details = btn.parentElement.nextElementSibling;';
        $html .= '  const icon = btn.querySelector("i");';
        $html .= '  const text = btn.querySelector("span");';
        $html .= '  if (details.style.display === "none") {';
        $html .= '    details.style.display = "block";';
        $html .= '    icon.className = "fas fa-chevron-up";';
        $html .= '    text.textContent = "隱藏詳細";';
        $html .= '  } else {';
        $html .= '    details.style.display = "none";';
        $html .= '    icon.className = "fas fa-chevron-down";';
        $html .= '    text.textContent = "詳細資訊";';
        $html .= '  }';
        $html .= '}';
        
        // 控制台輸出
        $html .= 'console.group("🔧 CDS Hooks Service Execution Summary");';
        $html .= 'console.log("Total services executed: ' . $totalServices . '");';
        $html .= 'console.log("Successful services: ' . $successCount . '");';
        $html .= 'console.log("Failed services: ' . $failedCount . '");';
        
        foreach ($serviceResults as $result) {
            $jsResult = json_encode($result);
            $statusEmoji = $result['success'] ? '✅' : '⚠️';
            $html .= 'console.log("' . $statusEmoji . ' Service: ' . addslashes($result['service_id']) . '", ' . $jsResult . ');';
        }
        $html .= 'console.groupEnd();';
        $html .= '</script>';
        
        return $html;
    }

    /**
     * 根據服務要求構建 prefetch 資源
     * AI-generated method by GitHub Copilot
     */
    /* BEGIN AI-generated code by GitHub Copilot */
    private function buildPrefetchResources(array $service, int $patientId, string $patientUuid, array $basePrefetch, array $servicePrefetch): array
    {
        if (($GLOBALS['cds_hooks_debug'] ?? false)) {
            error_log("CDS Hook Debug: Service {$service['service_id']} prefetch requirements: " . json_encode($servicePrefetch));
        }
        
        $prefetch = $basePrefetch;
        
        // 根據 prefetch 要求添加資源
        // AI Generated fix: Use service-specific key format based on discovery info
        
        // 檢查每種資源類型，支援大小寫兩種格式
        foreach (['Condition', 'condition'] as $conditionKey) {
            if (isset($servicePrefetch[$conditionKey])) {
                $conditions = $this->getPatientConditions($patientId, $patientUuid);
                $prefetch[$conditionKey] = $this->createBundle('conditions', $conditions, $patientUuid);
                if (($GLOBALS['cds_hooks_debug'] ?? false)) {
                    error_log("CDS Hook Debug: Added " . count($conditions) . " conditions for patient $patientId");
                }
                break;
            }
        }
        
        foreach (['Observation', 'observation'] as $observationKey) {
            if (isset($servicePrefetch[$observationKey])) {
                $observations = $this->getPatientObservations($patientId, $patientUuid);
                $prefetch[$observationKey] = $this->createBundle('observations', $observations, $patientUuid);
                if (($GLOBALS['cds_hooks_debug'] ?? false)) {
                    error_log("CDS Hook Debug: Added " . count($observations) . " observations for patient $patientId");
                }
                break;
            }
        }
        
        foreach (['Encounter', 'encounter'] as $encounterKey) {
            if (isset($servicePrefetch[$encounterKey])) {
                $encounters = $this->getPatientEncounters($patientId, $patientUuid);
                $prefetch[$encounterKey] = $this->createBundle('encounters', $encounters, $patientUuid);
                if (($GLOBALS['cds_hooks_debug'] ?? false)) {
                    error_log("CDS Hook Debug: Added " . count($encounters) . " encounters for patient $patientId");
                }
                break;
            }
        }
        
        foreach (['Procedure', 'procedure'] as $procedureKey) {
            if (isset($servicePrefetch[$procedureKey])) {
                $procedures = $this->getPatientProcedures($patientId, $patientUuid);
                $prefetch[$procedureKey] = $this->createBundle('procedures', $procedures, $patientUuid);
                if (($GLOBALS['cds_hooks_debug'] ?? false)) {
                    error_log("CDS Hook Debug: Added " . count($procedures) . " procedures for patient $patientId");
                }
                break;
            }
        }
        
        foreach (['FamilyMemberHistory', 'familyMemberHistory'] as $familyKey) {
            if (isset($servicePrefetch[$familyKey])) {
                $familyHistory = $this->getPatientFamilyHistory($patientId, $patientUuid);
                $prefetch[$familyKey] = $this->createBundle('familyhistory', $familyHistory, $patientUuid);
                if (($GLOBALS['cds_hooks_debug'] ?? false)) {
                    error_log("CDS Hook Debug: Added " . count($familyHistory) . " family history items for patient $patientId");
                }
                break;
            }
        }
        
        return $prefetch;
    }

    /**
     * 獲取服務的 prefetch 要求
     * AI Generated fix: Added support for different key formats per service
     */
    private function getServicePrefetchRequirements(string $serviceId): array
    {
        // 從 Discovery 服務獲取的 prefetch 要求
        $knownPrefetch = [
            // Sandbox services use lowercase keys
            'patient-greeting' => [
                'patient' => 'Patient/{{context.patientId}}'
            ],
            '09139C' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}',
                'Observation' => 'Observation?patient={{context.patientId}}'
            ],
            '13026C' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}'
            ],
            '17022B' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}',
                'Encounter' => 'Encounter?patient={{context.patientId}}',
                'Observation' => 'Observation?patient={{context.patientId}}'
            ],
            '26074C' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}'
            ],
            '36014B' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}'
            ],
            '37048B' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}',
                'Procedure' => 'Procedure?patient={{context.patientId}}'
            ],
            '80033B' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}',
                'Procedure' => 'Procedure?patient={{context.patientId}}'
            ],
            'USPSTFPrediabetesAndType2DiabetesPart1ScreeningFHIRv401' => [
                'Patient' => 'Patient/{{context.patientId}}',
                'Observation' => 'Observation?patient={{context.patientId}}',
                'Condition' => 'Condition?patient={{context.patientId}}',
                'FamilyMemberHistory' => 'FamilyMemberHistory?patient={{context.patientId}}'
            ]
        ];
        
        return $knownPrefetch[$serviceId] ?? [];
    }

    /**
     * 獲取患者的 Condition 資源
     */
    private function getPatientConditions(int $patientId, string $patientUuid): array
    {
        try {
            $query = "SELECT * FROM lists WHERE pid = ? AND type = 'medical_problem' AND begdate IS NOT NULL ORDER BY begdate DESC LIMIT 10";
            $result = sqlStatement($query, [$patientId]);
            
            $conditions = [];
            while ($row = sqlFetchArray($result)) {
                $condition = [
                    'resourceType' => 'Condition',
                    'id' => 'condition-' . $row['id'],
                    'meta' => [
                        'profile' => ['http://hl7.org/fhir/StructureDefinition/Condition']
                    ],
                    'subject' => ['reference' => 'Patient/' . $patientUuid],
                    'code' => [
                        'coding' => $this->buildConditionCoding($row['diagnosis'], $row['title']),
                        'text' => $row['title'] ?? 'Unknown condition'
                    ],
                    'clinicalStatus' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
                                'code' => 'active',
                                'display' => 'Active'
                            ]
                        ]
                    ],
                    'verificationStatus' => [
                        'coding' => [
                            [
                                'system' => 'http://terminology.hl7.org/CodeSystem/condition-ver-status',
                                'code' => 'confirmed',
                                'display' => 'Confirmed'
                            ]
                        ]
                    ]
                ];
                
                if (!empty($row['begdate'])) {
                    $condition['onsetDateTime'] = $row['begdate'];
                }
                
                $conditions[] = $condition;
            }
            
            return $conditions;
        } catch (Exception $e) {
            error_log("Error fetching patient conditions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 獲取患者的 Observation 資源
     */
    private function getPatientObservations(int $patientId, string $patientUuid): array
    {
        try {
            $observations = [];
            
            // 獲取生命徵象資料
            $vitalQuery = "SELECT * FROM form_vitals WHERE pid = ? ORDER BY date DESC LIMIT 10";
            $vitalResult = sqlStatement($vitalQuery, [$patientId]);
            
            while ($row = sqlFetchArray($vitalResult)) {
                if (!empty($row['bps'])) {
                    $observation = [
                        'resourceType' => 'Observation',
                        'id' => 'vitals-bp-' . $row['id'],
                        'meta' => [
                            'profile' => ['http://hl7.org/fhir/StructureDefinition/Observation']
                        ],
                        'status' => 'final',
                        'category' => [
                            [
                                'coding' => [
                                    [
                                        'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                                        'code' => 'vital-signs',
                                        'display' => 'Vital Signs'
                                    ]
                                ]
                            ]
                        ],
                        'subject' => ['reference' => 'Patient/' . $patientUuid],
                        'code' => [
                            'coding' => [
                                [
                                    'system' => 'http://loinc.org',
                                    'code' => '85354-9',
                                    'display' => 'Blood pressure systolic and diastolic'
                                ]
                            ],
                            'text' => 'Blood pressure'
                        ],
                        'component' => [
                            [
                                'code' => [
                                    'coding' => [
                                        [
                                            'system' => 'http://loinc.org',
                                            'code' => '8480-6',
                                            'display' => 'Systolic blood pressure'
                                        ]
                                    ]
                                ],
                                'valueQuantity' => [
                                    'value' => floatval($row['bps']),
                                    'unit' => 'mmHg',
                                    'system' => 'http://unitsofmeasure.org',
                                    'code' => 'mm[Hg]'
                                ]
                            ]
                        ]
                    ];
                    
                    if (!empty($row['date'])) {
                        $observation['effectiveDateTime'] = $row['date'];
                    }
                    
                    if (!empty($row['bpd'])) {
                        $observation['component'][] = [
                            'code' => [
                                'coding' => [
                                    [
                                        'system' => 'http://loinc.org',
                                        'code' => '8462-4',
                                        'display' => 'Diastolic blood pressure'
                                    ]
                                ]
                            ],
                            'valueQuantity' => [
                                'value' => floatval($row['bpd']),
                                'unit' => 'mmHg',
                                'system' => 'http://unitsofmeasure.org',
                                'code' => 'mm[Hg]'
                            ]
                        ];
                    }
                    
                    $observations[] = $observation;
                }
            }
            
            // 獲取實驗室檢查結果
            $labQuery = "SELECT pr.*, rep.date_collected, rep.date_report, po.patient_id 
                        FROM procedure_result pr 
                        JOIN procedure_report rep ON pr.procedure_report_id = rep.procedure_report_id 
                        JOIN procedure_order po ON rep.procedure_order_id = po.procedure_order_id 
                        WHERE po.patient_id = ? AND pr.result != '' 
                        ORDER BY rep.date_collected DESC LIMIT 20";
            $labResult = sqlStatement($labQuery, [$patientId]);
            
            while ($row = sqlFetchArray($labResult)) {
                if (!empty($row['result_code']) && !empty($row['result'])) {
                    $observation = [
                        'resourceType' => 'Observation',
                        'id' => 'lab-' . $row['procedure_result_id'],
                        'meta' => [
                            'profile' => ['http://hl7.org/fhir/StructureDefinition/Observation']
                        ],
                        'status' => 'final',
                        'category' => [
                            [
                                'coding' => [
                                    [
                                        'system' => 'http://terminology.hl7.org/CodeSystem/observation-category',
                                        'code' => 'laboratory',
                                        'display' => 'Laboratory'
                                    ]
                                ]
                            ]
                        ],
                        'subject' => ['reference' => 'Patient/' . $patientUuid],
                        'code' => [
                            'coding' => [
                                [
                                    'system' => 'http://loinc.org',
                                    'code' => $row['result_code'],
                                    'display' => $row['result_text'] ?? 'Laboratory test'
                                ]
                            ],
                            'text' => $row['result_text'] ?? 'Laboratory test'
                        ]
                    ];
                    
                    // 設定結果值
                    if (is_numeric($row['result'])) {
                        $observation['valueQuantity'] = [
                            'value' => floatval($row['result']),
                            'unit' => $row['units'] ?? '',
                            'system' => 'http://unitsofmeasure.org'
                        ];
                        if (!empty($row['units'])) {
                            $observation['valueQuantity']['code'] = $row['units'];
                        }
                    } else {
                        $observation['valueString'] = $row['result'];
                    }
                    
                    // 設定檢查時間
                    if (!empty($row['date_collected'])) {
                        $observation['effectiveDateTime'] = $row['date_collected'];
                    } elseif (!empty($row['date_report'])) {
                        $observation['effectiveDateTime'] = $row['date_report'];
                    } elseif (!empty($row['date'])) {
                        $observation['effectiveDateTime'] = $row['date'];
                    }
                    
                    $observations[] = $observation;
                }
            }
            
            return $observations;
        } catch (Exception $e) {
            error_log("Error fetching patient observations: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 獲取患者的 Encounter 資源
     */
    private function getPatientEncounters(int $patientId, string $patientUuid): array
    {
        try {
            $query = "SELECT * FROM form_encounter WHERE pid = ? ORDER BY date DESC LIMIT 10";
            $result = sqlStatement($query, [$patientId]);
            
            $encounters = [];
            while ($row = sqlFetchArray($result)) {
                $encounter = [
                    'resourceType' => 'Encounter',
                    'id' => 'encounter-' . $row['id'],
                    'meta' => [
                        'profile' => ['http://hl7.org/fhir/StructureDefinition/Encounter']
                    ],
                    'status' => 'finished',
                    'class' => [
                        'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                        'code' => 'AMB',
                        'display' => 'ambulatory'
                    ],
                    'subject' => ['reference' => 'Patient/' . $patientUuid],
                    'type' => [
                        [
                            'coding' => [
                                [
                                    'system' => 'http://snomed.info/sct',
                                    'code' => '185349003',
                                    'display' => 'Encounter for check up (procedure)'
                                ]
                            ]
                        ]
                    ]
                ];
                
                if (!empty($row['date'])) {
                    $encounter['period'] = [
                        'start' => $row['date']
                    ];
                }
                
                $encounters[] = $encounter;
            }
            
            return $encounters;
        } catch (Exception $e) {
            error_log("Error fetching patient encounters: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 獲取患者的 Procedure 資源
     */
    private function getPatientProcedures(int $patientId, string $patientUuid): array
    {
        try {
            $query = "SELECT * FROM lists WHERE pid = ? AND type = 'surgery' ORDER BY begdate DESC LIMIT 10";
            $result = sqlStatement($query, [$patientId]);
            
            $procedures = [];
            while ($row = sqlFetchArray($result)) {
                $procedures[] = [
                    'resourceType' => 'Procedure',
                    'id' => 'procedure-' . $row['id'],
                    'subject' => ['reference' => 'Patient/' . $patientUuid],
                    'status' => 'completed',
                    'code' => [
                        'text' => $row['title'] ?? 'Unknown procedure'
                    ],
                    'performedDateTime' => $row['begdate'] ?? null
                ];
            }
            
            return $procedures;
        } catch (Exception $e) {
            error_log("Error fetching patient procedures: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 獲取患者的家族病史資源
     */
    private function getPatientFamilyHistory(int $patientId, string $patientUuid): array
    {
        try {
            $query = "SELECT * FROM history_data WHERE pid = ? ORDER BY date DESC LIMIT 5";
            $result = sqlStatement($query, [$patientId]);
            
            $familyHistory = [];
            while ($row = sqlFetchArray($result)) {
                if (!empty($row['father']) || !empty($row['mother']) || !empty($row['siblings'])) {
                    $familyHistory[] = [
                        'resourceType' => 'FamilyMemberHistory',
                        'id' => 'family-history-' . $row['id'],
                        'patient' => ['reference' => 'Patient/' . $patientUuid],
                        'status' => 'completed',
                        'condition' => []
                    ];
                }
            }
            
            return $familyHistory;
        } catch (Exception $e) {
            error_log("Error fetching patient family history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 創建 FHIR Bundle 來包裝資源
     */
    private function createBundle(string $type, array $resources, string $patientUuid): array
    {
        return [
            'resourceType' => 'Bundle',
            'id' => $type . '-' . $patientUuid,
            'type' => 'searchset',
            'total' => count($resources),
            'entry' => array_map(function($resource) {
                return [
                    'resource' => $resource,
                    'fullUrl' => 'urn:uuid:' . $resource['id']
                ];
            }, $resources)
        ];
    }

    /**
     * 建立 Condition 的 coding 陣列，處理不同的診斷代碼系統
     * AI-generated code by GitHub Copilot
     */
    private function buildConditionCoding($diagnosis, $title): array
    {
        $coding = [];
        
        if (!empty($diagnosis)) {
            // 解析診斷代碼 (例如: "ICD10-CM:E08.22" 或 "SNOMED-CT:123456")
            if (strpos($diagnosis, ':') !== false) {
                list($system, $code) = explode(':', $diagnosis, 2);
                
                switch (strtoupper($system)) {
                    case 'ICD10-CM':
                    case 'ICD-10-CM':
                        $coding[] = [
                            'system' => 'http://hl7.org/fhir/sid/icd-10-cm',
                            'code' => $code,
                            'display' => $title ?? 'Unknown condition'
                        ];
                        break;
                    case 'SNOMED-CT':
                    case 'SNOMED':
                        $coding[] = [
                            'system' => 'http://snomed.info/sct',
                            'code' => $code,
                            'display' => $title ?? 'Unknown condition'
                        ];
                        break;
                    default:
                        // 未知系統，使用原始格式
                        $coding[] = [
                            'system' => 'http://terminology.hl7.org/CodeSystem/icd10',
                            'code' => $code,
                            'display' => $title ?? 'Unknown condition'
                        ];
                        break;
                }
            } else {
                // 沒有系統前綴，假設是 ICD-10-CM
                $coding[] = [
                    'system' => 'http://hl7.org/fhir/sid/icd-10-cm',
                    'code' => $diagnosis,
                    'display' => $title ?? 'Unknown condition'
                ];
            }
        }
        
        // 如果沒有找到有效的診斷代碼，使用 SNOMED 作為備用
        if (empty($coding)) {
            $coding[] = [
                'system' => 'http://snomed.info/sct',
                'display' => $title ?? 'Unknown condition'
            ];
        }
        
        return $coding;
    }
    /* END AI-generated code by GitHub Copilot */
}
?>
