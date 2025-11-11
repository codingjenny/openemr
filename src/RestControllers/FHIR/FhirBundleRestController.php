<?php

/**
 * FhirBundleRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Auto Generated
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleResponse;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\Common\Logging\SystemLogger;

require_once(__DIR__ . '/../../../_rest_config.php');

/**
 * Supports REST interactions with the FHIR Bundle resource
 */
class FhirBundleRestController
{
    private $fhirService;
    private $fhirValidate;
    private $logger;

    public function __construct()
    {
        $this->fhirService = new FhirResourcesService();
        $this->fhirValidate = new FhirValidationService();
        $this->logger = new SystemLogger();
    }

    /**
     * Processes a FHIR Bundle resource
     * Supports transaction and batch bundle types
     * @param $fhirJson The FHIR bundle resource
     * @returns 200 if the bundle is processed successfully, 400 if invalid
     */
    public function post($fhirJson)
    {
        try {
            // Validate the bundle structure
            if (empty($fhirJson) || !is_array($fhirJson)) {
                return RestControllerHelper::responseHandler(
                    ['error' => 'Invalid bundle: Bundle must be a valid JSON object'],
                    null,
                    400
                );
            }

            // Check if it's a Bundle resource
            if (!isset($fhirJson['resourceType']) || $fhirJson['resourceType'] !== 'Bundle') {
                return RestControllerHelper::responseHandler(
                    ['error' => 'Invalid bundle: resourceType must be "Bundle"'],
                    null,
                    400
                );
            }

            // Get bundle type directly from JSON
            $bundleType = $fhirJson['type'] ?? null;

            // Process bundle based on type - work directly with JSON array instead of parsing
            $responseBundle = null;
            if ($bundleType === 'transaction' || $bundleType === 'batch') {
                $responseBundle = $this->processTransactionOrBatchFromJson($fhirJson, $bundleType);
            } else {
                // For other bundle types (collection, searchset, etc.), just return the bundle
                $responseBundle = $this->processCollectionBundleFromJson($fhirJson);
            }

            return RestControllerHelper::responseHandler($responseBundle, null, 200);
        } catch (\Throwable $e) {
            // Catch any fatal errors or exceptions
            $this->logger->error("FhirBundleRestController::post() fatal error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Internal server error processing bundle',
                    'message' => $e->getMessage(),
                    'type' => get_class($e)
                ],
                null,
                500
            );
        }
    }

    /**
     * Process a transaction or batch bundle from JSON array
     * @param array $bundleJson
     * @param string $bundleType The original bundle type ('transaction' or 'batch')
     * @return array Response bundle
     */
    private function processTransactionOrBatchFromJson($bundleJson, $bundleType)
    {
        $entries = $bundleJson['entry'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }
        $responseEntries = [];

        foreach ($entries as $entry) {
            try {
                $entryResponse = $this->processBundleEntryFromJson($entry);
                if ($entryResponse) {
                    $responseEntries[] = $entryResponse;
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to process bundle entry", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                $responseEntries[] = [
                    'response' => [
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
                    ]
                ];
            }
        }

        // Determine response type based on request type
        // transaction requests return transaction-response
        // batch requests return batch-response
        $responseType = ($bundleType === 'transaction') ? 'transaction-response' : 'batch-response';

        // Create response bundle
        $responseBundle = [
            'resourceType' => 'Bundle',
            'type' => $responseType,
            'total' => count($responseEntries),
            'entry' => $responseEntries
        ];

        return $responseBundle;
    }

    /**
     * Process a collection bundle from JSON array (just return the resources)
     * @param array $bundleJson
     * @return array Response bundle
     */
    private function processCollectionBundleFromJson($bundleJson)
    {
        $entries = $bundleJson['entry'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }
        $processedEntries = [];

        foreach ($entries as $entry) {
            if (isset($entry['resource'])) {
                $processedEntries[] = [
                    'fullUrl' => $entry['fullUrl'] ?? null,
                    'resource' => $entry['resource']
                ];
            }
        }

        $responseBundle = [
            'resourceType' => 'Bundle',
            'type' => 'collection',
            'total' => count($processedEntries),
            'entry' => $processedEntries
        ];

        return $responseBundle;
    }

    /**
     * Process a single bundle entry from JSON array
     * @param array $entry
     * @return array|null Response entry
     */
    private function processBundleEntryFromJson($entry)
    {
        $request = $entry['request'] ?? null;
        $resource = $entry['resource'] ?? null;

        if (!$request) {
            // If no request, just return the resource
            return [
                'resource' => $resource
            ];
        }

        $methodValue = $request['method'] ?? null;
        $urlValue = $request['url'] ?? null;

        if (!$methodValue || !$urlValue) {
            return [
                'response' => [
                    'status' => '400',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Bundle entry request must have method and url'
                            ]
                        ]
                    ]
                ]
            ];
        }

        // Route to appropriate controller based on URL
        try {
            $result = $this->routeToController($methodValue, $urlValue, $resource);
            return [
                'response' => [
                    'status' => $result['status'],
                    'location' => $result['location'] ?? null,
                    'outcome' => $result['outcome'] ?? null
                ],
                'resource' => $result['resource'] ?? null
            ];
        } catch (\Exception $e) {
            $this->logger->error("Failed to process bundle entry", [
                'method' => $methodValue,
                'url' => $urlValue,
                'error' => $e->getMessage()
            ]);
            return [
                'response' => [
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
                ]
            ];
        }
    }

    /**
     * Route bundle entry to appropriate controller
     * @param string $method HTTP method (POST, PUT, etc.)
     * @param string $url Resource URL
     * @param mixed $resource Resource data
     * @return array Result with status, location, and outcome
     */
    private function routeToController($method, $url, $resource)
    {
        // Parse URL to determine resource type
        // URL format: /fhir/ResourceType or /fhir/ResourceType/{id}
        $urlParts = explode('/', trim($url, '/'));
        if (count($urlParts) < 2 || $urlParts[0] !== 'fhir') {
            throw new \Exception("Invalid URL format: $url");
        }

        $resourceType = $urlParts[1] ?? null;
        $resourceId = $urlParts[2] ?? null;

        if (!$resourceType) {
            throw new \Exception("Resource type not found in URL: $url");
        }

        // getResource() already returns jsonSerialize() result (array), so use it directly
        // If it's not an array, try to convert it
        $resourceArray = $resource;
        if (!is_array($resourceArray)) {
            if (is_object($resourceArray) && method_exists($resourceArray, 'jsonSerialize')) {
                $resourceArray = $resourceArray->jsonSerialize();
            } else {
                $resourceArray = json_decode(json_encode($resourceArray), true);
            }
        }
        
        // Ensure we have a valid array
        if (!is_array($resourceArray)) {
            throw new \Exception("Invalid resource data format");
        }

        // Route to appropriate controller based on resource type
        switch (strtolower($resourceType)) {
            case 'patient':
                return $this->processPatientResource($method, $resourceId, $resourceArray);
            case 'observation':
                return $this->processObservationResource($method, $resourceId, $resourceArray);
            case 'organization':
                return $this->processOrganizationResource($method, $resourceId, $resourceArray);
            case 'practitioner':
                return $this->processPractitionerResource($method, $resourceId, $resourceArray);
            default:
                // For unsupported resource types, return a not-supported response
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
    }

    /**
     * Process Patient resource
     */
    private function processPatientResource($method, $resourceId, $resourceArray)
    {
        $controller = new FhirPatientRestController();
        
        try {
            if ($method === 'POST') {
                // Capture status code before calling controller
                $statusCode = null;
                $result = $controller->post($resourceArray);
                // handleFhirProcessingResult sets http_response_code and returns array
                $statusCode = http_response_code() ?: 201;
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => isset($body['id']) ? '/fhir/Patient/' . $body['id'] : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                $result = $controller->put($resourceId, $resourceArray);
                // handleFhirProcessingResult sets http_response_code and returns array
                $statusCode = http_response_code() ?: 200;
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => '/fhir/Patient/' . $resourceId,
                    'resource' => $body
                ];
            } else {
                return [
                    'status' => '400',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => "Method '$method' not supported for Patient resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Patient resource", [
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

    /**
     * Process Observation resource
     */
    private function processObservationResource($method, $resourceId, $resourceArray)
    {
        $controller = new FhirObservationRestController();
        
        try {
            if ($method === 'POST') {
                $statusCode = null;
                $result = $controller->post($resourceArray);
                $statusCode = http_response_code() ?: 201;
                
                // handleFhirProcessingResult returns the FHIR resource directly (not wrapped in array)
                // Check if result is a FHIR resource object or an array
                if (is_object($result) && method_exists($result, 'jsonSerialize')) {
                    // It's a FHIR resource object, convert to array
                    $body = $result->jsonSerialize();
                } elseif (is_array($result)) {
                    $body = $result;
                } else {
                    $body = null;
                }
                
                $this->logger->debug("FhirBundleRestController::processObservationResource() POST result", [
                    'statusCode' => $statusCode,
                    'resultType' => gettype($result),
                    'bodyType' => gettype($body),
                    'bodyKeys' => is_array($body) ? array_keys($body) : null,
                    'isEmpty' => empty($body)
                ]);
                
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                    $this->logger->warning("FhirBundleRestController::processObservationResource() empty body returned", [
                        'httpResponseCode' => http_response_code(),
                        'result' => $result
                    ]);
                }
                
                // Extract ID from FHIR resource if it's an object
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
                    'location' => $resourceId ? '/fhir/Observation/' . $resourceId : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                $result = $controller->put($resourceId, $resourceArray);
                $statusCode = http_response_code() ?: 200;
                $body = is_array($result) ? $result : null;
                
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => '/fhir/Observation/' . $resourceId,
                    'resource' => $body
                ];
            } else {
                return [
                    'status' => '400',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => "Method '$method' not supported for Observation resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Observation resource", [
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

    /**
     * Process Organization resource
     */
    private function processOrganizationResource($method, $resourceId, $resourceArray)
    {
        $controller = new FhirOrganizationRestController();
        
        try {
            if ($method === 'POST') {
                $result = $controller->post($resourceArray);
                $statusCode = http_response_code() ?: 201;
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => isset($body['id']) ? '/fhir/Organization/' . $body['id'] : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                $result = $controller->patch($resourceId, $resourceArray);
                $statusCode = http_response_code() ?: 200;
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => '/fhir/Organization/' . $resourceId,
                    'resource' => $body
                ];
            } else {
                return [
                    'status' => '400',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => "Method '$method' not supported for Organization resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Organization resource", [
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

    /**
     * Process Practitioner resource
     */
    private function processPractitionerResource($method, $resourceId, $resourceArray)
    {
        $controller = new FhirPractitionerRestController();
        
        try {
            if ($method === 'POST') {
                $result = $controller->post($resourceArray);
                $statusCode = http_response_code() ?: 201;
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => isset($body['id']) ? '/fhir/Practitioner/' . $body['id'] : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                $result = $controller->patch($resourceId, $resourceArray);
                $statusCode = http_response_code() ?: 200;
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => '/fhir/Practitioner/' . $resourceId,
                    'resource' => $body
                ];
            } else {
                return [
                    'status' => '400',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => "Method '$method' not supported for Practitioner resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Practitioner resource", [
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
}

