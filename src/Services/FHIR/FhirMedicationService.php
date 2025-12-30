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
use OpenEMR\Common\Database\QueryUtils;
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

        if (isset($dataRecord['expiration']) || isset($dataRecord['lot_number'])) {
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
        $codeObj = $fhirResource->getCode();
        
        if (!empty($codeObj)) {
            $code = $codeObj;
            $codings = $code->getCoding();
            
            if (!empty($codings)) {
                foreach ($codings as $coding) {
                    $systemObj = method_exists($coding, 'getSystem') ? $coding->getSystem() : null;
                    $system = is_object($systemObj) && method_exists($systemObj, 'getValue') ? $systemObj->getValue() : (string)($systemObj ?? '');
                    $codeObj = method_exists($coding, 'getCode') ? $coding->getCode() : null;
                    $codeValue = is_object($codeObj) && method_exists($codeObj, 'getValue') ? $codeObj->getValue() : (string)($codeObj ?? '');
                    
                    // Check for RxNorm codes - store as string (database field is VARCHAR)
                    // DrugService::createResultRecordFromDatabaseResult will convert it to array format
                    if (strpos(strtolower($system), 'rxnorm') !== false && !empty($codeValue)) {
                        $parsedResource['drug_code'] = $codeValue; // Store as string
                        break;
                    }
                }
            }
            
            // Get medication name from text or display
            $textObj = method_exists($code, 'getText') ? $code->getText() : null;
            $text = is_object($textObj) && method_exists($textObj, 'getValue') ? $textObj->getValue() : (string)($textObj ?? '');
            
            if (!empty($text)) {
                $parsedResource['name'] = $text;
            } elseif (!empty($codings)) {
                $firstCoding = is_array($codings) ? ($codings[0] ?? null) : $codings;
                if (!empty($firstCoding)) {
                    $displayObj = method_exists($firstCoding, 'getDisplay') ? $firstCoding->getDisplay() : null;
                    $display = is_object($displayObj) && method_exists($displayObj, 'getValue') ? $displayObj->getValue() : (string)($displayObj ?? '');
                    if (!empty($display)) {
                        $parsedResource['name'] = $display;
                    }
                }
            }
        }

        // status - active/inactive
        if (!empty($fhirResource->getStatus())) {
            $statusObj = $fhirResource->getStatus();
            $status = is_object($statusObj) && method_exists($statusObj, 'getValue') ? $statusObj->getValue() : (string)($statusObj ?? '');
            $parsedResource['active'] = ($status === 'active') ? 1 : 0;
        } else {
            $parsedResource['active'] = 1; // default to active
        }

        // form - dosage form
        if (!empty($fhirResource->getForm())) {
            $form = $fhirResource->getForm();
            // Try to extract form code or text
            $textObj = method_exists($form, 'getText') ? $form->getText() : null;
            $text = is_object($textObj) && method_exists($textObj, 'getValue') ? $textObj->getValue() : (string)($textObj ?? '');
            if (!empty($text)) {
                $parsedResource['form'] = $text;
            } elseif (!empty($form->getCoding()) && !empty($form->getCoding()[0])) {
                $formCoding = $form->getCoding()[0];
                $displayObj = method_exists($formCoding, 'getDisplay') ? $formCoding->getDisplay() : null;
                $display = is_object($displayObj) && method_exists($displayObj, 'getValue') ? $displayObj->getValue() : (string)($displayObj ?? '');
                if (!empty($display)) {
                    $parsedResource['form'] = $display;
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
            throw new InvalidArgumentException("Medication name is required. Please provide code.text or code.coding[].display");
        }

        return $parsedResource;
    }

    /**
     * Inserts an OpenEMR drug record into the system
     * 
     * @param array $openEmrRecord The OpenEMR drug record
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord): ProcessingResult
    {
        $processingResult = new ProcessingResult();

        try {
            // Validate required fields
            if (empty($openEmrRecord['name'])) {
                $validationMessages = $processingResult->getValidationMessages();
                $validationMessages['name'] = 'Medication name is required';
                $processingResult->setValidationMessages($validationMessages);
                return $processingResult;
            }

            // Generate UUID
            $uuid = UuidRegistry::getRegistryForTable('drugs')->createUuid();

            // Prepare data for insertion - ensure all values are strings/primitives, not FHIR objects
            $drugData = [
                'uuid' => $uuid,
                'name' => is_object($openEmrRecord['name']) && method_exists($openEmrRecord['name'], 'getValue') 
                    ? $openEmrRecord['name']->getValue() 
                    : (string)($openEmrRecord['name'] ?? ''),
                'active' => (int)($openEmrRecord['active'] ?? 1),
            ];

            // Optional fields - convert objects to strings
            if (!empty($openEmrRecord['drug_code'])) {
                $drugData['drug_code'] = is_object($openEmrRecord['drug_code']) && method_exists($openEmrRecord['drug_code'], 'getValue')
                    ? $openEmrRecord['drug_code']->getValue()
                    : (string)($openEmrRecord['drug_code']);
            }
            if (!empty($openEmrRecord['form'])) {
                $drugData['form'] = is_object($openEmrRecord['form']) && method_exists($openEmrRecord['form'], 'getValue')
                    ? $openEmrRecord['form']->getValue()
                    : (string)($openEmrRecord['form']);
            }
            // ndc_number is required by database (NOT NULL), so set default if not provided
            $drugData['ndc_number'] = (string)($openEmrRecord['ndc_number'] ?? '');
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
                    // Ensure value is a primitive type (string, int, etc.), not an object
                    $binds[] = is_object($value) ? (string)$value : $value;
                } else {
                    $fields[] = "`$field` = ?";
                    // $value is already a UUID object from createUuid(), convert to string first, then to bytes
                    $uuidString = UuidRegistry::uuidToString($value);
                    $binds[] = UuidRegistry::uuidToBytes($uuidString);
                }
            }

            $sql = "INSERT INTO drugs SET " . implode(', ', $fields);
            
            try {
            $result = sqlInsert($sql, $binds);
            } catch (\Exception $insertException) {
                $processingResult->addInternalError("Failed to insert medication: " . $insertException->getMessage());
                return $processingResult;
            }

            if ($result && $result > 0) {
                // Query directly by drug_id (more reliable than getOne with UUID)
                // This avoids issues with UUID conversion and complex queries
                try {
                    $querySql = "SELECT drug_id, uuid, name, name AS drug, ndc_number, form, size, unit, route, related_code, active, drug_code, 
                                 last_updated AS drug_last_updated, date_created AS drug_date_created 
                                 FROM drugs WHERE drug_id = ?";
                    $queryResult = QueryUtils::fetchRecords($querySql, [$result]);
                    
                    if (!empty($queryResult)) {
                        $drugRow = $queryResult[0];
                        
                        // Convert UUID from binary to string
                        if (isset($drugRow['uuid'])) {
                            $drugRow['uuid'] = UuidRegistry::uuidToString($drugRow['uuid']);
                        }
                        // Set default values for missing fields
                        $drugRow['active'] = $drugRow['active'] ?? 1;
                        // rxnorm_drugcode is needed by createResultRecordFromDatabaseResult
                        $drugRow['rxnorm_drugcode'] = $drugRow['drug_code'] ?? '';
                        
                        // Format drug_code using reflection to call DrugService::addCoding
                        if (!empty($drugRow['drug_code']) && is_string($drugRow['drug_code'])) {
                            try {
                                $reflection = new \ReflectionClass($this->medicationService);
                                $addCodingMethod = $reflection->getMethod('addCoding');
                                $addCodingMethod->setAccessible(true);
                                $drugCodeStr = $drugRow['drug_code'];
                                if (strpos($drugCodeStr, ':') === false && strpos($drugCodeStr, 'RXCUI') === false) {
                                    $drugRow['drug_code'] = $addCodingMethod->invoke($this->medicationService, "RXCUI:" . $drugCodeStr);
                                } else {
                                    $drugRow['drug_code'] = $addCodingMethod->invoke($this->medicationService, $drugCodeStr);
                                }
                            } catch (\ReflectionException $reflectionException) {
                                $drugRow['drug_code'] = [];
                            }
                        } else {
                            $drugRow['drug_code'] = [];
                        }
                        
                        // Use createResultRecordFromDatabaseResult via reflection to get properly formatted record
                        try {
                            $reflection = new \ReflectionClass($this->medicationService);
                            $createMethod = $reflection->getMethod('createResultRecordFromDatabaseResult');
                            $createMethod->setAccessible(true);
                            $formattedDrug = $createMethod->invoke($this->medicationService, $drugRow);
                        } catch (\ReflectionException $reflectionException) {
                            $formattedDrug = $drugRow;
                        }
                        
                        // Convert to FHIR resource
                        try {
                            $fhirResource = $this->parseOpenEMRRecord($formattedDrug, false);
                            $processingResult->setData([$fhirResource]);
                        } catch (\Exception $parseException) {
                            $processingResult->addInternalError("Failed to parse medication record: " . $parseException->getMessage());
                        }
                    } else {
                        $processingResult->addInternalError("Failed to retrieve inserted medication record");
                    }
                } catch (\Exception $queryException) {
                    $processingResult->addInternalError("Failed to retrieve inserted medication record: " . $queryException->getMessage());
                }
            } else {
                $processingResult->addInternalError("Failed to insert medication");
            }
        } catch (\Exception $exception) {
            $processingResult->addInternalError($exception->getMessage());
        }

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
