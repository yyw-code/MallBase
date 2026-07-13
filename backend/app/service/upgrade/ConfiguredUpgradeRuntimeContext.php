<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Throwable;

/**
 * 进程启动时冻结运行身份与当时观察到的 deployment epoch。
 */
final readonly class ConfiguredUpgradeRuntimeContext implements UpgradeRuntimeContext
{
    private string $runtimeInstanceId;
    private string $bootId;
    private UpgradeRuntimeIdentity $identity;
    private int $observedDeploymentEpoch;

    public function __construct(
        UpgradeRuntimeIdentityLoader $identityLoader,
        UpgradeGateRepository $gate,
        ?string $runtimeInstanceId = null,
        ?string $bootId = null,
    ) {
        $this->runtimeInstanceId = $runtimeInstanceId ?? (string) getenv('MALLBASE_RUNTIME_INSTANCE_ID');
        $this->bootId = $bootId ?? (string) getenv('MALLBASE_RUNTIME_BOOT_ID');
        try {
            $this->identity = $identityLoader->load();
            $this->observedDeploymentEpoch = $gate->snapshot()->deploymentEpoch;
            // 统一复用值对象校验 UUID、镜像身份和 epoch 范围。
            new UpgradeRuntimeInstance(
                $this->runtimeInstanceId,
                $this->bootId,
                'http',
                $this->identity,
                $this->observedDeploymentEpoch,
            );
        } catch (Throwable $exception) {
            if ($exception instanceof UpgradeStateConflict) {
                throw $exception;
            }
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_CONTEXT_INVALID');
        }
    }

    public function owner(string $role): UpgradeRuntimeInstance
    {
        try {
            return new UpgradeRuntimeInstance(
                $this->runtimeInstanceId,
                $this->bootId,
                $role,
                $this->identity,
                $this->observedDeploymentEpoch,
            );
        } catch (Throwable) {
            throw new UpgradeStateConflict('UPGRADE_RUNTIME_CONTEXT_INVALID');
        }
    }
}
