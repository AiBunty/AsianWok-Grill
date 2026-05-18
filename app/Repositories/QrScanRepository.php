<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class QrScanRepository
{
    public function nextScanNumber(string $channel): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT COALESCE(MAX(scan_number), 0) FROM qr_scans WHERE channel = :channel');
        $stmt->execute(['channel' => $channel]);
        return ((int) $stmt->fetchColumn()) + 1;
    }

    public function create(array $data): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO qr_scans (
                scanned_at, user_agent, referer, ip_address, scan_number, channel,
                qr_id, qr_slug, destination_key, destination_label, resolved_url,
                city, region, country, device, browser, os, language, screen
            ) VALUES (
                NOW(), :user_agent, :referer, :ip_address, :scan_number, :channel,
                :qr_id, :qr_slug, :destination_key, :destination_label, :resolved_url,
                :city, :region, :country, :device, :browser, :os, :language, :screen
            )'
        );

        $stmt->execute([
            'user_agent' => (string) ($data['user_agent'] ?? ''),
            'referer' => (string) ($data['referer'] ?? ''),
            'ip_address' => (string) ($data['ip_address'] ?? ''),
            'scan_number' => (int) ($data['scan_number'] ?? 0),
            'channel' => (string) ($data['channel'] ?? 'customer'),
            'qr_id' => ($data['qr_id'] ?? null) !== null ? (int) $data['qr_id'] : null,
            'qr_slug' => (string) ($data['qr_slug'] ?? ''),
            'destination_key' => (string) ($data['destination_key'] ?? ''),
            'destination_label' => (string) ($data['destination_label'] ?? ''),
            'resolved_url' => (string) ($data['resolved_url'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'region' => (string) ($data['region'] ?? ''),
            'country' => (string) ($data['country'] ?? ''),
            'device' => (string) ($data['device'] ?? ''),
            'browser' => (string) ($data['browser'] ?? ''),
            'os' => (string) ($data['os'] ?? ''),
            'language' => (string) ($data['language'] ?? ''),
            'screen' => (string) ($data['screen'] ?? ''),
        ]);

        return (int) $db->lastInsertId();
    }

    public function report(int $recentLimit = 50): array
    {
        $db = Database::connection();

        $totalScans = (int) $db->query('SELECT COUNT(*) FROM qr_scans')->fetchColumn();

        $channelStmt = $db->query('SELECT channel, COUNT(*) AS total FROM qr_scans GROUP BY channel');
        $channelCounts = [];
        foreach (($channelStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $channelCounts[(string) ($row['channel'] ?? 'unknown')] = (int) ($row['total'] ?? 0);
        }

        $summaryStmt = $db->query(
            'SELECT
                COALESCE(qr_id, 0) AS qr_id,
                COALESCE(NULLIF(qr_slug, ""), "-") AS qr_slug,
                channel,
                COUNT(*) AS total,
                MAX(scanned_at) AS last_scanned_at
             FROM qr_scans
             GROUP BY COALESCE(qr_id, 0), COALESCE(NULLIF(qr_slug, ""), "-"), channel
             ORDER BY total DESC, last_scanned_at DESC'
        );
        $qrSummary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $recentStmt = $db->prepare(
            'SELECT id, scanned_at, scan_number, channel, qr_id, qr_slug,
                    destination_key, destination_label, resolved_url,
                    ip_address, city, region, country, device, browser, os, language, screen
             FROM qr_scans
             ORDER BY scanned_at DESC, id DESC
             LIMIT :limit'
        );
        $recentStmt->bindValue(':limit', max(1, $recentLimit), PDO::PARAM_INT);
        $recentStmt->execute();
        $recentScans = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'totalScans' => $totalScans,
            'channelCounts' => $channelCounts,
            'qrSummary' => $qrSummary,
            'recentScans' => $recentScans,
            'rows' => $recentScans,
        ];
    }
}
