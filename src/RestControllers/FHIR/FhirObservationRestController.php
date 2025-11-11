<?php

/**
 * FhirObservationRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Services\FHIR\FhirObservationService;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\Services\FHIR\Serialization\FhirObservationSerializer;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\Common\Logging\SystemLogger;

class FhirObservationRestController
{
    private $fhirObservationService;
    private $fhirService;
    private $fhirValidate;

    public function __construct()
    {
        $this->fhirObservationService = new FhirObservationService();
        $this->fhirService = new FhirResourcesService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * Creates a new FHIR observation resource
     * @param $fhirJson The FHIR observation resource
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
            }

            $object = FhirObservationSerializer::deserialize($fhirJson);

            $processingResult = $this->fhirObservationService->insert($object);
            return RestControllerHelper::handleFhirProcessingResult($processingResult, 201);
        } catch (\Throwable $e) {
            $logger = new \OpenEMR\Common\Logging\SystemLogger();
            $logger->error("FhirObservationRestController::post() fatal error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'fhirJson' => is_array($fhirJson) ? json_encode($fhirJson) : $fhirJson
            ]);
            return RestControllerHelper::responseHandler(
                [
                    'error' => 'Internal server error processing Observation',
                    'message' => $e->getMessage(),
                    'type' => get_class($e)
                ],
                null,
                500
            );
        }
    }

    /**
     * Updates an existing FHIR observation resource
     * @param $fhirId The FHIR observation resource id (uuid)
     * @param $fhirJson The updated FHIR observation resource (complete resource)
     * @returns 200 if the resource is updated, 400 if the resource is invalid
     */
    public function put($fhirId, $fhirJson)
    {
        $fhirValidate = $this->fhirValidate->validate($fhirJson);
        if (!empty($fhirValidate)) {
            return RestControllerHelper::responseHandler($fhirValidate, null, 400);
        }
        $object = FhirObservationSerializer::deserialize($fhirJson);

        $processingResult = $this->fhirObservationService->update($fhirId, $object);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for a single FHIR observation resource by FHIR id
     * @param $fhirId The FHIR observation resource id (uuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @returns 200 if the operation completes successfully
     */
    public function getOne($fhirId, $puuidBind = null)
    {
        $processingResult = $this->fhirObservationService->getOne($fhirId, $puuidBind);
        return RestControllerHelper::handleFhirProcessingResult($processingResult, 200);
    }

    /**
     * Queries for FHIR observation resources using various search parameters.
     * Search parameters include:
     * - patient (puuid)
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return FHIR bundle with query results, if found
     */
    public function getAll($searchParams, $puuidBind = null)
    {
        $processingResult = $this->fhirObservationService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            array_push($bundleEntries, $fhirBundleEntry);
        }
        $bundleSearchResult = $this->fhirService->createBundle('Observation', $bundleEntries, false);
        $searchResponseBody = RestControllerHelper::responseHandler($bundleSearchResult, null, 200);
        return $searchResponseBody;
    }
}
