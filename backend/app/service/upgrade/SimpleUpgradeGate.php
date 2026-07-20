<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use Throwable;

final class SimpleUpgradeGate
{
    private const STATES = [
        'normal',
        'draining',
        'paused',
        'awaiting_php_restart',
    ];

    private readonly string $runDirectory;
    private readonly int $runGid;

    public function __construct(string $runDirectory)
    {
        $runDirectory = rtrim($runDirectory, DIRECTORY_SEPARATOR);
        if ($runDirectory === '' || !str_starts_with($runDirectory, DIRECTORY_SEPARATOR)
            || str_contains($runDirectory, "\0")) {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_CONFIG_INVALID');
        }
        if (!is_dir($runDirectory)
            && (!mkdir($runDirectory, 0770, true) || !chmod($runDirectory, 0770))) {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
        }
        $resolved = realpath($runDirectory);
        $directoryStat = is_string($resolved) ? @lstat($resolved) : false;
        if (!is_string($resolved) || !is_array($directoryStat)
            || ($directoryStat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
        }
        $this->runDirectory = $resolved;
        $this->runGid = (int) $directoryStat['gid'];
        $this->initialize();
    }

    public function state(): string
    {
        $admission = $this->openLock('admission.lock');
        try {
            $this->lock($admission, LOCK_SH);

            return $this->readState();
        } finally {
            $this->unlockAndClose($admission);
        }
    }

    public function tryEnter(): ?SimpleUpgradeActivityLease
    {
        $admission = $this->openLock('admission.lock');
        $activity = null;
        try {
            $this->lock($admission, LOCK_SH);
            if ($this->readState() !== 'normal') {
                return null;
            }
            $activity = $this->openLock('activity.lock');
            $this->lock($activity, LOCK_SH);
        } catch (Throwable $exception) {
            if (is_resource($activity)) {
                $this->unlockAndClose($activity);
            }
            throw $exception;
        } finally {
            $this->unlockAndClose($admission);
        }

        return new SimpleUpgradeActivityLease($activity);
    }

    public function drain(): string
    {
        $admission = $this->openLock('admission.lock');
        try {
            $this->lock($admission, LOCK_EX);
            $state = $this->readState();
            if ($state === 'paused') {
                return $state;
            }
            if ($state === 'normal') {
                $this->writeState('draining');
            } elseif ($state !== 'draining') {
                throw new RuntimeException('SIMPLE_UPGRADE_STATE_CONFLICT');
            }
        } finally {
            $this->unlockAndClose($admission);
        }

        $activity = $this->openLock('activity.lock');
        try {
            $this->lock($activity, LOCK_EX);
            $state = $this->readState();
            if ($state === 'draining') {
                $this->writeState('paused');
                $state = 'paused';
            }
            if ($state !== 'paused') {
                throw new RuntimeException('SIMPLE_UPGRADE_STATE_CONFLICT');
            }

            return $state;
        } finally {
            $this->unlockAndClose($activity);
        }
    }

    public function markAwaitingPhpRestart(): string
    {
        return $this->transition('paused', 'awaiting_php_restart');
    }

    public function restoreNormal(): string
    {
        $admission = $this->openLock('admission.lock');
        try {
            $this->lock($admission, LOCK_EX);
            $state = $this->readState();
            if ($state === 'normal') {
                return $state;
            }
            if (!in_array($state, ['paused', 'awaiting_php_restart'], true)) {
                throw new RuntimeException('SIMPLE_UPGRADE_STATE_CONFLICT');
            }
            $this->writeState('normal');

            return 'normal';
        } finally {
            $this->unlockAndClose($admission);
        }
    }

    private function initialize(): void
    {
        $admission = $this->openLock('admission.lock');
        try {
            $this->lock($admission, LOCK_EX);
            $this->openAndCloseLock('activity.lock');
            if (!is_file($this->statePath())) {
                $this->writeState('normal');

                return;
            }
            $this->readState();
        } finally {
            $this->unlockAndClose($admission);
        }
    }

    private function transition(string $expected, string $next): string
    {
        $admission = $this->openLock('admission.lock');
        try {
            $this->lock($admission, LOCK_EX);
            $state = $this->readState();
            if ($state === $next) {
                return $state;
            }
            if ($state !== $expected) {
                throw new RuntimeException('SIMPLE_UPGRADE_STATE_CONFLICT');
            }
            $this->writeState($next);

            return $next;
        } finally {
            $this->unlockAndClose($admission);
        }
    }

    /** @return resource */
    private function openLock(string $name)
    {
        $path = $this->runDirectory . DIRECTORY_SEPARATOR . $name;
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
        }
        try {
            $opened = @fstat($handle);
            $named = @lstat($path);
            if (!$this->sameRegularLock($opened, $named)) {
                throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
            }
            if (($opened['mode'] & 0777) !== 0660 && !@chmod($path, 0660)) {
                throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
            }
            if ((int) $opened['gid'] !== $this->runGid && !@chgrp($path, $this->runGid)) {
                throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
            }
            $after = @fstat($handle);
            $namedAfter = @lstat($path);
            if (!$this->sameRegularLock($after, $namedAfter)
                || ($after['mode'] & 0777) !== 0660 || (int) $after['gid'] !== $this->runGid) {
                throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            throw $exception;
        }

        return $handle;
    }

    /** @param array<string|int,mixed>|false $opened @param array<string|int,mixed>|false $named */
    private function sameRegularLock(array|false $opened, array|false $named): bool
    {
        return is_array($opened) && is_array($named)
            && ($opened['mode'] & 0170000) === 0100000
            && ($named['mode'] & 0170000) === 0100000
            && (int) $opened['nlink'] === 1 && (int) $named['nlink'] === 1
            && $opened['dev'] === $named['dev'] && $opened['ino'] === $named['ino'];
    }

    private function openAndCloseLock(string $name): void
    {
        $handle = $this->openLock($name);
        fclose($handle);
    }

    /** @param resource $handle */
    private function lock($handle, int $operation): void
    {
        if (!flock($handle, $operation)) {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_LOCK_FAILED');
        }
    }

    /** @param resource $handle */
    private function unlockAndClose($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        @flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function readState(): string
    {
        $raw = @file_get_contents($this->statePath());
        if (!is_string($raw)) {
            throw new RuntimeException('SIMPLE_UPGRADE_STATE_INVALID');
        }
        foreach (self::STATES as $state) {
            if (hash_equals($this->encodeState($state), $raw)) {
                return $state;
            }
        }

        throw new RuntimeException('SIMPLE_UPGRADE_STATE_INVALID');
    }

    private function writeState(string $state): void
    {
        if (!in_array($state, self::STATES, true)) {
            throw new RuntimeException('SIMPLE_UPGRADE_STATE_INVALID');
        }
        $temporary = $this->runDirectory . DIRECTORY_SEPARATOR . '.state-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
        }
        try {
            $encoded = $this->encodeState($state);
            if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)
                || function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporary);
            throw $exception;
        }
        fclose($handle);
        if (!chmod($temporary, 0660) || !rename($temporary, $this->statePath())) {
            @unlink($temporary);
            throw new RuntimeException('SIMPLE_UPGRADE_GATE_STORAGE_UNAVAILABLE');
        }
    }

    private function encodeState(string $state): string
    {
        return '{"schema_version":1,"state":"' . $state . '"}' . "\n";
    }

    private function statePath(): string
    {
        return $this->runDirectory . DIRECTORY_SEPARATOR . 'state.json';
    }
}
