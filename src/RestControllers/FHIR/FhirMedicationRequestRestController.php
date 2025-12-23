<?php

/**
 * FhirMedicationRequestRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirMedicationRequestService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRMedicationRequest;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\Common\Logging\SystemLogger;

class FhirMedicationRequestRestController
{
    private $fhirService;
    private $fhirMedicationRequestService;
    private $fhirValidate;

    public function __construct()
    {
        $this->fhirService = new FhirResourcesService();
        $this->fhirMedicationRequestService = new FhirMedicationRequestService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Queries for a single FHIR medication resource by FHIR id
     * @param $fhirId The FHIR medication resource id (uuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $processingResult = $this->fhirMedicationRequestService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR medication resources using various search parameters.
     * Search parameters include:
     * - patient (puuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->fhirMedicationRequestService->getAll($searchParams, $puuidBind);
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
     * Creates a new FHIR MedicationRequest resource
     * @param $fhirJson The FHIR MedicationRequest resource in JSON format
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            // Validate the FHIR resource
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
            }

            // Parse JSON to FHIRMedicationRequest object
            $jsonString = is_array($fhirJson) ? json_encode($fhirJson) : $fhirJson;
            if (is_string($fhirJson) && empty(trim($fhirJson))) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Empty MedicationRequest resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);

            if (!($fhirResource instanceof FHIRMedicationRequest)) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Invalid MedicationRequest resource'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            // Insert the resource
            $processingResult = $this->fhirMedicationRequestService->insert($fhirResource);
            return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);

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
