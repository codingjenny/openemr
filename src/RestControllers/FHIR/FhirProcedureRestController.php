<?php

/**
 * FhirProcedureRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirProcedureService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRProcedure;

class FhirProcedureRestController
{
    private $fhirProcedureService;
    private $fhirService;
    private $fhirValidate;

    public function __construct(?HttpRestRequest $request = null)
    {
        $this->fhirProcedureService = new FhirProcedureService();
        $this->fhirService = new FhirResourcesService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Creates a new FHIR procedure resource
     * @param $fhirJson The FHIR procedure resource
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
            }

            // Parse JSON to FHIRProcedure object
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
                                'diagnostics' => 'Empty Procedure resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }
            
            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);
            
            if (!($fhirResource instanceof FHIRProcedure)) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Resource must be of type Procedure'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }

            $processingResult = $this->fhirProcedureService->insert($fhirResource);
            $result = RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
            return $result;
        } catch (\Throwable $e) {
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Internal server error processing Procedure',
                    'message' => $e->getMessage(),
                    'type' => get_class($e)
                ],
                null,
                500
            );
        }
    }

    /**
     * Queries for a single FHIR procedure resource by FHIR id
     * @param $fhirId The FHIR procedure resource id (uuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $processingResult = $this->fhirProcedureService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR procedure resources using various search parameters.
     * Search parameters include:
     * - patient (puuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->fhirProcedureService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('Procedure', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }
}
