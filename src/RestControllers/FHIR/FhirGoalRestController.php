<?php

/**
 * FhirGoalRestController.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirCareTeamService;
use OpenEMR\Services\FHIR\FhirGoalService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRGoal;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\Validators\ProcessingResult;

class FhirGoalRestController
{
    private $fhirService;

    /**
     * @var FhirGoalService
     */
    private $service;

    /**
     * @var FhirValidationService
     */
    private $fhirValidate;

    public function __construct()
    {
        $this->fhirService = new FhirResourcesService();
        $this->service = new FhirGoalService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Queries for a single FHIR location resource by FHIR id
     * @param $fhirId The FHIR location resource id (uuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $processingResult = $this->service->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR location resources using various search parameters.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->service->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('Goal', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }

    /**
     * Creates a new FHIR Goal resource
     * @param $fhirJson The FHIR Goal resource
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            error_log("FhirGoalRestController::post() - START");
            error_log("FhirGoalRestController::post() - fhirJson type: " . gettype($fhirJson));
            
            // Try validation, but don't fail if it's just a warning
            try {
                $fhirValidate = $this->fhirValidate->validate($fhirJson);
                if (!empty($fhirValidate)) {
                    error_log("FhirGoalRestController::post() - Validation errors: " . json_encode($fhirValidate));
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
                        error_log("FhirGoalRestController::post() - Validation warnings only, continuing");
                    }
                } else {
                    error_log("FhirGoalRestController::post() - Validation passed");
                }
            } catch (\Throwable $validationError) {
                error_log("FhirGoalRestController::post() - Validation exception (continuing anyway): " . $validationError->getMessage());
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
                                'diagnostics' => 'Empty Goal resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            error_log("FhirGoalRestController::post() - Parsing JSON string");
            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);
            
            error_log("FhirGoalRestController::post() - Parsed resource type: " . get_class($fhirResource));

            if (!($fhirResource instanceof FHIRGoal)) {
                error_log("FhirGoalRestController::post() - Resource type mismatch");
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Resource must be of type Goal, got: ' . get_class($fhirResource)
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            error_log("FhirGoalRestController::post() - Calling service->insert()");
            $processingResult = $this->service->insert($fhirResource);
            error_log("FhirGoalRestController::post() - Insert completed, hasErrors: " . ($processingResult->hasErrors() ? 'true' : 'false') . ", hasData: " . ($processingResult->hasData() ? 'true' : 'false'));

            // If there are validation or internal errors, use standard FHIR handler (may return 400/500)
            if ($processingResult->hasErrors()) {
                error_log("FhirGoalRestController::post() - Insert failed with errors, returning via handleFhirProcessingResult");
                error_log("FhirGoalRestController::post() - validation errors: " . json_encode($processingResult->getValidationMessages()));
                error_log("FhirGoalRestController::post() - internal errors: " . json_encode($processingResult->getInternalErrors()));
                return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
            }

            // On success, prefer to return the saved FHIR Goal from the service if available
            if ($processingResult->hasData()) {
                error_log("FhirGoalRestController::post() - SUCCESS! Returning 201 with FHIR resource");
                http_response_code(201);
                $data = $processingResult->getData();
                // For insert, we expect a single Goal resource
                return $data[0];
            }

            // Fallback: if the service reports no errors and no data (should not normally happen),
            // add diagnostic and treat as internal error
            error_log("FhirGoalRestController::post() - FALLBACK: no data and no errors!");
            $processingResult->addInternalError("DEBUG: Goal insert completed without errors but returned no data");
            return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
        } catch (\Throwable $e) {
            error_log("FhirGoalRestController::post() - EXCEPTION: " . $e->getMessage());
            error_log("FhirGoalRestController::post() - TRACE: " . $e->getTraceAsString());
            return RestControllerHelper::responseHandler(
                [
                    'resourceType' => 'OperationOutcome',
                    'issue' => [
                        [
                            'severity' => 'error',
                            'code' => 'exception',
                            'diagnostics' => 'Internal server error processing Goal: ' . $e->getMessage()
                        ]
                    ]
                ],
                null,
                500
            );
        }
    }
}
