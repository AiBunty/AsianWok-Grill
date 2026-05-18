<?php

declare(strict_types=1);

namespace AWG\Services;

use AWG\Support\SimpleXlsx;

final class CrmContactExportService
{
    private const COLUMNS = [
        'Phone',
        'Name',
        'Date Of Birth',
        'Date Of Anniversary',
        'First Seen',
        'Last Seen',
        'Latest Source',
        'Total Submissions',
        'Latest Lead ID',
        'Latest Lead Created At',
        'Latest CRM Sync Status',
        'Latest CRM Sync Code',
        'Latest CRM Sync Message',
        'Last CRM Attempted At',
        'Last CRM Pushed At',
    ];

    public function build(array $rows): array
    {
        $sheetRows = [self::COLUMNS];
        foreach ($rows as $row) {
            $sheetRows[] = [
                $row['phone'] ?? '',
                $row['name'] ?? '',
                $row['date_of_birth'] ?? '',
                $row['date_of_anniversary'] ?? '',
                $row['first_seen_at'] ?? '',
                $row['last_seen_at'] ?? '',
                $row['latest_source'] ?? '',
                $row['total_submissions'] ?? '',
                $row['latest_lead_id'] ?? '',
                $row['latest_lead_created_at'] ?? '',
                $row['latest_crm_sync_status'] ?? '',
                $row['latest_crm_sync_code'] ?? '',
                $row['latest_crm_sync_message'] ?? '',
                $row['last_crm_attempted_at'] ?? '',
                $row['last_crm_pushed_at'] ?? '',
            ];
        }

        $bytes = SimpleXlsx::writeWorkbook(['CRM Contacts' => $sheetRows]);
        return [
            'fileName' => 'crm-contacts-' . date('Ymd-His') . '.xlsx',
            'mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'base64' => base64_encode($bytes),
        ];
    }
}
