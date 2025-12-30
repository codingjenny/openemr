# 在 FHIR Bundle 中新增新資源類型指南

本文檔說明如何在 OpenEMR 的 FHIR Bundle 處理中新增新的資源類型支援。我們將以 **Observation** 資源為例，詳細說明需要修改的檔案和步驟。

## 概述

要在 Bundle 中支援新的資源類型（例如 `NewResource`），需要修改以下檔案：

1. **`src/RestControllers/FHIR/FhirBundleRestController.php`** - 主要的 Bundle 處理控制器
   - 在 `routeToController()` 方法的 switch 語句中新增新的 case
   - 新增 `process{Resource}Resource()` 方法
   - 在 `getControllerForResourceType()` 方法中新增控制器實例化

2. **`interface/main/fhir_bundle_test.php`** (可選) - 測試介面
   - 新增新資源的範例資料

## 前提條件

在開始之前，確保以下組件已經存在：

1. **FHIR REST Controller** - `src/RestControllers/FHIR/Fhir{Resource}RestController.php`
   - 必須實作 `post($fhirJson)` 方法用於建立資源
   - 必須實作 `put($fhirId, $fhirJson)` 方法用於更新資源（如果支援更新）
   - 控制器應該回傳 FHIR 資源物件或陣列

2. **FHIR Service** - `src/Services/FHIR/Fhir{Resource}Service.php`
   - 必須實作 `insert()` 和 `update()` 方法

## 詳細步驟

### 步驟 1: 在 `routeToController()` 方法中新增路由

**檔案位置：** `src/RestControllers/FHIR/FhirBundleRestController.php`

**方法位置：** 約第 607-697 行

在 `routeToController()` 方法的 switch 語句中新增新的 case。以 Observation 為例：

```php
switch (strtolower($resourceType)) {
    case 'patient':
        return $this->processPatientResource($method, $resourceId, $resourceArray, $reusedController);
    case 'observation':
        return $this->processObservationResource($method, $resourceId, $resourceArray, $reusedController);
    // ... 其他資源類型 ...
    case 'newresource':  // ← 新增這一行（使用小寫）
        return $this->processNewResourceResource($method, $resourceId, $resourceArray, $reusedController);
    default:
        // 回傳 501 Not Supported
        return [
            'status' => '501',
            'outcome' => [
                'resourceType' => 'OperationOutcome',
                'issue' => [
                    [
                        'severity' => 'error',
                        'code' => 'not-supported',
                        'diagnostics' => "Resource type '$resourceType' is not supported in bundle processing"
                    ]
                ]
            ]
        ];
}
```

**注意事項：**
- case 值必須使用**小寫**（`strtolower($resourceType)` 已轉換）
- 方法名使用駝峰命名：`process{Resource}Resource()`

### 步驟 2: 新增處理方法

**檔案位置：** `src/RestControllers/FHIR/FhirBundleRestController.php`

**位置：** 在其他 `process{Resource}Resource()` 方法附近（約第 808 行之後）

新增新的處理方法。參考 Observation 的實作：

```php
/**
 * Process NewResource resource
 * @param string $method HTTP method (POST, PUT)
 * @param string|null $resourceId Resource ID (for PUT operations)
 * @param array $resourceArray Resource data as array
 * @param object|null $reusedController Optional controller to reuse (for batch processing)
 * @return array Response array with status, location, and resource
 */
private function processNewResourceResource($method, $resourceId, $resourceArray, $reusedController = null)
{
    // 實例化控制器（如果提供了重用控制器則使用它，否則建立新實例）
    $controller = $reusedController ?? new FhirNewResourceRestController();
    
    try {
        if ($method === 'POST') {
            // 建立新資源
            $statusCode = null;
            $result = $controller->post($resourceArray);
            $statusCode = http_response_code() ?: 201;
            
            // 處理回傳結果：可能是 FHIR 物件或陣列
            if (is_object($result) && method_exists($result, 'jsonSerialize')) {
                // FHIR 資源物件，轉換為陣列
                $body = $result->jsonSerialize();
            } elseif (is_array($result)) {
                $body = $result;
            } else {
                $body = null;
            }
            
            // 檢查錯誤
            if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                $statusCode = 400;
            } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                $statusCode = 500;
            } elseif (empty($body)) {
                $statusCode = 404;
                $this->logger->warning("FhirBundleRestController::processNewResourceResource() empty body returned", [
                    'httpResponseCode' => http_response_code(),
                    'result' => $result
                ]);
            }
            
            // 提取資源 ID
            $resourceId = null;
            if (is_object($result) && method_exists($result, 'getId')) {
                $idObj = $result->getId();
                if (is_object($idObj) && method_exists($idObj, 'getValue')) {
                    $resourceId = $idObj->getValue();
                }
            } elseif (is_array($body) && isset($body['id'])) {
                $resourceId = $body['id'];
            }
            
            return [
                'status' => (string)$statusCode,
                'location' => $resourceId ? '/fhir/NewResource/' . $resourceId : null,
                'resource' => $body
            ];
        } elseif ($method === 'PUT' && $resourceId) {
            // 更新現有資源
            $result = $controller->put($resourceId, $resourceArray);
            $statusCode = http_response_code() ?: 200;
            $body = is_array($result) ? $result : null;
            
            // 檢查錯誤
            if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                $statusCode = 400;
            } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                $statusCode = 500;
            } elseif (empty($body)) {
                $statusCode = 404;
            }
            
            return [
                'status' => (string)$statusCode,
                'location' => '/fhir/NewResource/' . $resourceId,
                'resource' => $body
            ];
        } else {
            // 不支援的方法
            return [
                'status' => '400',
                'outcome' => [
                    'resourceType' => 'OperationOutcome',
                    'issue' => [
                        [
                            'severity' => 'error',
                            'code' => 'invalid',
                            'diagnostics' => "Method '$method' not supported for NewResource resource"
                        ]
                    ]
                ]
            ];
        }
    } catch (\Exception $e) {
        // 記錄錯誤並回傳錯誤回應
        $this->logger->error("Failed to process NewResource resource", [
            'method' => $method,
            'resourceId' => $resourceId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return [
            'status' => '500',
            'outcome' => [
                'resourceType' => 'OperationOutcome',
                'issue' => [
                    [
                        'severity' => 'error',
                        'code' => 'exception',
                        'diagnostics' => $e->getMessage()
                    ]
                ]
            ]
        ];
    }
}
```

**關鍵點：**
1. 方法名必須與 switch case 中呼叫的方法名一致
2. 支援 `POST`（建立）和 `PUT`（更新）方法
3. 正確處理控制器回傳的 FHIR 物件或陣列
4. 提取資源 ID 用於建構 location 回應標頭
5. 錯誤處理要完整，包括異常捕獲

### 步驟 3: 在 `getControllerForResourceType()` 方法中新增控制器

**檔案位置：** `src/RestControllers/FHIR/FhirBundleRestController.php`

**方法位置：** 約第 2377-2411 行

在 `getControllerForResourceType()` 方法的 switch 語句中新增新的 case：

```php
private function getControllerForResourceType($resourceType)
{
    if (!$resourceType) {
        return null;
    }
    
    switch (strtolower($resourceType)) {
        case 'patient':
            return new FhirPatientRestController();
        case 'observation':
            return new FhirObservationRestController();
        // ... 其他資源類型 ...
        case 'newresource':  // ← 新增這一行
            return new FhirNewResourceRestController();
        default:
            return null;
    }
}
```

**作用：**
這個方法用於批次處理時重用控制器實例，提高效能。當 Bundle 中有多個相同類型的資源時，可以重用同一個控制器實例。

### 步驟 4: 新增 use 語句（如果需要）

**檔案位置：** `src/RestControllers/FHIR/FhirBundleRestController.php`

**位置：** 檔案頂部（約第 1-30 行）

如果控制器類還沒有被匯入，需要新增 use 語句：

```php
use OpenEMR\RestControllers\FHIR\FhirNewResourceRestController;
```

**注意：** 檢查檔案頂部是否已經有類似的 use 語句。如果沒有，需要新增。

### 步驟 5: 更新測試介面（可選）

**檔案位置：** `interface/main/fhir_bundle_test.php`

如果要新增測試範例，需要修改兩個地方：

#### 5.1 新增下拉選項

在 `<select>` 元素中新增新選項（約第 241 行附近）：

```php
<option value="patient_newresource"><?php echo xlt('範例：Patient + NewResource'); ?></option>
```

#### 5.2 新增範例資料

在 JavaScript 部分新增範例資料（約第 642 行之後）：

```javascript
} else if (type === 'patient_newresource') {
    bundleJson = {
        "resourceType": "Bundle",
        "type": "transaction",
        "entry": [
            {
                "request": {
                    "method": "POST",
                    "url": "Patient"
                },
                "resource": {
                    "resourceType": "Patient",
                    "id": "urn:uuid:patient-001",
                    "name": [{
                        "family": "Doe",
                        "given": ["John"]
                    }],
                    "gender": "male",
                    "birthDate": "1990-01-01"
                }
            },
            {
                "request": {
                    "method": "POST",
                    "url": "NewResource"
                },
                "resource": {
                    "resourceType": "NewResource",
                    "subject": {
                        "reference": "urn:uuid:patient-001"
                    },
                    // ... 其他 NewResource 字段 ...
                }
            }
        ]
    };
}
```

## 完整範例：Observation 資源實作

以下是 Observation 資源在 Bundle 中的完整實作，可以作為參考：

### 1. Switch Case（第 655-656 行）

```php
case 'observation':
    return $this->processObservationResource($method, $resourceId, $resourceArray, $reusedController);
```

### 2. 處理方法（第 808-913 行）

```php
private function processObservationResource($method, $resourceId, $resourceArray, $reusedController = null)
{
    $controller = $reusedController ?? new FhirObservationRestController();
    
    try {
        if ($method === 'POST') {
            $statusCode = null;
            $result = $controller->post($resourceArray);
            $statusCode = http_response_code() ?: 201;
            
            // 處理回傳結果...
            // ... (完整程式碼見檔案)
        } elseif ($method === 'PUT' && $resourceId) {
            // 更新邏輯...
        }
    } catch (\Exception $e) {
        // 錯誤處理...
    }
}
```

### 3. 控制器實例化（第 2386-2387 行）

```php
case 'observation':
    return new FhirObservationRestController();
```

### 4. Use 語句（第 18 行）

```php
use OpenEMR\RestControllers\FHIR\FhirObservationRestController;
```

**注意：** Observation 的 use 語句可能在其他地方，因為可能有多個控制器被匯入。

## 驗證步驟

完成修改後，請按以下步驟驗證：

1. **語法檢查**
   ```bash
   php -l src/RestControllers/FHIR/FhirBundleRestController.php
   ```

2. **測試 Bundle POST**
   - 使用測試介面或 curl 發送包含新資源的 Bundle
   - 驗證資源是否成功建立
   - 檢查回應中的 status 和 location

3. **測試資源引用**
   - 在 Bundle 中使用 `urn:uuid:` 引用新資源
   - 驗證引用是否正確解析

4. **測試錯誤處理**
   - 發送無效的資源資料
   - 驗證是否正確回傳錯誤回應

## 常見問題

### Q: 為什麼我的資源回傳 501 Not Supported？

A: 檢查以下幾點：
- switch case 中的資源類型名稱是否使用**小寫**
- 方法名是否正確拼寫
- 控制器類是否存在且可被實例化

### Q: 如何處理不支援 PUT 的資源？

A: 在 `process{Resource}Resource()` 方法中，如果資源不支援更新，可以在 PUT 分支回傳 405 Method Not Allowed：

```php
elseif ($method === 'PUT') {
    return [
        'status' => '405',
        'outcome' => [
            'resourceType' => 'OperationOutcome',
            'issue' => [
                [
                    'severity' => 'error',
                    'code' => 'not-supported',
                    'diagnostics' => 'PUT method not supported for NewResource'
                ]
            ]
        ]
    ];
}
```

### Q: 控制器回傳的資料格式不一致怎麼辦？

A: 程式碼中已經處理了兩種情況：
- FHIR 物件（有 `jsonSerialize()` 方法）
- 陣列格式

如果控制器回傳其他格式，需要在處理方法中新增相應的轉換邏輯。

### Q: 如何支援 DELETE 方法？

A: 在 `process{Resource}Resource()` 方法中新增 DELETE 分支：

```php
elseif ($method === 'DELETE' && $resourceId) {
    $result = $controller->delete($resourceId);
    $statusCode = http_response_code() ?: 200;
    
    return [
        'status' => (string)$statusCode,
        'location' => null,
        'resource' => null
    ];
}
```

**注意：** 確保控制器實作了 `delete()` 方法。

## 相關檔案清單

新增新資源類型時，可能需要修改的檔案：

- `src/RestControllers/FHIR/FhirBundleRestController.php` - **必須修改**
- `interface/main/fhir_bundle_test.php` - 可選（用於測試）
- `FHIR_README.md` - 可選（更新文件中的支援資源列表）
- `TEST_FHIR_BUNDLE.md` - 可選（更新測試文件）

## 總結

新增新資源類型到 Bundle 處理需要三個主要步驟：

1. **新增路由** - 在 `routeToController()` 的 switch 中新增 case
2. **新增處理方法** - 實作 `process{Resource}Resource()` 方法
3. **新增控制器實例化** - 在 `getControllerForResourceType()` 中新增

遵循 Observation 的實作模式，可以確保新資源類型與現有系統保持一致的行為和錯誤處理。

