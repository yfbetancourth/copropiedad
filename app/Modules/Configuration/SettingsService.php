<?php

declare(strict_types=1);

namespace App\Modules\Configuration;

use App\Core\Database;
use App\Core\Tenant;
use PDOException;
use RuntimeException;

final class SettingsService
{
    public function propertyName(): string
    {
        $statement = Database::connection()->prepare(
            'SELECT property_name FROM copropiedad_settings WHERE tenant_id = ?'
        );
        $statement->execute([Tenant::id()]);
        $name = $statement->fetchColumn();

        return is_string($name) && trim($name) !== '' ? $name : Tenant::name();
    }

    /** Cómo se interpreta el valor de administración (columna fee_basis, migración 033). */
    public const FEE_BASES = [
        'base' => 'Base mensual de liquidación',
        'budget' => 'Valor mensual del presupuesto',
    ];

    public function current(): array
    {
        $columns = 'property_name, address, tax_id, account_holder, bank_name, account_type, account_number,
                    early_payment_day, payment_due_day,
                    administration_fee, %s effective_date, early_payment_discount,
                    late_fee_type, late_fee_value, late_fee_days, fine_amount,
                    extraordinary_fee, extraordinary_fee_start_date, extraordinary_fee_end_date,
                    housing_type, tower_nomenclature, apartment_nomenclature';
        try {
            $statement = Database::connection()->prepare(sprintf('SELECT ' . $columns . ' FROM copropiedad_settings WHERE tenant_id = ?', 'fee_basis,'));
            $statement->execute([Tenant::id()]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '42S22') {
                throw $exception;
            }
            // Sin la migración 033 todo conjunto liquida sobre la base mensual, como antes.
            $statement = Database::connection()->prepare(sprintf('SELECT ' . $columns . ", 'base' AS fee_basis FROM copropiedad_settings WHERE tenant_id = ?", ''));
            $statement->execute([Tenant::id()]);
        }
        $settings = $statement->fetch();

        return is_array($settings) ? $settings : [
            'property_name' => Tenant::name(),
            'address' => '',
            'tax_id' => '',
            'account_holder' => '',
            'bank_name' => '',
            'account_type' => '',
            'account_number' => '',
            'early_payment_day' => null,
            'payment_due_day' => null,
            'administration_fee' => '',
            'fee_basis' => 'base',
            'effective_date' => '',
            'early_payment_discount' => '',
            'late_fee_type' => 'percentage',
            'late_fee_value' => '',
            'late_fee_days' => 0,
            'fine_amount' => '',
            'extraordinary_fee' => null,
            'extraordinary_fee_start_date' => null,
            'extraordinary_fee_end_date' => null,
            'housing_type' => '',
            'tower_nomenclature' => 'numeric',
            'apartment_nomenclature' => 'piso_numero',
        ];
    }

    public function saveCharacterization(
        string $housingType,
        string $towerNomenclature,
        string $apartmentNomenclature,
    ): void {
        Database::connection()->prepare(
            'INSERT INTO copropiedad_settings (tenant_id, housing_type, tower_nomenclature, apartment_nomenclature)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                housing_type = VALUES(housing_type),
                tower_nomenclature = VALUES(tower_nomenclature),
                apartment_nomenclature = VALUES(apartment_nomenclature)'
        )->execute([Tenant::id(), $housingType, $towerNomenclature, $apartmentNomenclature]);
    }

    public function saveParametrization(
        string $name,
        string $address,
        string $taxId,
        string $accountHolder,
        string $bankName,
        string $accountType,
        string $accountNumber,
        ?int $earlyPaymentDay,
        ?int $paymentDueDay
    ): void {
        Database::connection()->prepare(
            'INSERT INTO copropiedad_settings
                (tenant_id, property_name, address, tax_id, account_holder, bank_name, account_type, account_number,
                 early_payment_day, payment_due_day)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                property_name = VALUES(property_name),
                address = VALUES(address),
                tax_id = VALUES(tax_id),
                account_holder = VALUES(account_holder),
                bank_name = VALUES(bank_name),
                account_type = VALUES(account_type),
                account_number = VALUES(account_number),
                early_payment_day = VALUES(early_payment_day),
                payment_due_day = VALUES(payment_due_day)'
        )->execute([
            Tenant::id(),
            $name,
            $address,
            $taxId,
            $accountHolder,
            $bankName,
            $accountType ?: null,
            $accountNumber,
            $earlyPaymentDay,
            $paymentDueDay,
        ]);
    }

    public function saveFinancial(
        string $administrationFee,
        string $effectiveDate,
        string $discount,
        string $lateFeeType,
        string $lateFeeValue,
        int $lateFeeDays,
        string $fineAmount,
        ?string $extraordinaryFee,
        ?string $extraordinaryFeeStartDate,
        ?string $extraordinaryFeeEndDate,
        string $feeBasis = 'base'
    ): void {
        if (!array_key_exists($feeBasis, self::FEE_BASES)) {
            throw new RuntimeException('Elige cómo se liquida la administración.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->saveFinancialValues($administrationFee, $effectiveDate, $discount, $lateFeeType, $lateFeeValue, $lateFeeDays, $fineAmount, $extraordinaryFee, $extraordinaryFeeStartDate, $extraordinaryFeeEndDate);
            try {
                $pdo->prepare('UPDATE copropiedad_settings SET fee_basis = ? WHERE tenant_id = ?')->execute([$feeBasis, Tenant::id()]);
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '42S22') {
                    throw $exception;
                }
                if ($feeBasis !== 'base') {
                    throw new RuntimeException('Para liquidar sobre el valor del presupuesto aplica primero la migración 033 (database/migrations/033_add_fee_basis.sql).');
                }
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function saveFinancialValues(
        string $administrationFee,
        string $effectiveDate,
        string $discount,
        string $lateFeeType,
        string $lateFeeValue,
        int $lateFeeDays,
        string $fineAmount,
        ?string $extraordinaryFee,
        ?string $extraordinaryFeeStartDate,
        ?string $extraordinaryFeeEndDate
    ): void {
        Database::connection()->prepare(
            'INSERT INTO copropiedad_settings
                (tenant_id, administration_fee, effective_date, early_payment_discount,
                 late_fee_type, late_fee_value, late_fee_days, fine_amount,
                 extraordinary_fee, extraordinary_fee_start_date, extraordinary_fee_end_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                administration_fee = VALUES(administration_fee),
                effective_date = VALUES(effective_date),
                early_payment_discount = VALUES(early_payment_discount),
                late_fee_type = VALUES(late_fee_type),
                late_fee_value = VALUES(late_fee_value),
                late_fee_days = VALUES(late_fee_days),
                fine_amount = VALUES(fine_amount),
                extraordinary_fee = VALUES(extraordinary_fee),
                extraordinary_fee_start_date = VALUES(extraordinary_fee_start_date),
                extraordinary_fee_end_date = VALUES(extraordinary_fee_end_date)'
        )->execute([
            Tenant::id(),
            $administrationFee,
            $effectiveDate,
            $discount,
            $lateFeeType,
            $lateFeeValue,
            $lateFeeDays,
            $fineAmount,
            $extraordinaryFee,
            $extraordinaryFeeStartDate,
            $extraordinaryFeeEndDate,
        ]);
    }
}
