<?php

/**
 * FhirDiagnosticReportClinicalNotesService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR\DiagnosticReport;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRDiagnosticReport;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAttachment;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDateTime;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRInstant;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\Services\ClinicalNotesService;
use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\FHIR\FhirCodeSystemConstants;
use OpenEMR\Services\FHIR\FhirOrganizationService;
use OpenEMR\Services\FHIR\FhirProvenanceService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\Indicates;
use OpenEMR\Services\FHIR\OpenEMR;
use OpenEMR\Services\FHIR\openEMRSearchParameters;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\ListService;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\SearchModifier;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\Common\Uuid\UuidRegistry;

class FhirDiagnosticReportClinicalNotesService extends FhirServiceBase
{
    use PatientSearchTrait;

    /**
     * @var ClinicalNotesService
     */
    private $service;

    public function __construct($fhirApiURL = null)
    {
        parent::__construct($fhirApiURL);
        $this->service = new ClinicalNotesService();
    }

    /**
     * Returns an array mapping FHIR Resource search parameters to OpenEMR search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'code' => new FhirSearchParameterDefinition('type', SearchFieldType::TOKEN, ['code']),
            'category' => new FhirSearchParameterDefinition('category', SearchFieldType::TOKEN, ['category_code']),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['last_updated']);
    }

    public function supportsCategory($category)
    {
        $loincCategory = "LOINC:" . $category;
        $listService = new ListService();
        $options = $listService->getOptionsByListName('Clinical_Note_Category', ['notes' => $loincCategory]);
        return !empty($options);
    }


    public function supportsCode($code)
    {
        return $this->service->isValidClinicalNoteCode($code);
    }

    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $report = new FHIRDiagnosticReport();
        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId('1');
        if (!empty($dataRecord['last_updated'])) {
            $fhirMeta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['last_updated']));
        } else {
            $fhirMeta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $report->setMeta($fhirMeta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $report->setId($id);

        if (!empty($dataRecord['date'])) {
            $date = UtilsService::getLocalDateAsUTC($dataRecord['date']);
            $report->setEffectiveDateTime(new FHIRDateTime($date));
            $report->setIssued(new FHIRInstant($date));
        } else {
            $report->setDate(UtilsService::createDataMissingExtension());
        }

        if (!empty($dataRecord['euuid'])) {
            $report->setEncounter(UtilsService::createRelativeReference('Encounter', $dataRecord['euuid']));
        }

        $fhirOrganizationService = new FhirOrganizationService();

        if (!empty($dataRecord['user_uuid'])) {
            if (!empty($dataRecord['user_npi'])) {
                $report->addPerformer(UtilsService::createRelativeReference('Practitioner', $dataRecord['user_uuid']));
            } else {
                $orgReference = $fhirOrganizationService->getOrganizationReferenceForUser($dataRecord['user_uuid']);
                $report->addPerformer($orgReference);
            }
        } else {
            $report->addPerformer($fhirOrganizationService->getPrimaryBusinessEntityReference());
        }

        // populate our clinical narrative notes
        if (!empty($dataRecord['description'])) {
            $attachment = new FHIRAttachment();
            $attachment->setContentType("text/plain");
            $attachment->setData(base64_encode($dataRecord['description']));
            $report->addPresentedForm($attachment);
        } else {
            // need to support data missing if its not there.
            $report->addPresentedForm(UtilsService::createDataMissingExtension());
        }

        if (!empty($dataRecord['puuid'])) {
            $report->setSubject(UtilsService::createRelativeReference('Patient', $dataRecord['puuid']));
        }

        $codeTypesService = new CodeTypesService();
        $codeParts = $codeTypesService->parseCode($dataRecord['category_code']);
        $code = $codeParts['code'];

        $category = UtilsService::createCodeableConcept([
            $code => [
                'code' => $code
                ,'description' => $dataRecord['category_title']
                ,'system' => $codeTypesService->getSystemForCodeType($codeParts['code_type'])
            ]
        ], FhirCodeSystemConstants::LOINC); // we default to LOINC if we don't have a valid type

        $report->addCategory($category);

        if (!empty($dataRecord['status'])) {
            $report->setStatus($dataRecord['status']);
        } else {
            $report->setStatus('final');
        }

        if (!empty($dataRecord['code'])) {
            $code = UtilsService::createCodeableConcept($dataRecord['code'], FhirCodeSystemConstants::LOINC, $dataRecord['codetext']);
            $report->setCode($code);
        } else {
            $report->setCode(UtilsService::createNullFlavorUnknownCodeableConcept());
        }

        return $report;
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     * @param openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return OpenEMR records
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
    {
        if (isset($openEMRSearchParameters['code']) && $openEMRSearchParameters['code'] instanceof TokenSearchField) {
            $openEMRSearchParameters['code']->transformValues([$this, 'addLOINCPrefix']);
        }

        if (isset($openEMRSearchParameters['category_code']) && $openEMRSearchParameters['category_code'] instanceof TokenSearchField) {
            $openEMRSearchParameters['category_code']->transformValues([$this, 'addLOINCPrefix']);
        } else {
            // we need to make sure we only include things with a category code in our clinical notes
            $openEMRSearchParameters['category_code'] = new TokenSearchField('category_code', [new TokenSearchValue(false)]);
            $openEMRSearchParameters['category_code']->setModifier(SearchModifier::MISSING);
        }
        return $this->service->search($openEMRSearchParameters);
    }

    public function addLOINCPrefix(TokenSearchValue $val)
    {
        // TODO: @adunsulag I don't like this, is there a way we can mark the code system we are using that will prefix the value
        // already?
        $val->setCode("LOINC:" . $val->getCode());
        return $val;
    }

    /**
     * Creates the Provenance resource  for the equivalent FHIR Resource
     *
     * @param $dataRecord The source OpenEMR data record
     * @param $encode Indicates if the returned resource is encoded into a string. Defaults to True.
     * @return the FHIR Resource. Returned format is defined using $encode parameter.
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        if (!($dataRecord instanceof FHIRDiagnosticReport)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        $performer = null;
        if (!empty($dataRecord->getPerformer())) {
            $performer = current($dataRecord->getPerformer());
        }
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord, $performer);
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }

    /**
     * Parses a FHIR DiagnosticReport resource, returning the equivalent OpenEMR record.
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource)
    {
        if (!($fhirResource instanceof FHIRDiagnosticReport)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRDiagnosticReport");
        }
        
        $data = [];

        // Extract patient reference (subject)
        $subjectRef = $fhirResource->getSubject();
        if (!empty($subjectRef)) {
            $subjectReference = UtilsService::parseReference($subjectRef);
            if (!empty($subjectReference) && $subjectReference['type'] === 'Patient' && $subjectReference['localResource']) {
                $data['puuid'] = $subjectReference['uuid'];
            }
        }

        // Extract encounter reference
        $encounterRef = $fhirResource->getEncounter();
        if (!empty($encounterRef)) {
            $encounterReference = UtilsService::parseReference($encounterRef);
            if (!empty($encounterReference) && $encounterReference['type'] === 'Encounter' && $encounterReference['localResource']) {
                $data['euuid'] = $encounterReference['uuid'];
            }
        }

        // Extract code (clinical note type)
        $code = $fhirResource->getCode();
        if (!empty($code)) {
            if (is_object($code) && method_exists($code, 'getCoding')) {
                $codings = $code->getCoding();
                if (!empty($codings) && is_array($codings)) {
                    foreach ($codings as $coding) {
                        if (is_object($coding) && method_exists($coding, 'getCode')) {
                            $codeObj = $coding->getCode();
                            $codeValue = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)$codeObj;
                            $systemObj = method_exists($coding, 'getSystem') ? $coding->getSystem() : null;
                            $codeSystem = is_object($systemObj) && method_exists($systemObj, 'getValue') ? $systemObj->getValue() : (string)($systemObj ?? '');
                            $displayObj = method_exists($coding, 'getDisplay') ? $coding->getDisplay() : null;
                            $codeDisplay = is_object($displayObj) && method_exists($displayObj, 'getValue') ? $displayObj->getValue() : (string)($displayObj ?? '');
                            
                            // Store LOINC code with system prefix
                            if (strpos($codeSystem, 'loinc') !== false) {
                                $data['code'] = 'LOINC:' . $codeValue;
                                $data['codetext'] = $codeDisplay ?: $codeValue;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Extract category (clinical note category)
        $categories = $fhirResource->getCategory();
        if (!empty($categories) && is_array($categories)) {
            foreach ($categories as $category) {
                if (is_object($category) && method_exists($category, 'getCoding')) {
                    $codings = $category->getCoding();
                    if (!empty($codings) && is_array($codings)) {
                        foreach ($codings as $coding) {
                            if (is_object($coding) && method_exists($coding, 'getCode')) {
                                $codeObj = $coding->getCode();
                                $codeValue = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)$codeObj;
                                $systemObj = method_exists($coding, 'getSystem') ? $coding->getSystem() : null;
                                $codeSystem = is_object($systemObj) && method_exists($systemObj, 'getValue') ? $systemObj->getValue() : (string)($systemObj ?? '');
                                
                                // Store LOINC category code with system prefix
                                if (strpos($codeSystem, 'loinc') !== false) {
                                    $data['category_code'] = 'LOINC:' . $codeValue;
                                    break 2; // Exit both loops
                                }
                            }
                        }
                    }
                }
            }
        }

        // Extract date
        $effectiveDateTime = $fhirResource->getEffectiveDateTime();
        if (!empty($effectiveDateTime)) {
            $dateValue = null;
            if (is_object($effectiveDateTime) && method_exists($effectiveDateTime, 'getValue')) {
                $dateValue = $effectiveDateTime->getValue();
            } elseif (is_string($effectiveDateTime)) {
                $dateValue = $effectiveDateTime;
            }
            
            if (!empty($dateValue)) {
                try {
                    $dateObj = new \DateTime($dateValue);
                    $data['date'] = $dateObj->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    // If date parsing fails, try to use the value as is
                    $data['date'] = $dateValue;
                }
            }
        }

        // Extract clinical note text from presentedForm
        $presentedForms = $fhirResource->getPresentedForm();
        if (!empty($presentedForms) && is_array($presentedForms)) {
            foreach ($presentedForms as $form) {
                if (is_object($form) && method_exists($form, 'getData')) {
                    $dataObj = $form->getData();
                    $base64Data = is_object($dataObj) && method_exists($dataObj, 'getValue') ? $dataObj->getValue() : (string)$dataObj;
                    if (!empty($base64Data)) {
                        $data['description'] = base64_decode($base64Data);
                        break;
                    }
                }
            }
        }

        // Extract status
        $status = $fhirResource->getStatus();
        if (!empty($status)) {
            $statusValue = is_object($status) && method_exists($status, 'getValue') ? $status->getValue() : (string)$status;
            $data['status'] = $statusValue;
        }

        // Extract performer (user)
        $performers = $fhirResource->getPerformer();
        if (!empty($performers) && is_array($performers)) {
            foreach ($performers as $performer) {
                $performerReference = UtilsService::parseReference($performer);
                if (!empty($performerReference) && $performerReference['type'] === 'Practitioner' && $performerReference['localResource']) {
                    $data['user_uuid'] = $performerReference['uuid'];
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Inserts an OpenEMR record into the system.
     *
     * @param array $openEmrRecord OpenEMR clinical notes record
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();
        
        try {
            // Validate required fields
            if (empty($openEmrRecord['puuid'])) {
                $processingResult->addValidationError('subject', 'Patient reference is required');
                return $processingResult;
            }

            if (empty($openEmrRecord['euuid'])) {
                $processingResult->addValidationError('encounter', 'Encounter reference is required');
                return $processingResult;
            }

            // Get patient ID and encounter ID from UUIDs
            $puuidBytes = UuidRegistry::uuidToBytes($openEmrRecord['puuid']);
            $pid = QueryUtils::fetchSingleValue(
                "SELECT pid FROM patient_data WHERE uuid = ?",
                'pid',
                [$puuidBytes]
            );
            
            $euuidBytes = UuidRegistry::uuidToBytes($openEmrRecord['euuid']);
            $encounter = QueryUtils::fetchSingleValue(
                "SELECT encounter FROM form_encounter WHERE uuid = ?",
                'encounter',
                [$euuidBytes]
            );
            
            if (empty($pid)) {
                $processingResult->addValidationError('subject', 'Patient not found with UUID: ' . $openEmrRecord['puuid']);
                return $processingResult;
            }

            if (empty($encounter)) {
                $processingResult->addValidationError('encounter', 'Encounter not found with UUID: ' . $openEmrRecord['euuid']);
                return $processingResult;
            }

            // Get user ID from user UUID if provided
            $user = null;
            if (!empty($openEmrRecord['user_uuid'])) {
                $uuidBytes = UuidRegistry::uuidToBytes($openEmrRecord['user_uuid']);
                $user = QueryUtils::fetchSingleValue(
                    "SELECT id FROM users WHERE uuid = ?",
                    'id',
                    [$uuidBytes]
                );
            }
            if (empty($user)) {
                $user = $_SESSION['authUser'] ?? null;
            }

            // Map category code to clinical_notes_category option_id
            $categoryOptionId = null;
            if (!empty($openEmrRecord['category_code'])) {
                $listService = new ListService();
                $options = $listService->getOptionsByListName('Clinical_Note_Category', ['notes' => $openEmrRecord['category_code']]);
                if (!empty($options)) {
                    $categoryOptionId = $options[0]['option_id'];
                }
            }

            // Map code to clinical_notes_type option_id
            $typeOptionId = null;
            if (!empty($openEmrRecord['code'])) {
                $listService = new ListService();
                $options = $listService->getOptionsByListName('Clinical_Note_Type', ['notes' => $openEmrRecord['code']]);
                if (!empty($options)) {
                    $typeOptionId = $options[0]['option_id'];
                }
            }

            // Create form_id
            $form_id = $this->service->createClinicalNotesParentForm($pid, $encounter, 1);

            // Prepare record for insertion
            $record = [
                'form_id' => $form_id,
                'pid' => $pid,
                'encounter' => $encounter,
                'user' => $user,
                'groupname' => $_SESSION["authProvider"] ?? '',
                'authorized' => 1,
                'activity' => ClinicalNotesService::ACTIVITY_ACTIVE,
                'date' => $openEmrRecord['date'] ?? date('Y-m-d H:i:s'),
                'clinical_notes_type' => $typeOptionId,
                'clinical_notes_category' => $categoryOptionId,
                'description' => $openEmrRecord['description'] ?? '',
                'code' => $openEmrRecord['code'] ?? '',
                'codetext' => $openEmrRecord['codetext'] ?? ''
            ];

            // Insert the record
            $insertedRecord = $this->service->saveArray($record);
            
            if (!empty($insertedRecord['uuid'])) {
                // Fetch the complete record and convert to FHIR
                $search = [
                    'uuid' => new TokenSearchField('uuid', new TokenSearchValue($insertedRecord['uuid'], false))
                ];
                $searchResult = $this->service->search($search);
                
                if ($searchResult->hasData() && count($searchResult->getData()) > 0) {
                    $clinicalNote = $searchResult->getData()[0];
                    
                    // Convert to FHIR resource
                    $fhirResource = $this->parseOpenEMRRecord($clinicalNote, false);
                    $processingResult->setData([]);
                    $processingResult->addData($fhirResource);
                } else {
                    $processingResult->addInternalError("Failed to retrieve inserted clinical note record");
                }
            } else {
                $processingResult->addInternalError("No UUID returned from insert operation");
            }
        } catch (\Exception $e) {
            $processingResult->addInternalError("Error inserting clinical note: " . $e->getMessage());
        }

        return $processingResult;
    }

    /**
     * Updates an OpenEMR record.
     * @param string $fhirResourceId The FHIR resource ID (UUID)
     * @param array $updatedOpenEMRRecord The updated OpenEMR record
     * @return ProcessingResult
     */
    public function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        $processingResult = new ProcessingResult();
        $processingResult->addInternalError("Update not yet implemented for DiagnosticReport Clinical Notes");
        return $processingResult;
    }
}
