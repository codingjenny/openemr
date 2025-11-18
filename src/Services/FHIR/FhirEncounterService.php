<?php

/**
 * FhirEncounterService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @author    Vishnu Yarmaneni <vardhanvishnu@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Stephen Nielson snielson@discoverandchange.com
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020, 2022 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2020 Vishnu Yarmaneni <vardhanvishnu@gmail.com>
 * @copyright Copyright (c) 2021 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2022 Stephen Nielson <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use DateTime;
use OpenEMR\FHIR\R4\FHIRElement\FHIRIdentifier;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterHospitalization;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterLocation;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIREncounter;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCode;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRPeriod;
use OpenEMR\FHIR\R4\FHIRResource\FHIREncounter\FHIREncounterParticipant;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

class FhirEncounterService extends FhirServiceBase implements
    IFhirExportableResourceService,
    IPatientCompartmentResourceService,
    IResourceUSCIGProfileService
{
    use PatientSearchTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    public const ENCOUNTER_STATUS_FINISHED = "finished";

    public const ENCOUNTER_TYPE_CHECK_UP = "185349003";
    public const ENCOUNTER_TYPE_CHECK_UP_DESCRIPTION = "Encounter for check up (procedure)";

    public const ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER = "PPRF";
    public const ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER_TEXT = "Primary Performer";

    public const ENCOUNTER_PARTICIPANT_TYPE_REFERRER = "REF";
    public const ENCOUNTER_PARTICIPANT_TYPE_REFERRER_TEXT = "Referrer";


    /**
     * @var EncounterService
     */
    private $encounterService;

    /**
     * @var SystemLogger
     */
    private $logger;

    public function __construct()
    {
        parent::__construct();
        $this->encounterService = new EncounterService();
        $this->logger = new SystemLogger();
    }

    /**
     * Returns an array mapping FHIR Encounter Resource search parameters to OpenEMR Encounter search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            '_id' => new FhirSearchParameterDefinition(
                '_id',
                SearchFieldType::TOKEN,
                [
                    new ServiceField(
                        'euuid',
                        ServiceField::TYPE_UUID
                    )
                ]
            ),
            'patient' => $this->getPatientContextSearchField(),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date']),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['last_update']);
    }

    /**
     * Parses an OpenEMR patient record, returning the equivalent FHIR Patient Resource
     * https://build.fhir.org/ig/HL7/US-Core-R4/StructureDefinition-us-core-encounter-definitions.html
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIREncounter
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $encounterResource = new FHIREncounter();

        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        $meta->setLastUpdated((new \DateTime($dataRecord['last_update']) )->format(DATE_ATOM)); // stored as utc
        $encounterResource->setMeta($meta);

        $id = new FhirId();
        $id->setValue($dataRecord['euuid']);
        $encounterResource->setId($id);

        // identifier - required
        $identifier = new FHIRIdentifier();
        $identifier->setValue($dataRecord['euuid']);
        // the system is a unique urn
        $identifier->setSystem("urn:uuid:" . strtolower($dataRecord['euuid']));
        $encounterResource->addIdentifier($identifier);

        // status - required
        $status = new FHIRCode(self::ENCOUNTER_STATUS_FINISHED);
        $encounterResource->setStatus($status);

        // class
        if (!empty($dataRecord['class_code'])) {
            $class = new FHIRCoding();
            $class->setSystem(FhirCodeSystemConstants::HL7_V3_ACT_CODE);
            $class->setCode($dataRecord['class_code']);
            $class->setDisplay($dataRecord['class_title']);
            $encounterResource->setClass($class);
        } else {
            $encounterResource->setClass(UtilsService::createDataAbsentUnknownCodeableConcept());
        }

        // TODO: @adunsulag check with @brady.miller and find out if this really is the only possible encounter type
        // ...  it was here originally
        $type = UtilsService::createCodeableConcept(
            [self::ENCOUNTER_TYPE_CHECK_UP => [
                'code' => self::ENCOUNTER_TYPE_CHECK_UP
                , "description" => self::ENCOUNTER_TYPE_CHECK_UP_DESCRIPTION
                , "system" => FhirCodeSystemConstants::SNOMED_CT
            ]]
        );
        $encounterResource->addType($type);

        // subject - required
        if (!empty($dataRecord['puuid'])) {
            $encounterResource->setSubject(UtilsService::createRelativeReference('Patient', $dataRecord['puuid']));
        } else {
            $encounterResource->setSubject(UtilsService::createDataMissingExtension());
        }

        // participant - must support
        if (!empty($dataRecord['provider_uuid'])) {
            $participant = new FHIREncounterParticipant();
            $participant->setIndividual(
                UtilsService::createRelativeReference(
                    "Practitioner",
                    $dataRecord['provider_uuid']
                )
            );
            $period = new FHIRPeriod();
            $period->setStart(UtilsService::getLocalDateAsUTC($dataRecord['date']));
            $participant->setPeriod($period);

            $participantType = UtilsService::createCodeableConcept([
                self::ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER =>
                [
                    'code' => self::ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER
                    ,'description' => self::ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER_TEXT
                    ,'system' => FhirCodeSystemConstants::HL7_PARTICIPATION_TYPE
                ]
            ]);
            $participant->addType($participantType);
            $encounterResource->addParticipant($participant);
        }

        // referring provider
        if (!empty($dataRecord['referrer_uuid'])) {
            $participant = new FHIREncounterParticipant();
            $participant->setIndividual(
                UtilsService::createRelativeReference(
                    "Practitioner",
                    $dataRecord['referrer_uuid']
                )
            );
            $period = new FHIRPeriod();
            $period->setStart(UtilsService::getLocalDateAsUTC($dataRecord['date']));
            $participant->setPeriod($period);

            $participantType = UtilsService::createCodeableConcept([
                self::ENCOUNTER_PARTICIPANT_TYPE_REFERRER =>
                [
                    'code' => self::ENCOUNTER_PARTICIPANT_TYPE_REFERRER
                    ,'description' => self::ENCOUNTER_PARTICIPANT_TYPE_REFERRER_TEXT
                    ,'system' => FhirCodeSystemConstants::HL7_PARTICIPATION_TYPE
                ]
            ]);
            $participant->addType($participantType);
            $encounterResource->addParticipant($participant);
        }

        // period - must support
        if (!empty($dataRecord['date'])) {
            $period = new FHIRPeriod();
            $period->setStart(UtilsService::getLocalDateAsUTC($dataRecord['date']));
            $encounterResource->setPeriod($period);
        }

        // reasonCode - must support OR must support reasonReference
        if (!empty($dataRecord['reason'])) {
            // Note: that we use the encounter textual representation for the reason here which is just fine as ccda
            // uses a textual representation of this.  According to HL7 chat this is just fine as epoch and
            // other systems do it this way
            // @see https://chat.fhir.org/#narrow/stream/179175-argonaut/topic/Encounter.20Reason.20For.20Visit
            // (beware of link rot)
            $reason = new FHIRCodeableConcept();
            $reasonText = $dataRecord['reason'] ?? "";
            $reason->setText(trim($reasonText));
            $encounterResource->addReasonCode($reason);
        }
        // hospitalization - must support

        // hospitalization.dischargeDisposition - must support
        if (!empty($dataRecord['discharge_disposition'])) {
            $code = $dataRecord['discharge_disposition'];
            $text = $dataRecord['discharge_disposition_text'];

            $hospitalization = new FHIREncounterHospitalization();
            $hospitalization->setDischargeDisposition(UtilsService::createCodeableConcept(
                [
                    $code => [
                        'code' => $text,
                        'description' => $text,
                        'system' => FhirCodeSystemConstants::HL7_DISCHARGE_DISPOSITION
                    ]
                ]
            ));
            $encounterResource->setHospitalization($hospitalization);
        }

        // SHALL support either location.location OR serviceProvider
        // however ONC inferno requires both serviceProvider AND location.location
        // location.location - must support
        // serviceProvider - must support
        if (!empty($dataRecord['facility_uuid'])) {
            $encounterResource->setServiceProvider(
                UtilsService::createRelativeReference(
                    'Organization',
                    $dataRecord['facility_uuid']
                )
            );

            // grab the facility location address
            if (!empty($dataRecord['facility_location_uuid'])) {
                $location = new FHIREncounterLocation();
                $location->setLocation(
                    UtilsService::createRelativeReference(
                        "Location",
                        $dataRecord['facility_location_uuid']
                    )
                );
                $encounterResource->addLocation($location);
            }
        }

        if ($encode) {
            return json_encode($encounterResource);
        } else {
            return $encounterResource;
        }
    }

    public function createProvenanceResource($dataRecord = array(), $encode = false)
    {
        if (!($dataRecord instanceof FHIREncounter)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $provenanceService = new FhirProvenanceService();
        $author = null;
        if (!empty($dataRecord->getParticipant())) {
            // grab the first one for author
            $participant = reset($dataRecord->getParticipant());
            $author = $participant->getIndividual() ?? null;
        }
        $provenance = $provenanceService->createProvenanceForDomainResource($dataRecord, $author);
        return $provenance;
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($searchParam, $puuidBind = null): ProcessingResult
    {
        return $this->encounterService->search($searchParam, true, $puuidBind);
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
        return [
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-encounter'
        ];
    }

    /**
     * Parses a FHIR Encounter Resource, returning the equivalent OpenEMR encounter record.
     *
     * @param FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        if (!$fhirResource instanceof FHIREncounter) {
            throw new \BadMethodCallException("Resource expected to be of type " . FHIREncounter::class . " but instead was of type " . get_class($fhirResource));
        }

        $data = array();
        $data['uuid'] = (string)$fhirResource->getId() ?? null;

        // Extract patient reference (required)
        $subject = $fhirResource->getSubject();
        if (!empty($subject)) {
            $subjectReference = UtilsService::parseReference($subject);
            if (!empty($subjectReference) && $subjectReference['type'] === 'Patient' && $subjectReference['localResource']) {
                $patientUuid = $subjectReference['uuid'];
                if (!empty($patientUuid)) {
                    $patientData = QueryUtils::fetchRecords(
                        "SELECT pid FROM patient_data WHERE uuid = ?",
                        [UuidRegistry::uuidToBytes($patientUuid)]
                    );
                    if (!empty($patientData) && !empty($patientData[0])) {
                        $data['pid'] = $patientData[0]['pid'];
                    }
                }
            } else {
                // Fallback: try to get reference string directly and parse it
                $referenceString = null;
                if (is_object($subject) && method_exists($subject, 'getReference')) {
                    $referenceString = $subject->getReference();
                } elseif (is_array($subject)) {
                    $referenceString = $subject['reference'] ?? null;
                }
                
                if (!empty($referenceString)) {
                    // Handle Patient/uuid format
                    if (preg_match('/^Patient\/(.+)$/', $referenceString, $matches)) {
                        $patientUuid = $matches[1];
                        $patientData = QueryUtils::fetchRecords(
                            "SELECT pid FROM patient_data WHERE uuid = ?",
                            [UuidRegistry::uuidToBytes($patientUuid)]
                        );
                        if (!empty($patientData) && !empty($patientData[0])) {
                            $data['pid'] = $patientData[0]['pid'];
                        }
                    }
                }
            }
        }

        // Extract class code (required) - from Encounter.class
        // Note: Encounter.class is FHIRCoding, not FHIRCodeableConcept
        $class = $fhirResource->getClass();
        if (!empty($class)) {
            if (is_object($class) && method_exists($class, 'getCode')) {
                $codeObj = $class->getCode();
                if (!empty($codeObj)) {
                    if (is_object($codeObj) && method_exists($codeObj, 'getValue')) {
                        $data['class_code'] = (string)$codeObj->getValue();
                    } else {
                        $data['class_code'] = (string)$codeObj;
                    }
                }
            } elseif (is_array($class)) {
                // Handle array format (from JSON)
                if (!empty($class['code'])) {
                    $codeValue = $class['code'];
                    if (is_array($codeValue) && isset($codeValue['value'])) {
                        $data['class_code'] = (string)$codeValue['value'];
                    } else {
                        $data['class_code'] = (string)$codeValue;
                    }
                }
            }
        }

        // Extract period/date
        $period = $fhirResource->getPeriod();
        if (!empty($period)) {
            if (is_object($period) && method_exists($period, 'getStart')) {
                $start = $period->getStart();
                if (!empty($start)) {
                    if (is_object($start) && method_exists($start, 'getValue')) {
                        $data['date'] = UtilsService::getLocalDateAsUTC($start->getValue());
                    } else {
                        $data['date'] = UtilsService::getLocalDateAsUTC($start);
                    }
                }
            } elseif (is_array($period)) {
                if (!empty($period['start'])) {
                    $data['date'] = UtilsService::getLocalDateAsUTC($period['start']);
                }
            }
        }
        // Default to today if no date provided
        if (empty($data['date'])) {
            $data['date'] = date('Y-m-d');
        }

        // Extract reason (reasonCode)
        $reasonCodes = $fhirResource->getReasonCode();
        if (!empty($reasonCodes) && is_array($reasonCodes)) {
            $firstReason = $reasonCodes[0];
            if (is_object($firstReason) && method_exists($firstReason, 'getText')) {
                $data['reason'] = (string)$firstReason->getText();
            } elseif (is_array($firstReason)) {
                $data['reason'] = (string)($firstReason['text'] ?? null);
            }
        }

        // Extract facility (serviceProvider)
        $serviceProvider = $fhirResource->getServiceProvider();
        if (!empty($serviceProvider)) {
            $facilityReference = UtilsService::parseReference($serviceProvider);
            if (!empty($facilityReference) && $facilityReference['type'] === 'Organization' && $facilityReference['localResource']) {
                $facilityUuid = $facilityReference['uuid'];
                if (!empty($facilityUuid)) {
                    $facilityData = QueryUtils::fetchRecords(
                        "SELECT id, name FROM facility WHERE uuid = ?",
                        [UuidRegistry::uuidToBytes($facilityUuid)]
                    );
                    if (!empty($facilityData) && !empty($facilityData[0])) {
                        $data['facility_id'] = $facilityData[0]['id'];
                        $data['facility'] = $facilityData[0]['name'];
                        $data['billing_facility'] = $facilityData[0]['id'];
                    }
                }
            }
        }

        // Extract provider (participant with type PPRF - Primary Performer)
        $participants = $fhirResource->getParticipant();
        if (!empty($participants) && is_array($participants)) {
            foreach ($participants as $participant) {
                $types = is_object($participant) && method_exists($participant, 'getType') 
                    ? $participant->getType() 
                    : ($participant['type'] ?? []);
                
                $isPrimaryPerformer = false;
                if (is_array($types)) {
                    foreach ($types as $type) {
                        $codings = is_array($type) ? ($type['coding'] ?? []) : ($type->getCoding() ?? []);
                        foreach ($codings as $coding) {
                            $code = is_array($coding) ? ($coding['code'] ?? null) : (method_exists($coding, 'getCode') ? $coding->getCode() : null);
                            if ($code === self::ENCOUNTER_PARTICIPANT_TYPE_PRIMARY_PERFORMER) {
                                $isPrimaryPerformer = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($isPrimaryPerformer) {
                    $individual = is_object($participant) && method_exists($participant, 'getIndividual')
                        ? $participant->getIndividual()
                        : ($participant['individual'] ?? null);
                    
                    if (!empty($individual)) {
                        $providerReference = UtilsService::parseReference($individual);
                        if (!empty($providerReference) && $providerReference['resourceType'] === 'Practitioner' && $providerReference['localResource']) {
                            $practitionerUuid = $providerReference['uuid'];
                            if (!empty($practitionerUuid)) {
                                $providerData = QueryUtils::fetchRecords(
                                    "SELECT id FROM users WHERE uuid = ?",
                                    [UuidRegistry::uuidToBytes($practitionerUuid)]
                                );
                                if (!empty($providerData) && !empty($providerData[0])) {
                                    $data['provider_id'] = $providerData[0]['id'];
                                }
                            }
                        }
                    }
                }

                // Check for referrer (type REF)
                $isReferrer = false;
                if (is_array($types)) {
                    foreach ($types as $type) {
                        $codings = is_array($type) ? ($type['coding'] ?? []) : ($type->getCoding() ?? []);
                        foreach ($codings as $coding) {
                            $code = is_array($coding) ? ($coding['code'] ?? null) : (method_exists($coding, 'getCode') ? $coding->getCode() : null);
                            if ($code === self::ENCOUNTER_PARTICIPANT_TYPE_REFERRER) {
                                $isReferrer = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($isReferrer && empty($data['referring_provider_id'])) {
                    $individual = is_object($participant) && method_exists($participant, 'getIndividual')
                        ? $participant->getIndividual()
                        : ($participant['individual'] ?? null);
                    
                    if (!empty($individual)) {
                        $providerReference = UtilsService::parseReference($individual);
                        if (!empty($providerReference) && $providerReference['resourceType'] === 'Practitioner' && $providerReference['localResource']) {
                            $practitionerUuid = $providerReference['uuid'];
                            if (!empty($practitionerUuid)) {
                                $providerData = QueryUtils::fetchRecords(
                                    "SELECT id FROM users WHERE uuid = ?",
                                    [UuidRegistry::uuidToBytes($practitionerUuid)]
                                );
                                if (!empty($providerData) && !empty($providerData[0])) {
                                    $data['referring_provider_id'] = $providerData[0]['id'];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Extract discharge disposition (hospitalization.dischargeDisposition)
        $hospitalization = $fhirResource->getHospitalization();
        if (!empty($hospitalization)) {
            $dischargeDisposition = is_object($hospitalization) && method_exists($hospitalization, 'getDischargeDisposition')
                ? $hospitalization->getDischargeDisposition()
                : ($hospitalization['dischargeDisposition'] ?? null);
            
            if (!empty($dischargeDisposition)) {
                $codings = is_object($dischargeDisposition) && method_exists($dischargeDisposition, 'getCoding')
                    ? $dischargeDisposition->getCoding()
                    : ($dischargeDisposition['coding'] ?? []);
                
                if (!empty($codings) && is_array($codings)) {
                    $firstCoding = $codings[0];
                    $code = is_object($firstCoding) && method_exists($firstCoding, 'getCode')
                        ? (string)$firstCoding->getCode()
                        : (string)($firstCoding['code'] ?? null);
                    
                    if (!empty($code)) {
                        // Try to find matching discharge disposition in list_options
                        $listService = new ListService();
                        $dispositionOption = $listService->getListOption('discharge-disposition', $code);
                        if (!empty($dispositionOption)) {
                            $data['discharge_disposition'] = $dispositionOption['option_id'];
                        } else {
                            // If not found, use the code directly
                            $data['discharge_disposition'] = $code;
                        }
                    }
                }
            }
        }

        // Get user and group from session (if available) or use defaults
        if (isset($_SESSION) && isset($_SESSION['authUser'])) {
            $data['user'] = $_SESSION['authUser'];
        } else {
            $data['user'] = 'admin'; // Default fallback
        }

        if (isset($_SESSION) && isset($_SESSION['authProvider'])) {
            $data['group'] = $_SESSION['authProvider'];
        } else {
            $data['group'] = 'Default'; // Default fallback
        }

        return $data;
    }

    /**
     * Inserts an OpenEMR encounter record into the system.
     *
     * @param array $openEmrRecord OpenEMR encounter record
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();

        try {
            // Validate required fields
            if (empty($openEmrRecord['pid'])) {
                $processingResult->setValidationMessages(['pid' => 'Patient ID is required']);
                return $processingResult;
            }

            // Get patient UUID (required for EncounterService)
            $patientService = new PatientService();
            $patient = $patientService->findByPid($openEmrRecord['pid']);
            if (empty($patient) || empty($patient['uuid'])) {
                $processingResult->setValidationMessages(['pid' => 'Patient not found or missing UUID']);
                return $processingResult;
            }
            $puuid = UuidRegistry::uuidToString($patient['uuid']);

            // Get default values if not provided
            $facilityService = new FacilityService();
            
            // Get facility if not provided
            if (empty($openEmrRecord['facility_id'])) {
                $userId = isset($_SESSION) && isset($_SESSION['authUserID']) ? $_SESSION['authUserID'] : 1;
                if ($userId > 0) {
                    $userFacility = $facilityService->getFacilityForUser($userId);
                    if (!empty($userFacility)) {
                        $openEmrRecord['facility_id'] = $userFacility['id'] ?? null;
                        $openEmrRecord['facility'] = $userFacility['name'] ?? null;
                        $openEmrRecord['billing_facility'] = $userFacility['id'] ?? null;
                    }
                }
                
                // If still no facility, get primary facility
                if (empty($openEmrRecord['facility_id'])) {
                    $primaryFacility = $facilityService->getPrimaryBusinessEntity();
                    if (!empty($primaryFacility)) {
                        $openEmrRecord['facility_id'] = $primaryFacility['id'] ?? null;
                        $openEmrRecord['facility'] = $primaryFacility['name'] ?? null;
                        $openEmrRecord['billing_facility'] = $primaryFacility['id'] ?? null;
                    }
                }
            }

            // Get pos_code from facility
            if (!empty($openEmrRecord['facility_id'])) {
                $openEmrRecord['pos_code'] = $this->encounterService->getPosCode($openEmrRecord['facility_id']);
            }

            // Get default class_code if not provided (required field)
            if (empty($openEmrRecord['class_code'])) {
                $listService = new ListService();
                $classOptions = $listService->getOptionsByListName('_ActEncounterCode');
                if (!empty($classOptions)) {
                    // Find the default
                    foreach ($classOptions as $code) {
                        if (!empty($code['is_default'])) {
                            $openEmrRecord['class_code'] = $code['option_id'];
                            break;
                        }
                    }
                    // If no default, use first entry
                    if (empty($openEmrRecord['class_code']) && !empty($classOptions[0])) {
                        $openEmrRecord['class_code'] = $classOptions[0]['option_id'];
                    }
                }
                // Fallback to default if still empty
                if (empty($openEmrRecord['class_code'])) {
                    $openEmrRecord['class_code'] = EncounterService::DEFAULT_CLASS_CODE; // 'AMB'
                }
            }

            // Get default pc_catid if not provided (required field)
            // Use only valid visit categories (pc_cattype IN (0,3)) and exclude 'no_show'
            if (empty($openEmrRecord['pc_catid'])) {
                $visitCategories = QueryUtils::fetchRecords(
                    "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_active = 1 AND pc_cattype IN (0,3) AND pc_constant_id != 'no_show' ORDER BY pc_seq, pc_catid LIMIT 1",
                    []
                );
                if (!empty($visitCategories) && !empty($visitCategories[0]['pc_catid'])) {
                    $openEmrRecord['pc_catid'] = $visitCategories[0]['pc_catid'];
                } else {
                    // Fallback: try without excluding no_show
                    $visitCategories = QueryUtils::fetchRecords(
                        "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_active = 1 AND pc_cattype IN (0,3) ORDER BY pc_seq, pc_catid LIMIT 1",
                        []
                    );
                    if (!empty($visitCategories) && !empty($visitCategories[0]['pc_catid'])) {
                        $openEmrRecord['pc_catid'] = $visitCategories[0]['pc_catid'];
                    } else {
                        // Final fallback to default category ID if none found
                        $openEmrRecord['pc_catid'] = 5; // Common default value
                    }
                }
            }

            // Get provider_id if not provided
            if (empty($openEmrRecord['provider_id'])) {
                $openEmrRecord['provider_id'] = isset($_SESSION) && isset($_SESSION['authUserID']) ? $_SESSION['authUserID'] : 1;
            }

            // Use EncounterService to create encounter
            $encounterResult = $this->encounterService->insertEncounter($puuid, $openEmrRecord);

            if ($encounterResult->isValid() && $encounterResult->hasData()) {
                // Convert saved encounter to FHIR resource
                $savedEncounter = $encounterResult->getFirstDataResult();
                if (!empty($savedEncounter) && !empty($savedEncounter['euuid'])) {
                    $fhirResource = $this->parseOpenEMRRecord($savedEncounter, false);
                    $processingResult->addData($fhirResource);
                } else {
                    $processingResult->addInternalError("Failed to retrieve created encounter");
                }
            } else {
                // Copy validation errors and internal errors from EncounterService
                $validationMessages = $encounterResult->getValidationMessages();
                $internalErrors = $encounterResult->getInternalErrors();
                if (!empty($validationMessages)) {
                    $processingResult->setValidationMessages($validationMessages);
                }
                if (!empty($internalErrors)) {
                    foreach ($internalErrors as $error) {
                        $processingResult->addInternalError($error);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("FhirEncounterService::insertOpenEMRRecord() exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'encounterData' => $openEmrRecord
            ]);
            $processingResult->addInternalError("Error creating encounter: " . $e->getMessage());
        }

        return $processingResult;
    }

    /**
     * Updates an existing OpenEMR encounter record.
     *
     * @param string $fhirResourceId The OpenEMR record's FHIR Resource ID (encounter UUID)
     * @param array $updatedOpenEMRRecord The "updated" OpenEMR record.
     * @return ProcessingResult
     */
    protected function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        $processingResult = new ProcessingResult();

        try {
            // Get patient UUID (required for EncounterService)
            if (empty($updatedOpenEMRRecord['pid'])) {
                $processingResult->setValidationMessages(['pid' => 'Patient ID is required']);
                return $processingResult;
            }

            $patientService = new PatientService();
            $patient = $patientService->findByPid($updatedOpenEMRRecord['pid']);
            if (empty($patient) || empty($patient['uuid'])) {
                $processingResult->setValidationMessages(['pid' => 'Patient not found or missing UUID']);
                return $processingResult;
            }
            $puuid = UuidRegistry::uuidToString($patient['uuid']);

            // Use EncounterService to update encounter
            $encounterResult = $this->encounterService->updateEncounter($puuid, $fhirResourceId, $updatedOpenEMRRecord);

            if ($encounterResult->isValid() && $encounterResult->hasData()) {
                // Convert saved encounter to FHIR resource
                $savedEncounter = $encounterResult->getFirstDataResult();
                if (!empty($savedEncounter) && !empty($savedEncounter['euuid'])) {
                    $fhirResource = $this->parseOpenEMRRecord($savedEncounter, false);
                    $processingResult->addData($fhirResource);
                } else {
                    $processingResult->addInternalError("Failed to retrieve updated encounter");
                }
            } else {
                // Copy validation errors and internal errors from EncounterService
                $validationMessages = $encounterResult->getValidationMessages();
                $internalErrors = $encounterResult->getInternalErrors();
                if (!empty($validationMessages)) {
                    $processingResult->setValidationMessages($validationMessages);
                }
                if (!empty($internalErrors)) {
                    foreach ($internalErrors as $error) {
                        $processingResult->addInternalError($error);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("FhirEncounterService::updateOpenEMRRecord() exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'fhirResourceId' => $fhirResourceId,
                'encounterData' => $updatedOpenEMRRecord
            ]);
            $processingResult->addInternalError("Error updating encounter: " . $e->getMessage());
        }

        return $processingResult;
    }
}
