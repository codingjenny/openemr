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
use OpenEMR\Common\Http\HttpRestRequest;

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
     * @param bool $useQueue If true, queue the request for background processing (returns 202 Accepted)
     * @returns 200 if the bundle is processed successfully, 202 if queued, 400 if invalid
     */
    public function post($fhirJson, $useQueue = false)
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

            // If queue mode is requested, add to queue and return 202 Accepted
            if ($useQueue) {
                return $this->queueBundle($fhirJson);
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
                    // Replace urn:uuid references in the resource before processing
                    // This allows later entries in the first pass to reference earlier ones
                    $entry = $this->replaceUuidReferences($entry, $uuidMap);
                    
                    // Process the entry
                    $entryResponse = $this->processBundleEntryFromJson($entry, $bundleType);
                    
                    if ($entryResponse) {
                        // Extract the created resource UUID from the response
                        $createdResource = $entryResponse['resource'] ?? null;
                        $resourceId = null;
                        
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
                        } else {
                            // Only log warning if we actually expected a UUID (not for all resource types)
                            $this->logger->warning("FhirBundleRestController: Could not extract UUID from resource", [
                                'fullUrl' => $fullUrl,
                                'resourceType' => is_array($createdResource) ? ($createdResource['resourceType'] ?? 'unknown') : 'unknown'
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
        // Optimized: Group entries by resource type to reuse controllers
        $groupedEntries = $this->groupEntriesByResourceType($entriesToProcess);
        
        // Process each group (entries of the same type can reuse the same controller)
        foreach ($groupedEntries as $groupKey => $group) {
            $resourceType = $group['resourceType'];
            $method = $group['method'];
            
            // Create controller once per resource type group (reuse for all entries in group)
            $controller = $this->getControllerForResourceType($resourceType);
            
            foreach ($group['entries'] as $entryData) {
                $index = $entryData['index'];
                $entry = $entryData['entry'];
                
                try {
                    // Replace urn:uuid references in the resource before processing
                    $entry = $this->replaceUuidReferences($entry, $uuidMap);
                    
                    // Process the entry (pass bundle type for auto-inference)
                    $entryResponse = $this->processBundleEntryFromJson($entry, $bundleType, $controller);
                    
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
                    if (isset($uuidMap[$value])) {
                        // Determine resource type from parent key (subject -> Patient, encounter -> Encounter, etc.)
                        $resourceType = $this->inferResourceTypeFromContext($parentKey);
                        if ($resourceType) {
                            $result[$key] = $resourceType . '/' . $uuidMap[$value];
                        } else {
                            // Fallback: just use the UUID (service will need to handle it)
                            $result[$key] = $uuidMap[$value];
                        }
                    } else {
                        // Only log warning if this is a critical reference (not all missing references are errors)
                        $this->logger->warning("FhirBundleRestController: urn:uuid reference not found in map", [
                            'value' => $value,
                            'parentKey' => $parentKey
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
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     * @return array|null Response entry
     */
    private function processBundleEntryFromJson($entry, $bundleType = null, $reusedController = null)
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
            $result = $this->routeToController($methodValue, $urlValue, $resource, $reusedController);
            
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
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     * @return array Result with status, location, and outcome
     */
    private function routeToController($method, $url, $resource, $reusedController = null)
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
                return $this->processPatientResource($method, $resourceId, $resourceArray, $reusedController);
            case 'observation':
                return $this->processObservationResource($method, $resourceId, $resourceArray, $reusedController);
            case 'organization':
                return $this->processOrganizationResource($method, $resourceId, $resourceArray, $reusedController);
            case 'practitioner':
                return $this->processPractitionerResource($method, $resourceId, $resourceArray, $reusedController);
            case 'encounter':
                return $this->processEncounterResource($method, $resourceId, $resourceArray, $reusedController);
            case 'allergyintolerance':
                return $this->processAllergyIntoleranceResource($method, $resourceId, $resourceArray, $reusedController);
            case 'condition':
                return $this->processConditionResource($method, $resourceId, $resourceArray, $reusedController);
            case 'careplan':
                return $this->processCarePlanResource($method, $resourceId, $resourceArray, $reusedController);
            case 'procedure':
                return $this->processProcedureResource($method, $resourceId, $resourceArray, $reusedController);
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
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     */
    private function processPatientResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirPatientRestController();
        
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
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     */
    private function processObservationResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirObservationRestController();
        
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
    private function processOrganizationResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirOrganizationRestController();
        
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
    private function processPractitionerResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirPractitionerRestController();
        
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

    /**
     * Process Encounter resource
     */
    private function processEncounterResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirEncounterRestController();
        
        try {
            if ($method === 'POST') {
                $statusCode = null;
                $result = $controller->post($resourceArray);
                $statusCode = http_response_code() ?: 201;
                
                // FhirEncounterRestController::post() returns an array (already converted from object)
                $body = is_array($result) ? $result : null;
                
                // Check for validation errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                    $this->logger->warning("FhirBundleRestController::processEncounterResource() validation errors", [
                        'validationErrors' => $body['validationErrors']
                    ]);
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                    $this->logger->error("FhirBundleRestController::processEncounterResource() internal errors", [
                        'internalErrors' => $body['internalErrors']
                    ]);
                } elseif (empty($body) || !isset($body['resourceType'])) {
                    $statusCode = 500;
                    $this->logger->error("FhirBundleRestController::processEncounterResource() invalid or empty body returned", [
                        'resultType' => gettype($result),
                        'body' => $body
                    ]);
                    return [
                        'status' => (string)$statusCode,
                        'outcome' => [
                            'resourceType' => 'OperationOutcome',
                            'issue' => [
                                [
                                    'severity' => 'error',
                                    'code' => 'exception',
                                    'diagnostics' => 'Encounter creation returned invalid or empty response. Check server logs for details.'
                                ]
                            ]
                        ]
                    ];
                }
                
                // Extract ID from FHIR resource
                $resourceId = $body['id'] ?? null;
                
                return [
                    'status' => (string)$statusCode,
                    'location' => $resourceId ? '/fhir/Encounter/' . $resourceId : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                // PUT not yet implemented for Encounter
                return [
                    'status' => '501',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'not-supported',
                                'diagnostics' => "PUT method not yet supported for Encounter resource"
                            ]
                        ]
                    ]
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
                                'diagnostics' => "Method '$method' not supported for Encounter resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Encounter resource", [
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
     * Process AllergyIntolerance resource
     * @param string $method HTTP method (POST, PUT, etc.)
     * @param string|null $resourceId Resource ID for PUT operations
     * @param array $resourceArray Resource data as array
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     */
    private function processAllergyIntoleranceResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirAllergyIntoleranceRestController();
        
        try {
            if ($method === 'POST') {
                $controller = $reusedController ?? new FhirAllergyIntoleranceRestController();
                
                $statusCode = null;
                $result = $controller->post($resourceArray);
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
                $createdResourceId = null;
                if (is_object($result) && method_exists($result, 'getId')) {
                    $idObj = $result->getId();
                    if (is_object($idObj) && method_exists($idObj, 'getValue')) {
                        $createdResourceId = $idObj->getValue();
                    }
                } elseif (is_array($body) && isset($body['id'])) {
                    $createdResourceId = $body['id'];
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => $createdResourceId ? '/fhir/AllergyIntolerance/' . $createdResourceId : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                // PUT not yet implemented for AllergyIntolerance
                return [
                    'status' => '501',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'not-supported',
                                'diagnostics' => "PUT method not yet supported for AllergyIntolerance resource"
                            ]
                        ]
                    ]
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
                                'diagnostics' => "Method '$method' not supported for AllergyIntolerance resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process AllergyIntolerance resource", [
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
     * Process Condition resource
     * @param string $method HTTP method (POST, PUT, etc.)
     * @param string|null $resourceId Resource ID for PUT operations
     * @param array $resourceArray Resource data as array
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     */
    private function processConditionResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirConditionRestController();
        
        try {
            if ($method === 'POST') {
                $statusCode = null;
                $result = $controller->post($resourceArray);
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
                $createdResourceId = null;
                if (is_object($result) && method_exists($result, 'getId')) {
                    $idObj = $result->getId();
                    if (is_object($idObj) && method_exists($idObj, 'getValue')) {
                        $createdResourceId = $idObj->getValue();
                    }
                } elseif (is_array($body) && isset($body['id'])) {
                    $createdResourceId = $body['id'];
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => $createdResourceId ? '/fhir/Condition/' . $createdResourceId : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                // PUT not yet implemented for Condition
                return [
                    'status' => '501',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'not-supported',
                                'diagnostics' => "PUT method not yet supported for Condition resource"
                            ]
                        ]
                    ]
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
                                'diagnostics' => "Method '$method' not supported for Condition resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Condition resource", [
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
     * Process CarePlan resource
     * @param string $method HTTP method (POST, PUT, etc.)
     * @param string|null $resourceId Resource ID for PUT operations
     * @param array $resourceArray Resource data as array
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     */
    private function processCarePlanResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        try {
            if ($method === 'POST') {
                $controller = $reusedController ?? new FhirCarePlanRestController();

                $result = $controller->post($resourceArray);
                // Trust the status code set by the controller
                $statusCode = http_response_code() ?: 201;

                if (is_object($result) && method_exists($result, 'jsonSerialize')) {
                    $body = $result->jsonSerialize();
                } elseif (is_array($result)) {
                    $body = $result;
                } else {
                    $body = null;
                }

                // Only override status code if there are explicit errors
                if (isset($body['validationErrors']) && !empty($body['validationErrors'])) {
                    $statusCode = 400;
                } elseif (isset($body['internalErrors']) && !empty($body['internalErrors'])) {
                    $statusCode = 500;
                }
                // Don't change statusCode to 404 just because body is empty - trust the controller's status code

                $createdResourceId = null;
                if (is_object($result) && method_exists($result, 'getId')) {
                    $idObj = $result->getId();
                    if (is_object($idObj) && method_exists($idObj, 'getValue')) {
                        $createdResourceId = $idObj->getValue();
                    }
                } elseif (is_array($body) && isset($body['id'])) {
                    $createdResourceId = $body['id'];
                }

                return [
                    'status' => (string)$statusCode,
                    'location' => $createdResourceId ? '/fhir/CarePlan/' . $createdResourceId : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                return [
                    'status' => '501',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'not-supported',
                                'diagnostics' => "PUT method not yet supported for CarePlan resource"
                            ]
                        ]
                    ]
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
                                'diagnostics' => "Method '$method' not supported for CarePlan resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process CarePlan resource", [
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
     * Process Procedure resource
     * @param string $method HTTP method (POST, PUT, etc.)
     * @param string|null $resourceId Resource ID for PUT operations
     * @param array $resourceArray Resource data as array
     * @param object|null $reusedController Optional controller to reuse (for batch processing)
     */
    private function processProcedureResource($method, $resourceId, $resourceArray, $reusedController = null)
    {
        $controller = $reusedController ?? new FhirProcedureRestController();
        
        try {
            if ($method === 'POST') {
                $statusCode = null;
                $result = $controller->post($resourceArray);
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
                $createdResourceId = null;
                if (is_object($result) && method_exists($result, 'getId')) {
                    $idObj = $result->getId();
                    if (is_object($idObj) && method_exists($idObj, 'getValue')) {
                        $createdResourceId = $idObj->getValue();
                    }
                } elseif (is_array($body) && isset($body['id'])) {
                    $createdResourceId = $body['id'];
                }
                
                return [
                    'status' => (string)$statusCode,
                    'location' => $createdResourceId ? '/fhir/Procedure/' . $createdResourceId : null,
                    'resource' => $body
                ];
            } elseif ($method === 'PUT' && $resourceId) {
                // PUT not yet implemented for Procedure
                return [
                    'status' => '501',
                    'outcome' => [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'not-supported',
                                'diagnostics' => "PUT method not yet supported for Procedure resource"
                            ]
                        ]
                    ]
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
                                'diagnostics' => "Method '$method' not supported for Procedure resource"
                            ]
                        ]
                    ]
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to process Procedure resource", [
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
     * Queue a FHIR Bundle for background processing
     * @param array $fhirJson The FHIR bundle resource (array)
     * @return array Response with 202 Accepted status and queue ID
     */
    private function queueBundle($fhirJson)
    {
        try {
            // Insert into queue
            $bundleJsonString = json_encode($fhirJson);
            if ($bundleJsonString === false) {
                $this->logger->error("Failed to encode bundle JSON", [
                    'json_error' => json_last_error_msg()
                ]);
                return RestControllerHelper::responseHandler(
                    ['error' => 'Failed to encode bundle JSON'],
                    null,
                    500
                );
            }

            $sql = "INSERT INTO `fhir_bundle_queue` 
                    (`bundle_json`, `status`, `datetime_queued`, `max_retries`) 
                    VALUES (?, 'pending', NOW(), 3)";
            
            $this->logger->debug("Attempting to insert bundle into queue", [
                'bundle_size' => strlen($bundleJsonString),
                'sql' => $sql
            ]);
            
            // Use sqlInsert() instead of sqlStatement() to get the last insert id
            // sqlInsert() may throw SqlQueryException, so we catch it
            try {
                $queueId = sqlInsert($sql, [$bundleJsonString]);
            } catch (\Exception $insertException) {
                $this->logger->error("sqlInsert() threw exception", [
                    'error' => $insertException->getMessage(),
                    'trace' => $insertException->getTraceAsString(),
                    'sql_error' => sqlGetLastError()
                ]);
                return RestControllerHelper::responseHandler(
                    [
                        'error' => 'Failed to queue bundle',
                        'message' => $insertException->getMessage()
                    ],
                    null,
                    500
                );
            }
            
            if ($queueId === false || $queueId === 0 || empty($queueId)) {
                $this->logger->error("Failed to insert bundle into queue - invalid queue ID", [
                    'queue_id' => $queueId,
                    'sql_error' => sqlGetLastError(),
                    'bundle_size' => strlen($bundleJsonString)
                ]);
                return RestControllerHelper::responseHandler(
                    [
                        'error' => 'Failed to queue bundle',
                        'message' => 'Insert returned invalid queue ID'
                    ],
                    null,
                    500
                );
            }
            
            $this->logger->info("FHIR Bundle queued for background processing", [
                'queue_id' => $queueId,
                'bundle_size' => strlen($bundleJsonString)
            ]);

            // Return 202 Accepted with queue ID
            return RestControllerHelper::responseHandler(
                [
                    'resourceType' => 'OperationOutcome',
                    'issue' => [
                        [
                            'severity' => 'information',
                            'code' => 'informational',
                            'details' => [
                                'text' => 'Bundle queued for processing'
                            ]
                        ]
                    ],
                    'queue_id' => $queueId,
                    'status_url' => '/fhir/Bundle/queue/' . $queueId
                ],
                null,
                202
            );
        } catch (\Exception $e) {
            $this->logger->error("Failed to queue bundle", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Failed to queue bundle',
                    'message' => $e->getMessage()
                ],
                null,
                500
            );
        }
    }

    /**
     * Get the status of a queued bundle
     * @param int $queueId The queue ID
     * @return array Response with queue status
     */
    public function getQueueStatus($queueId)
    {
        try {
            $sql = "SELECT `id`, `status`, `datetime_queued`, `datetime_processed`, 
                           `error`, `error_message`, `retry_count`, `result_json`
                    FROM `fhir_bundle_queue` 
                    WHERE `id` = ?";
            
            $result = sqlQuery($sql, [$queueId]);
            
            if (!$result) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'not-found',
                                'details' => [
                                    'text' => 'Queue entry not found'
                                ]
                            ]
                        ]
                    ],
                    null,
                    404
                );
            }

            $response = [
                'resourceType' => 'Bundle',
                'type' => 'searchset',
                'total' => 1,
                'entry' => [
                    [
                        'resource' => [
                            'resourceType' => 'BundleQueueStatus',
                            'id' => (string)$result['id'],
                            'status' => $result['status'],
                            'datetimeQueued' => $result['datetime_queued'],
                            'datetimeProcessed' => $result['datetime_processed'],
                            'retryCount' => (int)$result['retry_count']
                        ]
                    ]
                ]
            ];

            if ($result['error']) {
                $response['entry'][0]['resource']['error'] = true;
                $response['entry'][0]['resource']['errorMessage'] = $result['error_message'];
            }

            if ($result['status'] === 'completed' && !empty($result['result_json'])) {
                $resultBundle = json_decode($result['result_json'], true);
                if ($resultBundle) {
                    $response['entry'][0]['resource']['resultBundle'] = $resultBundle;
                }
            }

            return RestControllerHelper::responseHandler($response, null, 200);
        } catch (\Exception $e) {
            $this->logger->error("Failed to get queue status", [
                'queue_id' => $queueId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Failed to get queue status',
                    'message' => $e->getMessage()
                ],
                null,
                500
            );
        }
    }

    /**
     * Group entries by resource type and method for batch processing
     * This allows us to reuse controllers for entries of the same type
     * @param array $entriesToProcess Entries to group
     * @return array Grouped entries
     */
    private function groupEntriesByResourceType($entriesToProcess)
    {
        $grouped = [];
        
        foreach ($entriesToProcess as $index => $entry) {
            // Extract resource type and method from entry
            $resource = $entry['resource'] ?? null;
            $request = $entry['request'] ?? null;
            
            $resourceType = null;
            if (is_array($resource)) {
                $resourceType = $resource['resourceType'] ?? null;
            } elseif (is_object($resource) && method_exists($resource, 'getResourceType')) {
                $resourceType = $resource->getResourceType();
            }
            
            $method = $request['method'] ?? 'POST';
            
            // Create group key: resourceType_method
            $groupKey = strtolower(($resourceType ?? 'unknown') . '_' . $method);
            
            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = [
                    'resourceType' => $resourceType,
                    'method' => $method,
                    'entries' => []
                ];
            }
            
            $grouped[$groupKey]['entries'][] = [
                'index' => $index,
                'entry' => $entry
            ];
        }
        
        return $grouped;
    }

    /**
     * Get or create a controller for a specific resource type
     * This allows controller reuse across multiple entries of the same type
     * @param string $resourceType Resource type (Patient, Observation, etc.)
     * @return object|null Controller instance or null if not supported
     */
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
            case 'organization':
                return new FhirOrganizationRestController();
            case 'practitioner':
                return new FhirPractitionerRestController();
            case 'encounter':
                return new FhirEncounterRestController();
            case 'allergyintolerance':
                return new FhirAllergyIntoleranceRestController();
            case 'condition':
                return new FhirConditionRestController();
            case 'careplan':
                return new FhirCarePlanRestController();
            case 'procedure':
                return new FhirProcedureRestController();
            default:
                return null;
        }
    }

    /**
     * Create an HttpRestRequest object for controllers that require it
     * @return HttpRestRequest
     */
    private function createHttpRestRequest()
    {
        global $GLOBALS;
        $server = $_SERVER ?? [];
        $request = new HttpRestRequest($GLOBALS, $server);
        $request->setApiType("fhir");
        return $request;
    }
}

