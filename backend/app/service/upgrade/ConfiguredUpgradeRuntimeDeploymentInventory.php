<?php

declare(strict_types=1);

namespace app\service\upgrade;

use InvalidArgumentException;

final readonly class ConfiguredUpgradeRuntimeDeploymentInventory implements UpgradeRuntimeDeploymentInventory
{
    /** @var list<string> */
    private array $roles;

    /** @param list<string> $roles */
    public function __construct(array $roles)
    {
        if (!array_is_list($roles) || $roles === []) {
            throw new InvalidArgumentException('UPGRADE_RUNTIME_DEPLOYMENT_INVENTORY_INVALID');
        }
        $roles = array_values(array_unique($roles));
        sort($roles, SORT_STRING);
        if (!in_array('http', $roles, true)) {
            throw new InvalidArgumentException('UPGRADE_RUNTIME_DEPLOYMENT_INVENTORY_INVALID');
        }
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, ['http', 'queue', 'cron'], true)) {
                throw new InvalidArgumentException('UPGRADE_RUNTIME_DEPLOYMENT_INVENTORY_INVALID');
            }
        }
        $this->roles = $roles;
    }

    public function requiredRoles(): array
    {
        return $this->roles;
    }
}
