<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

final class SqlSessionStore implements SessionStoreInterface
{
    public function __construct(private SqlState $state) {}
    public function exists(Uuid $id): bool { return $this->read($id) !== false; }
    public function read(Uuid $sessionId): string|false { return $this->state->get('session',$sessionId->toRfc4122())['data'] ?? false; }
    public function write(Uuid $sessionId,string $data): bool
    {
        if (strlen($data) > 65536) throw new \RuntimeException('Session data too large');
        $this->state->replace('session',$sessionId->toRfc4122(),['data' => $data],time()+900); return true;
    }
    public function destroy(Uuid $sessionId): bool { return $this->state->consume('session',$sessionId->toRfc4122()); }
    public function gc(): array { $this->state->gcSessions(); return []; }
}
