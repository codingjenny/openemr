# FHIR Bundle 測試介面使用說明

## 概述

`fhir_bundle_test.php` 是一個用於測試 OpenEMR FHIR Bundle POST 端點的 Web 介面工具。它提供了簡單易用的圖形化介面，讓您可以輕鬆測試 FHIR Bundle 的各種功能。

## 訪問方式

1. 登入 OpenEMR 系統（帳：admin/密：TestUser123!）
2. 在瀏覽器中訪問：`https://<your-openemr-domain>/interface/main/fhir_bundle_test.php`
3. 或者從介面進入

   <img width="1507" height="822" alt="截圖 2025-12-30 上午11 31 57" src="https://github.com/user-attachments/assets/213a02dc-31c5-4bf1-b6ed-284b5ed52a81" />


## 功能特點

- 支援直接輸入 JSON 或上傳 .json 檔案
- 提供多種預設範例（14 種常見場景）
- 自動處理 Bundle 內的資源引用
- 支援多檔案批次上傳
- JSON 格式化功能
- 即時顯示回應結果和狀態

## 使用方式

### 方式一：直接輸入 JSON

1. **選擇輸入方式**
   - 預設為「直接輸入 JSON」模式
   - 如果當前是檔案上傳模式，請切換到「直接輸入 JSON」

2. **載入範例（可選）**
   - 點擊「範例」下拉選單
   - 選擇您想要測試的範例類型，例如：
     - `範例：Patient` - 單一病患資源
     - `範例：Patient + Observation` - 病患與觀察資料
     - `範例：Patient + Encounter + Observation` - 病患、就診記錄與觀察資料
     - `範例：Patient + AllergyIntolerance` - 病患與過敏資訊
     - `範例：Patient + Condition` - 病患與診斷條件
     - `範例：Patient + Procedure` - 病患與處置記錄
     - `範例：Patient + CarePlan` - 病患與照護計畫
     - `範例：Patient + Practitioner + Organization + CareTeam` - 病患、醫師、醫療機構與照護團隊
     - `範例：Patient + Goal` - 病患與目標
     - `範例：Patient + Immunization` - 病患與疫苗接種記錄
     - 以及其他更多範例...

3. **編輯 JSON（如需要）**
   - 範例載入後，您可以直接在文字框中編輯 JSON
   - 系統會自動處理資源之間的引用關係（如 `urn:uuid:patient-001`）

4. **格式化 JSON（可選）**
   - 點擊「格式化 JSON」按鈕，讓 JSON 格式更易讀

5. **發送請求**
   - 點擊「發送請求」按鈕
   - 系統會顯示處理中的動畫
   - 完成後會顯示回應結果

### 方式二：上傳 .json 檔案

1. **切換到檔案上傳模式**
   - 選擇「上傳 .json 檔案」選項

2. **選擇檔案**
   - 點擊「選擇 .json 檔案」按鈕
   - 可以選擇單個或多個 .json 檔案
   - 系統會顯示已選擇的檔案列表

3. **批次處理**
   - 點擊「發送請求」後，系統會逐一處理每個檔案
   - 顯示每個檔案的處理進度
   - 最後會顯示批次處理摘要（成功/失敗數量）

## 範例說明

### 範例：Patient
建立一個基本的病患資源。

### 範例：Patient + Observation
建立病患並同時建立一筆觀察資料（如血壓測量）。

### 範例：Patient + Encounter + Observation
建立完整的就診記錄，包含：
- 病患資料
- 就診記錄（Encounter）
- 觀察資料（Observation），並關聯到該次就診

### 範例：Patient + AllergyIntolerance
建立病患並記錄過敏資訊。

### 範例：Patient + Condition
建立病患並記錄診斷條件（如糖尿病）。

### 範例：Patient + Procedure
建立病患並記錄處置記錄。

### 範例：Patient + Encounter + Procedure
建立病患、就診記錄和處置記錄，並建立關聯。

### 範例：Patient + CarePlan
建立病患並建立照護計畫。

### 範例：Patient + Practitioner + Organization + CareTeam
建立病患並建立照護團隊，包含：
- 病患（Patient）
- 醫師（Practitioner）- 需要提供 NPI 識別碼
- 醫療機構（Organization）- 需要提供 NPI 識別碼
- 照護團隊（CareTeam），並關聯上述資源

### 範例：Patient + Goal
建立病患並設定治療目標（如血壓控制目標）。

### 範例：Patient + Encounter + DiagnosticReport
建立病患、就診記錄和診斷報告。

### 範例：Patient + QuestionnaireResponse
建立病患並提交問卷回應。

### 範例：Patient + Immunization
建立病患並建立疫苗接種記錄，包含：
- 病患（Patient）
- 疫苗接種記錄（Immunization），包含疫苗代碼（CVX）、接種日期、接種部位、途徑、劑量等資訊

### 範例：多個 Patient
在一個 Bundle 中建立多個病患資源。

## 回應結果說明

### 成功回應
- 狀態碼：200 OK
- 回應內容：包含處理後的 Bundle 資源，每個 entry 會包含：
  - `response.status` - HTTP 狀態碼（如 201 Created）
  - `response.location` - 新建立資源的位置
  - `resource` - 建立的資源內容（包含系統分配的 ID）

### 錯誤回應
- 狀態碼：400 Bad Request 或其他錯誤碼
- 回應內容：包含錯誤詳情，可能包括：
  - `error` - 錯誤訊息
  - `validationErrors` - 驗證錯誤
  - `internalErrors` - 內部錯誤

## 注意事項

1. **資源引用**
   - 系統會自動處理 Bundle 內的資源引用
   - 使用 `urn:uuid:xxx` 格式的引用會被自動解析
   - 例如：`"subject": { "reference": "urn:uuid:patient-001" }` 會自動關聯到同一個 Bundle 中的病患資源

2. **Bundle 類型**
   - 範例使用 `transaction` 類型
   - 所有操作會作為一個事務處理
   - 如果任何操作失敗，整個事務會回滾

3. **檔案格式**
   - 僅支援 `.json` 格式檔案
   - 檔案必須包含有效的 FHIR Bundle JSON

4. **權限要求**
   - 需要適當的 FHIR API 權限
   - 確保已啟用 FHIR API 服務（Administration->Config->Connectors->"Enable OpenEMR Standard FHIR REST API"）

5. **批次處理**
   - 多檔案上傳時，系統會逐一處理
   - 每個檔案之間會有短暫延遲，避免伺服器負載過高

## 常見問題

### Q: 如何知道資源是否成功建立？
A: 查看回應結果中的 `response.status`，如果是 `201` 表示成功建立，`response.location` 會顯示資源的 URL。

### Q: 支援哪些 Bundle 類型？
A: 目前主要支援 `transaction` 和 `batch` 類型。範例使用 `transaction` 類型。

## API 端點資訊

- **端點**：`/apis/{site_id}/fhir/Bundle`
- **方法**：POST
- **Content-Type**：`application/json`（直接輸入）或 `multipart/form-data`（檔案上傳）

## 相關文件

- [API_README.md](API_README.md#bundle-endpoint) - Bundle 端點的詳細 API 文件
- [FHIR_README.md](FHIR_README.md) - FHIR API 完整文件

