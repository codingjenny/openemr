<?php

namespace OpenEMR\Services;

use OpenEMR\Services\PatientService;

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
        
        foreach ($enabledServices as $service) {
            error_log("CDS Hook: Calling service: " . $service['service_id']);
            $cards = $this->callCDSService($service, $patient);
            error_log("CDS Hook: Service " . $service['service_id'] . " returned " . count($cards) . " cards");
            $cdsCards = array_merge($cdsCards, $cards);
        }
        
        error_log("CDS Hook: Total cards returned: " . count($cdsCards));
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
     */
    private function callCDSService(array $service, array $patient): array
    {
        // 使用真實的資料庫資料，但修復 UUID 格式問題
        $patientUuid = $this->convertUuidToString($patient['uuid'] ?? '1');
        
        // 使用真實的患者資料
        $patientFirstName = $patient['fname'] ?? 'John';
        $patientLastName = $patient['lname'] ?? 'Doe';
        $patientBirthDate = $patient['DOB'] ?? '1980-01-01';
        $patientGender = strtolower($patient['sex'] ?? 'male');
        
        $cdsRequest = [
            'hook' => $service['hook'],
            'hookInstance' => 'openemr-' . uniqid(),
            'fhirServer' => 'https://localhost:9300/apis/default/fhir',
            'user' => 'Practitioner/admin',
            'patient' => 'Patient/' . $patientUuid,
            'context' => [
                'patientId' => $patientUuid
            ],
            'prefetch' => [
                'patient' => [
                    'resourceType' => 'Patient',
                    'id' => $patientUuid,
                    'name' => [[
                        'use' => 'official',
                        'family' => $patientLastName,
                        'given' => [$patientFirstName]
                    ]],
                    'birthDate' => $patientBirthDate,
                    'gender' => $patientGender
                ]
            ]
        ];

        // 使用配置的超時設定
        $timeout = intval($GLOBALS['cds_hooks_timeout'] ?? 30);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service['url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cdsRequest));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
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
        }

        if ($curlError) {
            error_log("CDS Hook cURL Error for service {$service['service_id']}: " . $curlError);
            return [];
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("CDS Hook JSON decode error for service {$service['service_id']}: " . json_last_error_msg());
                return [];
            }
            return $data['cards'] ?? [];
        }

        error_log("CDS Hook failed for service {$service['service_id']} with HTTP code: $httpCode, Response: $response");
        return [];
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
        return [
            'resourceType' => 'Patient',
            'id' => $patientUuid,
            'name' => [[
                'use' => 'official',
                'family' => $patient['lname'] ?? '',
                'given' => [$patient['fname'] ?? '']
            ]],
            'birthDate' => $patient['DOB'] ?? '',
            'gender' => strtolower($patient['sex'] ?? 'unknown')
        ];
    }
}
?>
