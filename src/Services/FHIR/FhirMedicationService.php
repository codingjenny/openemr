<?php

namespace OpenEMR\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRMedication;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRDateTime;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRResource\FHIRMedication\FHIRMedicationBatch;
use OpenEMR\Services\DrugService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\IResourceCreatableService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Common\Uuid\UuidRegistry;
use InvalidArgumentException;

/**
 * FHIR Medication Service
 *
 * @package            OpenEMR
 * @link               http://www.open-emr.org
 * @author             Yash Bothra <yashrajbothra786gmail.com>
 * @copyright          Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license            https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class FhirMedicationService extends FhirServiceBase implements IResourceUSCIGProfileService, IResourceCreatableService
{
    /**
     * @var DrugService
     */
    private $medicationService;

    public function __construct()
    {
        parent::__construct();
        $this->medicationService = new DrugService();
    }

    /**
     * Returns an array mapping FHIR Medication Resource search parameters to OpenEMR Medication search parameters
     *
     * @return array The search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            '_id' => new FhirSearchParameterDefinition('uuid', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
            '_lastUpdated' => $this->getLastModifiedSearchField()
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['drug_last_updated']);
    }

    /**
     * Parses an OpenEMR medication record, returning the equivalent FHIR Medication Resource
     *
     * @param  array   $dataRecord The source OpenEMR data record
     * @param  boolean $encode     Indicates if the returned resource is encoded into a string. Defaults to false.
     * @return FHIRMedication
     */
    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $medicationResource = new FHIRMedication();

        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        if (!empty($dataRecord['drug_last_updated'])) {
            $meta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['drug_last_updated']));
        } else {
            $meta->setLastUpdated(UtilsService::getDateFormattedAsUTC());
        }
        $medicationResource->setMeta($meta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $medicationResource->setId($id);

        if ($dataRecord['active'] == '1') {
            $medicationResource->setStatus("active");
        } else {
            $medicationResource->setStatus("inactive");
        }

        if (!empty($dataRecord['drug_code'])) {
            $medicationCoding = new FHIRCoding();
            $medicationCode = new FHIRCodeableConcept();
            foreach ($dataRecord['drug_code'] as $code => $codeValues) {
                $medicationCoding->setSystem($codeValues['system']);
                $medicationCoding->setCode($code);
                $medicationCoding->setDisplay($codeValues['description']);
                $medicationCode->addCoding($medicationCoding);
            }
            $medicationResource->setCode($medicationCode);
        }

        //alternative for switch case
        list($formDisplay, $formCode) = [
            '1' => ['suspension', 'C60928'],
            '2' => ['tablet', 'C42998'],
            '3' => ['capsule', 'C25158'],
            '4' => ['solution', 'C42986'],
            '5' => ['tsp', 'C48544'],
            '6' => ['ml', 'C28254'],
            '7' => ['units', 'C44278'],
            '8' => ['inhalation', 'C42944'],
            '9' => ['gtts(drops)', 'C48491'],
            '10' => ['cream', 'C28944'],
            '11' => ['ointment', 'C42966'],
            '12' => ['puff', 'C42944']
        ][$dataRecord['form']] ?? ['', ''];

        if (!empty($formCode)) {
            $form = new FHIRCodeableConcept();
            $formCoding = new FHIRCoding();
            $formCoding->setSystem("http://ncimeta.nci.nih.gov");
            $formCoding->setCode($formCode);
            $formCoding->setDisplay($formDisplay);
            $form->addCoding($formCoding);
        }

        if (isset($dataRecord['expiration']) || isset($dataRecord['expiration'])) {
            $batch = new FHIRMedicationBatch();
            if (isset($dataRecord['expiration'])) {
                $expirationDate = new FHIRDateTime();
                $expirationDate->setValue($dataRecord['expiration']);
                $batch->setExpirationDate($expirationDate);
            }
            if (isset($dataRecord['lot_number'])) {
                $batch->setLotNumber($dataRecord['lot_number']);
            }
            $medicationResource->setBatch($batch);
        }

        if ($encode) {
            return json_encode($medicationResource);
        } else {
            return $medicationResource;
        }
    }

    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     *
     * @param  array openEMRSearchParameters OpenEMR search fields
     * @param $puuidBind - Patient uuid to return drug resources that are only visible to the current patient
     * @return ProcessingResult
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters, $puuidBind = null): ProcessingResult
    {
        return $this->medicationService->getAll($openEMRSearchParameters, true, $puuidBind);
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
            'http://hl7.org/fhir/us/core/StructureDefinition/us-core-medication'
        ];
    }

    /**
     * Parses a FHIR Medication resource into an OpenEMR drug record array
     * 
     * @param FHIRDomainResource $fhirResource The FHIR Medication resource
     * @return array The OpenEMR drug record array
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource): array
    {
        if (!($fhirResource instanceof FHIRMedication)) {
            throw new InvalidArgumentException("Resource must be a FHIRMedication");
        }

        $parsedResource = [];

        // code - contains medication codes (e.g., RxNorm)
        if (!empty($fhirResource->getCode())) {
            $code = $fhirResource->getCode();
            $codings = $code->getCoding();
            if (!empty($codings)) {
                foreach ($codings as $coding) {
                    $system = $coding->getSystem();
                    $codeValue = $coding->getCode();
                    
                    // Check for RxNorm codes
                    if (strpos($system, 'rxnorm') !== false && !empty($codeValue)) {
                        $parsedResource['drug_code'] = $codeValue;
                        break;
                    }
                }
            }
            
            // Get medication name from text or display
            if (!empty($code->getText())) {
                $parsedResource['name'] = $code->getText();
            } elseif (!empty($codings) && !empty($codings[0]->getDisplay())) {
                $parsedResource['name'] = $codings[0]->getDisplay();
            }
        }

        // status - active/inactive
        if (!empty($fhirResource->getStatus())) {
            $status = $fhirResource->getStatus();
            $parsedResource['active'] = ($status === 'active') ? 1 : 0;
        } else {
            $parsedResource['active'] = 1; // default to active
        }

        // form - dosage form
        if (!empty($fhirResource->getForm())) {
            $form = $fhirResource->getForm();
            // Try to extract form code or text
            if (!empty($form->getText())) {
                $parsedResource['form'] = $form->getText();
            } elseif (!empty($form->getCoding()) && !empty($form->getCoding()[0])) {
                $formCoding = $form->getCoding()[0];
                if (!empty($formCoding->getDisplay())) {
                    $parsedResource['form'] = $formCoding->getDisplay();
                }
            }
        }

        // batch information
        if (!empty($fhirResource->getBatch())) {
            $batch = $fhirResource->getBatch();
            if (!empty($batch->getLotNumber())) {
                $parsedResource['lot_number'] = $batch->getLotNumber();
            }
            if (!empty($batch->getExpirationDate())) {
                $parsedResource['expiration'] = $batch->getExpirationDate()->getValue();
            }
        }

        // Ensure we have at least a name
        if (empty($parsedResource['name'])) {
            throw new InvalidArgumentException("Medication name is required");
        }

        error_log("FhirMedicationService::parseFhirResource - END: " . json_encode($parsedResource));
        return $parsedResource;
    }

    /**
     * Inserts an OpenEMR drug record into the system
     * 
     * @param array $openEmrRecord The OpenEMR drug record
     * @return ProcessingResult
     */
    public function insertOpenEMRRecord($openEmrRecord): ProcessingResult
    {
        error_log("FhirMedicationService::insertOpenEMRRecord - START: " . json_encode($openEmrRecord));
        $processingResult = new ProcessingResult();

        try {
            // Validate required fields
            if (empty($openEmrRecord['name'])) {
                error_log("FhirMedicationService::insertOpenEMRRecord - VALIDATION ERROR: name is required");
                $processingResult->addValidationError('name', 'Medication name is required');
                return $processingResult;
            }

            // Generate UUID
            $uuid = UuidRegistry::getRegistryForTable('drugs')->createUuid();
            $uuidString = UuidRegistry::uuidToString($uuid);

            // Prepare data for insertion
            $drugData = [
                'uuid' => $uuid,
                'name' => $openEmrRecord['name'],
                'active' => $openEmrRecord['active'] ?? 1,
            ];

            // Optional fields
            if (!empty($openEmrRecord['drug_code'])) {
                $drugData['drug_code'] = $openEmrRecord['drug_code'];
            }
            if (!empty($openEmrRecord['form'])) {
                $drugData['form'] = $openEmrRecord['form'];
            }
            if (isset($openEmrRecord['ndc_number'])) {
                $drugData['ndc_number'] = $openEmrRecord['ndc_number'];
            }
            if (isset($openEmrRecord['lot_number'])) {
                // Note: lot_number is not in the drugs table schema
                // You may need to handle this differently
            }
            if (isset($openEmrRecord['expiration'])) {
                // Note: expiration is not in the drugs table schema
                // You may need to handle this differently
            }

            // Build INSERT query
            $fields = [];
            $binds = [];
            foreach ($drugData as $field => $value) {
                if ($field !== 'uuid') {
                    $fields[] = "`$field` = ?";
                    $binds[] = $value;
                } else {
                    $fields[] = "`$field` = ?";
                    $binds[] = UuidRegistry::uuidToBytes($value);
                }
            }

            $sql = "INSERT INTO drugs SET " . implode(', ', $fields);
            error_log("FhirMedicationService::insertOpenEMRRecord - SQL: " . $sql);
            error_log("FhirMedicationService::insertOpenEMRRecord - BINDS: " . json_encode($binds));
            $result = sqlInsert($sql, $binds);
            error_log("FhirMedicationService::insertOpenEMRRecord - INSERT RESULT: " . ($result ? "SUCCESS (ID: $result)" : "FAILED"));

            if ($result) {
                // Fetch the complete record using search - EXACTLY like DiagnosticReport does
                $search = [
                    'uuid' => new TokenSearchField('uuid', new TokenSearchValue($uuidString, false))
                ];
                error_log("FhirMedicationService::insertOpenEMRRecord - Searching for UUID: " . $uuidString);
                $searchResult = $this->medicationService->search($search);
                error_log("FhirMedicationService::insertOpenEMRRecord - Search hasData: " . ($searchResult->hasData() ? 'true' : 'false') . ", count: " . count($searchResult->getData()));

                if ($searchResult->hasData() && count($searchResult->getData()) > 0) {
                    $drug = $searchResult->getData()[0];
                    error_log("FhirMedicationService::insertOpenEMRRecord - Found drug: " . json_encode($drug));
                    
                    // Convert to FHIR resource
                    $fhirResource = $this->parseOpenEMRRecord($drug, false);
                    error_log("FhirMedicationService::insertOpenEMRRecord - FHIR resource created, ID: " . ($fhirResource->getId() ? $fhirResource->getId()->getValue() : 'NULL'));
                    $processingResult->setData([]);
                    $processingResult->addData($fhirResource);
                } else {
                    error_log("FhirMedicationService::insertOpenEMRRecord - ERROR: Failed to retrieve inserted medication record");
                    $processingResult->addInternalError("Failed to retrieve inserted medication record");
                }
            } else {
                error_log("FhirMedicationService::insertOpenEMRRecord - ERROR: Failed to insert medication");
                $processingResult->setInternalErrors("Failed to insert medication");
            }
        } catch (\Exception $exception) {
            error_log("FhirMedicationService::insertOpenEMRRecord - EXCEPTION: " . $exception->getMessage());
            error_log("FhirMedicationService::insertOpenEMRRecord - TRACE: " . $exception->getTraceAsString());
            $processingResult->setInternalErrors($exception->getMessage());
        }

        error_log("FhirMedicationService::insertOpenEMRRecord - END, hasData: " . ($processingResult->hasData() ? 'true' : 'false'));
        return $processingResult;
    }

    /**
     * Updates an OpenEMR drug record
     * 
     * @param string $fhirResourceId The FHIR resource ID (UUID)
     * @param array $updatedOpenEMRRecord The updated OpenEMR record
     * @return ProcessingResult
     */
    public function updateOpenEMRRecord($fhirResourceId, $updatedOpenEMRRecord): ProcessingResult
    {
        $processingResult = new ProcessingResult();
        $processingResult->addInternalError("Update not yet implemented for Medication");
        return $processingResult;
    }

    /**
     * Creates a provenance resource for a medication
     * 
     * @param array|FHIRMedication $dataRecord The data record
     * @param bool $encode Whether to encode the result
     * @return mixed
     */
    public function createProvenanceResource($dataRecord = array(), $encode = false)
    {
        // Provenance is optional for Medication
        return null;
    }
}
