<?php

/**
 * FHIR Resource Controller example for handling and responding to QuestionnaireResponse
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 *
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2022 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\FHIR;

use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Http\HttpRestRouteHandler;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle;
use OpenEMR\FHIR\R4\FHIRResource\FHIRBundle\FHIRBundleEntry;
use OpenEMR\FHIR\DomainModels\OpenEMRFhirQuestionnaireResponse;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRQuestionnaireResponse;
use OpenEMR\Services\FHIR\FhirQuestionnaireResponseService;
use OpenEMR\Services\FHIR\FhirValidationService;
use OpenEMR\RestControllers\RestControllerHelper;
use OpenEMR\Services\FHIR\FhirResourcesService;
use OpenEMR\FHIR\R4\PHPFHIRResponseParser;
use Psr\Http\Message\ResponseInterface;

class FhirQuestionnaireResponseRestController
{
    /**
     * @var FhirQuestionnaireResponseService
     */
    private FhirQuestionnaireResponseService $resourceService;

    /**
     * @var FhirResourcesService
     */
    private FhirResourcesService $fhirService;

    /**
     * @var FhirValidationService
     */
    private $fhirValidate;

    /**
     * @param ?FhirQuestionnaireResponseService $resourceService
     */
    public function __construct(?FhirQuestionnaireResponseService $resourceService = null)
    {
        $this->resourceService = $resourceService ?? new FhirQuestionnaireResponseService();
        $this->fhirService = new FhirResourcesService();
        $this->fhirValidate = new FhirValidationService();
    }

    /**
     * @return FhirQuestionnaireResponseService
     */
    public function getFhirQuestionnaireResponseService(): FhirQuestionnaireResponseService
    {
        return $this->resourceService;
    }


    /**
     * Handles the response to the API request GET /fhir/Questionnaire and returns the FHIRBundle resource
     * that was found for the given request.  Any query search parameters are processed by this method.  If the method
     * is run in the patient context (as a logged in patient) it restricts the search to just that patient.
     *
     * @param  HttpRestRequest $request
     * @return ResponseInterface
     */
    public function list(HttpRestRequest $request): ResponseInterface
    {
        if ($request->isPatientRequest()) {
            // only allow access to data of binded patient
            $result = $this->getAll($request->getQueryParams(), $request->getPatientUUIDString());
        } else {
            /**
             * If you need to check the API against any kind of ACL the RestConfig object will do an authorization check
             * and handle the API result back to the HTTP client
             */
            // RestConfig::authorization_check("patients", "med");
            $result = $this->getAll($request->getQueryParams());
        }
        return RestControllerHelper::returnSingleObjectResponse($result);
    }

    /**
     * Retrieves a single api resource.  Handles the response to the API request GET /fhir/Questionnaire/:fhirId
     * The $fhirId is populated from the API request by the rest route dispatcher.
     *
     * @see    HttpRestRouteHandler::dispatch to see how this parsing is done.
     * @param  string          $id      The unique id of the resource to be returned.
     * @param  HttpRestRequest $request
     * @return ResponseInterface
     */
    public function one(HttpRestRequest $request, string $id): ResponseInterface
    {
        $processingResult = $this->resourceService->getOne($id, $request->getPatientUUIDString());
        return RestControllerHelper::getResponseForProcessingResult($processingResult);
    }

    /**
     * Queries for FHIR encounter resources using various search parameters.
     * Search parameters include:
     * - _id (euuid)
     * - patient (puuid)
     * - date {gt|lt|ge|le}
     *
     * @param  $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return false|FHIRBundle FHIR bundle with query results, if found
     */
    private function getAll($searchParams, $puuidBind = null): FHIRBundle|false
    {
        $processingResult = $this->resourceService->getAll($searchParams, $puuidBind);
        $bundleEntries = array();
        foreach ($processingResult->getData() as $searchResult) {
            $bundleEntry = [
                'fullUrl' =>  $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? '') . '/' . $searchResult->getId(),
                'resource' => $searchResult
            ];
            $fhirBundleEntry = new FHIRBundleEntry($bundleEntry);
            $bundleEntries[] = $fhirBundleEntry;
        }
        return $this->fhirService->createBundle('Questionnaire', $bundleEntries, false);
    }

    /**
     * Creates a new FHIR questionnaire response resource
     * @param $fhirJson The FHIR questionnaire response resource
     * @returns 201 if the resource is created, 400 if the resource is invalid
     */
    public function post($fhirJson)
    {
        try {
            $fhirValidate = $this->fhirValidate->validate($fhirJson);
            if (!empty($fhirValidate)) {
                return RestControllerHelper::responseHandler($fhirValidate, null, 400);
            }

            // Parse JSON to FHIRQuestionnaireResponse object
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
                                'diagnostics' => 'Empty QuestionnaireResponse resource provided'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }
            
            $parser = new PHPFHIRResponseParser(false);
            $fhirResource = $parser->parse($jsonString);
            
            // Accept both FHIRQuestionnaireResponse and OpenEMRFhirQuestionnaireResponse
            if (!($fhirResource instanceof FHIRQuestionnaireResponse)) {
                return RestControllerHelper::responseHandler(
                    [
                        'resourceType' => 'OperationOutcome',
                        'issue' => [
                            [
                                'severity' => 'error',
                                'code' => 'invalid',
                                'diagnostics' => 'Invalid QuestionnaireResponse resource'
                            ]
                        ]
                    ],
                    null,
                    400
                );
            }
            
            // Convert to OpenEMRFhirQuestionnaireResponse if needed
            if (!($fhirResource instanceof OpenEMRFhirQuestionnaireResponse)) {
                // Use json_decode/encode to convert objects to arrays
                $jsonString = json_encode($fhirResource->jsonSerialize());
                $dataArray = json_decode($jsonString, true);
                $fhirResource = new OpenEMRFhirQuestionnaireResponse($dataArray);
            }

            $processingResult = $this->resourceService->insert($fhirResource);
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
