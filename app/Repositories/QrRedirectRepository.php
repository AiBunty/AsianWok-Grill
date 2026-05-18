<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class QrRedirectRepository
{
    public function ensureSystemRows(): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO qr_redirects (
                name, title, slug, target_url, redirect_mode, preset_key, manual_url,
                legacy_channel, notes, is_active, is_system, created_at, updated_at
             ) VALUES
                (:name, :title, :slug, :target_url, :redirect_mode, :preset_key, :manual_url, :legacy_channel, :notes, :is_active, :is_system, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_system = VALUES(is_system),
                legacy_channel = VALUES(legacy_channel),
                updated_at = NOW()'
        );

        $rows = [
            [
                'name' => 'Guest QR',
                'title' => 'Guest QR',
                'slug' => 'guest-menu',
                'target_url' => '/menu.html',
                'redirect_mode' => 'preset',
                'preset_key' => 'menu',
                'manual_url' => null,
                'legacy_channel' => 'customer',
                'notes' => 'System guest QR redirect',
                'is_active' => 1,
                'is_system' => 1,
            ],
            [
                'name' => 'Admin QR',
                'title' => 'Admin QR',
                'slug' => 'admin-portal',
                'target_url' => '/admin/admin-portal.html',
                'redirect_mode' => 'preset',
                'preset_key' => 'admin',
                'manual_url' => null,
                'legacy_channel' => 'admin',
                'notes' => 'System admin QR redirect',
                'is_active' => 1,
                'is_system' => 1,
            ],
        ];

        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    public function findBySlug(string $slug): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM qr_redirects WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function findById(int $id): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM qr_redirects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function findByLegacyChannel(string $channel): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM qr_redirects WHERE legacy_channel = :legacy_channel LIMIT 1');
        $stmt->execute(['legacy_channel' => $channel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function listAll(): array
    {
        $db = Database::connection();
        $stmt = $db->query('SELECT * FROM qr_redirects ORDER BY is_system DESC, id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function save(array $data): int
    {
        $db = Database::connection();
        $id = (int) ($data['id'] ?? 0);

        if ($id > 0) {
            $stmt = $db->prepare(
                'UPDATE qr_redirects
                 SET name = :name,
                     title = :title,
                     slug = :slug,
                     target_url = :target_url,
                     redirect_mode = :redirect_mode,
                     preset_key = :preset_key,
                     manual_url = :manual_url,
                     notes = :notes,
                     is_active = :is_active,
                     updated_by = :updated_by,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'name' => (string) ($data['name'] ?? ''),
                'title' => (string) ($data['title'] ?? $data['name'] ?? ''),
                'slug' => (string) ($data['slug'] ?? ''),
                'target_url' => (string) ($data['target_url'] ?? $data['manual_url'] ?? ''),
                'redirect_mode' => (string) ($data['redirect_mode'] ?? 'preset'),
                'preset_key' => (string) ($data['preset_key'] ?? ''),
                'manual_url' => (string) ($data['manual_url'] ?? ''),
                'notes' => (string) ($data['notes'] ?? ''),
                'is_active' => !empty($data['is_active']) ? 1 : 0,
                'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
            ]);
            return $id;
        }

        $stmt = $db->prepare(
            'INSERT INTO qr_redirects (
                name, title, slug, target_url, redirect_mode, preset_key, manual_url,
                legacy_channel, notes, is_active, is_system,
                created_by, updated_by, created_at, updated_at
            ) VALUES (
                :name, :title, :slug, :target_url, :redirect_mode, :preset_key, :manual_url,
                :legacy_channel, :notes, :is_active, :is_system,
                :created_by, :updated_by, NOW(), NOW()
            )'
        );
        $stmt->execute([
            'name' => (string) ($data['name'] ?? ''),
            'title' => (string) ($data['title'] ?? $data['name'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'target_url' => (string) ($data['target_url'] ?? $data['manual_url'] ?? ''),
            'redirect_mode' => (string) ($data['redirect_mode'] ?? 'preset'),
            'preset_key' => (string) ($data['preset_key'] ?? ''),
            'manual_url' => (string) ($data['manual_url'] ?? ''),
            'legacy_channel' => $data['legacy_channel'] ?? null,
            'notes' => (string) ($data['notes'] ?? ''),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'is_system' => !empty($data['is_system']) ? 1 : 0,
            'created_by' => ($data['created_by'] ?? null) !== null ? (int) $data['created_by'] : null,
            'updated_by' => ($data['updated_by'] ?? null) !== null ? (int) $data['updated_by'] : null,
        ]);

        return (int) $db->lastInsertId();
    }

    public function setActive(int $id, bool $isActive, ?int $updatedBy = null): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE qr_redirects SET is_active = :is_active, updated_by = :updated_by, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'is_active' => $isActive ? 1 : 0,
            'updated_by' => $updatedBy,
        ]);
    }

    public function delete(int $id): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('DELETE FROM qr_redirects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
