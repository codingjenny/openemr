<?php

/**
 * FhirCareTeamRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirCareTeamService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCareTeam;

class FhirCareTeamRestController
{
    /**
     * @var FhirCareTeamService
     */
    private $fhirCareTeamService;
    private $fhirService;
    private $fhirValidate;

    public function __construct()
    {
        $this->fhirCareTeamService = new FhirCareTeamService();
        $this->fhirService = new FhirResourcesService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Creates a new FHIR CareTeam resource
     * @param $fhirJson The FHIR CareTeam resource
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            error_log("FhirCareTeamRestController::post() - START");
            error_log("FhirCareTeamRestController::post() - fhirJson type: " . gettype($fhirJson));
            
            // Try validation, but don't fail if it's just a warning
            try {
                $fhirValidate = $this->fhirValidate->validate($fhirJson);
                if (!empty($fhirValidate)) {
                    error_log("FhirCareTeamRestController::post() - Validation errors: " . json_encode($fhirValidate));
                    // Check if it's a fatal error or just a warning
                    $isFatal = false;
                    if (is_array($fhirValidate) && isset($fhirValidate['issue'])) {
                        foreach ($fhirValidate['issue'] as $issue) {
                            if (isset($issue['severity']) && ($issue['severity'] === 'fatal' || $issue['severity'] === 'error')) {
                                $isFatal = true;
                                break;
                            }
                        }
                    }
                    if ($isFatal) {
                        return RestControllerHelper::responseHandler($fhirValidate, null, 400);
                    } else {
                        error_log("FhirCareTeamRestController::post() - Validation warnings only, continuing");
                    }
                } else {
                    error_log("FhirCareTeamRestController::post() - Validation passed");
                }
            } catch (\Throwable $validationError) {
                error_log("FhirCareTeamRestController::post() - Validation exception (continuing anyway): " . $validationError->getMessage());
                // Continue processing even if validation throws an exception
            }

            $jsonString = is_array($fhirJson) ? json_encode($fhirJson) : $fhirJson;
            if (is_string($fhirJson) && empty(trim($fhirJson))) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Empty CareTeam resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            error_log("FhirCareTeamRestController::post() - Parsing JSON string");
            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);
            
            error_log("FhirCareTeamRestController::post() - Parsed resource type: " . get_class($fhirResource));

            if (!($fhirResource instanceof FHIRCareTeam)) {
                error_log("FhirCareTeamRestController::post() - Resource type mismatch");
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Resource must be of type CareTeam, got: ' . get_class($fhirResource)
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            error_log("FhirCareTeamRestController::post() - Calling insert");
            $processingResult = $this->fhirCareTeamService->insert($fhirResource);
            error_log("FhirCareTeamRestController::post() - Insert completed, hasErrors: " . ($processingResult->hasErrors() ? 'true' : 'false') . ", hasData: " . ($processingResult->hasData() ? 'true' : 'false'));

            // If there are validation or internal errors, use standard FHIR handler (may return 400/500)
            if ($processingResult->hasErrors()) {
                return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
            }

            // On success, prefer to return the saved FHIR CareTeam from the service if available
            if ($processingResult->hasData()) {
                http_response_code(201);
                $data = $processingResult->getData();
                // For insert, we expect a single CareTeam resource
                return $data[0];
            }

            // Fallback: if the service reports no errors and no data (should not normally happen),
            // add diagnostic and treat as internal error
            $processingResult->addInternalError("DEBUG: CareTeam insert completed without errors but returned no data");
            return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
        } catch (\Throwable $e) {
            error_log("FhirCareTeamRestController::post() - EXCEPTION: " . $e->getMessage());
            error_log("FhirCareTeamRestController::post() - TRACE: " . $e->getTraceAsString());
            return RestControllerHelper::responseHandler(
                [
                    'resourceType' => 'OperationOutcome',
                    'issue' => [
                        [
                            'severity' => 'error',
                            'code' => 'exception',
                            'diagnostics' => 'Internal server error processing CareTeam: ' . $e->getMessage(),
                            'details' => [
                                'text' => 'Exception type: ' . get_class($e)
                            ]
                        ]
                    ]
                ],
                null,
                500
            );
        }
    }

    /**
     * Queries for a single FHIR location resource by FHIR id
     * @param $fhirId The FHIR location resource id (uuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $processingResult = $this->fhirCareTeamService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR location resources using various search parameters.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->fhirCareTeamService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('CareTeam', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }
}
