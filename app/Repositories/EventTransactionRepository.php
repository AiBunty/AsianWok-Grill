<?php

declare(strict_types=1);

namespace AWG\Repositories;

use AWG\Config\Database;
use PDO;

final class EventTransactionRepository
{
    private const TABLE = 'event_transactions';

    public function create(array $data): ?string
    {
        $db = Database::connection();
        $transactionId = (string) ($data['transaction_id'] ?? 'txn_' . bin2hex(random_bytes(8)));
        $status = (string) ($data['status'] ?? 'pending');
        $stmt = $db->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                transaction_id, event_id, event_title,
                customer_name, customer_email, customer_phone,
                qty, amount, currency, gateway,
                order_id, payment_id, status,
                qr_url, qr_payload, email_status, email_sent_at,
                checkin_status, checked_in_at, checked_in_count, verified_by,
                attendee_details, guest_passes_json,
                created_at, paid_at, updated_at
            ) VALUES (
                :transaction_id, :event_id, :event_title,
                :customer_name, :customer_email, :customer_phone,
                :qty, :amount, :currency, :gateway,
                :order_id, :payment_id, :status,
                :qr_url, :qr_payload, :email_status, :email_sent_at,
                :checkin_status, :checked_in_at, :checked_in_count, :verified_by,
                :attendee_details, :guest_passes_json,
                NOW(), :paid_at, NOW()
            )'
        );

        $stmt->execute([
            'transaction_id' => $transactionId,
            'event_id' => (string) ($data['event_id'] ?? ''),
            'event_title' => (string) ($data['event_title'] ?? ''),
            'customer_name' => (string) ($data['customer_name'] ?? ''),
            'customer_email' => strtolower(trim((string) ($data['customer_email'] ?? ''))),
            'customer_phone' => trim((string) ($data['customer_phone'] ?? '')),
            'qty' => max(1, (int) ($data['qty'] ?? 1)),
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper((string) ($data['currency'] ?? 'INR')),
            'gateway' => (string) ($data['gateway'] ?? 'free'),
            'order_id' => (string) ($data['order_id'] ?? ''),
            'payment_id' => (string) ($data['payment_id'] ?? ''),
            'status' => $status,
            'qr_url' => (string) ($data['qr_url'] ?? ''),
            'qr_payload' => (string) ($data['qr_payload'] ?? ''),
            'email_status' => (string) ($data['email_status'] ?? 'pending'),
            'email_sent_at' => $data['email_sent_at'] ?? null,
            'checkin_status' => (string) ($data['checkin_status'] ?? 'not_checked_in'),
            'checked_in_at' => $data['checked_in_at'] ?? null,
            'checked_in_count' => (int) ($data['checked_in_count'] ?? 0),
            'verified_by' => (string) ($data['verified_by'] ?? ''),
            'attendee_details' => (string) ($data['attendee_details'] ?? '[]'),
            'guest_passes_json' => (string) ($data['guest_passes_json'] ?? '[]'),
            'paid_at' => $status === 'paid' || $status === 'free_confirmed' ? date('Y-m-d H:i:s') : null,
        ]);

        return $db->lastInsertId();
    }

    public function findByTransactionId(string $transactionId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE transaction_id = ? LIMIT 1');
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByOrderId(string $orderId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findRecentDuplicate(string $eventId, string $email, string $phone): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM ' . self::TABLE . '
             WHERE event_id = :event_id
               AND (customer_email = :email OR customer_phone = :phone)
               AND status IN ("pending", "paid", "free_confirmed", "checked_in", "checked_in_free")
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'email' => strtolower(trim($email)),
            'phone' => trim($phone),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updatePaymentStatus(string $transactionId, string $status, string $paymentId = '', string $orderId = ''): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE ' . self::TABLE . '
             SET status = :status,
                 payment_id = CASE WHEN :payment_id = "" THEN payment_id ELSE :payment_id END,
                 order_id = CASE WHEN :order_id = "" THEN order_id ELSE :order_id END,
                 paid_at = CASE WHEN :status = "paid" OR :status = "free_confirmed" THEN NOW() ELSE paid_at END,
                 updated_at = NOW()
             WHERE transaction_id = :transaction_id'
        );

        return $stmt->execute([
            'status' => $status,
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'transaction_id' => $transactionId,
        ]);
    }

    public function updateQr(string $transactionId, string $qrPayload, string $qrUrl): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET qr_payload = ?, qr_url = ?, updated_at = NOW() WHERE transaction_id = ?');
        return $stmt->execute([$qrPayload, $qrUrl, $transactionId]);
    }

    public function updateEmailStatus(string $transactionId, string $emailStatus): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE ' . self::TABLE . ' SET email_status = ?, email_sent_at = CASE WHEN ? = "sent" THEN NOW() ELSE email_sent_at END, updated_at = NOW() WHERE transaction_id = ?');
        return $stmt->execute([$emailStatus, $emailStatus, $transactionId]);
    }

    public function applyCheckin(string $transactionId, int $admittedCount, string $verifiedBy): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE ' . self::TABLE . '
             SET checked_in_count = checked_in_count + :admitted,
                 checkin_status = CASE
                     WHEN (checked_in_count + :admitted) >= qty THEN CASE WHEN status = "free_confirmed" THEN "checked_in_free" ELSE "checked_in" END
                     ELSE checkin_status
                 END,
                 checked_in_at = NOW(),
                 verified_by = :verified_by,
                 updated_at = NOW()
             WHERE transaction_id = :transaction_id'
        );

        return $stmt->execute([
            'admitted' => max(1, $admittedCount),
            'verified_by' => $verifiedBy,
            'transaction_id' => $transactionId,
        ]);
    }

    public function listByEvent(string $eventId, int $limit = 1000): array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE event_id = ? ORDER BY id DESC LIMIT ' . max(1, (int) $limit));
        $stmt->execute([$eventId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function findById(string $transactionId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByBookingId(string $bookingId): ?array
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ' . self::TABLE . ' WHERE booking_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateStatus(string $transactionId, string $status, ?array $razorpayData = null): bool
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT transaction_id FROM ' . self::TABLE . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$transactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        return $this->updatePaymentStatus(
            (string) $row['transaction_id'],
            $status,
            (string) ($razorpayData['payment_id'] ?? ''),
            (string) ($razorpayData['order_id'] ?? '')
        );
    }

    public function getByEvent(string $eventId): array
    {
        return $this->listByEvent($eventId);
    }

    public function countByStatus(string $eventId, string $status): int
    {
        $db = Database::connection();
        $stmt = $db->prepare('
            SELECT COUNT(*) as count FROM ' . self::TABLE . ' 
            WHERE event_id = ? AND status = ?
        ');
        $stmt->execute([$eventId, $status]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($result['count'] ?? 0);
    }
}
