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
     * Processes a FHIR Bundle resource from uploaded file
     * Supports .json file uploads
     * @param array $fileData The uploaded file data from $_FILES
     * @returns 200 if the bundle is processed successfully, 400 if invalid
     */
    public function postFromFile($fileData)
    {
        try {
            // Validate file upload
            if (!isset($fileData['tmp_name']) || !is_uploaded_file($fileData['tmp_name'])) {
                return RestControllerHelper::responseHandler(
                    ['error' => 'Invalid file: No file uploaded or file upload failed'],
                    null,
                    400
                );
            }

            // Check file extension
            $fileName = $fileData['name'] ?? '';
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($fileExtension !== 'json') {
                return RestControllerHelper::responseHandler(
                    ['error' => 'Invalid file type: Only .json files are supported'],
                    null,
                    400
                );
            }

            // Read and parse JSON file
            $fileContent = file_get_contents($fileData['tmp_name']);
            if ($fileContent === false) {
                return RestControllerHelper::responseHandler(
                    ['error' => 'Invalid file: Could not read file contents'],
                    null,
                    400
                );
            }

            $fhirJson = json_decode($fileContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return RestControllerHelper::responseHandler(
                    ['error' => 'Invalid JSON: ' . json_last_error_msg()],
                    null,
                    400
                );
            }

            // Process the bundle
            return $this->post($fhirJson);
        } catch (\Throwable $e) {
            $this->logger->error("FhirBundleRestController::postFromFile() fatal error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Internal server error processing bundle file',
                    'message' => $e->getMessage(),
                    'type' => get_class($e)
                ],
                null,
                500
            );
        }
    }

    /**
     * Processes a FHIR Bundle resource
     * Supports transaction and batch bundle types
     * @param $fhirJson The FHIR bundle resource (array)
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
        
        // Map to track fullUrl (urn:uuid:xxx) to actual created resource UUID
        // This allows resources to reference each other within the bundle
        $uuidMap = [];
        
        // First pass: Process entries with fullUrl to build UUID map
        // This ensures references can be resolved in subsequent entries
        $entriesToProcess = [];
        foreach ($entries as $index => $entry) {
            $fullUrl = $entry['fullUrl'] ?? null;
            if ($fullUrl && strpos($fullUrl, 'urn:uuid:') === 0) {
                // This entry has a fullUrl, process it first to build the map
                try {
                    // Process without replacing references (uuidMap is still empty)
                    $entryResponse = $this->processBundleEntryFromJson($entry, $bundleType);
                    
                    $this->logger->debug("FhirBundleRestController: Processed entry with fullUrl", [
                        'fullUrl' => $fullUrl,
                        'entryResponse' => json_encode($entryResponse),
                        'responseStatus' => $entryResponse['response']['status'] ?? 'unknown'
                    ]);
                    
                    if ($entryResponse) {
                        // Extract the created resource UUID from the response
                        $createdResource = $entryResponse['resource'] ?? null;
                        $resourceId = null;
                        
                        $this->logger->debug("FhirBundleRestController: Extracting UUID from resource", [
                            'fullUrl' => $fullUrl,
                            'createdResourceType' => is_object($createdResource) ? get_class($createdResource) : (is_array($createdResource) ? 'array' : 'null'),
                            'createdResource' => is_array($createdResource) ? json_encode($createdResource) : 'not array'
                        ]);
                        
                        // Handle both object and array formats
                        if (is_object($createdResource) && method_exists($createdResource, 'getId')) {
                            $idObj = $createdResource->getId();
                            if (is_object($idObj) && method_exists($idObj, 'getValue')) {
                                $resourceId = $idObj->getValue();
                            }
                        } elseif (is_array($createdResource) && isset($createdResource['id'])) {
                            $resourceId = $createdResource['id'];
                        }
                        
                        if ($resourceId) {
                            $uuidMap[$fullUrl] = $resourceId;
                            $this->logger->debug("FhirBundleRestController: Mapped fullUrl to UUID", [
                                'fullUrl' => $fullUrl,
                                'uuid' => $resourceId
                            ]);
                        } else {
                            $this->logger->warning("FhirBundleRestController: Could not extract UUID from resource", [
                                'fullUrl' => $fullUrl,
                                'createdResource' => is_array($createdResource) ? json_encode($createdResource) : 'not array'
                            ]);
                        }
                        
                        $responseEntries[$index] = $entryResponse;
                    }
                } catch (\Exception $e) {
                    $this->logger->error("Failed to process bundle entry with fullUrl", [
                        'index' => $index,
                        'fullUrl' => $fullUrl,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $responseEntries[$index] = [
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
            } else {
                // This entry doesn't have a fullUrl, process it in second pass
                $entriesToProcess[$index] = $entry;
            }
        }
        
        // Second pass: Process remaining entries with UUID references replaced
        foreach ($entriesToProcess as $index => $entry) {
            try {
                // Log UUID map before replacement
                $this->logger->debug("FhirBundleRestController: Processing entry in second pass", [
                    'index' => $index,
                    'uuidMap' => $uuidMap,
                    'entryResourceType' => $entry['resource']['resourceType'] ?? 'unknown'
                ]);
                
                // Replace urn:uuid references in the resource before processing
                $entry = $this->replaceUuidReferences($entry, $uuidMap);
                
                // Log after replacement
                $this->logger->debug("FhirBundleRestController: After UUID replacement", [
                    'index' => $index,
                    'entryResource' => json_encode($entry['resource'] ?? [])
                ]);
                
                // Process the entry (pass bundle type for auto-inference)
                $entryResponse = $this->processBundleEntryFromJson($entry, $bundleType);
                
                if ($entryResponse) {
                    $responseEntries[$index] = $entryResponse;
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to process bundle entry", [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $responseEntries[$index] = [
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
        
        // Sort response entries by original index to maintain order
        ksort($responseEntries);
        $responseEntries = array_values($responseEntries);

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
     * Replace urn:uuid references in a bundle entry with actual UUIDs from the map
     * @param array $entry Bundle entry
     * @param array $uuidMap Map of fullUrl (urn:uuid:xxx) to actual UUID
     * @return array Entry with replaced references
     */
    private function replaceUuidReferences($entry, $uuidMap)
    {
        if (!isset($entry['resource'])) {
            return $entry;
        }
        
        $resource = $entry['resource'];
        
        // Ensure resource is an array for processing
        if (is_object($resource) && method_exists($resource, 'jsonSerialize')) {
            $resource = $resource->jsonSerialize();
        } elseif (!is_array($resource)) {
            $resource = json_decode(json_encode($resource), true);
        }
        
        // Log before replacement
        $this->logger->debug("FhirBundleRestController: replaceUuidReferences called", [
            'uuidMapSize' => count($uuidMap),
            'uuidMap' => $uuidMap,
            'resourceType' => $resource['resourceType'] ?? 'unknown'
        ]);
        
        // Recursively replace references in the resource (even if uuidMap is empty, we still need to process)
        $entry['resource'] = $this->replaceReferencesInResource($resource, $uuidMap);
        
        return $entry;
    }
    
    /**
     * Recursively replace urn:uuid references in a resource
     * @param mixed $data Resource data (array or object)
     * @param array $uuidMap Map of fullUrl to UUID
     * @param string|null $parentKey Parent key for context (to determine resource type)
     * @return mixed Resource with replaced references
     */
    private function replaceReferencesInResource($data, $uuidMap, $parentKey = null)
    {
        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                // Check if this is a reference field
                if ($key === 'reference' && is_string($value) && strpos($value, 'urn:uuid:') === 0) {
                    // Replace urn:uuid reference with actual UUID if available
                    $this->logger->debug("FhirBundleRestController: Found urn:uuid reference", [
                        'value' => $value,
                        'parentKey' => $parentKey,
                        'uuidMap' => $uuidMap,
                        'inMap' => isset($uuidMap[$value])
                    ]);
                    
                    if (isset($uuidMap[$value])) {
                        // Determine resource type from parent key (subject -> Patient, encounter -> Encounter, etc.)
                        $resourceType = $this->inferResourceTypeFromContext($parentKey);
                        if ($resourceType) {
                            $result[$key] = $resourceType . '/' . $uuidMap[$value];
                        } else {
                            // Fallback: just use the UUID (service will need to handle it)
                            $result[$key] = $uuidMap[$value];
                        }
                        $this->logger->debug("FhirBundleRestController: Replaced urn:uuid reference", [
                            'original' => $value,
                            'replaced' => $result[$key],
                            'parentKey' => $parentKey,
                            'resourceType' => $resourceType
                        ]);
                    } else {
                        $this->logger->warning("FhirBundleRestController: urn:uuid reference not found in map", [
                            'value' => $value,
                            'parentKey' => $parentKey,
                            'uuidMapKeys' => array_keys($uuidMap)
                        ]);
                        $result[$key] = $value;
                    }
                } else {
                    // Recursively process nested structures, passing current key as parent
                    $result[$key] = $this->replaceReferencesInResource($value, $uuidMap, $key);
                }
            }
            return $result;
        } elseif (is_object($data)) {
            // Convert object to array, process, and convert back if needed
            $array = json_decode(json_encode($data), true);
            $processed = $this->replaceReferencesInResource($array, $uuidMap, $parentKey);
            return $processed;
        }
        
        return $data;
    }
    
    /**
     * Infer resource type from context key
     * @param string|null $key Context key (e.g., 'subject', 'encounter', 'performer')
     * @return string|null Resource type or null
     */
    private function inferResourceTypeFromContext($key)
    {
        $contextMap = [
            'subject' => 'Patient',
            'encounter' => 'Encounter',
            'performer' => 'Practitioner',
            'organization' => 'Organization',
            'practitioner' => 'Practitioner',
            'patient' => 'Patient',
        ];
        
        return $contextMap[strtolower($key ?? '')] ?? null;
    }
    
    /**
     * Auto-infer request from resource when request is missing
     * For transaction/batch bundles, we can infer:
     * - POST if resource has no id
     * - PUT if resource has id
     * - URL from resourceType
     * @param array|null $resource Resource data
     * @return array|null Inferred request or null if cannot infer
     */
    private function inferRequestFromResource($resource)
    {
        if (empty($resource) || !is_array($resource)) {
            return null;
        }
        
        $resourceType = $resource['resourceType'] ?? null;
        if (!$resourceType) {
            return null;
        }
        
        // Check if resource has an id
        $hasId = !empty($resource['id']);
        
        // Infer method: POST for new resources, PUT for existing ones
        $method = $hasId ? 'PUT' : 'POST';
        
        // Infer URL from resourceType
        $url = $resourceType;
        
        // If PUT, include the id in the URL
        if ($hasId && $method === 'PUT') {
            $url = $resourceType . '/' . $resource['id'];
        }
        
        return [
            'method' => $method,
            'url' => $url
        ];
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
     * @param string $bundleType The bundle type (transaction, batch, etc.)
     * @return array|null Response entry
     */
    private function processBundleEntryFromJson($entry, $bundleType = null)
    {
        $request = $entry['request'] ?? null;
        $resource = $entry['resource'] ?? null;
        
        // Ensure resource is an array for processing
        if (is_object($resource) && method_exists($resource, 'jsonSerialize')) {
            $resource = $resource->jsonSerialize();
        } elseif (!is_array($resource) && $resource !== null) {
            $resource = json_decode(json_encode($resource), true);
        }
        
        // Update entry with converted resource
        $entry['resource'] = $resource;

        // If no request, try to auto-infer for transaction/batch bundles
        if (!$request && ($bundleType === 'transaction' || $bundleType === 'batch')) {
            $request = $this->inferRequestFromResource($resource);
            if ($request) {
                $resourceType = null;
                if (is_array($resource)) {
                    $resourceType = $resource['resourceType'] ?? null;
                } elseif (is_object($resource) && method_exists($resource, 'getResourceType')) {
                    $resourceType = $resource->getResourceType();
                }
                $this->logger->debug("FhirBundleRestController: Auto-inferred request", [
                    'method' => $request['method'],
                    'url' => $request['url'],
                    'resourceType' => $resourceType
                ]);
            }
        }

        if (!$request) {
            // If no request and couldn't infer, just return the resource (for collection, searchset, etc.)
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
                                'diagnostics' => 'Bundle entry request must have method and url. For transaction/batch bundles, ensure resource has resourceType and optionally id field.'
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
        // Support multiple URL formats:
        // - /fhir/ResourceType or /fhir/ResourceType/{id} (full path)
        // - ResourceType or ResourceType/{id} (relative path, without /fhir prefix)
        $urlParts = explode('/', trim($url, '/'));
        
        $resourceType = null;
        $resourceId = null;
        
        if (count($urlParts) >= 2 && $urlParts[0] === 'fhir') {
            // Full path format: /fhir/ResourceType or /fhir/ResourceType/{id}
            $resourceType = $urlParts[1] ?? null;
            $resourceId = $urlParts[2] ?? null;
        } elseif (count($urlParts) >= 1) {
            // Relative path format: ResourceType or ResourceType/{id}
            $resourceType = $urlParts[0] ?? null;
            $resourceId = $urlParts[1] ?? null;
        }
        
        if (!$resourceType) {
            throw new \Exception("Invalid URL format: $url. Expected format: /fhir/ResourceType or ResourceType");
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
                // handleFhirProcessingResult sets http_response_code and returns FHIR resource object or array
                $statusCode = http_response_code() ?: 201;
                
                // Check if result is a FHIR resource object or an array
                if (is_object($result) && method_exists($result, 'jsonSerialize')) {
                    // It's a FHIR resource object, convert to array
                    $body = $result->jsonSerialize();
                } elseif (is_array($result)) {
                    $body = $result;
                } else {
                    $body = null;
                }
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                } elseif (empty($body)) {
                    $statusCode = 404;
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
                    'location' => $resourceId ? '/fhir/Patient/' . $resourceId : null,
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

