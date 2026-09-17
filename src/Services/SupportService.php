<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class SupportService
{
    private PDO $db;
    private SupportEmailService $mail;

    public function __construct(PDO $db, SupportEmailService $mail)
    {
        $this->db = $db;
        $this->mail = $mail;
    }

    public function createTicket(?int $userId, string $name, string $email, string $subject, string $message): array
    {
        $email = strtolower(trim($email));

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO support_tickets (user_id, guest_name, guest_email, subject, status)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $name, $email, $subject, 'open']);
            $ticketId = (int) $this->db->lastInsertId();

            $this->insertMessage($ticketId, 'user', $userId, $message);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        try {
            $this->mail->sendTicketCreatedToUser($email, $name, $ticketId, $subject);
            $this->mail->sendNewTicketToStaff($ticketId, $name, $email, $subject, $message);
        } catch (\Throwable $e) {
            error_log('Support ticket email failed: ' . $e->getMessage());
        }

        return $this->getTicketById($ticketId, $userId, true);
    }

    public function listTicketsForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, subject, status, created_at, updated_at
             FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listTicketsForStaff(?string $status = null): array
    {
        $sql = 'SELECT t.id, t.subject, t.status, t.guest_name, t.guest_email, t.user_id,
                       t.created_at, t.updated_at, u.full_name AS user_full_name
                FROM support_tickets t
                LEFT JOIN users u ON u.id = t.user_id';
        $params = [];
        if ($status) {
            $sql .= ' WHERE t.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY t.updated_at DESC LIMIT 200';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTicketById(int $ticketId, ?int $userId, bool $staffAccess = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.*, u.full_name AS user_full_name, u.email AS user_account_email,
                    s.status AS subscription_status
             FROM support_tickets t
             LEFT JOIN users u ON u.id = t.user_id
             LEFT JOIN subscriptions s ON s.user_id = t.user_id
             WHERE t.id = ?'
        );
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ticket) {
            throw new \RuntimeException('Ticket not found', 404);
        }

        if (!$staffAccess && $userId !== null && (int) $ticket['user_id'] !== $userId) {
            throw new \RuntimeException('Forbidden', 403);
        }

        if (!$staffAccess && $userId === null) {
            throw new \RuntimeException('Forbidden', 403);
        }

        $ticket['messages'] = $this->getMessages($ticketId);
        return $ticket;
    }

    public function addUserReply(int $ticketId, int $userId, string $body): array
    {
        $ticket = $this->getTicketById($ticketId, $userId, false);
        if ($ticket['status'] === 'closed') {
            throw new \RuntimeException('Ticket is closed', 400);
        }

        $this->insertMessage($ticketId, 'user', $userId, $body);
        $this->updateTicketTimestamp($ticketId, 'open');

        try {
            $this->mail->sendUserReplyToStaff(
                $ticketId,
                $ticket['guest_name'],
                $ticket['guest_email'],
                $ticket['subject'],
                $body
            );
        } catch (\Throwable $e) {
            error_log('Support reply email failed: ' . $e->getMessage());
        }

        return $this->getTicketById($ticketId, $userId, false);
    }

    public function addStaffReply(int $ticketId, int $staffId, string $body, ?string $status = null): array
    {
        $ticket = $this->getTicketById($ticketId, null, true);

        $this->insertMessage($ticketId, 'staff', $staffId, $body);
        $newStatus = $status ?? 'in_progress';
        $this->setTicketStatus($ticketId, $newStatus);

        try {
            $this->mail->sendReplyToUser(
                $ticket['guest_email'],
                $ticket['guest_name'],
                $ticketId,
                $ticket['subject'],
                $body
            );
        } catch (\Throwable $e) {
            error_log('Staff reply email failed: ' . $e->getMessage());
        }

        return $this->getTicketById($ticketId, null, true);
    }

    public function updateTicketStatus(int $ticketId, string $status): array
    {
        $allowed = ['open', 'in_progress', 'waiting', 'closed'];
        if (!in_array($status, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid status');
        }
        $this->setTicketStatus($ticketId, $status);
        return $this->getTicketById($ticketId, null, true);
    }

    private function insertMessage(int $ticketId, string $authorType, ?int $authorUserId, string $body): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, body)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ticketId, $authorType, $authorUserId, $body]);
    }

    private function getMessages(int $ticketId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.id, m.author_type, m.author_user_id, m.body, m.created_at,
                    u.full_name AS author_name
             FROM support_ticket_messages m
             LEFT JOIN users u ON u.id = m.author_user_id
             WHERE m.ticket_id = ?
             ORDER BY m.created_at ASC'
        );
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function updateTicketTimestamp(int $ticketId, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE support_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$status, $ticketId]);
    }

    private function setTicketStatus(int $ticketId, string $status): void
    {
        $stmt = $this->db->prepare(
            'UPDATE support_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $stmt->execute([$status, $ticketId]);
    }
}
