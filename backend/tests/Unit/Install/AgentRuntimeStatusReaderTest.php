<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentRuntimeStatusReader;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AgentRuntimeStatusReaderTest extends TestCase
{
    public function testLiveServeLeaseIsRecognized(): void
    {
        $reader = new AgentRuntimeStatusReader(null, fn(): object => $this->fixtureStatus());

        self::assertTrue($reader->isServeLeaseAlive(1005));
        self::assertFalse($reader->isServeLeaseAlive(1011));
    }

    public function testOfflineMalformedFutureAndUnknownStatusesFailClosed(): void
    {
        $mutations = [
            'offline' => static fn(object $status) => $status->state = 'offline',
            'wrong mode' => static fn(object $status) => $status->mode = 'heartbeat',
            'future observation' => static fn(object $status) => $status->last_seen_at = 1010,
            'expired' => static fn(object $status) => $status->lease_until = 1000,
            'zero revision' => static fn(object $status) => $status->revision = 0,
            'unknown field' => static fn(object $status) => $status->attacker = true,
            'wrong bool' => static fn(object $status) => $status->safe_to_stop = 1,
        ];

        foreach ($mutations as $name => $mutate) {
            $status = $this->fixtureStatus();
            $mutate($status);
            $reader = new AgentRuntimeStatusReader(null, static fn(): object => $status);
            self::assertFalse($reader->isServeLeaseAlive(1005), $name);
        }
    }

    public function testMissingOrUnreadableStatusIsNotAServiceLease(): void
    {
        self::assertFalse((new AgentRuntimeStatusReader(null, static fn(): ?object => null))->isServeLeaseAlive(1000));
        self::assertFalse((new AgentRuntimeStatusReader(null, static function (): object {
            throw new \RuntimeException('secret path');
        }))->isServeLeaseAlive(1000));
    }

    private function fixtureStatus(): object
    {
        return (object) [
            'schema_version' => 1,
            'agent_version' => '1.0.0',
            'mode' => 'serve',
            'pid' => 12345,
            'arch' => 'amd64',
            'state' => 'ready',
            'platform_state' => 'online',
            'last_seen_at' => 1000,
            'lease_until' => 1010,
            'safe_to_stop' => true,
            'production_ready' => true,
            'upgrade_ready' => true,
            'revision' => 1,
        ];
    }
}
