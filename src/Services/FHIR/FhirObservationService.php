<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\Billing\BillingProcessor\LoggerInterface;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Uuid\UuidMapping;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\BaseService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\FormService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\FHIR\Observation\FhirObservationLaboratoryService;
use OpenEMR\Services\FHIR\Observation\FhirObservationSocialHistoryService;
use OpenEMR\Services\FHIR\Observation\FhirObservationVitalsService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRObservation;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Services\FHIR\Traits\MappedServiceCodeTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\ObservationLabService;
use OpenEMR\Services\ObservationService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldException;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Validators\ProcessingResult;

/**
 * FHIR Observation Service
 *
 * @package            OpenEMR
 * @link               http://www.open-emr.org
 * @author             Yash Bothra <yashrajbothra786gmail.com>
 * @copyright          Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license            https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class FhirObservationService extends FhirServiceBase implements IResourceSearchableService, IResourceUSCIGProfileService, IPatientCompartmentResourceService, IFhirExportableResourceService
{
    use MappedServiceCodeTrait;
    use PatientSearchTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    /**
     * @var ObservationLabService
     */
    private $observationLabService;

    /**
     * @var ObservationService
     */
    private $observationService;

    /**
     * @var EncounterService
     */
    private $encounterService;

    /**
     * @var BaseService[]
     */
    private $innerServices;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        parent::__construct();
        $this->innerServices = [];
        $this->addMappedService(new FhirObservationSocialHistoryService());
        $this->addMappedService(new FhirObservationVitalsService());
        $this->addMappedService(new FhirObservationLaboratoryService());
        $this->observationService = new ObservationService();
        $this->encounterService = new EncounterService();
        $this->logger = new SystemLogger();
    }

    /**
     * Returns an array mapping FHIR Resource search parameters to OpenEMR search parameters
     */
    protected function loadSearchParameters(): array
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'code' => new FhirSearchParameterDefinition('status', SearchFieldType::TOKEN, ['code']),
            'category' => new FhirSearchParameterDefinition('category', SearchFieldType::TOKEN, ['category']),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, ['uuid']),
            '_lastUpdated' => $this->getLastModifiedSearchField()
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['date_modified']);
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
            if (isset($fhirSearchParameters['_id'])) {
                $result = $this->populateSurrogateSearchFieldsForUUID($fhirSearchParameters['_id'], $fhirSearchParameters);
                if ($result instanceof ProcessingResult) { // failed to populate so return the results
                    return $result;
                }
            }

            if (isset($puuidBind)) {
                $field = $this->getPatientContextSearchField();
                $fhirSearchParameters[$field->getName()] = $puuidBind;
            }

            if (isset($fhirSearchParameters['category'])) {
                /**
                 * @var TokenSearchField
                 */
                $category = $fhirSearchParameters['category'];

                $service = $this->getServiceForCategory(
                    new TokenSearchField('category', $fhirSearchParameters['category']),
                    'vital-signs'
                );
                $fhirSearchResult = $service->getAll($fhirSearchParameters, $puuidBind);
            } else if (isset($fhirSearchParameters['code'])) {
                $service = $this->getServiceForCode(
                    new TokenSearchField('code', $fhirSearchParameters['code']),
                    FhirObservationVitalsService::VITALS_PANEL_LOINC_CODE
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
            $systemLogger = new SystemLogger();
            $systemLogger->error("FhirObservationService->getAll() exception thrown", ['message' => $exception->getMessage(),
                'field' => $exception->getField(), 'trace' => $exception->getTraceAsString()]);
            // put our exception information here
            $fhirSearchResult->setValidationMessages([$exception->getField() => $exception->getMessage()]);
        }
        return $fhirSearchResult;
    }

    /**
     * Take our uuid surrogate key and populate the underlying data elements and grabs the mapped key for it.
     * @param $fhirResourceId The uuid search field with the 1..* values to search on
     * @param $search Hashmap of search operators
     */
    private function populateSurrogateSearchFieldsForUUID($fhirResourceId, &$search)
    {
        $processingResult = new ProcessingResult();

        // we first grab the uuid from our registry and find out if its a mapping observation resource
        // (such as vital signs)
        $registryRecord = UuidRegistry::getRegistryRecordForUuid($fhirResourceId);

        if (empty($registryRecord)) {
            $processingResult->setValidationMessages(['_id' => 'Resource not found for that id']);
            return $processingResult;
        }

        // if its not mapped we will leave the _id alone and let the subsequent sub service pull the right resource
        if ($registryRecord['mapped'] != '1') {
            return;
        }

        // we are going to get our
        $mapping = UuidMapping::getMappingForUUID($fhirResourceId);

        if (empty($mapping)) {
            $processingResult->setValidationMessages(['_id' => 'Resource not found for that id']);
            return $processingResult;
        }

        // grab our category
        if ($mapping['resource'] !== 'Observation') {
            // we have a problem here
            $processingResult->setValidationMessages(["_id" => "Resource not found for that id"]);
            $this->logger->error("Requested observation resource for uuid that exists for a different resource", ['_id' => $fhirResourceId, 'mappingResource' => $mapping['resource']]);
            return $processingResult;
        }

        // grab category and code
        $query_vars = [];
        parse_str($mapping['resource_path'], $query_vars);
        if (empty($query_vars['category'])) {
            $processingResult->setValidationMessages(["_id" => "Resource not found for that id"]);
            $this->logger->error("Requested observation with no resource_path category to parse the mapping", ['uuid' => $fhirResourceId, 'resource_path' => $mapping['resource_path']]);
            return $processingResult;
        }

        $code = empty($search['code']) ? $query_vars['code'] : $search['code'] . "," . $query_vars['code'];
        $search['code'] = $code;
        $search['category'] = $query_vars['category'];

        // we only want a single search value for now... not supporting combined uuids
        $search['_id'] = UuidRegistry::uuidToString($mapping['target_uuid']);
    }

    public function getProfileURIs(): array
    {
        return [
            'http://hl7.org/fhir/R4/observation-vitalsigns'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/us-core-observation-lab'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/pediatric-bmi-for-age'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/pediatric-weight-for-height'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/us-core-pulse-oximetry'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/us-core-smokingstatus'
            ,'http://hl7.org/fhir/StructureDefinition/bp'
            ,'http://hl7.org/fhir/StructureDefinition/bodyheight'
            ,'http://hl7.org/fhir/StructureDefinition/bodyweight'
            ,'http://hl7.org/fhir/StructureDefinition/heartrate'
            ,'http://hl7.org/fhir/StructureDefinition/resprate'
            ,'http://hl7.org/fhir/StructureDefinition/bodytemp'
            ,'http://hl7.org/fhir/us/core/StructureDefinition/head-occipital-frontal-circumference-percentile'
        ];
    }

    /**
     * Parses a FHIR Observation Resource, returning the equivalent OpenEMR observation record.
     *
     * @param FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        if (!$fhirResource instanceof FHIRObservation) {
            throw new \BadMethodCallException("fhir resource must be of type " . FHIRObservation::class);
        }

        $data = array();

        // Extract UUID
        $data['uuid'] = (string)$fhirResource->getId() ?? null;

        // Extract patient reference
        $subject = $fhirResource->getSubject();
        if (!empty($subject)) {
            // Handle both object and array cases
            if (is_object($subject) && method_exists($subject, 'getReference')) {
                $subjectReference = UtilsService::parseReference($subject);
                if (!empty($subjectReference) && $subjectReference['type'] === 'Patient' && $subjectReference['localResource']) {
                    // Get patient ID from UUID
                    $patientUuid = $subjectReference['uuid'];
                    if (!empty($patientUuid)) {
                        $patientData = QueryUtils::fetchRecords("SELECT pid FROM patient_data WHERE uuid = ?", [UuidRegistry::uuidToBytes($patientUuid)]);
                        if (!empty($patientData) && !empty($patientData[0])) {
                            $data['pid'] = $patientData[0]['pid'];
                        }
                    }
                }
            } elseif (is_array($subject)) {
                // If subject is already an array (from JSON deserialization)
                $referenceString = $subject['reference'] ?? null;
                if (!empty($referenceString)) {
                    // Parse reference string like "Patient/uuid"
                    $parts = explode('/', $referenceString);
                    if (count($parts) >= 2 && $parts[0] === 'Patient') {
                        $patientUuid = $parts[1];
                        if (!empty($patientUuid)) {
                            $patientData = QueryUtils::fetchRecords("SELECT pid FROM patient_data WHERE uuid = ?", [UuidRegistry::uuidToBytes($patientUuid)]);
                            if (!empty($patientData) && !empty($patientData[0])) {
                                $data['pid'] = $patientData[0]['pid'];
                            }
                        }
                    }
                }
            }
        }

        // Extract encounter from FHIR encounter field (FHIR R4 uses 'encounter' not 'context')
        // Try getEncounter() method first (FHIR R4)
        $encounterRef = null;
        if (method_exists($fhirResource, 'getEncounter')) {
            $encounterRef = $fhirResource->getEncounter();
        } elseif (method_exists($fhirResource, 'getContext')) {
            // Fallback for older FHIR versions
            $encounterRef = $fhirResource->getContext();
        }
        
        if (!empty($encounterRef)) {
            // Handle both object and array cases
            if (is_object($encounterRef) && method_exists($encounterRef, 'getReference')) {
                $encounterReference = UtilsService::parseReference($encounterRef);
                if (!empty($encounterReference) && $encounterReference['type'] === 'Encounter' && $encounterReference['localResource']) {
                    // Get encounter ID from UUID
                    $encounterUuid = $encounterReference['uuid'];
                    if (!empty($encounterUuid)) {
                        $encounterData = QueryUtils::fetchRecords(
                            "SELECT encounter FROM form_encounter WHERE uuid = ?",
                            [UuidRegistry::uuidToBytes($encounterUuid)]
                        );
                        if (!empty($encounterData) && !empty($encounterData[0])) {
                            $data['encounter'] = $encounterData[0]['encounter'];
                        }
                    }
                }
            } elseif (is_array($encounterRef)) {
                // If encounterRef is already an array (from JSON deserialization)
                $referenceString = $encounterRef['reference'] ?? null;
                if (!empty($referenceString)) {
                    // Parse reference string like "Encounter/uuid"
                    $parts = explode('/', $referenceString);
                    if (count($parts) >= 2 && $parts[0] === 'Encounter') {
                        $encounterUuid = $parts[1];
                        if (!empty($encounterUuid)) {
                            $encounterData = QueryUtils::fetchRecords(
                                "SELECT encounter FROM form_encounter WHERE uuid = ?",
                                [UuidRegistry::uuidToBytes($encounterUuid)]
                            );
                            if (!empty($encounterData) && !empty($encounterData[0])) {
                                $data['encounter'] = $encounterData[0]['encounter'];
                            }
                        }
                    }
                }
            }
        }
        
        // If still no encounter, try to get from session (for web interface)
        if (empty($data['encounter']) && isset($_SESSION) && isset($_SESSION['encounter'])) {
            $data['encounter'] = $_SESSION['encounter'];
        }

        // Extract date/effectiveDateTime
        $effectiveDateTime = $fhirResource->getEffectiveDateTime();
        if (!empty($effectiveDateTime)) {
            // Check if it's an object with getValue() method or already a string
            if (is_object($effectiveDateTime) && method_exists($effectiveDateTime, 'getValue')) {
                $dateValue = $effectiveDateTime->getValue();
                if (!empty($dateValue)) {
                    $data['date'] = UtilsService::getLocalDateAsUTC($dateValue);
                } else {
                    $data['date'] = date('Y-m-d H:i:s');
                }
            } elseif (is_string($effectiveDateTime)) {
                // Already a string, use it directly
                $data['date'] = UtilsService::getLocalDateAsUTC($effectiveDateTime);
            } else {
                $data['date'] = date('Y-m-d H:i:s');
            }
        } else {
            $data['date'] = date('Y-m-d H:i:s');
        }

        // Extract status
        $status = (string)$fhirResource->getStatus() ?? 'final';
        $data['ob_status'] = $status;

        // Extract code
        $code = $fhirResource->getCode();
        if (!empty($code)) {
            // Check if code is an object or array
            if (is_object($code) && method_exists($code, 'getCoding')) {
                $codings = $code->getCoding();
                if (!empty($codings) && is_array($codings)) {
                    $primaryCoding = $codings[0];
                    // Check if primaryCoding is an object with getCode() method
                    if (is_object($primaryCoding) && method_exists($primaryCoding, 'getCode')) {
                        $data['code'] = (string)$primaryCoding->getCode() ?? null;
                        $data['code_type'] = 'LOINC'; // Default to LOINC
                        if (method_exists($primaryCoding, 'getDisplay')) {
                            $data['description'] = (string)$primaryCoding->getDisplay() ?? null;
                        }
                        if (empty($data['description']) && method_exists($code, 'getText')) {
                            $data['description'] = (string)$code->getText() ?? null;
                        }
                    } elseif (is_array($primaryCoding)) {
                        // If it's an array, extract code directly
                        $data['code'] = (string)($primaryCoding['code'] ?? null);
                        $data['code_type'] = 'LOINC';
                        $data['description'] = (string)($primaryCoding['display'] ?? null);
                    }
                } elseif (is_array($codings)) {
                    // If codings is already an array
                    $primaryCoding = $codings[0] ?? null;
                    if (is_array($primaryCoding)) {
                        $data['code'] = (string)($primaryCoding['code'] ?? null);
                        $data['code_type'] = 'LOINC';
                        $data['description'] = (string)($primaryCoding['display'] ?? null);
                    }
                }
            } elseif (is_array($code)) {
                // If code is already an array (from JSON deserialization)
                if (!empty($code['coding']) && is_array($code['coding'])) {
                    $primaryCoding = $code['coding'][0] ?? null;
                    if (is_array($primaryCoding)) {
                        $data['code'] = (string)($primaryCoding['code'] ?? null);
                        $data['code_type'] = 'LOINC';
                        $data['description'] = (string)($primaryCoding['display'] ?? $code['text'] ?? null);
                    }
                }
            }
        }

        // Extract category
        $categories = $fhirResource->getCategory();
        if (!empty($categories)) {
            $primaryCategory = is_array($categories) ? $categories[0] : $categories;
            if (is_object($primaryCategory) && method_exists($primaryCategory, 'getCoding')) {
                $categoryCodings = $primaryCategory->getCoding();
                if (!empty($categoryCodings) && is_array($categoryCodings)) {
                    $firstCoding = $categoryCodings[0];
                    if (is_object($firstCoding) && method_exists($firstCoding, 'getCode')) {
                        $data['category'] = (string)$firstCoding->getCode() ?? null;
                    } elseif (is_array($firstCoding)) {
                        $data['category'] = (string)($firstCoding['code'] ?? null);
                    }
                }
            } elseif (is_array($primaryCategory)) {
                // If category is already an array
                if (!empty($primaryCategory['coding']) && is_array($primaryCategory['coding'])) {
                    $firstCoding = $primaryCategory['coding'][0] ?? null;
                    if (is_array($firstCoding)) {
                        $data['category'] = (string)($firstCoding['code'] ?? null);
                    }
                }
            }
        }

        // Extract value
        $valueQuantity = $fhirResource->getValueQuantity();
        if (!empty($valueQuantity)) {
            // Check if it's an object with getValue() method
            if (is_object($valueQuantity) && method_exists($valueQuantity, 'getValue')) {
                $value = $valueQuantity->getValue();
                $data['ob_value'] = $value !== null ? (string)$value : null;
                if (method_exists($valueQuantity, 'getUnit')) {
                    $unit = $valueQuantity->getUnit();
                    $data['ob_unit'] = $unit !== null ? (string)$unit : null;
                }
                $data['ob_type'] = 'numeric';
            } elseif (is_array($valueQuantity)) {
                // If it's already an array (from JSON deserialization)
                $data['ob_value'] = (string)($valueQuantity['value'] ?? null);
                $data['ob_unit'] = (string)($valueQuantity['unit'] ?? null);
                $data['ob_type'] = 'numeric';
            } else {
                // If it's already a value, use it directly
                $data['ob_value'] = (string)$valueQuantity;
                $data['ob_type'] = 'numeric';
            }
            
            $this->logger->debug("FhirObservationService::parseFhirResource() extracted valueQuantity", [
                'ob_value' => $data['ob_value'] ?? null,
                'ob_unit' => $data['ob_unit'] ?? null,
                'ob_type' => $data['ob_type'] ?? null
            ]);
        } else {
            $valueString = $fhirResource->getValueString();
            if (!empty($valueString)) {
                // Check if it's an object with getValue() method
                if (is_object($valueString) && method_exists($valueString, 'getValue')) {
                    $data['ob_value'] = (string)$valueString->getValue();
                } else {
                    $data['ob_value'] = (string)$valueString;
                }
                $data['ob_type'] = 'text';
            } else {
                $valueCodeableConcept = $fhirResource->getValueCodeableConcept();
                if (!empty($valueCodeableConcept)) {
                    if (is_object($valueCodeableConcept) && method_exists($valueCodeableConcept, 'getCoding')) {
                        $valueCodings = $valueCodeableConcept->getCoding();
                        if (!empty($valueCodings) && is_array($valueCodings)) {
                            $firstCoding = $valueCodings[0];
                            if (is_object($firstCoding) && method_exists($firstCoding, 'getCode')) {
                                $data['ob_value'] = (string)$firstCoding->getCode();
                                $data['ob_type'] = 'code';
                            } elseif (is_array($firstCoding)) {
                                $data['ob_value'] = (string)($firstCoding['code'] ?? null);
                                $data['ob_type'] = 'code';
                            }
                        }
                    } elseif (is_array($valueCodeableConcept)) {
                        // If valueCodeableConcept is already an array
                        if (!empty($valueCodeableConcept['coding']) && is_array($valueCodeableConcept['coding'])) {
                            $firstCoding = $valueCodeableConcept['coding'][0] ?? null;
                            if (is_array($firstCoding)) {
                                $data['ob_value'] = (string)($firstCoding['code'] ?? null);
                                $data['ob_type'] = 'code';
                            }
                        }
                    }
                    
                    // If we still don't have a value, try to get text
                    if (empty($data['ob_value'])) {
                        if (is_object($valueCodeableConcept) && method_exists($valueCodeableConcept, 'getText')) {
                            $data['ob_value'] = (string)$valueCodeableConcept->getText();
                            $data['ob_type'] = 'text';
                        } elseif (is_array($valueCodeableConcept)) {
                            $data['ob_value'] = (string)($valueCodeableConcept['text'] ?? null);
                            $data['ob_type'] = 'text';
                        }
                    }
                }
            }
        }

        // Extract observation name/description
        if (empty($data['observation'])) {
            $data['observation'] = $data['description'] ?? $data['code'] ?? 'Observation';
        }

        // Extract performer (practitioner)
        $performers = $fhirResource->getPerformer();
        if (!empty($performers)) {
            $performerReference = UtilsService::parseReference($performers[0]);
            if (!empty($performerReference) && $performerReference['resourceType'] === 'Practitioner' && $performerReference['localResource']) {
                // Get user from practitioner UUID
                $practitionerUuid = $performerReference['uuid'];
                $practitionerData = QueryUtils::fetchRecords("SELECT id, username FROM users WHERE uuid = ?", [UuidRegistry::uuidToBytes($practitionerUuid)]);
                if (!empty($practitionerData) && !empty($practitionerData[0])) {
                    $data['user'] = $practitionerData[0]['username'];
                }
            }
        }

        // Set default values (safely check for session)
        if (empty($data['user'])) {
            $data['user'] = (isset($_SESSION) && isset($_SESSION['authUser'])) ? $_SESSION['authUser'] : null;
        }
        if (empty($data['groupname'])) {
            $data['groupname'] = (isset($_SESSION) && isset($_SESSION['authProvider'])) ? $_SESSION['authProvider'] : null;
        }
        if (empty($data['authorized'])) {
            $data['authorized'] = (isset($_SESSION) && isset($_SESSION['userauthorized'])) ? $_SESSION['userauthorized'] : 0;
        }
        
        // Ensure required fields have defaults
        if (empty($data['observation'])) {
            $data['observation'] = $data['description'] ?? $data['code'] ?? 'Observation';
        }
        if (empty($data['ob_status'])) {
            $data['ob_status'] = 'final';
        }

        return $data;
    }

    /**
     * Creates a default encounter for observation if none exists.
     * 
     * NOTE: This method is ONLY used for Web Interface requests as a fallback.
     * For API requests, users MUST provide an encounter reference in the FHIR Observation resource.
     * 
     * Note: We use EncounterService::insertEncounter() directly instead of FhirEncounterService
     * because FhirEncounterService currently only supports reading/searching encounters
     * (it uses FhirServiceBaseEmptyTrait which has empty insertOpenEMRRecord implementation).
     * 
     * This method follows the same logic as the UI (interface/forms/newpatient/save.php)
     * to ensure all required fields are properly set.
     *
     * @param int $pid Patient ID
     * @return int|null The encounter ID, or null if creation failed
     */
    private function createDefaultEncounterForObservation($pid)
    {
        try {
            // Get patient UUID (required for EncounterService)
            $patientService = new PatientService();
            $patient = $patientService->findByPid($pid);
            if (empty($patient) || empty($patient['uuid'])) {
                throw new \Exception("Patient not found or missing UUID for pid: " . $pid);
            }
            $puuid = UuidRegistry::uuidToString($patient['uuid']);
            
            // Get default values following the same logic as UI (interface/forms/newpatient/save.php)
            $today = date('Y-m-d');
            $userId = (isset($_SESSION) && isset($_SESSION['authUserID'])) ? $_SESSION['authUserID'] : 1;
            $userName = (isset($_SESSION) && isset($_SESSION['authUser'])) ? $_SESSION['authUser'] : 'admin';
            $userGroup = (isset($_SESSION) && isset($_SESSION['authProvider'])) ? $_SESSION['authProvider'] : 'Default';
            
            // Get default facility (from user or primary facility)
            $facilityService = new FacilityService();
            $facility_id = null;
            $facility = null;
            $billing_facility = null;
            $pos_code = null;
            
            if ($userId > 0) {
                $userFacility = $facilityService->getFacilityForUser($userId);
                if (!empty($userFacility)) {
                    $facility_id = $userFacility['id'] ?? null;
                    $facility = $userFacility['name'] ?? null;
                    $billing_facility = $userFacility['id'] ?? null;
                }
            }
            
            // If no facility from user, get primary facility
            if (empty($facility_id)) {
                $primaryFacility = $facilityService->getPrimaryBusinessEntity();
                if (!empty($primaryFacility)) {
                    $facility_id = $primaryFacility['id'] ?? null;
                    $facility = $primaryFacility['name'] ?? null;
                    $billing_facility = $primaryFacility['id'] ?? null;
                }
            }
            
            // Get pos_code from facility
            if (!empty($facility_id)) {
                $pos_code = $this->encounterService->getPosCode($facility_id);
            }
            
            // Get default class_code (required field) - same logic as UI
            $listService = new ListService();
            $class_code = null;
            $classOptions = $listService->getOptionsByListName('_ActEncounterCode');
            if (!empty($classOptions)) {
                // Find the default
                foreach ($classOptions as $code) {
                    if (!empty($code['is_default'])) {
                        $class_code = $code['option_id'];
                        break;
                    }
                }
                // If no default, use first entry
                if (empty($class_code) && !empty($classOptions[0])) {
                    $class_code = $classOptions[0]['option_id'];
                }
            }
            // Fallback to default if still empty
            if (empty($class_code)) {
                $class_code = EncounterService::DEFAULT_CLASS_CODE; // 'AMB'
            }
            
            // Get default pc_catid (visit category) - required field
            // Try to get first available visit category
            $pc_catid = null;
            $visitCategories = QueryUtils::fetchRecords(
                "SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_active = 1 ORDER BY pc_catid LIMIT 1",
                []
            );
            if (!empty($visitCategories) && !empty($visitCategories[0]['pc_catid'])) {
                $pc_catid = $visitCategories[0]['pc_catid'];
            }
            
            // Prepare encounter data matching UI structure (interface/forms/newpatient/save.php)
            $encounterData = [
                'date' => $today,
                'reason' => 'FHIR Observation Entry',
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
            
            // Use EncounterService to create encounter (same as UI does)
            $encounterResult = $this->encounterService->insertEncounter($puuid, $encounterData);
            
            if ($encounterResult->isValid() && $encounterResult->hasData()) {
                $encounterData = $encounterResult->getFirstDataResult();
                $encounterId = $encounterData['eid'] ?? $encounterData['encounter'] ?? null;
                
                $this->logger->info("Created encounter for observation using EncounterService", [
                    'pid' => $pid,
                    'puuid' => $puuid,
                    'encounter' => $encounterId,
                    'euuid' => $encounterData['euuid'] ?? null
                ]);
                
                return $encounterId;
            } else {
                $validationMessages = $encounterResult->getValidationMessages();
                $internalErrors = $encounterResult->getInternalErrors();
                throw new \Exception("Failed to create encounter: " . json_encode([
                    'validation' => $validationMessages,
                    'errors' => $internalErrors
                ]));
            }
        } catch (\Throwable $e) {
            $this->logger->error("Failed to create encounter using EncounterService", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'pid' => $pid
            ]);
            return null;
        }
    }

    /**
     * Inserts an OpenEMR observation record into the system.
     *
     * @param array $openEmrRecord OpenEMR observation record
     * @return ProcessingResult
     */
    public function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();

        try {
            // Validate required fields
            if (empty($openEmrRecord['pid'])) {
                $processingResult->setValidationMessages(['pid' => 'Patient ID is required']);
                return $processingResult;
            }

            // Validate encounter - it should be provided by the user in the FHIR resource
            // The encounter should be extracted from Observation.encounter field in parseFhirResource()
            if (empty($openEmrRecord['encounter'])) {
                // Check if this is a web interface request (has session with encounter context)
                $isWebInterface = isset($_SESSION) && isset($_SESSION['encounter']) && !empty($_SESSION['encounter']);
                
                if ($isWebInterface) {
                    // For web interface, use session encounter
                    $openEmrRecord['encounter'] = $_SESSION['encounter'];
                } elseif (isset($_SESSION) && isset($_SESSION['pid']) && $_SESSION['pid'] == $openEmrRecord['pid']) {
                    // Web interface but no encounter in session - try to find today's encounter
                    try {
                        $today = date('Y-m-d');
                        $existingEncounter = QueryUtils::fetchRecords(
                            "SELECT encounter FROM form_encounter WHERE pid = ? AND date = ? ORDER BY id DESC LIMIT 1",
                            [$openEmrRecord['pid'], $today]
                        );
                        
                        if (!empty($existingEncounter) && !empty($existingEncounter[0])) {
                            $openEmrRecord['encounter'] = $existingEncounter[0]['encounter'];
                            $this->logger->info("Using today's existing encounter for web interface observation", [
                                'pid' => $openEmrRecord['pid'],
                                'encounter' => $openEmrRecord['encounter']
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error("Failed to get existing encounter for observation", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'pid' => $openEmrRecord['pid']
                        ]);
                    }
                    
                    // For web interface, if still no encounter, create one as fallback
                    if (empty($openEmrRecord['encounter'])) {
                        $createdEncounter = $this->createDefaultEncounterForObservation($openEmrRecord['pid']);
                        if (!empty($createdEncounter)) {
                            $openEmrRecord['encounter'] = $createdEncounter;
                            $this->logger->info("Created default encounter for web interface observation", [
                                'pid' => $openEmrRecord['pid'],
                                'encounter' => $openEmrRecord['encounter']
                            ]);
                        }
                    }
                }
                
                // For API requests (not web interface), encounter MUST be provided in FHIR resource
                // If user didn't provide encounter in Observation.encounter field, return validation error
                if (empty($openEmrRecord['encounter'])) {
                    $errorMessage = 'Encounter is required for Observation. ';
                    $errorMessage .= 'Please provide an encounter reference: Observation.encounter.reference = "Encounter/{encounter-uuid}". ';
                    $errorMessage .= 'You can reference an existing Encounter, or create a new Encounter in the same FHIR Bundle. ';
                    $errorMessage .= 'To create an Encounter in a Bundle, include it as a separate entry before the Observation entry. ';
                    $errorMessage .= 'Required fields for Encounter creation: subject (Patient reference), class (encounter class code), status. ';
                    $errorMessage .= 'Optional but recommended: serviceProvider (Facility/Organization), participant (Practitioner), period (date).';
                    
                    $processingResult->setValidationMessages([
                        'encounter' => $errorMessage
                    ]);
                    $this->logger->warning("Observation creation failed: missing encounter reference", [
                        'pid' => $openEmrRecord['pid'] ?? null,
                        'context' => 'API request - encounter must be provided in FHIR resource',
                        'note' => 'Encounter can be created via FHIR Bundle or must reference existing Encounter'
                    ]);
                    return $processingResult;
                }
            }

            // Ensure we have minimum required fields for saveObservation
            if (empty($openEmrRecord['groupname'])) {
                // Try to get from user if available
                if (!empty($openEmrRecord['user'])) {
                    $userFacility = QueryUtils::fetchRecords(
                        "SELECT facility FROM users WHERE username = ?",
                        [$openEmrRecord['user']]
                    );
                    if (!empty($userFacility) && !empty($userFacility[0])) {
                        $openEmrRecord['groupname'] = $userFacility[0]['facility'];
                    }
                }
                // If still empty, use a default
                if (empty($openEmrRecord['groupname'])) {
                    $openEmrRecord['groupname'] = 'Default';
                }
            }
            
            // Save the observation using ObservationService
            try {
                $savedObservation = $this->observationService->saveObservation($openEmrRecord);

                if (!empty($savedObservation) && !empty($savedObservation['id']) && !empty($savedObservation['uuid'])) {
                    // Log the savedObservation structure for debugging
                    $this->logger->debug("FhirObservationService::insertOpenEMRRecord() savedObservation", [
                        'id' => $savedObservation['id'] ?? null,
                        'uuid' => $savedObservation['uuid'] ?? null,
                        'ob_value' => $savedObservation['ob_value'] ?? null,
                        'ob_value_type' => gettype($savedObservation['ob_value'] ?? null),
                        'ob_type' => $savedObservation['ob_type'] ?? null,
                        'ob_unit' => $savedObservation['ob_unit'] ?? null
                    ]);
                    
                    // Convert saved observation to FHIR resource directly
                    // savedObservation already contains all the data we need from ObservationService::saveObservation()
                    $fhirResource = $this->parseOpenEMRRecord($savedObservation, false);
                    $processingResult->addData($fhirResource);
                } else {
                    $processingResult->addInternalError("Failed to save observation record - no ID or UUID returned");
                }
            } catch (\Exception $saveException) {
                $this->logger->error("FhirObservationService::insertOpenEMRRecord() saveObservation exception", [
                    'error' => $saveException->getMessage(),
                    'trace' => $saveException->getTraceAsString(),
                    'observationData' => $openEmrRecord
                ]);
                $processingResult->addInternalError("Error saving observation: " . $saveException->getMessage());
            }
        } catch (\Exception $e) {
            $this->logger->error("FhirObservationService::insertOpenEMRRecord() exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $processingResult->addInternalError("Error inserting observation: " . $e->getMessage());
        }

        return $processingResult;
    }

    /**
     * Updates an existing OpenEMR observation record.
     *
     * @param string $fhirResourceId The OpenEMR record's FHIR Resource ID (UUID).
     * @param array $updatedOpenEMRRecord The "updated" OpenEMR record.
     * @return ProcessingResult
     */
    public function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord)
    {
        $processingResult = new ProcessingResult();

        try {
            // Get the existing observation by UUID
            $uuidBytes = UuidRegistry::uuidToBytes($fhirResourceId);
            $existingObservations = QueryUtils::fetchRecords(
                "SELECT id, pid, encounter FROM form_observation WHERE uuid = ?",
                [$uuidBytes]
            );
            $existingObservation = !empty($existingObservations) ? $existingObservations[0] : null;

            if (empty($existingObservation)) {
                $processingResult->setValidationMessages(['_id' => 'Observation not found']);
                return $processingResult;
            }

            // Merge with existing data
            $updatedOpenEMRRecord['id'] = $existingObservation['id'];
            $updatedOpenEMRRecord['pid'] = $existingObservation['pid'];
            $updatedOpenEMRRecord['encounter'] = $existingObservation['encounter'];

            // Save the updated observation
            $savedObservation = $this->observationService->saveObservation($updatedOpenEMRRecord);

            if (!empty($savedObservation) && !empty($savedObservation['id']) && !empty($savedObservation['uuid'])) {
                // Convert saved observation to FHIR resource directly
                // savedObservation already contains all the data we need from ObservationService::saveObservation()
                $fhirResource = $this->parseOpenEMRRecord($savedObservation, false);
                $processingResult->addData($fhirResource);
            } else {
                $processingResult->addInternalError("Failed to update observation record - no ID or UUID returned");
            }
        } catch (\Exception $e) {
            $this->logger->error("FhirObservationService::updateOpenEMRRecord() exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $processingResult->addInternalError("Error updating observation: " . $e->getMessage());
        }

        return $processingResult;
    }

    /**
     * Parses an OpenEMR data record, returning the equivalent FHIR Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param bool $encode Indicates if the returned resource is encoded into a string. Defaults to False.
     * @return FHIRObservation|string the FHIR Resource. Returned format is defined using $encode parameter.
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        // For generic observations, we'll use the ObservationService to get the data
        // and convert it to FHIR format
        // This is a simplified implementation - in practice, you might want to delegate
        // to a specific service based on category/code
        
        $observation = new FHIRObservation();
        $meta = new \OpenEMR\FHIR\R4\FHIRElement\FHIRMeta();
        $meta->setVersionId('1');
        if (!empty($dataRecord['date'])) {
            $meta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['date']));
        } else {
            $meta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $observation->setMeta($meta);

        $id = new \OpenEMR\FHIR\R4\FHIRElement\FHIRId();
        // Ensure uuid is a string (it should already be converted by createResultRecordFromDatabaseResult)
        $uuidValue = $dataRecord['uuid'] ?? null;
        if (!empty($uuidValue) && !is_string($uuidValue)) {
            $uuidValue = UuidRegistry::uuidToString($uuidValue);
        }
        $id->setValue($uuidValue);
        $observation->setId($id);

        // Set status
        $status = $dataRecord['ob_status'] ?? 'final';
        $observation->setStatus($status);

        // Set code
        if (!empty($dataRecord['code'])) {
            $codeConcept = new \OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept();
            $coding = new \OpenEMR\FHIR\R4\FHIRElement\FHIRCoding();
            $coding->setSystem('http://loinc.org');
            $coding->setCode($dataRecord['code']);
            $coding->setDisplay($dataRecord['description'] ?? $dataRecord['observation'] ?? null);
            $codeConcept->addCoding($coding);
            $observation->setCode($codeConcept);
        }

        // Set subject (patient)
        if (!empty($dataRecord['pid'])) {
            $patientUuidBytes = QueryUtils::fetchRecords(
                "SELECT uuid FROM patient_data WHERE pid = ?",
                [$dataRecord['pid']]
            );
            if (!empty($patientUuidBytes) && !empty($patientUuidBytes[0])) {
                $patientUuid = UuidRegistry::uuidToString($patientUuidBytes[0]['uuid']);
                $subject = new \OpenEMR\FHIR\R4\FHIRElement\FHIRReference();
                $subject->setReference('Patient/' . $patientUuid);
                $observation->setSubject($subject);
            }
        }

        // Set effective date
        if (!empty($dataRecord['date'])) {
            $effectiveDateTime = new \OpenEMR\FHIR\R4\FHIRElement\FHIRDateTime();
            $effectiveDateTime->setValue(UtilsService::getLocalDateAsUTC($dataRecord['date']));
            $observation->setEffectiveDateTime($effectiveDateTime);
        }

        // Set value
        $this->logger->debug("FhirObservationService::parseOpenEMRRecord() processing value", [
            'ob_value' => $dataRecord['ob_value'] ?? null,
            'ob_value_type' => gettype($dataRecord['ob_value'] ?? null),
            'ob_type' => $dataRecord['ob_type'] ?? null,
            'ob_unit' => $dataRecord['ob_unit'] ?? null,
            'has_ob_value' => !empty($dataRecord['ob_value'])
        ]);
        
        if (!empty($dataRecord['ob_value'])) {
            // Ensure ob_value is a scalar value, not an array
            $obValue = $dataRecord['ob_value'];
            if (is_array($obValue)) {
                // If it's an array, try to get the first element or convert to string
                $obValue = !empty($obValue) ? (is_array($obValue[0] ?? null) ? json_encode($obValue) : ($obValue[0] ?? (string)$obValue)) : null;
            }
            
            if ($dataRecord['ob_type'] === 'numeric' || is_numeric($obValue)) {
                $valueQuantity = new \OpenEMR\FHIR\R4\FHIRElement\FHIRQuantity();
                // Convert to float/int for numeric values
                $numericValue = is_numeric($obValue) ? (float)$obValue : null;
                if ($numericValue !== null) {
                    $valueQuantity->setValue($numericValue);
                    if (!empty($dataRecord['ob_unit'])) {
                        $valueQuantity->setUnit((string)$dataRecord['ob_unit']);
                    }
                    $observation->setValueQuantity($valueQuantity);
                    $this->logger->debug("FhirObservationService::parseOpenEMRRecord() set valueQuantity", [
                        'value' => $numericValue,
                        'unit' => $dataRecord['ob_unit'] ?? null
                    ]);
                } else {
                    $this->logger->warning("FhirObservationService::parseOpenEMRRecord() numericValue is null", [
                        'obValue' => $obValue,
                        'ob_type' => $dataRecord['ob_type'] ?? null
                    ]);
                }
            } else {
                $valueString = new \OpenEMR\FHIR\R4\FHIRElement\FHIRString();
                $valueString->setValue((string)$obValue);
                $observation->setValueString($valueString);
                $this->logger->debug("FhirObservationService::parseOpenEMRRecord() set valueString", [
                    'value' => (string)$obValue
                ]);
            }
        } else {
            $this->logger->warning("FhirObservationService::parseOpenEMRRecord() ob_value is empty", [
                'dataRecord_keys' => array_keys($dataRecord)
            ]);
        }

        // Set category
        if (!empty($dataRecord['category'])) {
            $categoryConcept = new \OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept();
            $categoryCoding = new \OpenEMR\FHIR\R4\FHIRElement\FHIRCoding();
            $categoryCoding->setSystem('http://terminology.hl7.org/CodeSystem/observation-category');
            $categoryCoding->setCode($dataRecord['category']);
            $categoryConcept->addCoding($categoryCoding);
            $observation->addCategory($categoryConcept);
        }

        if ($encode) {
            return json_encode($observation);
        } else {
            return $observation;
        }
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param array $openEMRSearchParameters OpenEMR search fields
     * @param string|null $puuidBind Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters, $puuidBind = null): ProcessingResult
    {
        $processingResult = new ProcessingResult();
        
        try {
            // Build search query
            $where = [];
            $bind = [];
            
            if (!empty($openEMRSearchParameters['pid'])) {
                $where[] = "pid = ?";
                $bind[] = $openEMRSearchParameters['pid'];
            }
            
            if (!empty($openEMRSearchParameters['code'])) {
                $where[] = "code = ?";
                $bind[] = $openEMRSearchParameters['code'];
            }
            
            if (!empty($openEMRSearchParameters['category'])) {
                $where[] = "category = ?";
                $bind[] = $openEMRSearchParameters['category'];
            }
            
            if (!empty($openEMRSearchParameters['date'])) {
                $where[] = "date >= ?";
                $bind[] = $openEMRSearchParameters['date'];
            }
            
            $sql = "SELECT * FROM form_observation";
            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }
            $sql .= " ORDER BY date DESC";
            
            $records = QueryUtils::fetchRecords($sql, $bind);
            
            foreach ($records as $record) {
                $processingResult->addData($record);
            }
        } catch (\Exception $e) {
            $this->logger->error("FhirObservationService::searchForOpenEMRRecords() exception", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $processingResult->addInternalError("Error searching observations: " . $e->getMessage());
        }
        
        return $processingResult;
    }

    /**
     * Creates the Provenance resource for the equivalent FHIR Resource
     *
     * @param mixed $dataRecord The source OpenEMR data record or FHIR resource
     * @param bool $encode Indicates if the returned resource is encoded into a string. Defaults to False.
     * @return \OpenEMR\FHIR\R4\FHIRResource\FhirProvenance|string the FHIR Resource. Returned format is defined using $encode parameter.
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        if (!($dataRecord instanceof FHIRObservation)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        
        $fhirProvenanceService = new \OpenEMR\Services\FHIR\FhirProvenanceService();
        $performer = null;
        if (!empty($dataRecord->getPerformer())) {
            // grab the first one
            $performer = current($dataRecord->getPerformer());
        }
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord, $performer);
        
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }
}
