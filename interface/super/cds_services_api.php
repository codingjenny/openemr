<?php
/**
 * CDS Hooks Services API
 * 
 * This file handles discovery and management of CDS Hooks services
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @author  GitHub Copilot AI Assistant 
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Set ignoreAuth to bypass session checks for API calls
$ignoreAuth = true;

require_once("../globals.php");
require_once("$srcdir/patient.inc");
require_once("$srcdir/sql.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

// Set content type for JSON response
header('Content-Type: application/json');

// Check for valid request
if (!isset($_POST['action']) && !isset($_GET['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No action specified']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'];

// CSRF protection for POST requests (disabled for API calls)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$ignoreAuth) {
    $csrfToken = $_POST['csrf_token'] ?? $_POST['csrf_token_form'] ?? '';
    if (!CsrfUtils::verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'CSRF token verification failed']);
        exit;
    }
}

/**
 * Get current CDS Hooks Discovery URL from settings
 */
function getDiscoveryUrl() {
    return $GLOBALS['cds_hooks_discovery_url'] ?? 'https://sandbox-services.cds-hooks.org/cds-services';
}

/**
 * Fetch services from CDS Hooks discovery endpoint
 */
function discoverServices($discoveryUrl) {
    try {
        $timeout = intval($GLOBALS['cds_hooks_timeout'] ?? 10);
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: OpenEMR/7.0.0 CDS-Hooks-Client'
                ]
            ]
        ]);
        
        $response = file_get_contents($discoveryUrl, false, $context);
        
        if ($response === false) {
            throw new Exception('Failed to fetch services from discovery URL');
        }
        
        $data = json_decode($response, true);
        
        if (!$data || !isset($data['services'])) {
            throw new Exception('Invalid discovery response format');
        }
        
        return $data['services'];
        
    } catch (Exception $e) {
        error_log("CDS Hooks Discovery Error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Get enabled/disabled status for services from database
 */
function getServiceStates() {
    // Check if table exists first
    $tableCheck = sqlQuery("SHOW TABLES LIKE 'cds_hooks_services'");
    if (empty($tableCheck)) {
        // Table doesn't exist yet, return empty array
        return [];
    }
    
    $result = sqlStatement("SELECT service_id, enabled, service_title, service_description, hook_types, prefetch FROM cds_hooks_services");
    $states = [];
    
    while ($row = sqlFetchArray($result)) {
        $states[$row['service_id']] = [
            'enabled' => (bool)$row['enabled'],
            'title' => $row['service_title'],
            'description' => $row['service_description'],
            'hook_types' => $row['hook_types'],
            'prefetch' => $row['prefetch']
        ];
    }
    
    return $states;
}

/**
 * Save service information and state to database
 */
function saveServiceInfo($service, $enabled = null) {
    // Create table if it doesn't exist
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS `cds_hooks_services` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `service_id` varchar(255) NOT NULL,
            `service_title` varchar(255) DEFAULT NULL,
            `service_description` text DEFAULT NULL,
            `hook_types` text DEFAULT NULL,
            `prefetch` text DEFAULT NULL,
            `enabled` tinyint(1) DEFAULT 0,
            `discovery_url` varchar(500) DEFAULT NULL,
            `last_discovered` timestamp NULL DEFAULT NULL,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `service_id_unique` (`service_id`),
            KEY `enabled` (`enabled`),
            KEY `discovery_url` (`discovery_url`(255))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Manages CDS Hooks services and their enabled/disabled states'
    ";
    
    sqlStatement($createTableSql);
    
    // Add prefetch column if it doesn't exist (for existing tables)
    $prefetchColumnCheck = sqlQuery("SHOW COLUMNS FROM `cds_hooks_services` LIKE 'prefetch'");
    if (empty($prefetchColumnCheck)) {
        sqlStatement("ALTER TABLE `cds_hooks_services` ADD COLUMN `prefetch` text DEFAULT NULL AFTER `hook_types`");
    }
    
    $serviceId = $service['id'] ?? '';
    $title = $service['title'] ?? '';
    $description = $service['description'] ?? '';
    $hookTypes = '';
    
    if (isset($service['hook'])) {
        $hookTypes = is_array($service['hook']) ? implode(', ', $service['hook']) : $service['hook'];
    }
    
    $prefetch = isset($service['prefetch']) ? json_encode($service['prefetch']) : null;
    
    $discoveryUrl = getDiscoveryUrl();
    
    // Only update enabled status if explicitly provided
    if ($enabled !== null) {
        $enabled = $enabled ? 1 : 0;
        sqlStatement(
            "INSERT INTO `cds_hooks_services` 
             (`service_id`, `service_title`, `service_description`, `hook_types`, `prefetch`, `enabled`, `discovery_url`, `last_discovered`) 
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) 
             ON DUPLICATE KEY UPDATE 
             `service_title` = ?, `service_description` = ?, `hook_types` = ?, `prefetch` = ?, `enabled` = ?, `discovery_url` = ?, `last_discovered` = NOW(), `updated_at` = CURRENT_TIMESTAMP",
            [$serviceId, $title, $description, $hookTypes, $prefetch, $enabled, $discoveryUrl, $title, $description, $hookTypes, $prefetch, $enabled, $discoveryUrl]
        );
    } else {
        // Just update service info, keep existing enabled status
        sqlStatement(
            "INSERT INTO `cds_hooks_services` 
             (`service_id`, `service_title`, `service_description`, `hook_types`, `prefetch`, `discovery_url`, `last_discovered`) 
             VALUES (?, ?, ?, ?, ?, ?, NOW()) 
             ON DUPLICATE KEY UPDATE 
             `service_title` = ?, `service_description` = ?, `hook_types` = ?, `prefetch` = ?, `discovery_url` = ?, `last_discovered` = NOW(), `updated_at` = CURRENT_TIMESTAMP",
            [$serviceId, $title, $description, $hookTypes, $prefetch, $discoveryUrl, $title, $description, $hookTypes, $prefetch, $discoveryUrl]
        );
    }
}

// Handle different actions
switch ($action) {
    case 'discover':
        try {
            // Get discovery URL from parameter or global setting
            $discoveryUrl = $_GET['discovery_url'] ?? getDiscoveryUrl();
            
            if (empty($discoveryUrl)) {
                throw new Exception('Discovery URL is required');
            }
            
            $services = discoverServices($discoveryUrl);
            $serviceStates = getServiceStates();
            
            // Merge service info with saved states
            foreach ($services as &$service) {
                $serviceId = $service['id'] ?? '';
                $savedState = $serviceStates[$serviceId] ?? null;
                $service['enabled'] = $savedState ? $savedState['enabled'] : false;
                
                // Update service info in database
                saveServiceInfo($service);
            }
            
            echo json_encode([
                'success' => true,
                'discoveryUrl' => $discoveryUrl,
                'services' => $services
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        break;
        
    case 'toggle_service':
        try {
            $serviceId = $_POST['service_id'] ?? '';
            $enabled = ($_POST['enabled'] ?? '') === '1' || ($_POST['enabled'] ?? '') === 'true';
            
            if (empty($serviceId)) {
                throw new Exception('Service ID is required');
            }
            
            saveServiceInfo(['id' => $serviceId], $enabled);
            
            echo json_encode([
                'success' => true,
                'service_id' => $serviceId,
                'enabled' => $enabled
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        break;
        
    case 'get_services':
        try {
            $discoveryUrl = getDiscoveryUrl();
            $serviceStates = getServiceStates();
            
            // Convert service states to array format expected by frontend
            $services = [];
            foreach ($serviceStates as $serviceId => $state) {
                $prefetch = !empty($state['prefetch']) ? json_decode($state['prefetch'], true) : [];
                
                $services[] = [
                    'id' => $serviceId,
                    'title' => $state['title'],
                    'description' => $state['description'],
                    'hook' => !empty($state['hook_types']) ? explode(', ', $state['hook_types']) : [],
                    'enabled' => $state['enabled'],
                    'prefetch' => $prefetch
                ];
            }
            
            echo json_encode([
                'success' => true,
                'services' => $services,
                'discoveryUrl' => $discoveryUrl
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        break;
        
    case 'get_csrf_token':
        echo json_encode([
            'success' => true,
            'csrf_token' => CsrfUtils::collectCsrfToken()
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>