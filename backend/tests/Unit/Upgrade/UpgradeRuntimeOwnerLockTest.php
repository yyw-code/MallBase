<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeRuntimeOwnerLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradeRuntimeOwnerLockTest extends TestCase
{
    private string $root;
    private string $allocation;
    private string $slot;
    private string $secondSlot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-runtime-lock-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0555);
        $this->allocation = $this->root . '/allocation.lock';
        $this->slot = $this->root . '/slot-1.lock';
        $this->secondSlot = $this->root . '/slot-2.lock';
        chmod($this->root, 0755);
        file_put_contents($this->allocation, '{"schema_version":1,"kind":"runtime_lifetime","slot_id":"allocation"}');
        file_put_contents($this->slot, '{"schema_version":1,"kind":"runtime_lifetime","slot_id":"slot-1"}');
        file_put_contents($this->secondSlot, '{"schema_version":1,"kind":"runtime_lifetime","slot_id":"slot-2"}');
        chmod($this->allocation, 0444);
        chmod($this->slot, 0444);
        chmod($this->secondSlot, 0444);
        chmod($this->root, 0555);
    }

    protected function tearDown(): void
    {
        chmod($this->root, 0755);
        foreach ([$this->allocation, $this->slot, $this->secondSlot] as $file) {
            if (is_file($file) || is_link($file)) {
                @chmod($file, 0644);
                @unlink($file);
            }
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testSharedOwnerLockBlocksExclusiveRetirementUntilRelease(): void
    {
        $trust = static fn(): bool => true;
        $lock = UpgradeRuntimeOwnerLock::acquire($this->allocation, $this->slot, 'slot-1', 100, $trust);
        self::assertFalse(UpgradeRuntimeOwnerLock::tryRetire(
            $this->allocation,
            $this->slot,
            'slot-1',
            static function (): void {
                self::fail('retirement ran while owner was live');
            },
            $trust,
        ));

        $lock->release();
        $retired = false;
        self::assertTrue(UpgradeRuntimeOwnerLock::tryRetire(
            $this->allocation,
            $this->slot,
            'slot-1',
            static function () use (&$retired): void {
                $retired = true;
            },
            $trust,
        ));
        self::assertTrue($retired);
    }

    public function testNamedSlotReplacementIsDetectedByHeldDescriptor(): void
    {
        $trust = static fn(): bool => true;
        $lock = UpgradeRuntimeOwnerLock::acquire($this->allocation, $this->slot, 'slot-1', 100, $trust);
        chmod($this->root, 0755);
        unlink($this->slot);
        file_put_contents($this->slot, '{"schema_version":1,"kind":"runtime_lifetime","slot_id":"slot-1"}');
        chmod($this->slot, 0444);
        chmod($this->root, 0555);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('UPGRADE_RUNTIME_SLOT_REPLACED');
            $lock->verifyStillCanonical();
        } finally {
            $lock->release();
        }
    }

    public function testPoolTriesTheNextCandidateWithoutWaitingOnABusyFirstSlot(): void
    {
        $trust = static fn(): bool => true;
        $busy = fopen($this->slot, 'rb');
        self::assertIsResource($busy);
        self::assertTrue(flock($busy, LOCK_EX | LOCK_NB));
        try {
            $published = null;
            $lock = UpgradeRuntimeOwnerLock::acquireFromPool(
                $this->allocation,
                fn(): array => [
                    'slot-1' => $this->slot,
                    'slot-2' => $this->secondSlot,
                ],
                static function (string $slotId) use (&$published): void {
                    $published = $slotId;
                },
                100,
                $trust,
            );
            try {
                self::assertSame('slot-2', $lock->slotId);
                self::assertSame('slot-2', $published);
            } finally {
                $lock->release();
            }
        } finally {
            flock($busy, LOCK_UN);
            fclose($busy);
        }
    }
}
