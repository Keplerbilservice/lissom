<?php
declare(strict_types=1);

/** Separate server-to-server reader. No browser sessions and no course writes. */
final class ChatgptKurser
{
    public function __construct(private PDO $db, private string $secret, private array $actors) {}

    public function les(string $raw, array $headers, int $now): array
    {
        if (strlen($this->secret) < 32 || $this->actors === []) {
            throw new RuntimeException('Integrasjonen er ikke konfigurert.', 503);
        }
        $timestamp = $headers['timestamp'] ?? '';
        $nonce = $headers['nonce'] ?? '';
        $signature = $headers['signature'] ?? '';
        if (strlen($raw) > 4096 || !preg_match('/^[0-9]{10}$/D', $timestamp)
            || abs($now - (int) $timestamp) > 60 || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || !hash_equals(hash_hmac('sha256', "lissom-courses-read-v1\n$timestamp\n$nonce\n$raw", $this->secret), $signature)) {
            throw new RuntimeException('Ugyldig tjenestetilgang.', 401);
        }
        try { $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('Ugyldig forespørsel.', 400); }
        if (!is_array($body) || !is_string($body['actor'] ?? null)
            || !is_string($body['requestId'] ?? null)
            || !preg_match('/^[a-f0-9-]{36}$/D', $body['requestId'])
            || !in_array($body['operation'] ?? null, ['list', 'get'], true)) {
            throw new RuntimeException('Ugyldig forespørsel.', 400);
        }
        $allowed = $body['operation'] === 'get' ? ['actor', 'id', 'operation', 'requestId'] : ['actor', 'afterId', 'limit', 'operation', 'requestId'];
        $keys = array_keys($body); sort($keys); sort($allowed);
        if ($keys !== $allowed || ($body['operation'] === 'get' && (!is_int($body['id']) || $body['id'] < 1))
            || ($body['operation'] === 'list' && (!is_int($body['afterId']) || $body['afterId'] < 0
                || !is_int($body['limit']) || $body['limit'] < 1 || $body['limit'] > 50))) {
            throw new RuntimeException('Ugyldig forespørsel.', 400);
        }
        $memberId = $this->actors[$body['actor']] ?? null;
        if (!is_int($memberId) || $memberId < 1) { throw new RuntimeException('Ingen tilgang.', 403); }
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT rolle, anonymisert_at FROM members WHERE id = :id');
            $q->execute(['id' => $memberId]); $member = $q->fetch(PDO::FETCH_ASSOC);
            if (!$member || $member['rolle'] !== 'admin' || $member['anonymisert_at'] !== null) {
                throw new RuntimeException('Ingen tilgang.', 403);
            }
            $q = $this->db->prepare('DELETE FROM rate_limits WHERE nokkel LIKE :prefix AND antall < :expired');
            $q->execute(['prefix' => 'chatgpt-nonce:%', 'expired' => $now - 120]);
            $q = $this->db->prepare('INSERT INTO rate_limits (nokkel, vindu_start, antall) VALUES (:nonce, :time, :created)');
            // Use the signed request's UTC day: normal Rate::rydd() must not erase live replay protection.
            try { $q->execute(['nonce' => 'chatgpt-nonce:' . $nonce,
                'time' => gmdate('Y-m-d 00:00:00', (int) $timestamp), 'created' => $now]); }
            catch (PDOException $e) {
                if (in_array((string) $e->getCode(), ['23000', '23505'], true)) {
                    throw new RuntimeException('Forespørselen er allerede brukt.', 409);
                }
                throw $e;
            }
            $columns = 'id, tittel, slug, status, bilde';
            if ($body['operation'] === 'get') {
                $q = $this->db->prepare("SELECT $columns FROM courses WHERE id = :id");
                $q->execute(['id' => $body['id']]); $row = $q->fetch(PDO::FETCH_ASSOC);
                $rows = $row ? [$row] : [];
            } else {
                $limit = $body['limit'];
                $q = $this->db->prepare("SELECT $columns FROM courses WHERE id > :afterId ORDER BY id LIMIT $limit");
                $q->execute(['afterId' => $body['afterId']]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as &$row) { $row['id'] = (int) $row['id']; } unset($row);
            $q = $this->db->prepare('INSERT INTO audit_log (member_id, handling, objekt_type, objekt_id, detaljer) VALUES (:actor, :action, :type, :id, :details)');
            $q->execute(['actor' => $memberId, 'action' => 'chatgpt_kurs_lest', 'type' => 'course',
                'id' => $body['id'] ?? null, 'details' => json_encode(['requestId' => $body['requestId'],
                    'operation' => $body['operation'], 'antall' => count($rows)], JSON_THROW_ON_ERROR)]);
            $this->db->commit();
            return ['courses' => $rows];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
