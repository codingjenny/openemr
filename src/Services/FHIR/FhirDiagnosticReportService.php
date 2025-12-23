<?php

/**
 * FhirDiagnosticReportService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRDiagnosticReport;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Services\FHIR\DiagnosticReport\FhirDiagnosticReportClinicalNotesService;
use OpenEMR\Services\FHIR\DiagnosticReport\FhirDiagnosticReportLaboratoryService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\MappedServiceCodeTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldException;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Validators\ProcessingResult;

class FhirDiagnosticReportService extends FhirServiceBase implements IPatientCompartmentResourceService, IResourceUSCIGProfileService, IFhirExportableResourceService
{
    use PatientSearchTrait;
    use MappedServiceCodeTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    public function __construct($fhirApiURL = null)
    {
        parent::__construct($fhirApiURL);
        $this->addMappedService(new FhirDiagnosticReportClinicalNotesService($fhirApiURL));
        $this->addMappedService(new FhirDiagnosticReportLaboratoryService($fhirApiURL));
    }

    /**
     * Returns an array mapping FHIR Resource search parameters to OpenEMR search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'code' => new FhirSearchParameterDefinition('code', SearchFieldType::TOKEN, ['code']),
            'category' => new FhirSearchParameterDefinition('category', SearchFieldType::TOKEN, ['category']),
            // shouldn't be a problem if date and _lastUpdated are provided as it will just be ignored with duplicate WHERE clause conditions
            // TODO: @adunsulag test this assumption to make sure it is correct
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['date']);
    }

    /**
     * Retrieves all of the fhir observation resources mapped to the underlying openemr data elements.
     * @param $fhirSearchParameters The FHIR resource search parameters
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return processing result
     */
    public function getAll($fhirSearchParameters, $puuidBind = null): ProcessingResult
    {
        $fhirSearchResult = new ProcessingResult();
        try {
            if (isset($puuidBind)) {
                $field = $this->getPatientContextSearchField();
                $fhirSearchParameters[$field->getName()] = $puuidBind;
            }

            if (isset($fhirSearchParameters['category'])) {
                /**
                 * @var TokenSearchField
                 */
                $category = $fhirSearchParameters['category'];
                $categorySearchField = new TokenSearchField('category', $category);
                $service = $this->getServiceForCategory(
                    $categorySearchField,
                    'LAB'
                );
                $fhirSearchResult = $service->getAll($fhirSearchParameters, $puuidBind);
            } elseif (isset($fhirSearchParameters['code'])) {
                $service = $this->getServiceForCode(
                    new TokenSearchField('code', $fhirSearchParameters['code']),
                    ''
                );
                // if we have a service let's search on that
                if (isset($service)) {
                    $fhirSearchResult = $service->getAll($fhirSearchParameters, $puuidBind);
                } else {
                    $fhirSearchResult = $this->searchAllServices($fhirSearchParameters, $puuidBind);
                }
            } else {
                $fhirSearchResult = $this->searchAllServices($fhirSearchParameters, $puuidBind);
            }
        } catch (SearchFieldException $exception) {
            (new SystemLogger())->error("FhirServiceBase->getAll() exception thrown", ['message' => $exception->getMessage(),
                'field' => $exception->getField()]);
            // put our exception information here
            $fhirSearchResult->setValidationMessages([$exception->getField() => $exception->getMessage()]);
        }
        return $fhirSearchResult;
    }

    public function getProfileURIs(): array
    {
        return [
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-diagnosticreport-note'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/us-core-diagnosticreport-lab'
        ];
    }

    /**
     * Parses an OpenEMR data record, returning the equivalent FHIR Resource
     * This method delegates to the appropriate sub-service
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        // Try to determine which service to use based on the data record
        if (!empty($dataRecord['category_code']) || !empty($dataRecord['code'])) {
            $code = $dataRecord['code'] ?? '';
            foreach ($this->getMappedServices() as $service) {
                if ($service->supportsCode($code)) {
                    return $service->parseOpenEMRRecord($dataRecord, $encode);
                }
            }
        }
        
        // Default to first service (clinical notes)
        $services = $this->getMappedServices();
        if (!empty($services)) {
            return $services[0]->parseOpenEMRRecord($dataRecord, $encode);
        }
        
        return null;
    }

    /**
     * Parses a FHIR Resource, returning the equivalent OpenEMR record.
     * This method delegates to the appropriate sub-service
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        if (!($fhirResource instanceof FHIRDiagnosticReport)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRDiagnosticReport");
        }

        // Try code-based routing
        $codeableConcept = $fhirResource->getCode();
        if (!empty($codeableConcept) && is_object($codeableConcept) && method_exists($codeableConcept, 'getCoding')) {
            $codings = $codeableConcept->getCoding();
            if (!empty($codings) && is_array($codings)) {
                foreach ($codings as $coding) {
                    if (is_object($coding) && method_exists($coding, 'getCode')) {
                        $codeObj = $coding->getCode();
                        $code = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)$codeObj;
                        
                        foreach ($this->getMappedServices() as $service) {
                            if ($service->supportsCode($code)) {
                                return $service->parseFhirResource($fhirResource);
                            }
                        }
                    }
                }
            }
        }

        // Default to first service
        $services = $this->getMappedServices();
        if (!empty($services)) {
            return $services[0]->parseFhirResource($fhirResource);
        }

        return [];
    }

    /**
     * Inserts an OpenEMR record into the system.
     * This method should not be called directly - use insert() instead
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();
        $processingResult->addInternalError("insertOpenEMRRecord should not be called directly on FhirDiagnosticReportService. Use insert() instead.");
        return $processingResult;
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
    {
        $processingResult = new ProcessingResult();
        $processingResult->setInternalErrors(['searchForOpenEMRRecords not implemented - use getAll() instead']);
        return $processingResult;
    }

    /**
     * Creates the Provenance resource for the equivalent FHIR Resource
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        // Delegate to the appropriate sub-service
        if ($dataRecord instanceof FHIRDiagnosticReport) {
            foreach ($this->getMappedServices() as $service) {
                $result = $service->createProvenanceResource($dataRecord, $encode);
                if ($result) {
                    return $result;
                }
            }
        }
        return null;
    }

    /**
     * Updates an OpenEMR record.
     */
    public function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        $processingResult = new ProcessingResult();
        $processingResult->addInternalError("Update not yet implemented for DiagnosticReport");
        return $processingResult;
    }

    /**
     * Inserts a FHIR DiagnosticReport resource into the system.
     * Routes to the appropriate sub-service based on the category or code.
     * 
     * @param FHIRDomainResource $fhirResource The FHIR DiagnosticReport resource
     * @return ProcessingResult The OpenEMR Service Result
     */
    public function insert(FHIRDomainResource $fhirResource): ProcessingResult
    {
        if (!($fhirResource instanceof FHIRDiagnosticReport)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRDiagnosticReport");
        }

        $processingResult = new ProcessingResult();

        try {
            // Try to determine which service to use based on category
            $categories = $fhirResource->getCategory();
            if (!empty($categories) && is_array($categories)) {
                foreach ($categories as $category) {
                    if (is_object($category) && method_exists($category, 'getCoding')) {
                        $codings = $category->getCoding();
                        if (!empty($codings) && is_array($codings)) {
                            foreach ($codings as $coding) {
                                if (is_object($coding) && method_exists($coding, 'getCode')) {
                                    $codeObj = $coding->getCode();
                                    $code = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)$codeObj;
                                    
                                    // Try each service to see if it supports this category
                                    foreach ($this->getMappedServices() as $service) {
                                        if ($service->supportsCategory($code)) {
                                            return $service->insert($fhirResource);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // If category didn't match, try code
            $codeableConcept = $fhirResource->getCode();
            if (!empty($codeableConcept)) {
                if (is_object($codeableConcept) && method_exists($codeableConcept, 'getCoding')) {
                    $codings = $codeableConcept->getCoding();
                    if (!empty($codings) && is_array($codings)) {
                        foreach ($codings as $coding) {
                            if (is_object($coding) && method_exists($coding, 'getCode')) {
                                $codeObj = $coding->getCode();
                                $code = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)$codeObj;
                                
                                // Try each service to see if it supports this code
                                foreach ($this->getMappedServices() as $service) {
                                    if ($service->supportsCode($code)) {
                                        return $service->insert($fhirResource);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Default to clinical notes service if no specific match found
            $services = $this->getMappedServices();
            
            foreach ($services as $service) {
                if ($service instanceof FhirDiagnosticReportClinicalNotesService) {
                    return $service->insert($fhirResource);
                }
            }

            // If we get here, something went wrong
            $processingResult->addInternalError("No suitable service found for DiagnosticReport");
        } catch (\Exception $e) {
            (new SystemLogger())->error("FhirDiagnosticReportService->insert() exception thrown", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $processingResult->addInternalError("Error inserting DiagnosticReport: " . $e->getMessage());
        }

        return $processingResult;
    }
}
