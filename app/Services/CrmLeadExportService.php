<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Support\SimpleXlsx;

final class CrmLeadExportService
{
    private const COLUMNS = [
        'Created At',
        'Mobile',
        'Name',
        'Prize',
        'Outcome',
        'Coupon Code',
        'Lead Status',
        'Redeemed At',
        'Source',
        'Date Of Birth',
        'Date Of Anniversary',
        'Visit Count',
        'CRM Sync Status',
        'CRM Sync Code',
        'CRM Sync Message',
    ];

    public function build(array $rows): array
    {
        $sheetRows = [self::COLUMNS];
        foreach ($rows as $row) {
            $sheetRows[] = [
                $row['createdAt'] ?? $row['created_at'] ?? '',
                $row['phone'] ?? '',
                $row['name'] ?? '',
                $row['prize'] ?? '',
                $row['outcomeBadge'] ?? $row['outcome'] ?? '',
                $row['couponCode'] ?? $row['coupon_code'] ?? '',
                $row['status'] ?? '',
                $row['redeemedAt'] ?? $row['redeemed_at'] ?? '',
                $row['source'] ?? '',
                $row['dateOfBirth'] ?? $row['date_of_birth'] ?? '',
                $row['dateOfAnniversary'] ?? $row['date_of_anniversary'] ?? '',
                $row['visitCount'] ?? $row['visit_count'] ?? '',
                $row['crmSyncStatus'] ?? $row['crm_sync_status'] ?? '',
                $row['crmSyncCode'] ?? $row['crm_sync_code'] ?? '',
                $row['crmSyncMessage'] ?? $row['crm_sync_message'] ?? '',
            ];
        }

        $bytes = SimpleXlsx::writeWorkbook(['CRM Leads' => $sheetRows]);
        return [
            'fileName' => 'crm-leads-' . date('Ymd-His') . '.xlsx',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'base64' => base64_encode($bytes),
        ];
    }
}
