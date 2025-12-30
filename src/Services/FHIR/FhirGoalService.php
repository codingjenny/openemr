<?php

/**
 * FhirGoalService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRGoal;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDate;
use OpenEMR\FHIR\R4\FHIRElement\FHIRGoalLifecycleStatus;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRGoal\FHIRGoalTarget;
use OpenEMR\Services\CarePlanService;
use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;

class FhirGoalService extends FhirServiceBase implements IResourceUSCIGProfileService, IFhirExportableResourceService, IPatientCompartmentResourceService
{
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    /**
     * @var CarePlanService
     */
    private $service;

    /**
     * @var EncounterService
     */
    private $encounterService;

    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-goal';

    public function __construct()
    {
        parent::__construct();
        // goals are stored inside the care plan forms
        $this->service = new CarePlanService(CarePlanService::TYPE_GOAL);
        $this->encounterService = new EncounterService();
    }

    /**
     * Returns an array mapping FHIR Resource search parameters to OpenEMR search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            // note even though we label this as a uuid, it is a SURROGATE UID because of the nature of how goals are stored
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, ['uuid']),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        // TODO: @adunsulag introduce a last_modified date field to the care plan table as we don't track this anywhere
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['creation_date']);
    }

    /**
     * Parses an OpenEMR careTeam record, returning the equivalent FHIR CareTeam Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRGoal
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $goal = new FHIRGoal();

        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId('1');
        if (!empty($dataRecord['creation_date'])) {
            $fhirMeta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['creation_date']));
        } else {
            $fhirMeta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $goal->setMeta($fhirMeta);

        $fhirId = new FHIRId();
        $fhirId->setValue($dataRecord['uuid']);
        $goal->setId($fhirId);

        if (isset($dataRecord['puuid'])) {
            $goal->setSubject(UtilsService::createRelativeReference("Patient", $dataRecord['puuid']));
        } else {
            $goal->setSubject(UtilsService::createDataMissingExtension());
        }

        $lifecycleStatus = new FHIRGoalLifecycleStatus();
        $lifecycleStatus->setValue("active");
        $goal->setLifecycleStatus($lifecycleStatus);

        if (!empty($dataRecord['provider_uuid']) && !empty($dataRecord['provider_npi'])) {
            $goal->setExpressedBy(UtilsService::createRelativeReference("Practitioner", $dataRecord['provider_uuid']));
        }


        // ONC only requires a descriptive text.  Future FHIR implementors can grab these details and populate the
        // activity element if they so choose, for now we just return the combined description of the care plan.
        if (!empty($dataRecord['details'])) {
            $text = $this->getGoalTextFromDetails($dataRecord['details']);
            $codeableConcept = new FHIRCodeableConcept();
            $codeableConcept->setText($text['text']);
            $goal->setDescription($codeableConcept);

            $codeTypeService = new CodeTypesService();
            foreach ($dataRecord['details'] as $detail) {
                $fhirGoalTarget = new FHIRGoalTarget();
                if (!empty($detail['date'])) {
                    $fhirDate = new FHIRDate();
                    $parsedDateTime = \DateTime::createFromFormat("Y-m-d H:i:s", $detail['date'], new \DateTimeZone(date('P')));
                    $fhirDate->setValue($parsedDateTime->format("Y-m-d"));
                    $fhirGoalTarget->setDueDate($fhirDate);
                } else {
                    $fhirGoalTarget->setDueDate(UtilsService::createDataMissingExtension());
                }
                $detailDescription = trim($detail['description'] ?? "");
                if (!empty($detailDescription)) {
                    // if description is populated we also have to populate the measure with the correct code
                    $fhirGoalTarget->setDetailString($detailDescription);

                    if (!empty($detail['code'])) {
                        $codeText = $codeTypeService->lookup_code_description($detail['code']);
                        $codeSystem = $codeTypeService->getSystemForCode($detail['code']);

                        $targetCodeableConcept = new FHIRCodeableConcept();
                        $coding = new FhirCoding();
                        $coding->setCode($detail['code']);
                        if (empty($codeText)) {
                            $coding->setDisplay(UtilsService::createDataMissingExtension());
                        } else {
                            $coding->setDisplay(xlt($codeText));
                        }

                        $coding->setSystem($codeSystem); // these should always be LOINC but we want this generic
                        $targetCodeableConcept->addCoding($coding);
                        $fhirGoalTarget->setMeasure($targetCodeableConcept);
                    } else {
                        $fhirGoalTarget->setMeasure(UtilsService::createDataMissingExtension());
                    }
                }
                $goal->addTarget($fhirGoalTarget);
            }
        }

        if ($encode) {
            return json_encode($goal);
        } else {
            return $goal;
        }
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
        return $this->service->search($openEMRSearchParameters, true, $puuidBind);
    }

    public function createProvenanceResource($dataRecord, $encode = false)
    {
        if (!($dataRecord instanceof FHIRGoal)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $provenanceService = new FhirProvenanceService();
        $provenance = $provenanceService->createProvenanceForDomainResource($dataRecord, $dataRecord->getExpressedBy());
        return $provenance;
    }

    public function getProfileURIs(): array
    {
        return [self::USCGI_PROFILE_URI];
    }

    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::REFERENCE, [new ServiceField('puuid', ServiceField::TYPE_UUID)]);
    }

    private function getGoalTextFromDetails($details)
    {
        $descriptions = [];
        foreach ($details as $detail) {
            // use description or fallback on codetext if needed
            $descriptions[] = $detail['description'] ?? $detail['codetext'] ?? "";
        }
        $carePlanText = ['text' => trim(implode("\n", $descriptions)), "xhtml" => ""];
        if (!empty($descriptions)) {
            $carePlanText['xhtml'] = "<p>" . implode("</p><p>", $descriptions) . "</p>";
        }
        return $carePlanText;
    }

    /**
     * Inserts a FHIR resource into the system with error handling
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource The FHIR resource
     * @return ProcessingResult The OpenEMR Service Result
     */
    public function insert(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource): ProcessingResult
    {
        error_log("FhirGoalService::insert() - START");
        $processingResult = new ProcessingResult();
        
        try {
            $openEmrRecord = $this->parseFhirResource($fhirResource);
            error_log("FhirGoalService::insert() - parseFhirResource completed, keys: " . implode(', ', array_keys($openEmrRecord ?? [])));
            
            if (empty($openEmrRecord)) {
                error_log("FhirGoalService::insert() - parseFhirResource returned empty array");
                $processingResult->addInternalError("parseFhirResource returned empty array");
                return $processingResult;
            }
            
            return $this->insertOpenEMRRecord($openEmrRecord);
        } catch (\Throwable $e) {
            error_log("FhirGoalService::insert() - EXCEPTION in parseFhirResource: " . $e->getMessage());
            error_log("FhirGoalService::insert() - TRACE: " . $e->getTraceAsString());
            $processingResult->addInternalError("Error parsing FHIR Goal resource: " . $e->getMessage());
            $processingResult->addInternalError("Stack trace: " . $e->getTraceAsString());
            return $processingResult;
        }
    }

    /**
     * Parses a FHIR Goal resource into an OpenEMR record structure suitable for CarePlanService.
     *
     * We map only the minimal fields needed to create a Goal Form:
     * - subject -> patient (puuid/pid)
     * - description -> details[]
     * - target.dueDate -> details[].date
     * - target.detailString -> details[].description
     * - expressedBy -> care_plan_user (username)
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource
     * @return array
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        if (!($fhirResource instanceof FHIRGoal)) {
            throw new \BadMethodCallException("Resource expected to be of type " . FHIRGoal::class . " but instead was of type " . get_class($fhirResource));
        }

        error_log("FhirGoalService::parseFhirResource() - START");
        
        $data = [];

        // Subject -> patient uuid (puuid) / pid
        $subject = $fhirResource->getSubject();
        error_log("FhirGoalService::parseFhirResource() - subject: " . json_encode($subject));
        if (!empty($subject)) {
            $subjectReference = UtilsService::parseReference($subject);
            if (!empty($subjectReference) && $subjectReference['type'] === 'Patient' && $subjectReference['localResource']) {
                $patientUuid = $subjectReference['uuid'];
            } else {
                // Fallback: handle array / plain reference string like "Patient/{uuid}"
                $referenceString = null;
                if (is_object($subject) && method_exists($subject, 'getReference')) {
                    $referenceString = $subject->getReference();
                } elseif (is_array($subject)) {
                    $referenceString = $subject['reference'] ?? null;
                }
                if (!empty($referenceString)) {
                    $parts = explode('/', $referenceString);
                    if (count($parts) >= 2 && $parts[0] === 'Patient') {
                        $patientUuid = $parts[1];
                    }
                }
            }

            if (!empty($patientUuid)) {
                error_log("FhirGoalService::parseFhirResource() - patientUuid: " . $patientUuid);
                $patientData = QueryUtils::fetchRecords(
                    "SELECT pid FROM patient_data WHERE uuid = ?",
                    [UuidRegistry::uuidToBytes($patientUuid)]
                );
                error_log("FhirGoalService::parseFhirResource() - patientData: " . json_encode($patientData));
                if (!empty($patientData) && !empty($patientData[0])) {
                    $data['pid'] = $patientData[0]['pid'];
                    $data['puuid'] = $patientUuid;
                    error_log("FhirGoalService::parseFhirResource() - found pid: " . $data['pid']);
                } else {
                    error_log("FhirGoalService::parseFhirResource() - patient NOT found in DB!");
                }
            } else {
                error_log("FhirGoalService::parseFhirResource() - patientUuid is empty!");
            }
        }

        // Description -> details[]
        $details = [];
        
        // Main description
        $description = $fhirResource->getDescription();
        if (!empty($description)) {
            $descText = '';
            if (is_object($description) && method_exists($description, 'getText')) {
                $descText = $description->getText();
            } elseif (is_string($description)) {
                $descText = $description;
            }
            
            if (!empty($descText)) {
                $details[] = [
                    'description' => $descText,
                    'codetext' => null,
                    'code' => null,
                    'date' => date('Y-m-d'),
                    'moodCode' => null
                ];
            }
        }

        // Target -> details[]
        $targets = $fhirResource->getTarget();
        if (!empty($targets) && is_array($targets)) {
            foreach ($targets as $target) {
                if ($target instanceof FHIRGoalTarget) {
                    $targetDetail = [];
                    
                    // dueDate
                    $dueDate = $target->getDueDate();
                    if (!empty($dueDate)) {
                        $dateValue = null;
                        if (is_object($dueDate) && method_exists($dueDate, 'getValue')) {
                            $dateValue = $dueDate->getValue();
                        } elseif (is_string($dueDate)) {
                            $dateValue = $dueDate;
                        }
                        if (!empty($dateValue)) {
                            $targetDetail['date'] = date('Y-m-d', strtotime($dateValue));
                        }
                    }
                    
                    // detailString
                    $detailString = $target->getDetailString();
                    if (!empty($detailString)) {
                        $targetDetail['description'] = (string)$detailString;
                    }
                    
                    // measure (code)
                    $measure = $target->getMeasure();
                    if (!empty($measure) && is_object($measure) && method_exists($measure, 'getCoding')) {
                        $codings = $measure->getCoding();
                        if (!empty($codings) && is_array($codings) && !empty($codings[0])) {
                            $coding = $codings[0];
                            if (method_exists($coding, 'getCode')) {
                                $targetDetail['code'] = $coding->getCode();
                            }
                            if (method_exists($coding, 'getDisplay')) {
                                $targetDetail['codetext'] = $coding->getDisplay();
                            }
                        }
                    }
                    
                    if (!empty($targetDetail['description'])) {
                        $targetDetail['codetext'] = $targetDetail['codetext'] ?? null;
                        $targetDetail['code'] = $targetDetail['code'] ?? null;
                        $targetDetail['date'] = $targetDetail['date'] ?? date('Y-m-d');
                        $targetDetail['moodCode'] = null;
                        $details[] = $targetDetail;
                    }
                }
            }
        }

        if (!empty($details)) {
            $data['details'] = $details;
        }

        // ExpressedBy -> care plan user (username)
        $username = null;
        $expressedBy = $fhirResource->getExpressedBy();
        if (!empty($expressedBy)) {
            $expressedByRef = UtilsService::parseReference($expressedBy);
            if (!empty($expressedByRef) && $expressedByRef['type'] === 'Practitioner' && $expressedByRef['localResource']) {
                $practUuid = $expressedByRef['uuid'];
                $userData = QueryUtils::fetchRecords(
                    "SELECT username FROM users WHERE uuid = ?",
                    [UuidRegistry::uuidToBytes($practUuid)]
                );
                if (!empty($userData) && !empty($userData[0]['username'])) {
                    $username = $userData[0]['username'];
                }
            }
        }
        if (empty($username) && isset($_SESSION['authUser'])) {
            $username = $_SESSION['authUser'];
        }
        if (!empty($username)) {
            $data['care_plan_user'] = $username;
        }

        // Goal type (use TYPE_GOAL)
        $data['care_plan_type'] = CarePlanService::TYPE_GOAL;
        
        // Default date if not set
        if (empty($data['date'])) {
            $data['date'] = date('Y-m-d');
        }

        error_log("FhirGoalService::parseFhirResource() - final data keys: " . implode(', ', array_keys($data)));
        return $data;
    }

    /**
     * Inserts an OpenEMR Goal record into the system by creating one Care Plan Form
     * with one or more form_care_plan rows, then linking it in forms table.
     *
     * @param array $openEmrRecord
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        error_log("FhirGoalService::insertOpenEMRRecord() - START");
        error_log("FhirGoalService::insertOpenEMRRecord() - openEmrRecord keys: " . implode(', ', array_keys($openEmrRecord ?? [])));
        
        $processingResult = new ProcessingResult();

        try {
            if (empty($openEmrRecord)) {
                error_log("FhirGoalService::insertOpenEMRRecord() - openEmrRecord is EMPTY");
                $processingResult->addInternalError("openEmrRecord is empty");
                return $processingResult;
            }
            
            // Validate required fields
            if (empty($openEmrRecord['pid'])) {
                $diagnostics = "Patient ID is required for Goal. Received keys: " . implode(', ', array_keys($openEmrRecord));
                if (isset($openEmrRecord['puuid'])) {
                    $diagnostics .= " | puuid present: " . $openEmrRecord['puuid'];
                }
                error_log("FhirGoalService::insertOpenEMRRecord() - MISSING PID: " . $diagnostics);
                $processingResult->setValidationMessages(['pid' => $diagnostics]);
                return $processingResult;
            }
            
            error_log("FhirGoalService::insertOpenEMRRecord() - pid found: " . $openEmrRecord['pid']);

            // Determine encounter for the goal
            $pid = $openEmrRecord['pid'];
            $planDate = $openEmrRecord['date'] ?? date('Y-m-d');

            // Try to find an encounter on the same day
            $encounter = null;
            $encounters = QueryUtils::fetchRecords(
                "SELECT encounter FROM form_encounter WHERE pid = ? AND DATE(date) = ? ORDER BY id DESC LIMIT 1",
                [$pid, $planDate]
            );
            if (!empty($encounters) && !empty($encounters[0]['encounter'])) {
                $encounter = $encounters[0]['encounter'];
            } elseif (isset($_SESSION['encounter'])) {
                $encounter = $_SESSION['encounter'];
            }

            if (empty($encounter)) {
                // No existing encounter; create a default one for this Goal
                error_log("FhirGoalService::insertOpenEMRRecord() - No encounter found, attempting to create default");
                $encounter = $this->createDefaultEncounterForGoal($pid, $planDate);
                if (empty($encounter)) {
                    error_log("FhirGoalService::insertOpenEMRRecord() - FAILED: Could not create or find encounter for Goal");
                    $processingResult->setValidationMessages([
                        'encounter' => 'Encounter is required for Goal and could not be created automatically.'
                    ]);
                    return $processingResult;
                }
                error_log("FhirGoalService::insertOpenEMRRecord() - Using default encounter: " . $encounter);
            } else {
                error_log("FhirGoalService::insertOpenEMRRecord() - Using existing encounter: " . $encounter);
            }

            // Generate new form_id for form_care_plan and forms
            $maxIdResult = QueryUtils::fetchRecords("SELECT MAX(id) as largestId FROM form_care_plan");
            $formId = (!empty($maxIdResult) && !empty($maxIdResult[0]['largestId'])) ? ($maxIdResult[0]['largestId'] + 1) : 1;

            // Insert one or more form_care_plan rows based on details[]
            $details = $openEmrRecord['details'] ?? [];
            if (empty($details)) {
                // If no details, create single generic row from description
                $details = [[
                    'description' => 'Goal',
                    'codetext' => null,
                    'code' => null,
                    'date' => $planDate,
                    'moodCode' => null
                ]];
            }

            foreach ($details as $detail) {
                $desc = $detail['description'] ?? $detail['codetext'] ?? '';
                if ($desc === '' && empty($detail['code'])) {
                    continue; // skip empty rows
                }

                QueryUtils::sqlStatementThrowException(
                    "INSERT INTO form_care_plan (`id`,`pid`,`groupname`,`user`,`encounter`,`activity`,`code`,`codetext`,`description`,`date`,`care_plan_type`) 
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $formId,
                        $pid,
                        $_SESSION['authProvider'] ?? '',
                        $openEmrRecord['care_plan_user'] ?? ($_SESSION['authUser'] ?? ''),
                        $encounter,
                        1,
                        $detail['code'] ?? '',
                        $detail['codetext'] ?? '',
                        $desc,
                        $detail['date'] ?? $planDate,
                        CarePlanService::TYPE_GOAL
                    ]
                );
            }

            // Link form in forms table if not already present
            QueryUtils::sqlStatementThrowException(
                "INSERT INTO forms (date,encounter,form_name,form_id,pid,user,groupname,formdir) VALUES (?,?,?,?,?,?,?,?)",
                [
                    date('Y-m-d'),
                    $encounter,
                    'Care Plan Form',
                    $formId,
                    $pid,
                    $_SESSION['authUser'] ?? '',
                    $_SESSION['authProvider'] ?? '',
                    'care_plan'
                ]
            );

            // Now fetch back the aggregated record through CarePlanService to keep semantics identical to GET
            $search = [
                'form_id' => $formId
            ];
            error_log("FhirGoalService::insertOpenEMRRecord() - searching for form_id: " . $formId);
            $result = $this->service->search($search, true);
            error_log("FhirGoalService::insertOpenEMRRecord() - search result: isValid=" . ($result->isValid() ? 'true' : 'false') . ", hasData=" . ($result->hasData() ? 'true' : 'false'));
            
            if ($result->isValid() && $result->hasData()) {
                $record = $result->getData()[0];
                error_log("FhirGoalService::insertOpenEMRRecord() - found record, creating FHIR resource");
                $fhirResource = $this->parseOpenEMRRecord($record, false);
                $processingResult->addData($fhirResource);
                error_log("FhirGoalService::insertOpenEMRRecord() - SUCCESS! FHIR resource added to processingResult");
            } else {
                $diagnostics = "Goal form_id=$formId created but could not be reloaded. ";
                $diagnostics .= "isValid=" . ($result->isValid() ? 'true' : 'false') . ", ";
                $diagnostics .= "hasData=" . ($result->hasData() ? 'true' : 'false');
                error_log("FhirGoalService::insertOpenEMRRecord() - FAILED to reload: " . $diagnostics);
                $processingResult->addInternalError($diagnostics);
            }

            error_log("FhirGoalService::insertOpenEMRRecord() - returning processingResult: hasData=" . ($processingResult->hasData() ? 'true' : 'false') . ", hasErrors=" . ($processingResult->hasErrors() ? 'true' : 'false'));
            return $processingResult;
        } catch (\Throwable $e) {
            error_log("FhirGoalService::insertOpenEMRRecord() - EXCEPTION: " . $e->getMessage());
            error_log("FhirGoalService::insertOpenEMRRecord() - TRACE: " . $e->getTraceAsString());
            $processingResult->addInternalError("Error inserting Goal: " . $e->getMessage());
            return $processingResult;
        }
    }

    /**
     * Creates a default encounter for a Goal when none is provided.
     * This follows the same pattern as FhirCarePlanService::createDefaultEncounterForCarePlan().
     *
     * @param int $pid
     * @param string $planDate Y-m-d
     * @return int|null encounter id
     */
    private function createDefaultEncounterForGoal($pid, $planDate)
    {
        try {
            // Get patient UUID (required for EncounterService)
            $patientService = new PatientService();
            $patient = $patientService->findByPid($pid);
            if (empty($patient) || empty($patient['uuid'])) {
                error_log("FhirGoalService::createDefaultEncounterForGoal() - Patient not found or missing UUID");
                return null;
            }
            $puuid = UuidRegistry::uuidToString($patient['uuid']);

            $today = $planDate ?: date('Y-m-d');
            $userId = $_SESSION['authUserID'] ?? 1;
            $userName = $_SESSION['authUser'] ?? 'admin';
            $userGroup = $_SESSION['authProvider'] ?? 'Default';

            // Resolve facility
            $facilityService = new FacilityService();
            $facility_id = null;
            $facility = null;
            $billing_facility = null;

            if ($userId > 0) {
                $userFacility = $facilityService->getFacilityForUser($userId);
                if (!empty($userFacility)) {
                    $facility_id = $userFacility['id'] ?? null;
                    $facility = $userFacility['name'] ?? null;
                    $billing_facility = $userFacility['id'] ?? null;
                }
            }
            if (empty($facility_id)) {
                $primaryFacility = $facilityService->getPrimaryBusinessEntity();
                if (!empty($primaryFacility)) {
                    $facility_id = $primaryFacility['id'] ?? null;
                    $facility = $primaryFacility['name'] ?? null;
                    $billing_facility = $primaryFacility['id'] ?? null;
                }
            }

            $pos_code = null;
            if (!empty($facility_id)) {
                $pos_code = $this->encounterService->getPosCode($facility_id);
            }

            // Get default class_code
            $listService = new ListService();
            $class_code = null;
            $classOptions = $listService->getOptionsByListName('_ActEncounterCode');
            if (!empty($classOptions)) {
                foreach ($classOptions as $code) {
                    if (!empty($code['is_default'])) {
                        $class_code = $code['option_id'];
                        break;
                    }
                }
                if (empty($class_code) && !empty($classOptions[0])) {
                    $class_code = $classOptions[0]['option_id'];
                }
            }
            if (empty($class_code)) {
                $class_code = EncounterService::DEFAULT_CLASS_CODE; // 'AMB'
            }

            // Get default visit category (pc_catid)
            $pc_catid = null;
            $visitCategories = QueryUtils::fetchRecords(
                "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_active = 1 ORDER BY pc_catid LIMIT 1",
                []
            );
            if (!empty($visitCategories) && !empty($visitCategories[0]['pc_catid'])) {
                $pc_catid = $visitCategories[0]['pc_catid'];
            }

            $encounterData = [
                'date' => $today,
                'reason' => 'FHIR Goal Entry',
                'facility_id' => $facility_id,
                'facility' => $facility,
                'billing_facility' => $billing_facility,
                'class_code' => $class_code,
                'pc_catid' => $pc_catid,
                'pos_code' => $pos_code,
                'provider_id' => $userId,
                'user' => $userName,
                'group' => $userGroup,
            ];

            error_log("FhirGoalService::createDefaultEncounterForGoal() - Creating encounter with data: " . json_encode($encounterData));
            $encounterResult = $this->encounterService->insertEncounter($puuid, $encounterData);
            if ($encounterResult->isValid() && $encounterResult->hasData()) {
                $encounterRow = $encounterResult->getFirstDataResult();
                $encounterId = $encounterRow['eid'] ?? $encounterRow['encounter'] ?? null;
                error_log("FhirGoalService::createDefaultEncounterForGoal() - SUCCESS: Created encounter " . $encounterId);
                return $encounterId;
            } else {
                error_log("FhirGoalService::createDefaultEncounterForGoal() - FAILED: EncounterService returned invalid result");
            }
        } catch (\Throwable $e) {
            error_log("FhirGoalService::createDefaultEncounterForGoal() - EXCEPTION: " . $e->getMessage());
            error_log("FhirGoalService::createDefaultEncounterForGoal() - TRACE: " . $e->getTraceAsString());
        }

        return null;
    }

    /**
     * Updates an OpenEMR record
     * @param $fhirResourceId The FHIR Resource ID used to lookup the existing FHIR resource/OpenEMR record
     * @param $updatedOpenEMRRecord The OpenEMR record
     * @return ProcessingResult The OpenEMR processing result.
     */
    protected function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord): ProcessingResult
    {
        $processingResult = new ProcessingResult();
        $processingResult->addInternalError("updateOpenEMRRecord not implemented for Goal");
        return $processingResult;
    }
}
