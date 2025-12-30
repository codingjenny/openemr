<?php

/**
 * FhirImmunizationRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirImmunizationService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRImmunization;

require_once(__DIR__ . '/../../../_rest_config.php');

/**
 * Supports REST interactions with the FHIR immunization resource
 */
class FhirImmunizationRestController
{
    private FhirImmunizationService $fhirImmunizationService;
    private FhirResourcesService $fhirService;
    private FhirValidationService $fhirValidationService;

    public function __construct()
    {
        $this->fhirService = new FhirResourcesService();
        $this->fhirImmunizationService = new FhirImmunizationService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Creates a new FHIR immunization resource
     * @param $fhirJson The FHIR immunization resource
     * @returns 201 if the resource is created, 400 if the resource is invalid, 501 if not implemented
     */
    public function post($fhirJson)
    {
        try {
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
            }

            // Parse JSON to FHIRImmunization object
            // Handle both array and string input
            $jsonString = is_array($fhirJson) ? json_encode($fhirJson) : $fhirJson;
            if (is_string($fhirJson) && empty(trim($fhirJson))) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Empty Immunization resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }
            
            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);
            
            if (!($fhirResource instanceof FHIRImmunization)) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Resource must be of type Immunization'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            $processingResult = $this->fhirImmunizationService->insert($fhirResource);
            $result = RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
            return $result;
        } catch (\Throwable $e) {
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Internal server error processing Immunization',
                    'message' => $e->getMessage(),
                    'type' => get_class($e)
                ],
                null,
                500
            );
        }
    }

    /**
     * Queries for a single FHIR immunization resource by FHIR id
     * @param $fhirId The FHIR immunization resource id (uuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $processingResult = $this->fhirImmunizationService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR immunization resources using various search parameters.
     * Search parameters include:
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->fhirImmunizationService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('Immunization', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }
}
