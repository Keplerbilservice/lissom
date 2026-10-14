<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;

/** State is isolated by issuer. Raw bearer tokens, auth codes and session IDs are not stored. */
final class SqlState
{
    public function __construct(public readonly \PDO $db, private string $issuer) {}
    private function key(string $kind, string $key): string { return hash('sha256', $this->issuer . "\n$kind\n$key"); }
    public function put(string $kind, string $key, array $value, int $expires): void
    {
        $q = $this->db->prepare('INSERT INTO mcp_oauth_state (kind, key_hash, value_json, expires_at, revoked) VALUES (:kind, :key, :value, :expiry, 0)');
        $q->execute(['kind' => $kind, 'key' => $this->key($kind,$key),
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'expiry' => $expires]);
    }
    public function get(string $kind, string $key): ?array
    {
        $q = $this->db->prepare('SELECT value_json FROM mcp_oauth_state WHERE kind = :kind AND key_hash = :key AND revoked = 0 AND expires_at > :now');
        $q->execute(['kind' => $kind, 'key' => $this->key($kind,$key), 'now' => time()]);
        $raw = $q->fetchColumn();
        return $raw === false ? null : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    }
    public function consume(string $kind, string $key): bool
    {
        $q = $this->db->prepare('UPDATE mcp_oauth_state SET revoked = 1 WHERE kind = :kind AND key_hash = :key AND revoked = 0 AND expires_at > :now');
        $q->execute(['kind' => $kind, 'key' => $this->key($kind,$key), 'now' => time()]);
        return $q->rowCount() === 1;
    }
    public function record(string $kind,string $key): ?array
    {
        $q=$this->db->prepare('SELECT value_json,expires_at,revoked FROM mcp_oauth_state WHERE kind=:kind AND key_hash=:key');
        $q->execute(['kind'=>$kind,'key'=>$this->key($kind,$key)]);$row=$q->fetch(\PDO::FETCH_ASSOC);
        if(!$row)return null;
        return ['value'=>json_decode($row['value_json'],true,16,JSON_THROW_ON_ERROR),
            'expires'=>(int)$row['expires_at'],'revoked'=>(bool)$row['revoked']];
    }
    public function replace(string $kind, string $key, array $value, int $expires): void
    {
        $sql = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'INSERT INTO mcp_oauth_state (kind,key_hash,value_json,expires_at,revoked) VALUES (:kind,:key,:value,:expiry,0) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),expires_at=VALUES(expires_at),revoked=0'
            : 'INSERT INTO mcp_oauth_state (kind,key_hash,value_json,expires_at,revoked) VALUES (:kind,:key,:value,:expiry,0) ON CONFLICT(kind,key_hash) DO UPDATE SET value_json=excluded.value_json,expires_at=excluded.expires_at,revoked=0';
        $q = $this->db->prepare($sql);
        $q->execute(['kind' => $kind,'key' => $this->key($kind,$key),'value' => json_encode($value,JSON_THROW_ON_ERROR),'expiry' => $expires]);
    }
    public function gcSessions(): void
    {
        $q = $this->db->prepare('DELETE FROM mcp_oauth_state WHERE kind = :kind AND expires_at <= :now');
        $q->execute(['kind' => 'session','now' => time()]);
    }
}
