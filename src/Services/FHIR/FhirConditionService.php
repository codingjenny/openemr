<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCondition;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\ConditionService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

/**
 * FHIR Condition Service
 *
 * @package            OpenEMR
 * @link               http://www.open-emr.org
 * @author             Yash Bothra <yashrajbothra786gmail.com>
 * @copyright          Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license            https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class FhirConditionService extends FhirServiceBase implements IResourceUSCIGProfileService, IFhirExportableResourceService, IPatientCompartmentResourceService
{
    use FhirServiceBaseEmptyTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    /**
     * @var ConditionService
     */
    private $conditionService;


    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-condition';

    public function __construct()
    {
        parent::__construct();
        $this->conditionService = new ConditionService();
    }

    /**
     * Returns an array mapping FHIR Condition Resource search parameters to OpenEMR Condition search parameters
     *
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('condition_uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['last_updated_time']);
    }

    /**
     * Parses an OpenEMR condition record, returning the equivalent FHIR Condition Resource
     *
     * @param  array   $dataRecord The source OpenEMR data record
     * @param  boolean $encode     Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRCondition
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $conditionResource = new FHIRCondition();

        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        if (!empty($dataRecord['last_updated_time'])) {
            $meta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['last_updated_time']));
        } else {
            $meta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $conditionResource->setMeta($meta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $conditionResource->setId($id);

        // ONC requirements
        $this->populateClinicalStatus($dataRecord, $conditionResource);
        $this->populateCategory($dataRecord, $conditionResource);
        $this->populateVerificationStatus($dataRecord, $conditionResource);
        $this->populateCode($dataRecord, $conditionResource);
        $this->populateSubject($dataRecord, $conditionResource);

        // non-ONC requirements
        $this->populateEncounter($dataRecord, $conditionResource);


        if ($encode) {
            return json_encode($conditionResource);
        } else {
            return $conditionResource;
        }
    }

    private function populateEncounter($dataRecord, FHIRCondition $conditionResource)
    {
        if (isset($dataRecord['encounter_uuid'])) {
            $encounter = new FHIRReference();
            $encounter->setReference('Encounter/' . $dataRecord['encounter_uuid']);
            $conditionResource->setEncounter($encounter);
        }
    }

    private function populateSubject($dataRecord, FHIRCondition $conditionResource)
    {
        if (isset($dataRecord['puuid'])) {
            $patient = new FHIRReference();
            $patient->setReference('Patient/' . $dataRecord['puuid']);
            $conditionResource->setSubject($patient);
        }
    }

    private function populateCode($dataRecord, FHIRCondition $conditionResource)
    {
        if (!empty($dataRecord['diagnosis'])) {
            $diagnosisCoding = new FHIRCoding();
            $diagnosisCode = new FHIRCodeableConcept();
            foreach ($dataRecord['diagnosis'] as $code => $codeValues) {
                if (!is_string($code)) {
                    $code = "$code"; // FHIR expects a string
                }
                $diagnosisCoding->setCode($code);
                $diagnosisCoding->setDisplay($codeValues['description']);
                $diagnosisCoding->setSystem($codeValues['system']);
                $diagnosisCode->addCoding($diagnosisCoding);
            }
            $conditionResource->setCode($diagnosisCode);
        }
    }

    private function populateVerificationStatus($dataRecord, FHIRCondition $conditionResource)
    {
        $verificationStatus = new FHIRCodeableConcept();
        $verificationCoding = array(
            'system' => "http://terminology.hl7.org/CodeSystem/condition-ver-status",
            'code' => 'unconfirmed',
            'display' => 'Unconfirmed',
        );
        if (!empty($dataRecord['verification'])) {
            $verificationCoding = array(
                'system' => "http://terminology.hl7.org/CodeSystem/condition-ver-status",
                'code' => $dataRecord['verification'],
                'display' => $dataRecord['verification_title']
            );
        }
        $verificationStatus->addCoding($verificationCoding);
        $conditionResource->setVerificationStatus($verificationStatus);
    }

    private function populateCategory($dataRecord, $conditionResource)
    {

        $conditionCategory = new FHIRCodeableConcept();
        $conditionCategory->addCoding(
            array(
                'system' => "http://terminology.hl7.org/CodeSystem/condition-category",
                'code' => 'problem-list-item',
                'display' => 'Problem List Item'
            )
        );
        $conditionResource->addCategory($conditionCategory);
    }

    private function populateClinicalStatus($dataRecord, FHIRCondition $conditionResource)
    {
        $clinicalStatus = "inactive";
        $clinicalSysytem = "http://terminology.hl7.org/CodeSystem/condition-clinical";
        if (
            (!isset($dataRecord['enddate']) && isset($dataRecord['begdate']))
            || isset($dataRecord['enddate']) && strtotime($dataRecord['enddate']) >= strtotime("now")
        ) {
            // Active if Only Begin Date isset OR End Date isnot expired
            $clinicalStatus = "active";
            if ($dataRecord['occurrence'] == 1 || $dataRecord['outcome'] == 1) {
                $clinicalStatus = "resolved";
            } elseif ($dataRecord['occurrence'] > 1) {
                $clinicalStatus = "recurrence";
            }
        } elseif (isset($dataRecord['enddate']) && strtotime($dataRecord['enddate']) < strtotime("now")) {
            //Inactive if End Date is expired
            $clinicalStatus = "inactive";
        } else {
            $clinicalSysytem = "http://terminology.hl7.org/CodeSystem/data-absent-reason";
            $clinicalStatus = "unknown";
        }
        $clinical_Status = new FHIRCodeableConcept();
        $clinical_Status->addCoding(
            array(
                'system' => $clinicalSysytem,
                'code' => $clinicalStatus,
                'display' => ucwords($clinicalStatus),
            )
        );
        $conditionResource->setClinicalStatus($clinical_Status);
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param  array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters, $puuidBind = null): ProcessingResult
    {
        $result = $this->conditionService->getAll($openEMRSearchParameters, true, $puuidBind);
        return $result;
    }

    public function createProvenanceResource($dataRecord = array(), $encode = false)
    {
        if (!($dataRecord instanceof FHIRCondition)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord);
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }

    /**
     * Returns the Canonical URIs for the FHIR resource for each of the US Core Implementation Guide Profiles that the
     * resource implements.  Most resources have only one profile, but several like DiagnosticReport and Observation
     * has multiple profiles that must be conformed to.
     * @see https://www.hl7.org/fhir/us/core/CapabilityStatement-us-core-server.html for the list of profiles
     * @return string[]
     */
    function getProfileURIs(): array
    {
        return [self::USCGI_PROFILE_URI];
    }

    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::REFERENCE, [new ServiceField('puuid', ServiceField::TYPE_UUID)]);
    }

    /**
     * Parses a FHIR Condition resource, returning the equivalent OpenEMR record.
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource)
    {
        // Ensure it's a Condition resource
        if (!($fhirResource instanceof FHIRCondition)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRCondition");
        }
        
        $data = [];

        // Extract patient reference (subject)
        $subjectRef = $fhirResource->getSubject();
        if (!empty($subjectRef)) {
            $subjectReference = UtilsService::parseReference($subjectRef);
            if (!empty($subjectReference) && $subjectReference['type'] === 'Patient' && $subjectReference['localResource']) {
                $data['puuid'] = $subjectReference['uuid'];
            } elseif (is_array($subjectRef) && !empty($subjectRef['reference'])) {
                // Handle array format (from JSON deserialization)
                $referenceString = $subjectRef['reference'];
                $parts = explode('/', $referenceString);
                if (count($parts) >= 2 && $parts[0] === 'Patient') {
                    $data['puuid'] = $parts[1];
                }
            }
        }

        // Extract code (diagnosis)
        $code = $fhirResource->getCode();
        if (!empty($code)) {
            if (is_object($code) && method_exists($code, 'getCoding')) {
                $codings = $code->getCoding();
                if (!empty($codings) && is_array($codings)) {
                    $primaryCoding = $codings[0];
                    if (is_object($primaryCoding) && method_exists($primaryCoding, 'getCode')) {
                        $codeObj = $primaryCoding->getCode();
                        $codeValue = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)$codeObj;
                        $systemObj = method_exists($primaryCoding, 'getSystem') ? $primaryCoding->getSystem() : null;
                        $codeSystem = is_object($systemObj) && method_exists($systemObj, 'getValue') ? $systemObj->getValue() : (string)($systemObj ?? '');
                        $displayObj = method_exists($primaryCoding, 'getDisplay') ? $primaryCoding->getDisplay() : null;
                        $codeDisplay = is_object($displayObj) && method_exists($displayObj, 'getValue') ? $displayObj->getValue() : (string)($displayObj ?? '');
                        
                        $data['diagnosis'] = [
                            $codeValue => [
                                'code' => $codeValue,
                                'system' => $codeSystem ?: 'http://snomed.info/sct',
                                'description' => $codeDisplay ?: $codeValue
                            ]
                        ];
                        $data['title'] = $codeDisplay ?: $codeValue;
                    }
                }
            } elseif (is_array($code)) {
                // Handle array format
                if (!empty($code['coding']) && is_array($code['coding'])) {
                    $primaryCoding = $code['coding'][0] ?? null;
                    if (is_array($primaryCoding)) {
                        $codeValue = $primaryCoding['code'] ?? null;
                        $codeSystem = $primaryCoding['system'] ?? 'http://snomed.info/sct';
                        $codeDisplay = $primaryCoding['display'] ?? $codeValue;
                        
                        if ($codeValue) {
                            $data['diagnosis'] = [
                                $codeValue => [
                                    'code' => $codeValue,
                                    'system' => $codeSystem,
                                    'description' => $codeDisplay
                                ]
                            ];
                            $data['title'] = $codeDisplay;
                        }
                    }
                }
            }
        }

        // Extract clinical status
        $clinicalStatus = $fhirResource->getClinicalStatus();
        if (!empty($clinicalStatus)) {
            if (is_object($clinicalStatus) && method_exists($clinicalStatus, 'getCoding')) {
                $codings = $clinicalStatus->getCoding();
                if (!empty($codings) && is_array($codings)) {
                    $primaryCoding = $codings[0];
                    if (is_object($primaryCoding) && method_exists($primaryCoding, 'getCode')) {
                        $statusCodeObj = $primaryCoding->getCode();
                        $statusCode = is_object($statusCodeObj) && method_exists($statusCodeObj, 'getValue') ? $statusCodeObj->getValue() : (string)$statusCodeObj;
                        // Map FHIR clinical status to OpenEMR outcome
                        // active -> outcome = 0, resolved -> outcome = 1, inactive -> outcome = 0
                        if ($statusCode === 'resolved') {
                            $data['outcome'] = '1';
                        } else {
                            $data['outcome'] = '0';
                        }
                    }
                }
            } elseif (is_array($clinicalStatus)) {
                if (!empty($clinicalStatus['coding']) && is_array($clinicalStatus['coding'])) {
                    $primaryCoding = $clinicalStatus['coding'][0] ?? null;
                    if (is_array($primaryCoding)) {
                        $statusCode = $primaryCoding['code'] ?? null;
                        if ($statusCode === 'resolved') {
                            $data['outcome'] = '1';
                        } else {
                            $data['outcome'] = '0';
                        }
                    }
                }
            }
        }

        // Extract verification status
        $verificationStatus = $fhirResource->getVerificationStatus();
        if (!empty($verificationStatus)) {
            if (is_object($verificationStatus) && method_exists($verificationStatus, 'getCoding')) {
                $codings = $verificationStatus->getCoding();
                if (!empty($codings) && is_array($codings)) {
                    $primaryCoding = $codings[0];
                    if (is_object($primaryCoding) && method_exists($primaryCoding, 'getCode')) {
                        $verificationCodeObj = $primaryCoding->getCode();
                        $data['verification'] = is_object($verificationCodeObj) && method_exists($verificationCodeObj, 'getValue') ? $verificationCodeObj->getValue() : (string)$verificationCodeObj;
                    }
                }
            } elseif (is_array($verificationStatus)) {
                if (!empty($verificationStatus['coding']) && is_array($verificationStatus['coding'])) {
                    $primaryCoding = $verificationStatus['coding'][0] ?? null;
                    if (is_array($primaryCoding)) {
                        $data['verification'] = $primaryCoding['code'] ?? null;
                    }
                }
            }
        }

        // Extract onset date
        $onsetDateTime = $fhirResource->getOnsetDateTime();
        if (!empty($onsetDateTime)) {
            $dateValue = null;
            if (is_object($onsetDateTime) && method_exists($onsetDateTime, 'getValue')) {
                $dateValue = $onsetDateTime->getValue();
            } elseif (is_string($onsetDateTime)) {
                $dateValue = $onsetDateTime;
            }
            
            if (!empty($dateValue)) {
                // Convert to date format (Y-m-d) for database validation
                // ConditionValidator expects Y-m-d format (not Y-m-d H:i:s)
                try {
                    $dateObj = new \DateTime($dateValue);
                    $data['begdate'] = $dateObj->format('Y-m-d');
                } catch (\Exception $e) {
                    // If date parsing fails, try to extract just the date part
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                        $data['begdate'] = $matches[1];
                    }
                }
            }
        }

        // Extract abatement date (end date)
        $abatementDateTime = $fhirResource->getAbatementDateTime();
        if (!empty($abatementDateTime)) {
            $dateValue = null;
            if (is_object($abatementDateTime) && method_exists($abatementDateTime, 'getValue')) {
                $dateValue = $abatementDateTime->getValue();
            } elseif (is_string($abatementDateTime)) {
                $dateValue = $abatementDateTime;
            }
            
            if (!empty($dateValue)) {
                // Convert to date format (Y-m-d) for database validation
                // ConditionValidator expects Y-m-d format (not Y-m-d H:i:s)
                try {
                    $dateObj = new \DateTime($dateValue);
                    $data['enddate'] = $dateObj->format('Y-m-d');
                } catch (\Exception $e) {
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                        $data['enddate'] = $matches[1];
                    }
                }
            }
        }

        // Extract encounter reference
        $encounterRef = $fhirResource->getEncounter();
        if (!empty($encounterRef)) {
            $encounterReference = UtilsService::parseReference($encounterRef);
            if (!empty($encounterReference) && $encounterReference['type'] === 'Encounter' && $encounterReference['localResource']) {
                $data['encounter_uuid'] = $encounterReference['uuid'];
            } elseif (is_array($encounterRef) && !empty($encounterRef['reference'])) {
                $referenceString = $encounterRef['reference'];
                $parts = explode('/', $referenceString);
                if (count($parts) >= 2 && $parts[0] === 'Encounter') {
                    $data['encounter_uuid'] = $parts[1];
                }
            }
        }

        // Extract recorder (practitioner)
        $recorder = $fhirResource->getRecorder();
        if (!empty($recorder)) {
            $recorderReference = UtilsService::parseReference($recorder);
            if (!empty($recorderReference) && $recorderReference['type'] === 'Practitioner' && $recorderReference['localResource']) {
                $practitionerUuid = $recorderReference['uuid'];
                $practitionerData = QueryUtils::fetchRecords(
                    "SELECT username FROM users WHERE uuid = ?",
                    [UuidRegistry::uuidToBytes($practitionerUuid)]
                );
                if (!empty($practitionerData) && !empty($practitionerData[0])) {
                    $data['user'] = $practitionerData[0]['username'];
                }
            }
        }

        // Set default values
        if (empty($data['user'])) {
            $data['user'] = (isset($_SESSION) && isset($_SESSION['authUser'])) ? $_SESSION['authUser'] : null;
        }

        // Set default occurrence
        if (empty($data['occurrence'])) {
            $data['occurrence'] = '0';
        }

        return $data;
    }

    /**
     * Inserts an OpenEMR record into the system.
     * @param array $openEmrRecord OpenEMR condition record
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();

        try {
            // Validate required fields
            if (empty($openEmrRecord['puuid'])) {
                $processingResult->setValidationMessages(['patient' => 'Patient reference is required']);
                return $processingResult;
            }

            // Convert diagnosis array to string for database storage
            // Database expects format: "CODE_TYPE:CODE" or "CODE_TYPE:CODE;CODE_TYPE:CODE" for multiple
            if (!empty($openEmrRecord['diagnosis']) && is_array($openEmrRecord['diagnosis'])) {
                $diagnosisStrings = [];
                foreach ($openEmrRecord['diagnosis'] as $code => $codeValues) {
                    $codeType = 'SNOMED-CT'; // Default
                    $system = $codeValues['system'] ?? '';
                    // Map FHIR system URI to OpenEMR code type
                    if (strpos($system, 'snomed') !== false) {
                        $codeType = 'SNOMED-CT';
                    } elseif (strpos($system, 'icd-10') !== false || strpos($system, 'icd10') !== false) {
                        $codeType = 'ICD10';
                    } elseif (strpos($system, 'loinc') !== false) {
                        $codeType = 'LOINC';
                    }
                    $diagnosisStrings[] = $codeType . ':' . $code;
                }
                $openEmrRecord['diagnosis'] = implode(';', $diagnosisStrings);
            }

            // Use ConditionService to insert
            $insertResult = $this->conditionService->insert($openEmrRecord);

            if ($insertResult->isValid() && !empty($insertResult->getData())) {
                // Get the inserted record
                $insertedData = $insertResult->getData()[0];
                $uuid = $insertedData['uuid'] ?? null;

                if (!empty($uuid)) {
                    // Ensure UUID is in string format
                    $uuidString = is_string($uuid) ? $uuid : UuidRegistry::uuidToString($uuid);
                    
                    // Fetch the complete record and convert to FHIR
                    $conditionRecordResult = $this->conditionService->getOne($uuidString);
                    
                    if ($conditionRecordResult->hasData() && count($conditionRecordResult->getData()) > 0) {
                        $conditionRecord = $conditionRecordResult->getData()[0];
                        
                        // Ensure it's an array (OpenEMR record format)
                        if (!is_array($conditionRecord)) {
                            $conditionRecord = json_decode(json_encode($conditionRecord), true);
                        }
                        
                        // Convert complete OpenEMR condition record to FHIR resource
                        $fhirResource = $this->parseOpenEMRRecord($conditionRecord, false);
                        $processingResult->setData([]);
                        $processingResult->addData($fhirResource);
                    } else {
                        $processingResult->addInternalError("Failed to retrieve inserted condition record");
                    }
                } else {
                    $processingResult->addInternalError("No UUID returned from insert operation");
                }
            } else {
                // Copy validation messages and errors from insert result
                $validationMessages = $insertResult->getValidationMessages();
                if (!empty($validationMessages)) {
                    $processingResult->setValidationMessages($validationMessages);
                }
                foreach ($insertResult->getInternalErrors() as $error) {
                    $processingResult->addInternalError($error);
                }
            }
        } catch (\Exception $e) {
            $processingResult->addInternalError("Error inserting condition record: " . $e->getMessage());
        }

        return $processingResult;
    }
}
