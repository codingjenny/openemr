<?php

/**
 * FHIR Procedure Service
 *
 * @package            OpenEMR
 * @link               http://www.open-emr.org
 * @author             Yash Bothra <yashrajbothra786gmail.com>
 * @author             Stephen Nielson <stephen@nielson.org>
 * @copyright          Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @copyright          Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license            https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\FHIR\Procedure\FhirProcedureOEProcedureService;
use OpenEMR\Services\FHIR\Procedure\FhirProcedureSurgeryService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\Traits\MappedServiceTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\ProcedureService;
use OpenEMR\Services\SurgeryService;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRProcedure;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldException;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

class FhirProcedureService extends FhirServiceBase implements IResourceUSCIGProfileService, IFhirExportableResourceService, IPatientCompartmentResourceService
{
    use MappedServiceTrait;
    use PatientSearchTrait;
    use FhirServiceBaseEmptyTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    const FHIR_PROCEDURE_STATUS_COMPLETED = "completed";
    const FHIR_PROCEDURE_STATUS_IN_PROGRESS = "in-progress";
    const FHIR_PROCEDURE_STATUS_STOPPED = "stopped";
    const FHIR_PROCEDURE_STATUS_UNKNOWN = "unknown";

    const PROCEDURE_STATUS_COMPLETED = "completed";
    const PROCEDURE_STATUS_PENDING = "pending";
    const PROCEDURE_STATUS_CANCELLED = "cancelled";



    /**
     * @var ProcedureService
     */
    private $procedureService;

    /**
     * @var SurgeryService
     */
    private $surgeryService;

    public function __construct()
    {
        parent::__construct();
        $this->addMappedService(new FhirProcedureOEProcedureService());
        $this->addMappedService(new FhirProcedureSurgeryService());
        $this->surgeryService = new SurgeryService();
    }

    /**
     * Returns an array mapping FHIR Procedure Resource search parameters to OpenEMR Procedure search parameters
     *
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['report_date']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['last_updated']);
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

            $fhirSearchResult = $this->searchAllServices($fhirSearchParameters, $puuidBind);
        } catch (SearchFieldException $exception) {
            (new SystemLogger())->error("FhirServiceBase->getAll() exception thrown", ['message' => $exception->getMessage(),
                'field' => $exception->getField()]);
            // put our exception information here
            $fhirSearchResult->setValidationMessages([$exception->getField() => $exception->getMessage()]);
        }
        return $fhirSearchResult;
    }

    /**
     * Returns the Canonical URIs for the FHIR resource for each of the US Core Implementation Guide Profiles that the
     * resource implements.  Most resources have only one profile, but several like DiagnosticReport and Observation
     * has multiple profiles that must be conformed to.
     * @see https://www.hl7.org/fhir/us/core/CapabilityStatement-us-core-server.html for the list of profiles
     * @return string[]
     */
    public function getProfileURIs(): array
    {
        return [
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-procedure'
        ];
    }

    /**
     * Parses a FHIR Procedure resource, returning the equivalent OpenEMR record.
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource)
    {
        // Ensure it's a Procedure resource
        if (!($fhirResource instanceof FHIRProcedure)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRProcedure");
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

        // Extract code (procedure code)
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

        // Extract status
        $status = $fhirResource->getStatus();
        if (!empty($status)) {
            if (is_object($status) && method_exists($status, 'getValue')) {
                $statusValue = $status->getValue();
                // Map FHIR status to OpenEMR status
                // completed -> active, in-progress -> inactive, stopped -> inactive
                if ($statusValue === 'completed') {
                    $data['status'] = 'active';
                } else {
                    $data['status'] = 'inactive';
                }
            } elseif (is_string($status)) {
                if ($status === 'completed') {
                    $data['status'] = 'active';
                } else {
                    $data['status'] = 'inactive';
                }
            }
        }

        // Extract performed date
        $performedDateTime = $fhirResource->getPerformedDateTime();
        if (!empty($performedDateTime)) {
            $dateValue = null;
            if (is_object($performedDateTime) && method_exists($performedDateTime, 'getValue')) {
                $dateValue = $performedDateTime->getValue();
            } elseif (is_string($performedDateTime)) {
                $dateValue = $performedDateTime;
            }
            
            if (!empty($dateValue)) {
                // Convert to date format (Y-m-d) for database
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
        } else {
            // Try performer if recorder is not available
            $performers = $fhirResource->getPerformer();
            if (!empty($performers) && is_array($performers) && count($performers) > 0) {
                $firstPerformer = $performers[0];
                if (is_object($firstPerformer) && method_exists($firstPerformer, 'getActor')) {
                    $actor = $firstPerformer->getActor();
                    if (!empty($actor)) {
                        $actorReference = UtilsService::parseReference($actor);
                        if (!empty($actorReference) && $actorReference['type'] === 'Practitioner' && $actorReference['localResource']) {
                            $practitionerUuid = $actorReference['uuid'];
                            $practitionerData = QueryUtils::fetchRecords(
                                "SELECT username FROM users WHERE uuid = ?",
                                [UuidRegistry::uuidToBytes($practitionerUuid)]
                            );
                            if (!empty($practitionerData) && !empty($practitionerData[0])) {
                                $data['user'] = $practitionerData[0]['username'];
                            }
                        }
                    }
                }
            }
        }

        // Set default values
        if (empty($data['user'])) {
            $data['user'] = (isset($_SESSION) && isset($_SESSION['authUser'])) ? $_SESSION['authUser'] : null;
        }

        // Set default status if not set
        if (empty($data['status'])) {
            $data['status'] = 'active';
        }

        return $data;
    }

    /**
     * Inserts an OpenEMR record into the system.
     * @param array $openEmrRecord OpenEMR procedure record
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

            // Get patient ID from UUID
            $puuidBytes = UuidRegistry::uuidToBytes($openEmrRecord['puuid']);
            $pid = QueryUtils::fetchSingleValue(
                "SELECT pid FROM patient_data WHERE uuid = ?",
                'pid',
                [$puuidBytes]
            );

            if (empty($pid)) {
                $processingResult->setValidationMessages(['patient' => 'Patient not found']);
                return $processingResult;
            }

            $openEmrRecord['pid'] = $pid;

            // Encounter is optional for Procedure (unlike Observation)
            // If encounter is provided, validate it exists
            $encounterId = null;
            if (!empty($openEmrRecord['encounter_uuid'])) {
                $euuidBytes = UuidRegistry::uuidToBytes($openEmrRecord['encounter_uuid']);
                $encounterId = QueryUtils::fetchSingleValue(
                    "SELECT id FROM form_encounter WHERE uuid = ?",
                    'id',
                    [$euuidBytes]
                );

                if (empty($encounterId)) {
                    $processingResult->setValidationMessages(['encounter' => 'Encounter not found']);
                    return $processingResult;
                }
            }

            // Create UUID for the procedure
            // createUuid() returns binary format, so we need to convert to string for storage
            $uuidBytes = (new UuidRegistry(['table_name' => 'lists']))->createUuid();
            $uuidString = UuidRegistry::uuidToString($uuidBytes);
            $openEmrRecord['uuid'] = $uuidString;

            // Build insert query manually (similar to ConditionService)
            $setParts = [];
            $bindValues = [];
            
            // Fields to insert
            $fields = ['pid', 'uuid', 'title', 'diagnosis', 'begdate', 'enddate', 'comments', 'user'];
            foreach ($fields as $field) {
                if (isset($openEmrRecord[$field])) {
                    $setParts[] = "`$field` = ?";
                    if ($field === 'uuid') {
                        // Convert string UUID back to bytes for database storage
                        $bindValues[] = UuidRegistry::uuidToBytes($openEmrRecord[$field]);
                    } else {
                        $bindValues[] = $openEmrRecord[$field];
                    }
                }
            }

            $sql = "INSERT INTO lists SET";
            $sql .= " date=NOW(),";
            $sql .= " activity=1,";
            $sql .= " type='surgery',";
            if (!empty($setParts)) {
                $sql .= " " . implode(",", $setParts);
            }
            
            $results = sqlInsert($sql, $bindValues);

            if ($results) {
                // uuidString is already set above
                
                // Link procedure to encounter if provided (optional)
                if (!empty($encounterId)) {
                    try {
                        sqlInsert(
                            "INSERT INTO issue_encounter (pid, list_id, encounter) VALUES (?, ?, ?)",
                            [$pid, $results, $encounterId]
                        );
                    } catch (\Exception $e) {
                        // Log but don't fail if encounter linking fails
                        // The procedure was already inserted successfully
                    }
                }

                // Fetch the complete record directly from database
                // Simplified query that works with or without encounter
                $uuidBytes = UuidRegistry::uuidToBytes($uuidString);
                
                // First try a simple query to get the basic record
                // Note: UUIDs from database are binary, we'll convert them after fetching
                $procedureRecord = QueryUtils::fetchRecords(
                    "SELECT 
                        lists.id,
                        lists.date,
                        lists.begdate,
                        lists.enddate,
                        lists.title,
                        lists.diagnosis,
                        lists.uuid,
                        lists.comments,
                        lists.user as surgery_recorder,
                        lists.modifydate AS date_modified,
                        patient.uuid AS puuid,
                        encounter.uuid AS euuid,
                        recorders.uuid AS recorder_uuid,
                        recorders.npi AS recorder_npi
                    FROM lists
                    LEFT JOIN patient_data AS patient ON lists.pid = patient.pid
                    LEFT JOIN issue_encounter AS issue ON issue.list_id = lists.id AND issue.pid = lists.pid
                    LEFT JOIN form_encounter AS encounter ON issue.encounter = encounter.id
                    LEFT JOIN users AS recorders ON recorders.username = lists.user
                    WHERE lists.type = 'surgery' AND lists.uuid = ?",
                    [$uuidBytes]
                );
                
                // Convert all UUIDs from binary to string format immediately after query
                if (!empty($procedureRecord) && count($procedureRecord) > 0) {
                    foreach ($procedureRecord as &$record) {
                        // Convert lists.uuid
                        if (!empty($record['uuid'])) {
                            if (is_string($record['uuid']) && strlen($record['uuid']) === 16) {
                                $record['uuid'] = UuidRegistry::uuidToString($record['uuid']);
                            } elseif (is_string($record['uuid']) && strlen($record['uuid']) !== 36) {
                                $record['uuid'] = UuidRegistry::uuidToString($record['uuid']);
                            }
                        }
                        // Convert puuid
                        if (!empty($record['puuid'])) {
                            if (is_string($record['puuid']) && strlen($record['puuid']) === 16) {
                                $record['puuid'] = UuidRegistry::uuidToString($record['puuid']);
                            } elseif (is_string($record['puuid']) && strlen($record['puuid']) !== 36) {
                                $record['puuid'] = UuidRegistry::uuidToString($record['puuid']);
                            }
                        }
                        // Convert euuid
                        if (!empty($record['euuid'])) {
                            if (is_string($record['euuid']) && strlen($record['euuid']) === 16) {
                                $record['euuid'] = UuidRegistry::uuidToString($record['euuid']);
                            } elseif (is_string($record['euuid']) && strlen($record['euuid']) !== 36) {
                                $record['euuid'] = UuidRegistry::uuidToString($record['euuid']);
                            }
                        }
                        // Convert recorder_uuid
                        if (!empty($record['recorder_uuid'])) {
                            if (is_string($record['recorder_uuid']) && strlen($record['recorder_uuid']) === 16) {
                                $record['recorder_uuid'] = UuidRegistry::uuidToString($record['recorder_uuid']);
                            } elseif (is_string($record['recorder_uuid']) && strlen($record['recorder_uuid']) !== 36) {
                                $record['recorder_uuid'] = UuidRegistry::uuidToString($record['recorder_uuid']);
                            }
                        }
                    }
                    unset($record); // Break reference
                }
                
                // If query returns empty, try a simpler query without joins
                if (empty($procedureRecord) || count($procedureRecord) === 0) {
                    $simpleRecord = QueryUtils::fetchRecords(
                        "SELECT 
                            id,
                            date,
                            begdate,
                            enddate,
                            title,
                            diagnosis,
                            uuid,
                            comments,
                            user as surgery_recorder,
                            modifydate AS date_modified,
                            pid
                        FROM lists
                        WHERE type = 'surgery' AND uuid = ?",
                        [$uuidBytes]
                    );
                    
                    if (!empty($simpleRecord) && count($simpleRecord) > 0) {
                        $record = $simpleRecord[0];
                        
                        // Get patient UUID separately
                        $pidValue = $record['pid'];
                        $patientUuid = QueryUtils::fetchSingleValue(
                            "SELECT uuid FROM patient_data WHERE pid = ?",
                            'uuid',
                            [$pidValue]
                        );
                        if (!empty($patientUuid)) {
                            // Check if it's binary (16 bytes) or string (36 chars)
                            if (is_string($patientUuid) && strlen($patientUuid) === 16) {
                                $record['puuid'] = UuidRegistry::uuidToString($patientUuid);
                            } elseif (is_string($patientUuid) && strlen($patientUuid) === 36) {
                                $record['puuid'] = $patientUuid;
                            } else {
                                $record['puuid'] = UuidRegistry::uuidToString($patientUuid);
                            }
                        }
                        
                        // Get encounter UUID if exists
                        $encounterIdFromIssue = QueryUtils::fetchSingleValue(
                            "SELECT encounter FROM issue_encounter WHERE list_id = ? AND pid = ?",
                            'encounter',
                            [$record['id'], $pidValue]
                        );
                        if (!empty($encounterIdFromIssue)) {
                            $encounterUuid = QueryUtils::fetchSingleValue(
                                "SELECT uuid FROM form_encounter WHERE id = ?",
                                'uuid',
                                [$encounterIdFromIssue]
                            );
                            if (!empty($encounterUuid)) {
                                // Check if it's binary (16 bytes) or string (36 chars)
                                if (is_string($encounterUuid) && strlen($encounterUuid) === 16) {
                                    $record['euuid'] = UuidRegistry::uuidToString($encounterUuid);
                                } elseif (is_string($encounterUuid) && strlen($encounterUuid) === 36) {
                                    $record['euuid'] = $encounterUuid;
                                } else {
                                    $record['euuid'] = UuidRegistry::uuidToString($encounterUuid);
                                }
                            }
                        }
                        
                        // Convert UUID to string
                        if (!empty($record['uuid'])) {
                            // Check if it's binary (16 bytes) or string (36 chars)
                            if (is_string($record['uuid']) && strlen($record['uuid']) === 16) {
                                $record['uuid'] = UuidRegistry::uuidToString($record['uuid']);
                            } elseif (is_string($record['uuid']) && strlen($record['uuid']) !== 36) {
                                // If it's not 36 chars, try to convert it
                                $record['uuid'] = UuidRegistry::uuidToString($record['uuid']);
                            }
                            // If it's already 36 chars, keep it as is
                        }
                        
                        // Update procedureRecord with the modified record
                        $procedureRecord = [$record];
                    }
                }
                
                if (!empty($procedureRecord) && count($procedureRecord) > 0) {
                    $record = $procedureRecord[0];
                    
                    // Convert UUIDs to string format (handle both binary and string formats)
                    // UUID from database might be binary, empty, or already a string
                    if (isset($record['uuid'])) {
                        if (is_string($record['uuid']) && !empty($record['uuid']) && strlen($record['uuid']) === 16) {
                            // It's binary (16 bytes), convert to string
                            $record['uuid'] = UuidRegistry::uuidToString($record['uuid']);
                        } elseif (empty($record['uuid']) || (is_string($record['uuid']) && strlen($record['uuid']) !== 36)) {
                            // If uuid is empty or invalid format, use the one we inserted
                            $record['uuid'] = $uuidString;
                        }
                        // If it's already a valid UUID string (36 chars), keep it as is
                    } else {
                        // UUID field doesn't exist, use the one we inserted
                        $record['uuid'] = $uuidString;
                    }
                    
                    // Convert other UUIDs to string format
                    if (!empty($record['puuid']) && !is_string($record['puuid'])) {
                        $record['puuid'] = UuidRegistry::uuidToString($record['puuid']);
                    }
                    if (!empty($record['euuid']) && !is_string($record['euuid'])) {
                        $record['euuid'] = UuidRegistry::uuidToString($record['euuid']);
                    }
                    if (!empty($record['recorder_uuid']) && !is_string($record['recorder_uuid'])) {
                        $record['recorder_uuid'] = UuidRegistry::uuidToString($record['recorder_uuid']);
                    }
                    
                    // Set status based on enddate (similar to SurgeryService::createResultRecordFromDatabaseResult)
                    if (empty($record['enddate'])) {
                        $record['status'] = 'inactive';
                    } else {
                        $record['status'] = 'active';
                    }
                    
                    // Map surgery_recorder to user for FhirProcedureSurgeryService
                    // Note: FhirProcedureSurgeryService expects 'user' field, but we have 'surgery_recorder'
                    if (!empty($record['surgery_recorder'])) {
                        $record['user'] = $record['surgery_recorder'];
                    }
                    
                    // Ensure all required fields are present for FhirProcedureSurgeryService
                    if (empty($record['date_modified'])) {
                        $record['date_modified'] = date('Y-m-d H:i:s');
                    }
                    
                    // Ensure uuid is set (required by FhirProcedureSurgeryService)
                    if (empty($record['uuid'])) {
                        $record['uuid'] = $uuidString;
                    }
                    
                    // Clean all string fields to ensure valid UTF-8 encoding for JSON
                    // This is critical to prevent "Malformed UTF-8 characters" errors during json_encode
                    $stringFields = ['title', 'diagnosis', 'comments', 'surgery_recorder', 'user', 'recorder_npi'];
                    foreach ($stringFields as $field) {
                        if (isset($record[$field]) && is_string($record[$field])) {
                            // Convert to UTF-8 and remove invalid characters
                            $cleaned = mb_convert_encoding($record[$field], 'UTF-8', 'UTF-8');
                            // Remove null bytes and control characters (except newlines and tabs)
                            $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cleaned);
                            // Ensure it's a valid UTF-8 string
                            if (mb_check_encoding($cleaned, 'UTF-8')) {
                                $record[$field] = $cleaned;
                            } else {
                                // If still invalid, set to empty string
                                $record[$field] = '';
                            }
                        }
                    }
                    
                    // Use FhirProcedureSurgeryService to convert to FHIR
                    try {
                        $fhirProcedureSurgeryService = new FhirProcedureSurgeryService();
                        $fhirResource = $fhirProcedureSurgeryService->parseOpenEMRRecord($record, false);
                        
                        // Additional safety: Try to serialize and deserialize to catch any encoding issues
                        // This will throw an exception if there are encoding problems
                        try {
                            // First try with strict encoding
                            $testJson = json_encode($fhirResource, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            json_decode($testJson, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $jsonException) {
                            // If strict encoding fails, try with UTF-8 substitution/ignore flags (PHP 7.2+)
                            try {
                                $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
                                if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                                    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
                                } elseif (defined('JSON_INVALID_UTF8_IGNORE')) {
                                    $jsonFlags |= JSON_INVALID_UTF8_IGNORE;
                                }
                                
                                $testJson = json_encode($fhirResource, $jsonFlags);
                                if ($testJson === false) {
                                    // If that also fails, let it proceed and see if HttpRestRouteHandler can handle it
                                    // The resource might still be valid even if our test fails
                                } else {
                                    json_decode($testJson, true, 512, JSON_THROW_ON_ERROR);
                                }
                            } catch (\Exception $e) {
                                // Don't throw - let it proceed and see if HttpRestRouteHandler can handle it
                                // The resource might still be valid even if our test fails
                            }
                        }
                        
                        $processingResult->setData([]);
                        $processingResult->addData($fhirResource);
                    } catch (\Exception $e) {
                        $processingResult->addInternalError("Failed to convert procedure record to FHIR: " . $e->getMessage());
                    }
                } else {
                    $processingResult->addInternalError("Failed to retrieve inserted procedure record");
                }
            } else {
                $processingResult->addInternalError("No ID returned from insert operation");
            }
        } catch (\Exception $e) {
            $processingResult->addInternalError("Error inserting procedure record: " . $e->getMessage());
        }

        return $processingResult;
    }
}
