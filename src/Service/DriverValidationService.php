<?php

namespace GlpiPlugin\Fleetbooking\Service;

class DriverValidationService
{
    /** Valid CNH categories (must include category B or higher) */
    public const VALID_CATEGORIES = ['B', 'C', 'D', 'E', 'AB', 'AC', 'AD', 'AE'];

    /**
     * Translates a string safely within GLPI or fallback in isolated test environments.
     */
    private static function translate(string $text): string
    {
        return function_exists('__') ? \__($text, 'fleetbooking') : $text;
    }

    /**
     * Validate all driver input data.
     *
     * @param array $input Form POST input data
     * @return array ['ok' => bool, 'errors' => string[]]
     */
    public function validate(array $input): array
    {
        $errors = [];

        $idType = (string) ($input['driver_id_type'] ?? 'cpf');
        $cpf = (string) ($input['driver_cpf'] ?? '');
        $registration = (string) ($input['driver_registration'] ?? '');
        $cnhNumber = trim((string) ($input['driver_cnh_number'] ?? ''));
        $cnhCategory = strtoupper(trim((string) ($input['driver_cnh_category'] ?? '')));
        $cnhExpiry = trim((string) ($input['driver_cnh_expiry'] ?? ''));
        $returnDate = trim((string) ($input['end_datetime'] ?? $input['end'] ?? ''));

        // 1. Identification (CPF vs Registration)
        if ($idType === 'cpf') {
            $cleanCpf = preg_replace('/[^\d]/', '', $cpf);
            if (empty($cleanCpf)) {
                $errors[] = self::translate('CPF is mandatory when CPF identification is selected.');
            } elseif (!$this->validateCpf($cleanCpf)) {
                $errors[] = self::translate('Invalid CPF number.');
            }
        } elseif ($idType === 'registration') {
            $cleanReg = preg_replace('/[^\d]/', '', $registration);
            if (empty($cleanReg)) {
                $errors[] = self::translate('Employee ID / Registration is mandatory.');
            } elseif (!preg_match('/^[0-9]{3,4}$/', $cleanReg)) {
                $errors[] = self::translate('Employee ID / Registration must be numeric and contain 3 to 4 digits (e.g. 0000).');
            }
        } else {
            $errors[] = self::translate('Invalid identification type selected.');
        }

        // 2. CNH Number (up to 9 digits, numbers only)
        if (empty($cnhNumber)) {
            $errors[] = self::translate('CNH Number is mandatory.');
        } elseif (!preg_match('/^[0-9]{1,9}$/', $cnhNumber)) {
            $errors[] = self::translate('CNH Number must contain only numbers (up to 9 digits).');
        }

        // 3. CNH Category (minimum category B)
        if (empty($cnhCategory)) {
            $errors[] = self::translate('CNH Category is mandatory.');
        } elseif (!$this->isValidCategory($cnhCategory)) {
            $errors[] = self::translate('Invalid CNH Category. A minimum category of B is required (e.g. B, AB, C, D, E).');
        }

        // 4. CNH Expiry Date
        if (empty($cnhExpiry)) {
            $errors[] = self::translate('CNH Expiry Date is mandatory.');
        } else {
            try {
                $expiryObj = new \DateTimeImmutable($cnhExpiry);
                $todayObj = new \DateTimeImmutable('today');
                $expiryDateOnly = $expiryObj->format('Y-m-d');
                $todayDateOnly = $todayObj->format('Y-m-d');

                if ($expiryDateOnly < $todayDateOnly) {
                    $errors[] = self::translate('CNH is already expired.');
                } elseif (!empty($returnDate)) {
                    $returnObj = new \DateTimeImmutable($returnDate);
                    $returnDateOnly = $returnObj->format('Y-m-d');
                    if ($expiryDateOnly < $returnDateOnly) {
                        $errors[] = sprintf(
                            self::translate('CNH will be expired on the vehicle return date (%1$s). CNH valid until: %2$s.'),
                            $returnObj->format('d/m/Y'),
                            $expiryObj->format('d/m/Y')
                        );
                    }
                }
            } catch (\Exception $e) {
                $errors[] = self::translate('Invalid CNH Expiry Date format.');
            }
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validates a Brazilian CPF using standard modulus 11 checksum calculation.
     *
     * @param string $cpf Digits only (11 characters)
     * @return bool
     */
    public function validateCpf(string $cpf): bool
    {
        $clean = preg_replace('/[^\d]/', '', $cpf);

        if (strlen($clean) !== 11) {
            return false;
        }

        // Reject known invalid repetitive sequences (000.000.000-00, etc.)
        if (preg_match('/^(\d)\1{10}$/', $clean)) {
            return false;
        }

        // First verification digit
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $clean[$i]) * (10 - $i);
        }
        $remainder = $sum % 11;
        $d1 = ($remainder < 2) ? 0 : 11 - $remainder;

        if ((int) $clean[9] !== $d1) {
            return false;
        }

        // Second verification digit
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ((int) $clean[$i]) * (11 - $i);
        }
        $remainder = $sum % 11;
        $d2 = ($remainder < 2) ? 0 : 11 - $remainder;

        return (int) $clean[10] === $d2;
    }

    /**
     * Checks whether a category is valid (contains B or higher).
     *
     * @param string $category
     * @return bool
     */
    public function isValidCategory(string $category): bool
    {
        return in_array(strtoupper(trim($category)), self::VALID_CATEGORIES, true);
    }
}
