<?php

declare(strict_types=1);

namespace app\service\upgrade;

final class SimpleUpgradeActivityLease
{
    /** @param resource $handle */
    public function __construct(private mixed $handle)
    {
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        @flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
