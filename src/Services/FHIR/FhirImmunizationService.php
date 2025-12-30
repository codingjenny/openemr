<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRImmunization\FHIRImmunizationPerformer;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\ImmunizationService;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRImmunization;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDate;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDateTime;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRImmunizationStatusCodes;
use OpenEMR\FHIR\R4\FHIRElement\FHIRQuantity;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\FHIR\R4\FHIRResource\FHIRImmunization\FHIRImmunizationEducation;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

/**
 * FHIR Immunization Service
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class FhirImmunizationService extends FhirServiceBase implements IResourceUSCIGProfileService, IFhirExportableResourceService, IPatientCompartmentResourceService
{
    use FhirServiceBaseEmptyTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;

    /**
     * @var ImmunizationService
     */
    private $immunizationService;

    const USCGI_PROFILE_URI = 'http://hl7.org/fhir/us/core/StructureDefinition/us-core-immunization';

    public function __construct()
    {
        parent::__construct();
        $this->immunizationService = new ImmunizationService();
    }

    /**
     * Returns an array mapping FHIR Immunization Resource search parameters to OpenEMR Immunization search parameters
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            '_id' => new FhirSearchParameterDefinition('uuid', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['update_date']);
    }

    /**
     * Parses an OpenEMR immunization record, returning the equivalent FHIR Immunization Resource
     *
     * @param array $dataRecord The source OpenEMR data record
     * @param boolean $encode Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRImmunization
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $immunizationResource = new FHIRImmunization();

        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        if (!empty($dataRecord['update_date'])) {
            $meta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['update_date']));
        } else {
            $meta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $immunizationResource->setMeta($meta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $immunizationResource->setId($id);

        $status = new FHIRImmunizationStatusCodes();
        if ($dataRecord['added_erroneously'] != "0") {
            $status->setValue("entered-in-error");
        } elseif ($dataRecord['completion_status'] == "Completed") {
            $status->setValue("completed");
        } else {
            $status->setValue("not-done");

            // TODO: @adunsulag we need to update these codes here as we need to map better from NIP002
            // to these status codes here: https://terminology.hl7.org/3.1.0/CodeSystem-v3-ActReason.html
            //
            if (!empty($dataRecord['refusal_reason_cdc_nip_code'])) {
                $code = "PATOBJ";
                $display = "patient objection";

                // we are leaving these here just to document these values as PATOBJ corresponds to both patient or
                // guardian objection.  Other doesn't have a correspondance, and patient decision is already handled.
                switch ($dataRecord['refusal_reason_cdc_nip_code']) {
                    case '00': // Parental exemption
                        break;
                    case '01': // Religious exemption
                        $code = "RELIG";
                        $display =  "religious objection";
                        break;
                    case '02': // other
                        break;
                    case '03': // patient decision
                    default:
                        break;
                }
            }
            $statusReason = new FHIRCodeableConcept();
            $statusReasonCoding = new FHIRCoding();
            $statusReasonCoding->setSystem(FhirCodeSystemConstants::IMMUNIZATION_OBJECTION_REASON);
            $statusReasonCoding->setCode($code);
            $statusReasonCoding->setDisplay($display);
            $statusReason->addCoding($statusReasonCoding);
            $immunizationResource->setStatusReason($statusReason);
        }
        $immunizationResource->setStatus($status);
        $immunizationResource->setPrimarySource($dataRecord['primarySource']);

        if (!empty($dataRecord['cvx_code'])) {
            $vaccineCode = new FHIRCodeableConcept();
            $vaccineCode->addCoding(array(
                'system' => "http://hl7.org/fhir/sid/cvx",
                'code' =>  $dataRecord['cvx_code'],
                'display' => $dataRecord['cvx_code_text']
            ));
            $immunizationResource->setVaccineCode($vaccineCode);
        }

        if (!empty($dataRecord['puuid'])) {
            $patient = new FHIRReference(['reference' => 'Patient/' . $dataRecord['puuid']]);
            $immunizationResource->setPatient($patient);
        }

        if (!empty($dataRecord['administered_date'])) {
            $occurenceDateTime = new FHIRDateTime();
            $occurenceDateTime->setValue($dataRecord['administered_date']);
            $immunizationResource->setOccurrenceDateTime($occurenceDateTime);
        }

        if (!empty($dataRecord['create_date'])) {
            $recorded = new FHIRDateTime();
            $recorded->setValue($dataRecord['create_date']);
            $immunizationResource->setRecorded($recorded);
        }

        if (!empty($dataRecord['expiration_date'])) {
            $expirationDate = new FHIRDate();
            $expirationDate->setValue($dataRecord['expiration_date']);
            $immunizationResource->setExpirationDate($expirationDate);
        }

        if (!empty($dataRecord['note'])) {
            $immunizationResource->addNote(array(
                'text' => $dataRecord['note']
            ));
        }

        if (!empty($dataRecord['administration_site'])) {
            $siteCode = new FHIRCodeableConcept();
            $siteCode->addCoding(array(
                'system' => "http://terminology.hl7.org/CodeSystem/v3-ActSite",
                'code' =>  $dataRecord['site_code'],
                'display' => $dataRecord['site_display']
            ));
            $immunizationResource->setSite($siteCode);
        }

        if (!empty($dataRecord['lot_number'])) {
            $immunizationResource->setLotNumber($dataRecord['lot_number']);
        }

        if (!empty($dataRecord['administration_site'])) {
            $doseQuantity = new FHIRQuantity();
            $doseQuantity->setValue($dataRecord['amount_administered']);
            $doseQuantity->setSystem(FhirCodeSystemConstants::UNITS_OF_MEASURE);
            $doseQuantity->setCode($dataRecord['amount_administered_unit']);
            $immunizationResource->setDoseQuantity($doseQuantity);
        }

        if (!empty($dataRecord['provider_uuid']) && !empty($dataRecord['provider_npi'])) {
            $performer = new FHIRImmunizationPerformer();
            $performer->setActor(UtilsService::createRelativeReference("Practitioner", $dataRecord['provider_uuid']));
            $immunizationResource->addPerformer($performer);
        }

        // education is failing ONC validation, since we don't need it for ONC we are going to leave it off for now.
//        if (!empty($dataRecord['education_date'])) {
//            $education = new FHIRImmunizationEducation();
//            $educationDateTime = new FHIRDateTime();
//            $educationDateTime->setValue($dataRecord['education_date']);
//            $education->setPresentationDate($educationDateTime);
//            $immunizationResource->addEducation($education);
//        }

        if ($encode) {
            return json_encode($immunizationResource);
        } else {
            return $immunizationResource;
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
        return $this->immunizationService->getAll($openEMRSearchParameters, true, $puuidBind);
    }
    public function createProvenanceResource($dataRecord = array(), $encode = false)
    {
        if (!($dataRecord instanceof FHIRImmunization)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        $author = null;
        if (!empty($dataRecord->getPerformer())) {
            $performer = current($dataRecord->getPerformer());
            $author = $performer->getActor();
        }
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord, $author);
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
    public function getProfileURIs(): array
    {
        return [self::USCGI_PROFILE_URI];
    }

    public function getPatientContextSearchField(): FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('patient', SearchFieldType::REFERENCE, [new ServiceField('puuid', ServiceField::TYPE_UUID)]);
    }

    /**
     * Parses a FHIR Immunization resource, returning the equivalent OpenEMR record.
     *
     * @param \OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(\OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource $fhirResource)
    {
        // Ensure it's an Immunization resource
        if (!($fhirResource instanceof FHIRImmunization)) {
            throw new \InvalidArgumentException("Resource must be of type FHIRImmunization");
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

        // Extract status
        $status = $fhirResource->getStatus();
        if (!empty($status)) {
            $statusValue = is_object($status) && method_exists($status, 'getValue') ? $status->getValue() : (string)$status;
            if ($statusValue === 'entered-in-error') {
                $data['added_erroneously'] = '1';
                $data['completion_status'] = 'Completed';
            } elseif ($statusValue === 'completed') {
                $data['added_erroneously'] = '0';
                $data['completion_status'] = 'Completed';
            } else {
                $data['added_erroneously'] = '0';
                $data['completion_status'] = 'Not Administered';
            }
        } else {
            $data['added_erroneously'] = '0';
            $data['completion_status'] = 'Completed';
        }

        // Extract vaccine code (CVX code)
        $vaccineCode = $fhirResource->getVaccineCode();
        if (!empty($vaccineCode)) {
            $codings = null;
            if (is_object($vaccineCode) && method_exists($vaccineCode, 'getCoding')) {
                $codings = $vaccineCode->getCoding();
            } elseif (is_array($vaccineCode) && !empty($vaccineCode['coding'])) {
                $codings = $vaccineCode['coding'];
            }
            
            if (!empty($codings)) {
                foreach ($codings as $coding) {
                    $system = null;
                    $code = null;
                    $display = null;
                    
                    if (is_object($coding)) {
                        $systemObj = method_exists($coding, 'getSystem') ? $coding->getSystem() : null;
                        $system = is_object($systemObj) && method_exists($systemObj, 'getValue') ? $systemObj->getValue() : (string)($systemObj ?? '');
                        $codeObj = method_exists($coding, 'getCode') ? $coding->getCode() : null;
                        $code = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)($codeObj ?? '');
                        $displayObj = method_exists($coding, 'getDisplay') ? $coding->getDisplay() : null;
                        $display = is_object($displayObj) && method_exists($displayObj, 'getValue') ? $displayObj->getValue() : (string)($displayObj ?? '');
                    } elseif (is_array($coding)) {
                        $system = $coding['system'] ?? '';
                        $code = $coding['code'] ?? '';
                        $display = $coding['display'] ?? '';
                    }
                    
                    // CVX code system: http://hl7.org/fhir/sid/cvx
                    if ($system === 'http://hl7.org/fhir/sid/cvx' && !empty($code)) {
                        $data['cvx_code'] = $code;
                        if (!empty($display)) {
                            $data['cvx_code_text'] = $display;
                        }
                        break; // Use first CVX code found
                    }
                }
            }
        }

        // Extract occurrence date (administered date)
        $occurrenceDateTime = $fhirResource->getOccurrenceDateTime();
        if (!empty($occurrenceDateTime)) {
            $dateValue = is_object($occurrenceDateTime) && method_exists($occurrenceDateTime, 'getValue') 
                ? $occurrenceDateTime->getValue() 
                : (string)$occurrenceDateTime;
            if (!empty($dateValue)) {
                try {
                    $dateObj = new \DateTime($dateValue);
                    $data['administered_date'] = $dateObj->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    // If date parsing fails, try to extract just the date part
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                        $data['administered_date'] = $matches[1] . ' 00:00:00';
                    }
                }
            }
        }

        // Extract recorded date (create date)
        $recorded = $fhirResource->getRecorded();
        if (!empty($recorded)) {
            $dateValue = is_object($recorded) && method_exists($recorded, 'getValue') 
                ? $recorded->getValue() 
                : (string)$recorded;
            if (!empty($dateValue)) {
                try {
                    $dateObj = new \DateTime($dateValue);
                    $data['create_date'] = $dateObj->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    // If date parsing fails, try to extract just the date part
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                        $data['create_date'] = $matches[1] . ' 00:00:00';
                    }
                }
            }
        }

        // Extract expiration date
        $expirationDate = $fhirResource->getExpirationDate();
        if (!empty($expirationDate)) {
            $dateValue = is_object($expirationDate) && method_exists($expirationDate, 'getValue') 
                ? $expirationDate->getValue() 
                : (string)$expirationDate;
            if (!empty($dateValue)) {
                try {
                    $dateObj = new \DateTime($dateValue);
                    $data['expiration_date'] = $dateObj->format('Y-m-d');
                } catch (\Exception $e) {
                    // If date parsing fails, try to extract just the date part
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateValue, $matches)) {
                        $data['expiration_date'] = $matches[1];
                    }
                }
            }
        }

        // Extract lot number
        $lotNumber = $fhirResource->getLotNumber();
        if (!empty($lotNumber)) {
            $lotValue = is_object($lotNumber) && method_exists($lotNumber, 'getValue') 
                ? $lotNumber->getValue() 
                : (string)$lotNumber;
            if (!empty($lotValue)) {
                $data['lot_number'] = $lotValue;
            }
        }

        // Extract site (administration site)
        $site = $fhirResource->getSite();
        if (!empty($site)) {
            $codings = null;
            if (is_object($site) && method_exists($site, 'getCoding')) {
                $codings = $site->getCoding();
            } elseif (is_array($site) && !empty($site['coding'])) {
                $codings = $site['coding'];
            }
            
            if (!empty($codings)) {
                $primaryCoding = is_array($codings) ? ($codings[0] ?? null) : $codings;
                if (!empty($primaryCoding)) {
                    $code = null;
                    $display = null;
                    
                    if (is_object($primaryCoding)) {
                        $codeObj = method_exists($primaryCoding, 'getCode') ? $primaryCoding->getCode() : null;
                        $code = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)($codeObj ?? '');
                        $displayObj = method_exists($primaryCoding, 'getDisplay') ? $primaryCoding->getDisplay() : null;
                        $display = is_object($displayObj) && method_exists($displayObj, 'getValue') ? $displayObj->getValue() : (string)($displayObj ?? '');
                    } elseif (is_array($primaryCoding)) {
                        $code = $primaryCoding['code'] ?? '';
                        $display = $primaryCoding['display'] ?? '';
                    }
                    
                    if (!empty($code)) {
                        $data['site_code'] = $code;
                        $data['site_display'] = $display ?: $code;
                        // Note: administration_site needs to be mapped to list_options option_id
                        // For now, we'll store the code and let the insert method handle the mapping
                        $data['administration_site'] = $code;
                    }
                }
            }
        }

        // Extract route
        $route = $fhirResource->getRoute();
        if (!empty($route)) {
            $codings = null;
            if (is_object($route) && method_exists($route, 'getCoding')) {
                $codings = $route->getCoding();
            } elseif (is_array($route) && !empty($route['coding'])) {
                $codings = $route['coding'];
            }
            
            if (!empty($codings)) {
                $primaryCoding = is_array($codings) ? ($codings[0] ?? null) : $codings;
                if (!empty($primaryCoding)) {
                    $code = null;
                    if (is_object($primaryCoding)) {
                        $codeObj = method_exists($primaryCoding, 'getCode') ? $primaryCoding->getCode() : null;
                        $code = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)($codeObj ?? '');
                    } elseif (is_array($primaryCoding)) {
                        $code = $primaryCoding['code'] ?? '';
                    }
                    
                    if (!empty($code)) {
                        $data['route'] = $code;
                    }
                }
            }
        }

        // Extract dose quantity
        $doseQuantity = $fhirResource->getDoseQuantity();
        if (!empty($doseQuantity)) {
            $value = null;
            $unit = null;
            
            if (is_object($doseQuantity)) {
                $valueObj = method_exists($doseQuantity, 'getValue') ? $doseQuantity->getValue() : null;
                $value = is_object($valueObj) && method_exists($valueObj, 'getValue') ? $valueObj->getValue() : (float)($valueObj ?? 0);
                $unitObj = method_exists($doseQuantity, 'getUnit') ? $doseQuantity->getUnit() : null;
                $unit = is_object($unitObj) && method_exists($unitObj, 'getValue') ? $unitObj->getValue() : (string)($unitObj ?? '');
            } elseif (is_array($doseQuantity)) {
                $value = isset($doseQuantity['value']) ? (float)$doseQuantity['value'] : 0;
                $unit = $doseQuantity['unit'] ?? '';
            }
            
            if ($value > 0) {
                $data['amount_administered'] = $value;
            }
            if (!empty($unit)) {
                $data['amount_administered_unit'] = $unit;
            }
        }

        // Extract performer (administered by)
        $performers = $fhirResource->getPerformer();
        if (!empty($performers)) {
            $performer = is_array($performers) ? ($performers[0] ?? null) : $performers;
            if (!empty($performer)) {
                $actor = null;
                if (is_object($performer) && method_exists($performer, 'getActor')) {
                    $actor = $performer->getActor();
                } elseif (is_array($performer) && !empty($performer['actor'])) {
                    $actor = $performer['actor'];
                }
                
                if (!empty($actor)) {
                    $practitionerRef = UtilsService::parseReference($actor);
                    if (!empty($practitionerRef) && $practitionerRef['type'] === 'Practitioner' && $practitionerRef['localResource']) {
                        $data['provider_uuid'] = $practitionerRef['uuid'];
                    } elseif (is_array($actor) && !empty($actor['reference'])) {
                        $referenceString = $actor['reference'];
                        $parts = explode('/', $referenceString);
                        if (count($parts) >= 2 && $parts[0] === 'Practitioner') {
                            $data['provider_uuid'] = $parts[1];
                        }
                    }
                }
            }
        }

        // Extract primary source
        $primarySource = $fhirResource->getPrimarySource();
        if ($primarySource !== null) {
            $primarySourceValue = is_object($primarySource) && method_exists($primarySource, 'getValue') 
                ? $primarySource->getValue() 
                : (bool)$primarySource;
            $data['primarySource'] = $primarySourceValue ? '1' : '0';
            // Map to information_source
            if ($primarySourceValue) {
                $data['information_source'] = 'new_immunization_record';
            } else {
                $data['information_source'] = 'other_provider';
            }
        }

        // Extract notes
        $notes = $fhirResource->getNote();
        if (!empty($notes)) {
            $noteTexts = [];
            foreach ($notes as $note) {
                $text = null;
                if (is_object($note) && method_exists($note, 'getText')) {
                    $textObj = $note->getText();
                    $text = is_object($textObj) && method_exists($textObj, 'getValue') ? $textObj->getValue() : (string)($textObj ?? '');
                } elseif (is_array($note) && !empty($note['text'])) {
                    $text = $note['text'];
                }
                
                if (!empty($text)) {
                    $noteTexts[] = $text;
                }
            }
            
            if (!empty($noteTexts)) {
                $data['note'] = implode("\n", $noteTexts);
            }
        }

        return $data;
    }

    /**
     * Inserts an OpenEMR record into the system.
     * @param array $openEmrRecord The OpenEMR data record to insert
     * @return ProcessingResult The OpenEMR processing result.
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        return $this->immunizationService->insert($openEmrRecord);
    }
}
