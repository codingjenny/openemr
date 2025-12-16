<?php

/**
 * FhirProcedureSurgeryService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR\Procedure;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRProcedure;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\Services\CodeTypesService;
use OpenEMR\Services\FHIR\FhirProcedureService;
use OpenEMR\Services\FHIR\FhirProvenanceService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\SurgeryService;
use OpenEMR\Validators\ProcessingResult;

class FhirProcedureSurgeryService extends FhirServiceBase
{
    use FhirServiceBaseEmptyTrait;
    use PatientSearchTrait;

    /**
     * @var SurgeryService
     */
    private $service;

    public function __construct($fhirApiURL = null)
    {
        parent::__construct($fhirApiURL);
        $this->service = new SurgeryService();
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
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['begdate']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['date_modified']);
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     * @param openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return OpenEMR records
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
    {
        return $this->service->search($openEMRSearchParameters);
    }


    /**
     * Parses an OpenEMR procedure record, returning the equivalent FHIR Procedure Resource
     *
     * @param  array   $dataRecord The source OpenEMR data record
     * @param  boolean $encode     Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRProcedure
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $procedureResource = new FHIRProcedure();

        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        if (!empty($dataRecord['date_modified'])) {
            $meta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['date_modified']));
        } else {
            $meta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $procedureResource->setMeta($meta);

        // UUID should already be a valid string (36 chars) from FhirProcedureService
        // Just ensure it's a string and valid UUID format, but don't aggressively clean it
        $uuid = $dataRecord['uuid'] ?? '';
        if (is_string($uuid) && strlen($uuid) === 36) {
            // Valid UUID format, use as is
        } elseif (is_string($uuid) && !empty($uuid)) {
            // If it's not 36 chars, it might be binary or corrupted, but we'll use it anyway
            // The FhirProcedureService should have already converted it
        } else {
            $uuid = '';
        }
        
        $id = new FHIRId();
        $id->setValue($uuid);
        $procedureResource->setId($id);
        
        // puuid should already be a valid string (36 chars) from FhirProcedureService
        $puuid = $dataRecord['puuid'] ?? '';
        if (!empty($puuid) && is_string($puuid) && strlen($puuid) === 36) {
            // Valid UUID format, use as is
            $procedureResource->setSubject(UtilsService::createRelativeReference('Patient', $puuid));
        } elseif (!empty($puuid) && is_string($puuid)) {
            // If it's not 36 chars, it might be binary or corrupted
            // Try to convert it if it looks like binary (16 bytes)
            if (strlen($puuid) === 16) {
                $puuid = UuidRegistry::uuidToString($puuid);
            }
            if (!empty($puuid) && strlen($puuid) === 36) {
                $procedureResource->setSubject(UtilsService::createRelativeReference('Patient', $puuid));
            } else {
                $procedureResource->setSubject(UtilsService::createDataMissingExtension());
            }
        } else {
            $procedureResource->setSubject(UtilsService::createDataMissingExtension());
        }

        // euuid should already be a valid string (36 chars) from FhirProcedureService
        $euuid = $dataRecord['euuid'] ?? '';
        if (!empty($euuid) && is_string($euuid) && strlen($euuid) === 36) {
            // Valid UUID format, use as is
            $procedureResource->setEncounter(UtilsService::createRelativeReference('Encounter', $euuid));
        } elseif (!empty($euuid) && is_string($euuid)) {
            // If it's not 36 chars, it might be binary or corrupted
            // Try to convert it if it looks like binary (16 bytes)
            if (strlen($euuid) === 16) {
                $euuid = UuidRegistry::uuidToString($euuid);
            }
            if (!empty($euuid) && strlen($euuid) === 36) {
                $procedureResource->setEncounter(UtilsService::createRelativeReference('Encounter', $euuid));
            }
        }

        if ($dataRecord['status'] == "active") {
            $procedureResource->setStatus(FhirProcedureService::FHIR_PROCEDURE_STATUS_COMPLETED);
        } elseif ($dataRecord['status'] == "inactive") {
            $procedureResource->setStatus(FhirProcedureService::FHIR_PROCEDURE_STATUS_IN_PROGRESS);
        } else {
            $procedureResource->setStatus(FhirProcedureService::FHIR_PROCEDURE_STATUS_UNKNOWN);
        }

        if (!empty($dataRecord['diagnosis'])) {
            $codesService = new CodeTypesService();
            $codes = explode(";", $dataRecord['diagnosis']);
            $diagnosisCode = new FHIRCodeableConcept();
            foreach ($codes as $code) {
                $description = $codesService->lookup_code_description($code);
                $description = !empty($description) ? $description : null; // we can get an "" string back from lookup
                // Clean description to ensure valid UTF-8 encoding for JSON
                if (!empty($description) && is_string($description)) {
                    // First, try to detect and convert from the original encoding
                    $detectedEncoding = mb_detect_encoding($description, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
                    if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                        $description = mb_convert_encoding($description, 'UTF-8', $detectedEncoding);
                    } else {
                        // If already UTF-8 or detection failed, ensure it's valid UTF-8
                        $description = mb_convert_encoding($description, 'UTF-8', 'UTF-8');
                    }
                    // Remove null bytes and control characters (except newlines and tabs)
                    $description = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $description);
                    // Final validation
                    if (!mb_check_encoding($description, 'UTF-8')) {
                        $description = mb_convert_encoding($description, 'UTF-8', 'UTF-8', true); // Use substitution
                        if (!mb_check_encoding($description, 'UTF-8')) {
                            $description = ''; // If still invalid, set to empty
                        }
                    }
                }
                $system = $codesService->getSystemForCode($code);
                // Also clean the system string if it exists
                if (!empty($system) && is_string($system)) {
                    $detectedEncoding = mb_detect_encoding($system, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
                    if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
                        $system = mb_convert_encoding($system, 'UTF-8', $detectedEncoding);
                    } else {
                        $system = mb_convert_encoding($system, 'UTF-8', 'UTF-8');
                    }
                    $system = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $system);
                    if (!mb_check_encoding($system, 'UTF-8')) {
                        $system = mb_convert_encoding($system, 'UTF-8', 'UTF-8', true);
                        if (!mb_check_encoding($system, 'UTF-8')) {
                            $system = '';
                        }
                    }
                }
                
                // Also clean the code string
                if (!empty($code) && is_string($code)) {
                    $code = mb_convert_encoding($code, 'UTF-8', 'UTF-8');
                    $code = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $code);
                    if (!mb_check_encoding($code, 'UTF-8')) {
                        $code = mb_convert_encoding($code, 'UTF-8', 'UTF-8', true);
                    }
                }
                
                $diagnosisCode->addCoding(UtilsService::createCoding($code, $description, $system));
            }
            $procedureResource->setCode($diagnosisCode);
        }

        if (!empty($dataRecord['begdate'])) {
            $procedureResource->setPerformedDateTime(UtilsService::getLocalDateAsUTC($dataRecord['begdate']));
        }

        if (!empty($dataRecord['comments'])) {
            // Ensure comments is valid UTF-8 before adding to FHIR resource
            $comments = $dataRecord['comments'];
            if (is_string($comments)) {
                $comments = mb_convert_encoding($comments, 'UTF-8', 'UTF-8');
                $comments = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $comments);
                if (!mb_check_encoding($comments, 'UTF-8')) {
                    $comments = '';
                }
            }
            if (!empty($comments)) {
                $procedureResource->addNote(['text' => $comments]);
            }
        }

        if (!empty($dataRecord['recorder_npi']) && !empty($dataRecord['recorder_uuid'])) {
            // recorder_uuid should already be a valid string (36 chars) from FhirProcedureService
            $recorderUuid = $dataRecord['recorder_uuid'];
            if (is_string($recorderUuid) && strlen($recorderUuid) === 36) {
                // Valid UUID format, use as is
                $procedureResource->setRecorder(UtilsService::createRelativeReference('Practitioner', $recorderUuid));
            } elseif (is_string($recorderUuid) && strlen($recorderUuid) === 16) {
                // If it's binary (16 bytes), convert it
                $recorderUuid = UuidRegistry::uuidToString($recorderUuid);
                if (!empty($recorderUuid) && strlen($recorderUuid) === 36) {
                    $procedureResource->setRecorder(UtilsService::createRelativeReference('Practitioner', $recorderUuid));
                }
            }
        }

        if ($encode) {
            return json_encode($procedureResource);
        } else {
            return $procedureResource;
        }
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
        if (!($dataRecord instanceof FHIRProcedure)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        $user = $dataRecord->getRecorder() ?? null;
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord, $user);

        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }
}
