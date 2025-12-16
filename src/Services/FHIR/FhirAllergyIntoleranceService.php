<?php

namespace OpenEMR\Services\FHIR;

use Google\Service;
use OpenEMR\FHIR\Export\ExportCannotEncodeException;
use OpenEMR\FHIR\Export\ExportException;
use OpenEMR\FHIR\Export\ExportJob;
use OpenEMR\FHIR\Export\ExportStreamWriter;
use OpenEMR\FHIR\Export\ExportWillShutdownException;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRElement\FHIRUri;
use OpenEMR\FHIR\R4\FHIRResource\FHIRAllergyIntolerance\FHIRAllergyIntoleranceReaction;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\AllergyIntoleranceService;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRAllergyIntolerance;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRProvenance;
use OpenEMR\FHIR\R4\FHIRResource\FHIRProvenance\FHIRProvenanceAgent;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAddress;
use OpenEMR\FHIR\R4\FHIRElement\FHIRHumanName;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAdministrativeGender;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAllergyIntoleranceCategory;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAllergyIntoleranceCriticality;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\PractitionerService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\ReferenceSearchValue;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\Common\Uuid;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\FHIR\UtilsService;

/**
 * FHIR AllergyIntolerance Service
 *
 * @package   OpenEMR
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class FhirAllergyIntoleranceService extends FhirServiceBase implements IResourceUSCIGProfileService, IPatientCompartmentResourceService, IFhirExportableResourceService
{
    use FhirServiceBaseEmptyTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    /**
     * @var AllergyIntoleranceService
     */
    private $allergyIntoleranceService;

    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-allergyintolerance';

    public function __construct($fhirAPIURL = null)
    {
        parent::__construct($fhirAPIURL);
        $this->allergyIntoleranceService = new AllergyIntoleranceService();
    }

    /**
     * Returns an array mapping FHIR AllergyIntolerance Resource search parameters to OpenEMR AllergyIntolerance search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('allergy_uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['modifydate']);
    }

    /**
     * Parses an OpenEMR allergyIntolerance record, returning the equivalent FHIR AllergyIntolerance Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRAllergyIntolerance
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        if (!($dataRecord instanceof FHIRAllergyIntolerance)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord, $dataRecord->getRecorder());
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }

    /**
     * Parses an OpenEMR allergyIntolerance record, returning the equivalent FHIR AllergyIntolerance Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRAllergyIntolerance
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $allergyIntoleranceResource = new FHIRAllergyIntolerance();
        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId("1");
        if (!empty($dataRecord['date'])) {
            $fhirMeta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['modifydate']));
        } else {
            $fhirMeta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $allergyIntoleranceResource->setMeta($fhirMeta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $allergyIntoleranceResource->setId($id);

        $clinicalStatus = "inactive";
        if ($dataRecord['outcome'] == '1' && isset($dataRecord['enddate'])) {
            $clinicalStatus = "resolved";
        } elseif (!isset($dataRecord['enddate'])) {
            $clinicalStatus = "active";
        }
        $clinical_Status = new FHIRCodeableConcept();
        $clinical_Status->addCoding(
            array(
            'system' => "http://terminology.hl7.org/CodeSystem/allergyintolerance-clinical",
            'code' => $clinicalStatus,
            'display' => ucwords($clinicalStatus),
            )
        );
        $allergyIntoleranceResource->setClinicalStatus($clinical_Status);

        $allergyIntoleranceCategory = new FHIRAllergyIntoleranceCategory();
        // @see https://www.hl7.org/fhir/us/core/StructureDefinition-us-core-allergyintolerance-definitions.html#AllergyIntolerance.category
        $allergyIntoleranceCategory->setValue("medication");
        $allergyIntoleranceResource->addCategory($allergyIntoleranceCategory);

        if (isset($dataRecord['severity_al'])) {
            $criticalityCode = array(
                "mild" => ["code" => "low", "display" => "Low Risk"],
                "mild_to_moderate" => ["code" => "low", "display" => "Low Risk"],
                "moderate" => ["code" => "low", "display" => "Low Risk"],
                "moderate_to_severe" => ["code" => "high", "display" => "High Risk"],
                "severe" => ["code" => "high", "display" => "High Risk"],
                "life_threatening_severity" => ["code" => "high", "display" => "High Risk"],
                "fatal" => ["code" => "high", "display" => "High Risk"],
                "unassigned" => ["code" => "unable-to-assess", "display" => "Unable to Assess Risk"],
            );
            $criticality = new FHIRAllergyIntoleranceCriticality();
            $criticality->setValue($criticalityCode[$dataRecord['severity_al']]['code']);
            $allergyIntoleranceResource->setCriticality($criticality);
        }

        if (isset($dataRecord['puuid'])) {
            $patient = new FHIRReference();
            $patient->setReference('Patient/' . $dataRecord['puuid']);
            $allergyIntoleranceResource->setPatient($patient);
        }

        if (isset($dataRecord['practitioner']) && !empty($dataRecord['practitioner_npi'])) {
            $recorder = new FHIRReference();
            $recorder->setReference('Practitioner/' . $dataRecord['practitioner']);
            $allergyIntoleranceResource->setRecorder($recorder);
        }

        // cardinality is 0..*
        // however in OpenEMR we currently only track a single reaction, we will populate it if we have it.
        // if a reaction is unassigned, it has no codes and so we will skip over this as it has no meaning in FHIR.
        if (!empty($dataRecord['reaction']) && $dataRecord['reaction'] !== 'unassigned') {
            $reaction = new FHIRAllergyIntoleranceReaction();
            $reactionConcept = new FHIRCodeableConcept();
            $conceptText = $dataRecord['reaction_title'] ?? "";
            $reactionConcept->setText($conceptText);

            foreach ($dataRecord['reaction'] as $code => $codeValues) {
                $reactionCoding = new FHIRCoding();
                // some of our codes are parsed as numbers on the underlying service.. and we need to force them as
                // strings
                if (is_numeric($code)) {
                    $code = "$code";
                }

                $reactionCoding->setCode($code);
                $display = !empty($display) ? $codeValues['description'] : $dataRecord['reaction_title'];
                // Ensure display is a string (handle case where it might be an array)
                $display = is_array($display) ? (string)($display[0] ?? '') : (string)($display ?? '');
                // we trim as some of the database values have white space which violates ONC spec
                $reactionCoding->setDisplay(trim($display));
                // @see http://hl7.org/fhir/R4/valueset-clinical-findings.html
                $system = $codeValues['system'] ?? '';
                // Ensure system is a string (handle case where it might be an array)
                $system = is_array($system) ? (string)($system[0] ?? '') : (string)($system ?? '');
                $reactionCoding->setSystem($system);
                $reactionConcept->addCoding($reactionCoding);
            }
            $reaction->addManifestation($reactionConcept);
            $allergyIntoleranceResource->addReaction($reaction);
        } else {
            $reaction = new FHIRAllergyIntoleranceReaction();
            $reaction->addManifestation(UtilsService::createDataAbsentUnknownCodeableConcept());
        }

        if (!empty($dataRecord['diagnosis'])) {
            $diagnosisCoding = new FHIRCoding();
            $diagnosisCode = new FHIRCodeableConcept();
            foreach ($dataRecord['diagnosis'] as $code => $codeValues) {
                // some of our codes are parsed as numbers on the underlying service.. and we need to force them as
                // strings
                if (is_numeric($code)) {
                    $code = "$code";
                }
                $diagnosisCoding->setCode($code);
                // if we have no display value we will just show the code value here
                $display = !empty($codeValues['description']) ? $codeValues['description'] : $dataRecord['title'];
                // Ensure display is a string (handle case where it might be an array)
                $display = is_array($display) ? (string)($display[0] ?? '') : (string)($display ?? '');
                // we trim as some of the database values have white space which violates ONC spec
                $diagnosisCoding->setDisplay(trim($display));
                $system = $codeValues['system'] ?? '';
                // Ensure system is a string (handle case where it might be an array)
                $system = is_array($system) ? (string)($system[0] ?? '') : (string)($system ?? '');
                $diagnosisCoding->setSystem($system);
                $diagnosisCode->addCoding($diagnosisCoding);
            }
            $allergyIntoleranceResource->setCode($diagnosisCode);
        } else {
            $allergyIntoleranceResource->setCode(UtilsService::createDataAbsentUnknownCodeableConcept());
        }
        // we don't have title anywhere else so we mark it as an additional narrative.  If we don't have an actual code
        // this becomes very helpful.
        $title = $dataRecord['title'] ?? '';
        // Ensure title is a string (handle case where it might be an array)
        $title = is_array($title) ? (string)($title[0] ?? '') : (string)$title;
        $allergyIntoleranceResource->setText(UtilsService::createNarrative($title, "additional"));

        $verificationStatus = new FHIRCodeableConcept();
        $verificationCoding = array(
            'system' => "http://terminology.hl7.org/CodeSystem/allergyintolerance-verification",
            'code' => 'unconfirmed',
            'display' => 'Unconfirmed',
        );
        if (!empty($dataRecord['verification'])) {
            $verificationCoding = array(
                'system' => "http://terminology.hl7.org/CodeSystem/allergyintolerance-verification",
                'code' => $dataRecord['verification'],
                'display' => $dataRecord['verification_title']
            );
        }
        $verificationStatus->addCoding($verificationCoding);
        $allergyIntoleranceResource->setVerificationStatus($verificationStatus);

        if ($encode) {
            return json_encode($allergyIntoleranceResource);
        } else {
            return $allergyIntoleranceResource;
        }
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters, $puuidBind = null): ProcessingResult
    {
        return $this->allergyIntoleranceService->search($openEMRSearchParameters, true, $puuidBind);
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
     * Parses a FHIR AllergyIntolerance resource, returning the equivalent OpenEMR record.
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource)
    {
        // Ensure it's an AllergyIntolerance resource
        if (!($fhirResource instanceof FHIRAllergyIntolerance)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRAllergyIntolerance");
        }
        
        $data = [];

        // Extract patient reference
        $patientRef = $fhirResource->getPatient();
        if (!empty($patientRef)) {
            $patientReference = UtilsService::parseReference($patientRef);
            if (!empty($patientReference) && $patientReference['type'] === 'Patient' && $patientReference['localResource']) {
                $data['puuid'] = $patientReference['uuid'];
            } elseif (is_array($patientRef) && !empty($patientRef['reference'])) {
                // Handle array format (from JSON deserialization)
                $referenceString = $patientRef['reference'];
                $parts = explode('/', $referenceString);
                if (count($parts) >= 2 && $parts[0] === 'Patient') {
                    $data['puuid'] = $parts[1];
                }
            }
        }

        // Extract code (allergen/diagnosis)
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

        // Extract criticality/severity
        $criticality = $fhirResource->getCriticality();
        if (!empty($criticality)) {
            if (is_object($criticality) && method_exists($criticality, 'getValue')) {
                $criticalityValue = $criticality->getValue();
                // Map FHIR criticality to OpenEMR severity
                $severityMap = [
                    'low' => 'mild',
                    'high' => 'severe',
                    'unable-to-assess' => 'unassigned'
                ];
                $data['severity_al'] = $severityMap[$criticalityValue] ?? 'unassigned';
            } elseif (is_string($criticality)) {
                $severityMap = [
                    'low' => 'mild',
                    'high' => 'severe',
                    'unable-to-assess' => 'unassigned'
                ];
                $data['severity_al'] = $severityMap[$criticality] ?? 'unassigned';
            }
        }

        // Extract reactions
        $reactions = $fhirResource->getReaction();
        if (!empty($reactions) && is_array($reactions)) {
            $firstReaction = $reactions[0];
            if (is_object($firstReaction) && method_exists($firstReaction, 'getManifestation')) {
                $manifestations = $firstReaction->getManifestation();
                if (!empty($manifestations) && is_array($manifestations)) {
                    $firstManifestation = $manifestations[0];
                    if (is_object($firstManifestation) && method_exists($firstManifestation, 'getCoding')) {
                        $codings = $firstManifestation->getCoding();
                        if (!empty($codings) && is_array($codings)) {
                            $reactionCoding = $codings[0];
                            if (is_object($reactionCoding) && method_exists($reactionCoding, 'getCode')) {
                                $reactionCodeObj = $reactionCoding->getCode();
                                $reactionCode = is_object($reactionCodeObj) && method_exists($reactionCodeObj, 'getValue') ? $reactionCodeObj->getValue() : (string)$reactionCodeObj;
                                $reactionSystemObj = method_exists($reactionCoding, 'getSystem') ? $reactionCoding->getSystem() : null;
                                $reactionSystem = is_object($reactionSystemObj) && method_exists($reactionSystemObj, 'getValue') ? $reactionSystemObj->getValue() : (string)($reactionSystemObj ?? '');
                                $reactionDisplayObj = method_exists($reactionCoding, 'getDisplay') ? $reactionCoding->getDisplay() : null;
                                $reactionDisplay = is_object($reactionDisplayObj) && method_exists($reactionDisplayObj, 'getValue') ? $reactionDisplayObj->getValue() : (string)($reactionDisplayObj ?? '');
                                
                                $data['reaction'] = [
                                    $reactionCode => [
                                        'code' => $reactionCode,
                                        'system' => $reactionSystem ?: 'http://snomed.info/sct',
                                        'description' => $reactionDisplay ?: $reactionCode
                                    ]
                                ];
                                $data['reaction_title'] = $reactionDisplay ?: $reactionCode;
                            }
                        }
                    }
                }
            } elseif (is_array($firstReaction)) {
                if (!empty($firstReaction['manifestation']) && is_array($firstReaction['manifestation'])) {
                    $firstManifestation = $firstReaction['manifestation'][0] ?? null;
                    if (is_array($firstManifestation) && !empty($firstManifestation['coding'])) {
                        $reactionCoding = $firstManifestation['coding'][0] ?? null;
                        if (is_array($reactionCoding)) {
                            $reactionCode = $reactionCoding['code'] ?? null;
                            $reactionSystem = $reactionCoding['system'] ?? 'http://snomed.info/sct';
                            $reactionDisplay = $reactionCoding['display'] ?? $reactionCode;
                            
                            if ($reactionCode) {
                                $data['reaction'] = [
                                    $reactionCode => [
                                        'code' => $reactionCode,
                                        'system' => $reactionSystem,
                                        'description' => $reactionDisplay
                                    ]
                                ];
                                $data['reaction_title'] = $reactionDisplay;
                            }
                        }
                    }
                }
            }
        }

        // Extract recorder (practitioner)
        $recorder = $fhirResource->getRecorder();
        if (!empty($recorder)) {
            $recorderReference = UtilsService::parseReference($recorder);
            if (!empty($recorderReference) && $recorderReference['type'] === 'Practitioner' && $recorderReference['localResource']) {
                // Get username from practitioner UUID
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
                // Convert to datetime format (Y-m-d H:i:s) for database validation
                // Handle both ISO 8601 format (2024-01-15T00:00:00+00:00) and simple date (2024-01-15)
                try {
                    $dateObj = new \DateTime($dateValue);
                    // Validator expects Y-m-d H:i:s format, so add time if not present
                    $data['begdate'] = $dateObj->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    // If date parsing fails, try to extract just the date part and add time
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                        $data['begdate'] = $matches[1] . ' 00:00:00';
                    }
                }
            }
        }

        // Extract end date (if resolved)
        if (!empty($data['outcome']) && $data['outcome'] === '1') {
            $data['enddate'] = date('Y-m-d');
        }

        // Set default values
        if (empty($data['user'])) {
            $data['user'] = (isset($_SESSION) && isset($_SESSION['authUser'])) ? $_SESSION['authUser'] : null;
        }

        // Set comments if available
        $text = $fhirResource->getText();
        if (!empty($text) && is_object($text) && method_exists($text, 'getDiv')) {
            // Extract text from narrative if available
            $div = $text->getDiv();
            if (!empty($div)) {
                // Simple extraction - remove HTML tags
                $data['comments'] = strip_tags($div);
            }
        }

        return $data;
    }

    /**
     * Inserts an OpenEMR allergyIntolerance record into the system.
     *
     * @param array $openEmrRecord OpenEMR allergyIntolerance record
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

            // Convert diagnosis and reaction arrays to strings for database storage
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
            
            if (!empty($openEmrRecord['reaction']) && is_array($openEmrRecord['reaction'])) {
                $reactionStrings = [];
                foreach ($openEmrRecord['reaction'] as $code => $codeValues) {
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
                    $reactionStrings[] = $codeType . ':' . $code;
                }
                $openEmrRecord['reaction'] = implode(';', $reactionStrings);
            }

            // Use AllergyIntoleranceService to insert
            $insertResult = $this->allergyIntoleranceService->insert($openEmrRecord);

            if ($insertResult->isValid() && !empty($insertResult->getData())) {
                // Get the inserted record
                $insertedData = $insertResult->getData()[0];
                $uuid = $insertedData['uuid'] ?? null;

                if (!empty($uuid)) {
                    // Ensure UUID is in string format (insert() already returns string UUID)
                    $uuidString = is_string($uuid) ? $uuid : UuidRegistry::uuidToString($uuid);
                    
                    // Fetch the complete record and convert to FHIR
                    $allergyRecordResult = $this->allergyIntoleranceService->getOne($uuidString);
                    
                    if ($allergyRecordResult->hasData() && count($allergyRecordResult->getData()) > 0) {
                        $allergyRecord = $allergyRecordResult->getData()[0];
                        
                        // Ensure it's an array (OpenEMR record format)
                        if (!is_array($allergyRecord)) {
                            $allergyRecord = json_decode(json_encode($allergyRecord), true);
                        }
                        
                        // Convert complete OpenEMR allergy record to FHIR resource
                        $fhirResource = $this->parseOpenEMRRecord($allergyRecord, false);
                        $processingResult->setData([]);
                        $processingResult->addData($fhirResource);
                    } else {
                        $processingResult->addInternalError("Failed to retrieve inserted allergy record");
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
            $processingResult->addInternalError("Error inserting allergyIntolerance: " . $e->getMessage());
        }

        return $processingResult;
    }
}
