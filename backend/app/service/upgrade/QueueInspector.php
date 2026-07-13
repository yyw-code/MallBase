<?php

declare(strict_types=1);

namespace app\service\upgrade;

interface QueueInspector
{
    public function inventory(): UpgradeQueueInventory;
}
