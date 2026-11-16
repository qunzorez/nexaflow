<?php
declare(strict_types=1);

/**
 * Internal management ledger for dentistry only. Call inside the source
 * transaction so operational rows and journal rows commit or roll back together.
 */
function dentalErpLedgerAccounts(PDO $pdo, string $ownerId): array
{
    $accounts = [
        'cash' => ['101', 'Грошові кошти', 'asset'],
        'receivable' => ['121', 'Заборгованість пацієнтів', 'asset'],
        'inventory' => ['201', 'Матеріали на складі', 'asset'],
        'goods_in_transit' => ['205', 'Товари в дорозі / до рахунка', 'asset'],
        'receipt_accrual' => ['209', 'Отримані товари без рахунка', 'liability'],
        'payable' => ['361', 'Заборгованість постачальникам', 'liability'],
        'patient_advance' => ['681', 'Аванси пацієнтів', 'liability'],
        'payroll_payable' => ['661', 'Заборгованість із зарплати', 'liability'],
        'service_income' => ['701', 'Дохід від стоматологічних послуг', 'income'],
        'material_expense' => ['801', 'Витрати матеріалів процедур', 'expense'],
        'operating_expense' => ['811', 'Операційні витрати клініки', 'expense'],
        'labor_expense' => ['821', 'Витрати на оплату праці', 'expense'],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO dental_erp_accounting_accounts (id, owner_id, account_code, name, account_type) VALUES (?, ?, ?, ?, ?)');
    foreach ($accounts as [$code, $name, $type]) $insert->execute([md5($ownerId . '|dental-account|' . $code), $ownerId, $code, $name, $type]);
    $lookup = $pdo->prepare('SELECT id FROM dental_erp_accounting_accounts WHERE owner_id = ? AND account_code = ? AND is_active = 1');
    $ids = [];
    foreach ($accounts as $key => [$code]) { $lookup->execute([$ownerId, $code]); $ids[$key] = (string)$lookup->fetchColumn(); }
    return $ids;
}

function dentalErpPostJournal(PDO $pdo, string $ownerId, string $actorId, string $sourceType, string $sourceId, string $date, string $description, array $lines): void
{
    if (!$lines) return;
    $existing = $pdo->prepare('SELECT id FROM dental_erp_accounting_journals WHERE owner_id = ? AND source_type = ? AND source_id = ? LIMIT 1');
    $existing->execute([$ownerId, $sourceType, $sourceId]);
    if ($existing->fetchColumn()) return;
    $date = substr($date, 0, 10);
    $stamp = strtotime($date);
    if ($stamp === false) throw new DomainException('Некоректна дата бухгалтерського проведення.');
    $starts = date('Y-m-01', $stamp); $ends = date('Y-m-t', $stamp);
    $periodId = md5($ownerId . '|dental-accounting-period|' . $starts . '|' . $ends);
    $pdo->prepare("INSERT IGNORE INTO dental_erp_accounting_periods (id, owner_id, starts_on, ends_on, status) VALUES (?, ?, ?, ?, 'open')")
        ->execute([$periodId, $ownerId, $starts, $ends]);
    $period = $pdo->prepare('SELECT id, status FROM dental_erp_accounting_periods WHERE owner_id = ? AND starts_on <= ? AND ends_on >= ? ORDER BY starts_on DESC LIMIT 1 FOR UPDATE');
    $period->execute([$ownerId, $date, $date]); $periodRow = $period->fetch();
    if (!$periodRow || $periodRow['status'] !== 'open') throw new DomainException('Період закритий. Для виправлення створіть контрольоване коригування в іншому відкритому періоді.');

    $accounts = dentalErpLedgerAccounts($pdo, $ownerId);
    $normalized = []; $debit = 0; $credit = 0;
    foreach ($lines as $line) {
        $key = (string)($line['account'] ?? '');
        $debitCents = (int)($line['debit_cents'] ?? 0); $creditCents = (int)($line['credit_cents'] ?? 0);
        if (!isset($accounts[$key]) || $debitCents < 0 || $creditCents < 0 || (($debitCents > 0) === ($creditCents > 0))) throw new DomainException('Некоректний рядок бухгалтерського проведення.');
        $debit += $debitCents; $credit += $creditCents;
        $normalized[] = [$accounts[$key], $debitCents, $creditCents, mb_substr((string)($line['memo'] ?? ''), 0, 500) ?: null];
    }
    if ($debit <= 0 || $debit !== $credit) throw new DomainException('Проведення не збалансоване: дебет має дорівнювати кредиту.');
    $journalId = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO dental_erp_accounting_journals (id, owner_id, period_id, source_type, source_id, entry_date, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'posted', ?)")
        ->execute([$journalId, $ownerId, $periodRow['id'], $sourceType, $sourceId, $date, mb_substr($description, 0, 500), $actorId]);
    $entry = $pdo->prepare('INSERT INTO dental_erp_accounting_entries (id, owner_id, journal_id, account_id, debit, credit, memo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($normalized as [$accountId, $debitCents, $creditCents, $memo]) {
        $entry->execute([bin2hex(random_bytes(16)), $ownerId, $journalId, $accountId, number_format($debitCents / 100, 2, '.', ''), number_format($creditCents / 100, 2, '.', ''), $memo]);
    }
}
