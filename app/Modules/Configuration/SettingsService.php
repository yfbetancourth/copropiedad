<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;

final class SettingsService
{
    public function propertyName(): string
    {
        $name = Database::connection()->query(
            'SELECT property_name FROM copropiedad_settings WHERE id = 1'
        )->fetchColumn();

        return is_string($name) && trim($name) !== '' ? $name : 'Copropiedad';
    }

    public function current(): array
    {
        $settings = Database::connection()->query(
            'SELECT property_name, address, tax_id, account_holder, bank_name, account_type, account_number,
                    administration_fee, effective_date, early_payment_discount,
                    late_fee_type, late_fee_value, late_fee_days, fine_amount, housing_type
             FROM copropiedad_settings
             WHERE id = 1'
        )->fetch();

        return is_array($settings) ? $settings : [
            'property_name' => '',
            'address' => '',
            'tax_id' => '',
            'account_holder' => '',
            'bank_name' => '',
            'account_type' => '',
            'account_number' => '',
            'administration_fee' => '',
            'effective_date' => '',
            'early_payment_discount' => '',
            'late_fee_type' => 'percentage',
            'late_fee_value' => '',
            'late_fee_days' => 0,
            'fine_amount' => '',
            'housing_type' => '',
        ];
    }

    public function saveCharacterization(string $housingType): void
    {
        Database::connection()->prepare(
            'INSERT INTO copropiedad_settings (id, housing_type)
             VALUES (1, ?)
             ON DUPLICATE KEY UPDATE housing_type = VALUES(housing_type)'
        )->execute([$housingType]);
    }

    public function saveParametrization(
        string $name,
        string $address,
        string $taxId,
        string $accountHolder,
        string $bankName,
        string $accountType,
        string $accountNumber
    ): void {
        Database::connection()->prepare(
            'INSERT INTO copropiedad_settings
                (id, property_name, address, tax_id, account_holder, bank_name, account_type, account_number)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                property_name = VALUES(property_name),
                address = VALUES(address),
                tax_id = VALUES(tax_id),
                account_holder = VALUES(account_holder),
                bank_name = VALUES(bank_name),
                account_type = VALUES(account_type),
                account_number = VALUES(account_number)'
        )->execute([$name, $address, $taxId, $accountHolder, $bankName, $accountType ?: null, $accountNumber]);
    }

    public function saveFinancial(
        string $administrationFee,
        string $effectiveDate,
        string $discount,
        string $lateFeeType,
        string $lateFeeValue,
        int $lateFeeDays,
        string $fineAmount
    ): void {
        Database::connection()->prepare(
            'INSERT INTO copropiedad_settings
                (id, administration_fee, effective_date, early_payment_discount,
                 late_fee_type, late_fee_value, late_fee_days, fine_amount)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                administration_fee = VALUES(administration_fee),
                effective_date = VALUES(effective_date),
                early_payment_discount = VALUES(early_payment_discount),
                late_fee_type = VALUES(late_fee_type),
                late_fee_value = VALUES(late_fee_value),
                late_fee_days = VALUES(late_fee_days),
                fine_amount = VALUES(fine_amount)'
        )->execute([
            $administrationFee,
            $effectiveDate,
            $discount,
            $lateFeeType,
            $lateFeeValue,
            $lateFeeDays,
            $fineAmount,
        ]);
    }
}
