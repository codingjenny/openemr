<?php

/**
 * FHIR Bundle Queue Background Service
 *
 * This service processes FHIR Bundle requests from the queue table.
 * It is called by the background_services system (execute_background_services.php)
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2025
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../interface/globals.php");

use OpenEMR\RestControllers\FHIR\FhirBundleRestController;
use OpenEMR\Common\Logging\SystemLogger;

/**
 * Process FHIR Bundle queue entries
 * This function is called by the background_services system
 */
function fhirBundleServiceRun(): void
{
    $logger = new SystemLogger();
    
    try {
        // Get pending queue entries (status = 'pending' and not processing)
        $sql = "SELECT `id`, `bundle_json`, `retry_count`, `max_retries` 
                FROM `fhir_bundle_queue` 
                WHERE `status` = 'pending' 
                ORDER BY `datetime_queued` ASC 
                LIMIT 10";
        
        $queueEntries = sqlStatement($sql);
        
        if (!$queueEntries || sqlNumRows($queueEntries) == 0) {
            // No pending entries
            return;
        }
        
        $bundleController = new FhirBundleRestController();
        
        while ($entry = sqlFetchArray($queueEntries)) {
            $queueId = $entry['id'];
            $bundleJson = json_decode($entry['bundle_json'], true);
            $retryCount = (int)$entry['retry_count'];
            $maxRetries = (int)$entry['max_retries'];
            
            if (!$bundleJson) {
                // Invalid JSON, mark as failed
                sqlStatement(
                    "UPDATE `fhir_bundle_queue` 
                     SET `status` = 'failed', 
                         `error` = 1, 
                         `error_message` = ?, 
                         `datetime_error` = NOW() 
                     WHERE `id` = ?",
                    ['Invalid JSON in bundle_json', $queueId]
                );
                continue;
            }
            
            // Mark as processing (to prevent concurrent processing)
            sqlStatement(
                "UPDATE `fhir_bundle_queue` 
                 SET `status` = 'processing' 
                 WHERE `id` = ? AND `status` = 'pending'",
                [$queueId]
            );
            
            // Check if we actually acquired the lock
            $check = sqlQuery("SELECT `status` FROM `fhir_bundle_queue` WHERE `id` = ?", [$queueId]);
            if ($check['status'] !== 'processing') {
                // Another process is handling this, skip
                continue;
            }
            
            try {
                // Process the bundle
                $result = $bundleController->post($bundleJson);
                
                // Extract status code from result
                $statusCode = http_response_code() ?: 200;
                $resultBody = is_array($result) ? $result : json_decode(json_encode($result), true);
                
                // Check if processing was successful
                if ($statusCode >= 200 && $statusCode < 300) {
                    // Success - mark as completed
                    sqlStatement(
                        "UPDATE `fhir_bundle_queue` 
                         SET `status` = 'completed', 
                             `datetime_processed` = NOW(),
                             `result_json` = ?,
                             `error` = 0,
                             `error_message` = NULL
                         WHERE `id` = ?",
                        [json_encode($resultBody), $queueId]
                    );
                    
                    $logger->info("FHIR Bundle queue entry processed successfully", ['queue_id' => $queueId]);
                } else {
                    // Failed - check if we should retry
                    $errorMessage = $resultBody['error'] ?? 'Unknown error';
                    
                    if ($retryCount < $maxRetries) {
                        // Retry - reset to pending
                        sqlStatement(
                            "UPDATE `fhir_bundle_queue` 
                             SET `status` = 'pending', 
                                 `retry_count` = ?,
                                 `error_message` = ?
                             WHERE `id` = ?",
                            [$retryCount + 1, $errorMessage, $queueId]
                        );
                        
                        $logger->warning("FHIR Bundle queue entry failed, will retry", [
                            'queue_id' => $queueId,
                            'retry_count' => $retryCount + 1,
                            'error' => $errorMessage
                        ]);
                    } else {
                        // Max retries reached - mark as failed
                        sqlStatement(
                            "UPDATE `fhir_bundle_queue` 
                             SET `status` = 'failed', 
                                 `datetime_error` = NOW(),
                                 `error` = 1,
                                 `error_message` = ?
                             WHERE `id` = ?",
                            [$errorMessage, $queueId]
                        );
                        
                        $logger->error("FHIR Bundle queue entry failed after max retries", [
                            'queue_id' => $queueId,
                            'retry_count' => $retryCount,
                            'error' => $errorMessage
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Exception during processing
                $errorMessage = $e->getMessage();
                
                if ($retryCount < $maxRetries) {
                    // Retry
                    sqlStatement(
                        "UPDATE `fhir_bundle_queue` 
                         SET `status` = 'pending', 
                             `retry_count` = ?,
                             `error_message` = ?
                         WHERE `id` = ?",
                        [$retryCount + 1, $errorMessage, $queueId]
                    );
                    
                    $logger->warning("FHIR Bundle queue entry exception, will retry", [
                        'queue_id' => $queueId,
                        'retry_count' => $retryCount + 1,
                        'error' => $errorMessage,
                        'trace' => $e->getTraceAsString()
                    ]);
                } else {
                    // Max retries reached
                    sqlStatement(
                        "UPDATE `fhir_bundle_queue` 
                         SET `status` = 'failed', 
                             `datetime_error` = NOW(),
                             `error` = 1,
                             `error_message` = ?
                         WHERE `id` = ?",
                        [$errorMessage, $queueId]
                    );
                    
                    $logger->error("FHIR Bundle queue entry exception after max retries", [
                        'queue_id' => $queueId,
                        'retry_count' => $retryCount,
                        'error' => $errorMessage,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }
    } catch (\Exception $e) {
        $logger->error("FHIR Bundle service run fatal error", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

