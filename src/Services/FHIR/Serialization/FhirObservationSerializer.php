<?php

/**
 * FhirObservationSerializer.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Auto-generated
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR\Serialization;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRObservation;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRReference;
use OpenEMR\FHIR\R4\FHIRResource\FHIRObservation\FHIRObservationComponent;
use OpenEMR\FHIR\R4\FHIRResource\FHIRObservation\FHIRObservationReferenceRange;

class FhirObservationSerializer
{
    public static function serialize(FHIRObservation $observation)
    {
        return $observation->jsonSerialize();
    }

    /**
     * Takes a fhir json representing an observation and returns the populated FHIRObservation resource
     * @param $fhirJson
     * @return FHIRObservation
     */
    public static function deserialize($fhirJson)
    {
        // Extract complex nested elements that need special handling
        $category = $fhirJson['category'] ?? [];
        $code = $fhirJson['code'] ?? null;
        $subject = $fhirJson['subject'] ?? null;
        $performer = $fhirJson['performer'] ?? [];
        $component = $fhirJson['component'] ?? [];
        $referenceRange = $fhirJson['referenceRange'] ?? [];
        $hasMember = $fhirJson['hasMember'] ?? [];
        $derivedFrom = $fhirJson['derivedFrom'] ?? [];

        // Remove these from the main array so they can be handled separately
        unset($fhirJson['category']);
        unset($fhirJson['code']);
        unset($fhirJson['subject']);
        unset($fhirJson['performer']);
        unset($fhirJson['component']);
        unset($fhirJson['referenceRange']);
        unset($fhirJson['hasMember']);
        unset($fhirJson['derivedFrom']);

        $observation = new FHIRObservation($fhirJson);

        // Handle category
        if (!empty($category)) {
            foreach ($category as $catItem) {
                $categoryConcept = new FHIRCodeableConcept($catItem);
                if (!empty($catItem['coding'])) {
                    foreach ($catItem['coding'] as $codingItem) {
                        $coding = new FHIRCoding($codingItem);
                        $categoryConcept->addCoding($coding);
                    }
                }
                $observation->addCategory($categoryConcept);
            }
        }

        // Handle code
        if (!empty($code)) {
            $codeConcept = new FHIRCodeableConcept($code);
            if (!empty($code['coding'])) {
                foreach ($code['coding'] as $codingItem) {
                    $coding = new FHIRCoding($codingItem);
                    $codeConcept->addCoding($coding);
                }
            }
            $observation->setCode($codeConcept);
        }

        // Handle subject
        if (!empty($subject)) {
            $subjectRef = new FHIRReference($subject);
            $observation->setSubject($subjectRef);
        }

        // Handle performer
        foreach ($performer as $item) {
            $performerRef = new FHIRReference($item);
            $observation->addPerformer($performerRef);
        }

        // Handle component
        foreach ($component as $item) {
            $componentObj = new FHIRObservationComponent($item);
            if (!empty($item['code'])) {
                $componentCode = new FHIRCodeableConcept($item['code']);
                if (!empty($item['code']['coding'])) {
                    foreach ($item['code']['coding'] as $codingItem) {
                        $coding = new FHIRCoding($codingItem);
                        $componentCode->addCoding($coding);
                    }
                }
                $componentObj->setCode($componentCode);
            }
            $observation->addComponent($componentObj);
        }

        // Handle referenceRange
        foreach ($referenceRange as $item) {
            $rangeObj = new FHIRObservationReferenceRange($item);
            $observation->addReferenceRange($rangeObj);
        }

        // Handle hasMember
        foreach ($hasMember as $item) {
            $memberRef = new FHIRReference($item);
            $observation->addHasMember($memberRef);
        }

        // Handle derivedFrom
        foreach ($derivedFrom as $item) {
            $derivedRef = new FHIRReference($item);
            $observation->addDerivedFrom($derivedRef);
        }

        return $observation;
    }
}

