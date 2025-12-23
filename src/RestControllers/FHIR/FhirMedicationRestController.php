<?php

/**
 * FhirMedicationRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirMedicationService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRMedication;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;

class FhirMedicationRestController
{
    private $fhirMedicationService;
    private $fhirService;
    private $fhirValidate;

    public function __construct()
    {
        $this->fhirMedicationService = new FhirMedicationService();
        $this->fhirService = new FhirResourcesService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Queries for a single FHIR medication resource by FHIR id
     * @param $fhirId The FHIR medication resource id (uuid)
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId)
    {
        $processingResult = $this->fhirMedicationService->getOne($fhirId);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR medication resources using various search parameters.
     * Search parameters include:
     * - patient (puuid)
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams)
    {
        $processingResult = $this->fhirMedicationService->getAll($searchParams);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('Medication', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }

    /**
     * Creates a new FHIR Medication resource
     * @param $fhirJson The FHIR Medication resource in JSON format
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        error_log("FhirMedicationRestController::post - START");
        try {
            // Validate the FHIR resource
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                error_log("FhirMedicationRestController::post - VALIDATION FAILED");
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
            }

            // Parse JSON to FHIRMedication object
            $jsonString = is_array($fhirJson) ? json_encode($fhirJson) : $fhirJson;
            error_log("FhirMedicationRestController::post - JSON: " . substr($jsonString, 0, 200));
            if (is_string($fhirJson) && empty(trim($fhirJson))) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Empty Medication resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);

            if (!($fhirResource instanceof FHIRMedication)) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Invalid Medication resource'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            // Insert the resource
            error_log("FhirMedicationRestController::post - Calling fhirMedicationService->insert()");
            $processingResult = $this->fhirMedicationService->insert($fhirResource);
            error_log("FhirMedicationRestController::post - Insert result hasData: " . ($processingResult->hasData() ? 'true' : 'false'));
            error_log("FhirMedicationRestController::post - Insert result data count: " . count($processingResult->getData()));
            $response = RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
            error_log("FhirMedicationRestController::post - Response type: " . gettype($response));
            return $response;

        } catch (\Exception $e) {
            return RestControllerHelper::responseHandler(
                [
                    'resourceType' => 'OperationOutcome',
                    'issue' => [
                        [
                            'severity' => 'error',
                            'code' => 'exception',
                            'diagnostics' => $e->getMessage()
                        ]
                    ]
                ],
                null,
                500
            );
        }
    }
}
