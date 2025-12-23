<?php

/**
 * FhirCareTeamService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRCareTeam;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCareTeam\FHIRCareTeamParticipant;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Services\CareTeamService;
use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Uuid\UuidMapping;

class FhirCareTeamService extends FhirServiceBase implements IResourceUSCIGProfileService, IFhirExportableResourceService
{
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    // @see http://hl7.org/fhir/R4/valueset-care-team-status.html
    private const CARE_TEAM_STATUS_ACTIVE = "active";
    private const CARE_TEAM_STATUS_PROPOSED = "proposed";
    private const CARE_TEAM_STATUS_SUSPENDED = "suspended";
    private const CARE_TEAM_STATUS_INACTIVE = "inactive";
    private const CARE_TEAM_STATUS_ENTERED_IN_ERROR = "entered-in-error";
    private const CARE_TEAM_STATII = [self::CARE_TEAM_STATUS_ACTIVE, self::CARE_TEAM_STATUS_INACTIVE
        , self::CARE_TEAM_STATUS_PROPOSED, self::CARE_TEAM_STATUS_SUSPENDED, self::CARE_TEAM_STATUS_ENTERED_IN_ERROR];

    /**
     * @var CareTeamService
     */
    private $careTeamService;


    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-careteam';

    public function __construct()
    {
        parent::__construct();
        $this->careTeamService = new CareTeamService();
    }

    /**
     * Returns an array mapping FHIR CareTeam Resource search parameters to OpenEMR CareTeam search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'status' => new FhirSearchParameterDefinition('status', SearchFieldType::TOKEN, ['care_team_status']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['date']);
    }

    /**
     * Parses an OpenEMR careTeam record, returning the equivalent FHIR CareTeam Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRCareTeam
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $careTeamResource = new FHIRCareTeam();

        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId('1');
        if (!empty($dataRecord['date'])) {
            $fhirMeta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['date']));
        } else {
            $fhirMeta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $careTeamResource->setMeta($fhirMeta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $careTeamResource->setId($id);

        if (array_search($dataRecord['care_team_status'], self::CARE_TEAM_STATII) !== false) {
            $careTeamResource->setStatus($dataRecord['care_team_status']);
        } else {
            // default is active
            $careTeamResource->setStatus(self::CARE_TEAM_STATUS_ACTIVE);
        }


        $careTeamResource->setSubject(UtilsService::createRelativeReference("Patient", $dataRecord['puuid']));
        $codeTypesService = new CodeTypesService();

        if (!empty($dataRecord['providers'])) {
            foreach ($dataRecord['providers'] as $dataRecordProviderList) {
                $provider = new FHIRCareTeamParticipant();

                // provider can have more than facility matching... we are only going to grab the first facility for now
                $dataRecordProvider = end($dataRecordProviderList);

                if (!empty($dataRecordProvider['physician_type_codes'])) {
                    $codes = $codeTypesService->parseCode($dataRecordProvider['physician_type_codes']);
                    $codes['description'] = $codeTypesService->lookup_code_description($dataRecordProvider['physician_type_title']) ?? xlt($dataRecordProvider['physician_type']);
                    if (empty($codes['description'])) {
                        $codes['description'] = xlt($dataRecordProvider['role_title']);
                    }
                    // The OID is the value set system but the larger system is SNOMED_CT so we will put that as the system.
                    $codes['system'] = FhirCodeSystemConstants::SNOMED_CT;
//                    $codes['system'] = FhirCodeSystemConstants::CARE_TEAM_MEMBER_FUNCTION_SNOMEDCT;
                    $role = UtilsService::createCodeableConcept([$codes['code'] => $codes]);
                } else {
                    // need to provide the data absent reason
                    $role = UtilsService::createDataAbsentUnknownCodeableConcept();
                }

                // US Core only allows onBehalfOf to be populated if participant is a practitioner
                if (!empty($dataRecordProvider['facility_uuid'])) {
                    $provider->setOnBehalfOf(UtilsService::createRelativeReference("Organization", $dataRecordProvider['facility_uuid']));
                }

                $provider->addRole($role);
                $provider->setMember(UtilsService::createRelativeReference("Practitioner", $dataRecordProvider['provider_uuid']));
                $careTeamResource->addParticipant($provider);
            }
        }

        // facilities have to use SNOMED_CT for our code system since NUCC is no longer a valid code system for FHIR.
        if (!empty($dataRecord['facilities'])) {
            foreach ($dataRecord['facilities'] as $dataRecordFacility) {
                $organization = new FHIRCareTeamParticipant();
                $organization->setMember(UtilsService::createRelativeReference("Organization", $dataRecordFacility['uuid']));

                $roleCoding = new FHIRCoding();
                if (empty($dataRecordFacility['facility_taxonomy'])) {
                    $role = UtilsService::createDataAbsentUnknownCodeableConcept();
                } else {
                    $codes = [
                        'code' => $dataRecordFacility['facility_taxonomy']
                        ,'system' => FhirCodeSystemConstants::SNOMED_CT
                        ,'description' => null
                    ];
                    $fullCode = $codeTypesService->getCodeWithType($codes['code'], CodeTypesService::CODE_TYPE_SNOMED_CT);
                    $codes['description'] = $codeTypesService->lookup_code_description($fullCode);
                    if (empty($codes['description'])) {
                        $codes['description'] = xlt('Healthcare facility');
                    }
                    $role = UtilsService::createCodeableConcept([$codes['code'] => $codes]);
                }
                $organization->addRole($role);
                $careTeamResource->addParticipant($organization);
            }
        }

        if ($encode) {
            return json_encode($careTeamResource);
        } else {
            return $careTeamResource;
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
        return $this->careTeamService->getAll($openEMRSearchParameters, true, $puuidBind);
    }

    public function createProvenanceResource($dataRecord = array(), $encode = false)
    {
        if (!($dataRecord instanceof FHIRCareTeam)) {
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

    public function getProfileURIs(): array
    {
        return [self::USCGI_PROFILE_URI];
    }

    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::REFERENCE, [new ServiceField('puuid', ServiceField::TYPE_UUID)]);
    }

    /**
     * Inserts a FHIR resource into the system with error handling
     * @param FHIRDomainResource $fhirResource The FHIR resource
     * @return ProcessingResult The OpenEMR Service Result
     */
    public function insert(FHIRDomainResource $fhirResource): ProcessingResult
    {
        error_log("FhirCareTeamService::insert() - START");
        $processingResult = new ProcessingResult();

        try {
            $openEmrRecord = $this->parseFhirResource($fhirResource);
            error_log("FhirCareTeamService::insert() - parseFhirResource completed, keys: " . implode(', ', array_keys($openEmrRecord ?? [])));

            if (empty($openEmrRecord)) {
                error_log("FhirCareTeamService::insert() - parseFhirResource returned empty array");
                $processingResult->addInternalError("parseFhirResource returned empty array");
                return $processingResult;
            }

            return $this->insertOpenEMRRecord($openEmrRecord);
        } catch (\Throwable $e) {
            error_log("FhirCareTeamService::insert() - EXCEPTION in parseFhirResource: " . $e->getMessage());
            error_log("FhirCareTeamService::insert() - TRACE: " . $e->getTraceAsString());
            $processingResult->addInternalError("Error parsing FHIR CareTeam resource: " . $e->getMessage());
            $processingResult->addInternalError("Stack trace: " . $e->getTraceAsString());
            return $processingResult;
        }
    }

    /**
     * Parses a FHIR CareTeam resource into an OpenEMR record structure
     *
     * @param FHIRDomainResource $fhirResource
     * @return array
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        if (!($fhirResource instanceof FHIRCareTeam)) {
            throw new \BadMethodCallException("fhir resource must be of type " . FHIRCareTeam::class);
        }

        error_log("FhirCareTeamService::parseFhirResource() - START");
        $data = [];

        // Extract patient (subject)
        $subject = $fhirResource->getSubject();
        if (!empty($subject)) {
            $subjectReference = UtilsService::parseReference($subject);
            if (!empty($subjectReference) && $subjectReference['type'] === 'Patient' && $subjectReference['localResource']) {
                $patientUuid = $subjectReference['uuid'];
            } else {
                // Fallback: handle array / plain reference string
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
                // Get patient ID from UUID
                $patientData = QueryUtils::fetchRecords(
                    "SELECT pid FROM patient_data WHERE uuid = ?",
                    [UuidRegistry::uuidToBytes($patientUuid)]
                );
                if (!empty($patientData) && !empty($patientData[0])) {
                    $data['pid'] = $patientData[0]['pid'];
                    $data['puuid'] = $patientUuid;
                }
            }
        }

        // Extract status
        $status = $fhirResource->getStatus();
        if (!empty($status)) {
            $data['care_team_status'] = $status;
        } else {
            $data['care_team_status'] = self::CARE_TEAM_STATUS_ACTIVE;
        }

        // Extract participants (providers and facilities)
        $participants = $fhirResource->getParticipant();
        $providerIds = [];
        $facilityIds = [];

        if (!empty($participants)) {
            foreach ($participants as $participant) {
                $member = $participant->getMember();
                if (!empty($member)) {
                    $memberReference = UtilsService::parseReference($member);
                    if (!empty($memberReference)) {
                        if ($memberReference['type'] === 'Practitioner' && $memberReference['localResource']) {
                            $practitionerUuid = $memberReference['uuid'];
                            // Get provider ID from users table
                            $practitionerData = QueryUtils::fetchRecords(
                                "SELECT id FROM users WHERE uuid = ?",
                                [UuidRegistry::uuidToBytes($practitionerUuid)]
                            );
                            if (!empty($practitionerData) && !empty($practitionerData[0])) {
                                $providerIds[] = $practitionerData[0]['id'];
                            }
                        } elseif ($memberReference['type'] === 'Organization' && $memberReference['localResource']) {
                            $organizationUuid = $memberReference['uuid'];
                            // Get facility ID from facility table
                            $facilityData = QueryUtils::fetchRecords(
                                "SELECT id FROM facility WHERE uuid = ?",
                                [UuidRegistry::uuidToBytes($organizationUuid)]
                            );
                            if (!empty($facilityData) && !empty($facilityData[0])) {
                                $facilityIds[] = $facilityData[0]['id'];
                            }
                        }
                    }
                }
            }
        }

        // Store provider and facility IDs as comma-separated strings (matching CareTeamService format)
        if (!empty($providerIds)) {
            $data['care_team_provider'] = implode('|', $providerIds);
        }
        if (!empty($facilityIds)) {
            $data['care_team_facility'] = implode(',', $facilityIds);
        }

        // Extract period start date if available
        $period = $fhirResource->getPeriod();
        if (!empty($period)) {
            $start = $period->getStart();
            if (!empty($start)) {
                if (is_object($start) && method_exists($start, 'getValue')) {
                    $data['date'] = $start->getValue();
                } elseif (is_string($start)) {
                    $data['date'] = $start;
                }
            }
        }

        if (empty($data['date'])) {
            $data['date'] = date('Y-m-d');
        }

        error_log("FhirCareTeamService::parseFhirResource() - END, data keys: " . implode(', ', array_keys($data)));
        return $data;
    }

    /**
     * Inserts an OpenEMR CareTeam record
     *
     * @param array $openEmrRecord
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        error_log("FhirCareTeamService::insertOpenEMRRecord() - START");
        error_log("FhirCareTeamService::insertOpenEMRRecord() - openEmrRecord keys: " . implode(', ', array_keys($openEmrRecord ?? [])));
        
        $processingResult = new ProcessingResult();

        try {
            // Validate required fields
            if (empty($openEmrRecord)) {
                error_log("FhirCareTeamService::insertOpenEMRRecord() - openEmrRecord is EMPTY");
                $processingResult->addInternalError("openEmrRecord is empty");
                return $processingResult;
            }
            
            if (empty($openEmrRecord['pid'])) {
                error_log("FhirCareTeamService::insertOpenEMRRecord() - MISSING PID");
                $processingResult->setValidationMessages(['pid' => 'Patient ID is required for CareTeam']);
                return $processingResult;
            }

            $pid = $openEmrRecord['pid'];
            $careTeamStatus = $openEmrRecord['care_team_status'] ?? self::CARE_TEAM_STATUS_ACTIVE;
            $careTeamProvider = $openEmrRecord['care_team_provider'] ?? '';
            $careTeamFacility = $openEmrRecord['care_team_facility'] ?? '';
            $date = $openEmrRecord['date'] ?? date('Y-m-d');

            // Create UUID for this CareTeam
            $careTeamUuid = UuidRegistry::getRegistryForTable('patient_history')->createUuid();

            // Update patient_data table with care team information
            $updateSql = "UPDATE patient_data SET 
                            care_team_provider = ?,
                            care_team_facility = ?,
                            care_team_status = ?,
                            date = ?
                         WHERE pid = ?";
            
            QueryUtils::sqlStatementThrowException($updateSql, [
                $careTeamProvider,
                $careTeamFacility,
                $careTeamStatus,
                $date,
                $pid
            ]);

            // Create UUID mapping entry
            $patientUuid = $openEmrRecord['puuid'] ?? null;
            if (empty($patientUuid)) {
                // Get patient UUID from pid
                $patientData = QueryUtils::fetchRecords(
                    "SELECT uuid FROM patient_data WHERE pid = ?",
                    [$pid]
                );
                if (!empty($patientData) && !empty($patientData[0])) {
                    $patientUuid = UuidRegistry::uuidToString($patientData[0]['uuid']);
                }
            }

            if (!empty($patientUuid)) {
                // Create or update UUID mapping
                $mappingExists = QueryUtils::fetchRecords(
                    "SELECT uuid FROM uuid_mapping WHERE resource = ? AND target_uuid = ?",
                    ['CareTeam', UuidRegistry::uuidToBytes($patientUuid)]
                );

                if (empty($mappingExists)) {
                    // Insert new mapping
                    $mappingSql = "INSERT INTO uuid_mapping (uuid, target_uuid, resource) VALUES (?, ?, ?)";
                    QueryUtils::sqlStatementThrowException($mappingSql, [
                        UuidRegistry::uuidToBytes($careTeamUuid),
                        UuidRegistry::uuidToBytes($patientUuid),
                        'CareTeam'
                    ]);
                } else {
                    // Update existing mapping
                    $careTeamUuid = UuidRegistry::uuidToString($mappingExists[0]['uuid']);
                }
            }

            // Reload the record to return it
            $search = [
                'uuid' => new TokenSearchField('uuid', $careTeamUuid, true)
            ];
            $result = $this->careTeamService->search($search, true);

            if ($result->isValid() && $result->hasData()) {
                $record = $result->getData()[0];
                $fhirResource = $this->parseOpenEMRRecord($record, false);
                $processingResult->addData($fhirResource);
            } else {
                // Create a minimal record for return
                $minimalRecord = [
                    'uuid' => $careTeamUuid,
                    'puuid' => $patientUuid,
                    'care_team_status' => $careTeamStatus,
                    'date' => $date,
                    'providers' => [],
                    'facilities' => []
                ];
                $fhirResource = $this->parseOpenEMRRecord($minimalRecord, false);
                $processingResult->addData($fhirResource);
            }

            error_log("FhirCareTeamService::insertOpenEMRRecord() - SUCCESS");
            return $processingResult;
        } catch (\Throwable $e) {
            error_log("FhirCareTeamService::insertOpenEMRRecord() - EXCEPTION: " . $e->getMessage());
            error_log("FhirCareTeamService::insertOpenEMRRecord() - TRACE: " . $e->getTraceAsString());
            $processingResult->addInternalError("Error inserting CareTeam: " . $e->getMessage());
            $processingResult->addInternalError("Stack trace: " . $e->getTraceAsString());
            return $processingResult;
        }
    }

    /**
     * Updates an existing OpenEMR CareTeam record.
     * Currently not implemented – CareTeam updates via FHIR are not supported.
     *
     * @param string $fhirResourceId
     * @param array  $updatedOpenEMRRecord
     * @return ProcessingResult
     */
    protected function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord): ProcessingResult
    {
        $processingResult = new ProcessingResult();
        $processingResult->addInternalError("updateOpenEMRRecord not implemented for CareTeam");
        return $processingResult;
    }
}
