<?php

namespace OpenEMR\Validators;

use Particle\Validator\Validator;

/**
 * Supports Immunization Record Validation.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786@gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
class ImmunizationValidator extends BaseValidator
{
    /**
     * Configures validations for the Immunization DB Insert and Update use-case.
     * The update use-case is comprised of the same fields as the insert use-case.
     * The update use-case differs from the insert use-case in that fields other than uuid are not required.
     */
    protected function configureValidator()
    {
        parent::configureValidator();

        // insert validations
        $this->validator->context(
            self::DATABASE_INSERT_CONTEXT,
            function (Validator $context): void {
                $context->required("puuid", "Patient UUID")->uuid();
                $context->optional("cvx_code", "CVX Code")->string();
                $context->optional("administered_date", "Administered Date")->string();
            }
        );

        // update validations copied from insert
        $this->validator->context(
            self::DATABASE_UPDATE_CONTEXT,
            function (Validator $context): void {
                $context->copyContext(
                    self::DATABASE_INSERT_CONTEXT,
                    function ($rules): void {
                        foreach ($rules as $chain) {
                            $chain->required(false);
                        }
                    }
                );
                // additional uuid validation
                $context->required("uuid", "Immunization UUID")->callback(function ($value) {
                    return $this->validateId("uuid", "immunizations", $value, true);
                })->uuid();
            }
        );
    }
}
