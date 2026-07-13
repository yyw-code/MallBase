<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;

final class UpgradeStateConflict extends RuntimeException
{
    public function __construct(string $code = 'UPGRADE_STATE_CONFLICT')
    {
        parent::__construct($code);
    }
}
