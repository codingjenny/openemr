<?php

/**
 * FhirCarePlanService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCarePlan;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRElement\FHIRNarrative;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCarePlan\FHIRCarePlanActivity;
use OpenEMR\Services\CarePlanService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientService;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

class FhirCarePlanService extends FhirServiceBase implements IResourceUSCIGProfileService, IPatientCompartmentResourceService, IFhirExportableResourceService
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

    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-careplan';

    public function __construct()
    {
        parent::__construct();
        $this->service = new CarePlanService();
        $this->encounterService = new EncounterService();
    }

    /**
     * Returns an array mapping FHIR CarePlan Resource search parameters to OpenEMR CarePlan search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'category' => new FhirSearchParameterDefinition('status', SearchFieldType::TOKEN, ['careplan_category']),
            // note even though we label this as a uuid, it is a SURROGATE UID because of the nature of CarePlan
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
     * Parses an OpenEMR record, returning the equivalent FHIR Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRCarePlan
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $carePlanResource = new FHIRCarePlan();

        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId('1');
        if (!empty($dataRecord['creation_date'])) {
            $fhirMeta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['creation_date']));
        } else {
            $fhirMeta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $carePlanResource->setMeta($fhirMeta);

        $fhirId = new FHIRId();
        $fhirId->setValue($dataRecord['uuid']);
        $carePlanResource->setId($fhirId);

        if (isset($dataRecord['puuid'])) {
            $carePlanResource->setSubject(UtilsService::createRelativeReference("Patient", $dataRecord['puuid']));
        } else {
            $carePlanResource->setSubject(UtilsService::createDataMissingExtension());
        }

        $codeableConcept = new FHIRCodeableConcept();
        $coding = new FHIRCoding();
        $coding->setCode("assess-plan");
        $coding->setSystem(FhirCodeSystemConstants::HL7_SYSTEM_CAREPLAN_CATEGORY);
        $codeableConcept->addCoding($coding);
        $carePlanResource->addCategory($codeableConcept);

        $carePlanResource->setIntent("plan");
        $carePlanResource->setStatus("active");

        // TODO: our care plan reason codes would go inside an activity's reasonCode property.
        //  Right now we don't generate activities, but this is what we would add here if we start including care plan activities.

        // ONC only requires a descriptive text.  Future FHIR implementors can grab these details and populate the
        // activity element if they so choose, for now we just return the combined description of the care plan.
        if (!empty($dataRecord['details'])) {
            $carePlanText = $this->getCarePlanTextFromDetails($dataRecord['details']);
            $carePlanResource->setDescription($carePlanText['text']);

            // since we pull the text from the description status is generated, if we had additional info we would
            // set status to 'additional'
            $narrative = new FHIRNarrative();
            $narrative->setStatus("generated");
            $narrative->setDiv('<div xmlns="http://www.w3.org/1999/xhtml">' . $carePlanText['xhtml'] . '</div>');
            $carePlanResource->setText($narrative);
        } else {
            $carePlanResource->setText(UtilsService::createDataMissingExtension());
        }

        if (!empty($dataRecord['provider_uuid']) && !empty($dataRecord['provider_npi'])) {
            $carePlanResource->setAuthor(UtilsService::createRelativeReference("Practitioner", $dataRecord['provider_uuid']));
        }

        if ($encode) {
            return json_encode($carePlanResource);
        } else {
            return $carePlanResource;
        }
    }

    /**
     * Parses a FHIR CarePlan resource into an OpenEMR record structure suitable for CarePlanService.
     *
     * We map only the minimal fields needed to create a Care Plan Form:
     * - subject -> patient (puuid/pid)
     * - period.start / period.end -> date / date_end
     * - description / activity.detail.description -> details[]
     * - author / performer -> care_plan_user (username)
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource
     * @return array
     */
    public function parseFhirResource(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource)
    {
        if (!($fhirResource instanceof FHIRCarePlan)) {
            throw new \BadMethodCallException("Resource expected to be of type " . FHIRCarePlan::class . " but instead was of type " . get_class($fhirResource));
        }

        error_log("FhirCarePlanService::parseFhirResource() called");
        
        $data = [];

        // Subject -> patient uuid (puuid) / pid
        $subject = $fhirResource->getSubject();
        error_log("FhirCarePlanService::parseFhirResource() - subject: " . json_encode($subject));
        if (!empty($subject)) {
            // First try using UtilsService::parseReference (for FHIRReference objects)
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
                error_log("FhirCarePlanService::parseFhirResource() - patientUuid: " . $patientUuid);
                $patientData = QueryUtils::fetchRecords(
                    "SELECT pid FROM patient_data WHERE uuid = ?",
                    [UuidRegistry::uuidToBytes($patientUuid)]
                );
                error_log("FhirCarePlanService::parseFhirResource() - patientData: " . json_encode($patientData));
                if (!empty($patientData) && !empty($patientData[0])) {
                    $data['pid'] = $patientData[0]['pid'];
                    $data['puuid'] = $patientUuid;
                    error_log("FhirCarePlanService::parseFhirResource() - found pid: " . $data['pid']);
                } else {
                    error_log("FhirCarePlanService::parseFhirResource() - patient NOT found in DB!");
                }
            } else {
                error_log("FhirCarePlanService::parseFhirResource() - patientUuid is empty!");
            }
        }
        
        error_log("FhirCarePlanService::parseFhirResource() - final data keys: " . implode(', ', array_keys($data)));

        // Period (date, date_end)
        $period = $fhirResource->getPeriod();
        if (!empty($period)) {
            // start
            if (is_object($period) && method_exists($period, 'getStart')) {
                $start = $period->getStart();
                if (!empty($start) && method_exists($start, 'getValue')) {
                    $startValue = $start->getValue();
                    if (!empty($startValue)) {
                        $data['date'] = date('Y-m-d', strtotime($startValue));
                    }
                }
            }
            // end
            if (is_object($period) && method_exists($period, 'getEnd')) {
                $end = $period->getEnd();
                if (!empty($end) && method_exists($end, 'getValue')) {
                    $endValue = $end->getValue();
                    if (!empty($endValue)) {
                        $data['date_end'] = date('Y-m-d H:i:s', strtotime($endValue));
                    }
                }
            }
        }

        // Description / activities -> details[]
        $details = [];

        // Main description
        $description = $fhirResource->getDescription();
        if (!empty($description)) {
            $details[] = [
                'description' => (string)$description,
                'codetext' => null,
                'code' => null,
                'date' => $data['date'] ?? null,
                'moodCode' => null
            ];
        }

        // Activities detail.description
        $activities = $fhirResource->getActivity();
        if (!empty($activities) && is_array($activities)) {
            foreach ($activities as $activity) {
                if ($activity instanceof FHIRCarePlanActivity && method_exists($activity, 'getDetail')) {
                    $detail = $activity->getDetail();
                    if (!empty($detail) && method_exists($detail, 'getDescription')) {
                        $actDesc = $detail->getDescription();
                        $actText = is_object($actDesc) && method_exists($actDesc, 'getValue') ? $actDesc->getValue() : (string)$actDesc;
                        if (!empty($actText)) {
                            $details[] = [
                                'description' => $actText,
                                'codetext' => null,
                                'code' => null,
                                'date' => $data['date'] ?? null,
                                'moodCode' => null
                            ];
                        }
                    }
                }
            }
        }

        if (!empty($details)) {
            $data['details'] = $details;
        }

        // Author / performer -> care plan user (username)
        $username = null;
        $author = $fhirResource->getAuthor();
        if (!empty($author)) {
            // Author is array of references; use first
            $firstAuthor = is_array($author) ? reset($author) : $author;
            $authorRef = UtilsService::parseReference($firstAuthor);
            if (!empty($authorRef) && $authorRef['type'] === 'Practitioner' && $authorRef['localResource']) {
                $practUuid = $authorRef['uuid'];
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

        // Care plan type (use plan_of_care for now)
        $data['care_plan_type'] = CarePlanService::TYPE_PLAN_OF_CARE;

        return $data;
    }

    /**
     * Inserts an OpenEMR CarePlan record into the system by creating one Care Plan Form
     * with one or more form_care_plan rows, then linking it in forms table.
     *
     * @param array $openEmrRecord
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();

        try {
            error_log("FhirCarePlanService::insertOpenEMRRecord() called with keys: " . implode(', ', array_keys($openEmrRecord ?? [])));
            
            // Debug: Add diagnostic info to help troubleshoot
            if (empty($openEmrRecord)) {
                error_log("FhirCarePlanService::insertOpenEMRRecord() - openEmrRecord is EMPTY");
                $processingResult->addInternalError("DEBUG: openEmrRecord is empty");
                return $processingResult;
            }
            
            // Validate required fields
            if (empty($openEmrRecord['pid'])) {
                $diagnostics = "Patient ID is required for CarePlan. Received keys: " . implode(', ', array_keys($openEmrRecord));
                if (isset($openEmrRecord['puuid'])) {
                    $diagnostics .= " | puuid present: " . $openEmrRecord['puuid'];
                }
                error_log("FhirCarePlanService::insertOpenEMRRecord() - MISSING PID: " . $diagnostics);
                $processingResult->setValidationMessages(['pid' => $diagnostics]);
                return $processingResult;
            }
            
            error_log("FhirCarePlanService::insertOpenEMRRecord() - pid found: " . $openEmrRecord['pid']);

            // Determine encounter for the care plan
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
                // Fallback to current session encounter if present
                $encounter = $_SESSION['encounter'];
            }

            if (empty($encounter)) {
                // No existing encounter; create a default one for this CarePlan
                $encounter = $this->createDefaultEncounterForCarePlan($pid, $planDate);
                if (empty($encounter)) {
                    $processingResult->setValidationMessages([
                        'encounter' => 'Encounter is required for CarePlan and could not be created automatically.'
                    ]);
                    return $processingResult;
                }
            }

            // Generate new form_id for form_care_plan and forms
            $maxIdResult = QueryUtils::fetchRecords("SELECT MAX(id) as largestId FROM form_care_plan");
            $formId = (!empty($maxIdResult) && !empty($maxIdResult[0]['largestId'])) ? ($maxIdResult[0]['largestId'] + 1) : 1;

            // Insert one or more form_care_plan rows based on details[]
            $details = $openEmrRecord['details'] ?? [];
            if (empty($details)) {
                // If no details, create single generic row from description
                $details = [[
                    'description' => $openEmrRecord['description'] ?? 'Care Plan',
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
                        $openEmrRecord['care_plan_type'] ?? CarePlanService::TYPE_PLAN_OF_CARE
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
            error_log("FhirCarePlanService::insertOpenEMRRecord() - searching for form_id: " . $formId);
            $result = $this->service->search($search, true);
            error_log("FhirCarePlanService::insertOpenEMRRecord() - search result: isValid=" . ($result->isValid() ? 'true' : 'false') . ", hasData=" . ($result->hasData() ? 'true' : 'false'));
            
            if ($result->isValid() && $result->hasData()) {
                $record = $result->getData()[0];
                error_log("FhirCarePlanService::insertOpenEMRRecord() - found record, creating FHIR resource");
                $fhirResource = $this->parseOpenEMRRecord($record, false);
                $processingResult->addData($fhirResource);
                error_log("FhirCarePlanService::insertOpenEMRRecord() - SUCCESS! FHIR resource added to processingResult");
            } else {
                $diagnostics = "CarePlan form_id=$formId created but could not be reloaded. ";
                $diagnostics .= "isValid=" . ($result->isValid() ? 'true' : 'false') . ", ";
                $diagnostics .= "hasData=" . ($result->hasData() ? 'true' : 'false');
                error_log("FhirCarePlanService::insertOpenEMRRecord() - FAILED to reload: " . $diagnostics);
                $processingResult->addInternalError($diagnostics);
            }

            error_log("FhirCarePlanService::insertOpenEMRRecord() - returning processingResult: hasData=" . ($processingResult->hasData() ? 'true' : 'false') . ", hasErrors=" . ($processingResult->hasErrors() ? 'true' : 'false'));
            return $processingResult;
        } catch (\Throwable $e) {
            $processingResult->addInternalError("Error inserting CarePlan: " . $e->getMessage());
            return $processingResult;
        }
    }

    /**
     * Creates a default encounter for a CarePlan when none is provided.
     *
     * @param int $pid
     * @param string $planDate Y-m-d
     * @return int|null encounter id
     */
    private function createDefaultEncounterForCarePlan($pid, $planDate)
    {
        try {
            // Get patient UUID (required for EncounterService)
            $patientService = new PatientService();
            $patient = $patientService->findByPid($pid);
            if (empty($patient) || empty($patient['uuid'])) {
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
                'reason' => 'FHIR CarePlan Entry',
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

            $encounterResult = $this->encounterService->insertEncounter($puuid, $encounterData);
            if ($encounterResult->isValid() && $encounterResult->hasData()) {
                $encounterRow = $encounterResult->getFirstDataResult();
                return $encounterRow['eid'] ?? $encounterRow['encounter'] ?? null;
            }
        } catch (\Throwable $e) {
            // swallow and let caller handle validation error
            return null;
        }

        return null;
    }

    /**
     * Updates an existing OpenEMR CarePlan record.
     * Currently not implemented; CarePlan update via FHIR is not supported.
     */
    protected function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        $processingResult = new ProcessingResult();
        $processingResult->setInternalErrors(['CarePlan update via FHIR is not implemented']);
        return $processingResult;
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
        if (!($dataRecord instanceof FHIRCarePlan)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $provenanceService = new FhirProvenanceService();
        $provenance = $provenanceService->createProvenanceForDomainResource($dataRecord, $dataRecord->getAuthor());
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

    private function getCarePlanTextFromDetails($details)
    {
        $descriptions = [];
        foreach ($details as $detail) {
            // use description or fallback on codetext if needed
            $descriptions[] = $detail['description'] ?? $detail['codetext'] ?? "";
        }
        // make sure we clear any white space out that blows up FHIR validation
        $carePlanText = ['text' => trim(implode("\n", $descriptions)), "xhtml" => ""];
        if (!empty($descriptions)) {
            $carePlanText['xhtml'] = "<p>" . implode("</p><p>", $descriptions) . "</p>";
        }
        return $carePlanText;
    }
}
