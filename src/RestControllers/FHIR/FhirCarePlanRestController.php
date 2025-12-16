<?php

/**
 * FhirCarePlanRestController.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirCarePlanService;
use OpenEMR\Services\FHIR\FhirCareTeamService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCarePlan;
use OpenEMR\Validators\ProcessingResult;

class FhirCarePlanRestController
{
    /**
     * @var FhirCarePlanService
     */
    private $fhirResourceService;

    private $fhirService;

    private $fhirValidate;

    public function __construct()
    {
        $this->fhirService = new FhirResourcesService();
        $this->fhirResourceService = new FhirCarePlanService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Creates a new FHIR CarePlan resource
     * @param $fhirJson The FHIR CarePlan resource
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            // DIAGNOSTIC: Log that this method was called
            error_log("FhirCarePlanRestController::post() called with data: " . json_encode($fhirJson));
            
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
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
                                'diagnostics' => 'Empty CarePlan resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);

            if (!($fhirResource instanceof FHIRCarePlan)) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Resource must be of type CarePlan'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            $processingResult = $this->fhirResourceService->insert($fhirResource);
            
            error_log("FhirCarePlanRestController::post() - after insert: hasData=" . ($processingResult->hasData() ? 'true' : 'false') . ", hasErrors=" . ($processingResult->hasErrors() ? 'true' : 'false'));
            
            // If there are validation or internal errors, use standard FHIR handler (may return 400/500)
            if ($processingResult->hasErrors()) {
                error_log("FhirCarePlanRestController::post() - has errors, returning via handleFhirProcessingResult");
                error_log("FhirCarePlanRestController::post() - validation errors: " . json_encode($processingResult->getValidationMessages()));
                error_log("FhirCarePlanRestController::post() - internal errors: " . json_encode($processingResult->getInternalErrors()));
                return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
            }

            // On success, prefer to return the saved FHIR CarePlan from the service if available
            if ($processingResult->hasData()) {
                error_log("FhirCarePlanRestController::post() - SUCCESS! Returning 201 with FHIR resource");
                http_response_code(201);
                $data = $processingResult->getData();
                // For insert, we expect a single CarePlan resource
                return $data[0];
            }

            // Fallback: if the service reports no errors and no data (should not normally happen),
            // add diagnostic and treat as internal error
            error_log("FhirCarePlanRestController::post() - FALLBACK: no data and no errors!");
            $processingResult->addInternalError("DEBUG: CarePlan insert completed without errors but returned no data");
            return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
        } catch (\Throwable $e) {
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Internal server error processing CarePlan',
                    'message' => $e->getMessage(),
                    'type' => get_class($e)
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
        $processingResult = $this->fhirResourceService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR location resources using various search parameters.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->fhirResourceService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('CarePlan', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }
}
