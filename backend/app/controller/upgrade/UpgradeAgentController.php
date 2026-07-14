<?php

declare(strict_types=1);

namespace app\controller\upgrade;

use app\service\upgrade\DatabaseBackupService;
use app\service\upgrade\PersistentStateVerificationService;
use app\service\upgrade\ReconciliationResult;
use app\service\upgrade\SchemaMigrationService;
use app\service\upgrade\UpgradeAgentStateTransitionService;
use app\service\upgrade\UpgradeDrainCoordinator;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradeMigrationPlanRegistry;
use app\service\upgrade\UpgradeOperationStore;
use app\service\upgrade\UpgradeOperationAttempt;
use app\service\upgrade\UpgradePaymentReconciliationService;
use app\service\upgrade\UpgradePlatformReceiptService;
use app\service\upgrade\UpgradeRuntimeContext;
use app\service\upgrade\UpgradeRuntimeFenceService;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeRegistry;
use app\service\upgrade\UpgradeStrictJsonDecoder;
use app\service\upgrade\UpgradeState;
use app\service\upgrade\UpgradeWritableSurfaceAuditService;
use mall_base\base\BaseController;
use RuntimeException;
use think\facade\Db;
use think\Response;
use Throwable;

/** Narrow, capability-authenticated API used only by the foreground Agent. */
final class UpgradeAgentController extends BaseController
{
    public function backupDatabase(string $jobId): Response
    {
        try {
            $body = $this->body(['operation_id', 'attempt']);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $operationId = $this->uuidField($body, 'operation_id');
            $service = app()->make(DatabaseBackupService::class);
            if (!hash_equals($service->operationId($jobId, $attempt), $operationId)) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();
            $result = $service->backup($jobId, $owner->runtimeInstanceId, $owner->bootId, $attempt);

            return $this->operationResponse($result);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function stateTransition(string $jobId): Response
    {
        try {
            $body = $this->body([
                'operation_id', 'expected_revision', 'expected_state',
                'next_state', 'platform_sync_pending', 'source', 'fence_operation_id', 'attempt',
            ]);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $source = $this->identity($body['source'] ?? null);
            $fenceOperationId = $this->uuidField($body, 'fence_operation_id');
            unset($source, $fenceOperationId);
            $expectedRevision = $this->integerField($body, 'expected_revision', 0);
            $expectedState = is_string($body['expected_state'] ?? null)
                ? UpgradeState::tryFrom($body['expected_state']) : null;
            $nextState = is_string($body['next_state'] ?? null)
                ? UpgradeState::tryFrom($body['next_state']) : null;
            if (!$expectedState instanceof UpgradeState || !$nextState instanceof UpgradeState
                || !is_bool($body['platform_sync_pending'] ?? null)) {
                throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
            }
            $service = app()->make(UpgradeAgentStateTransitionService::class);
            $operationId = $service->operationId(
                $jobId,
                $expectedRevision,
                $expectedState,
                $nextState,
                $body['platform_sync_pending'],
                $attempt,
            );
            if (!hash_equals($operationId, $this->uuidField($body, 'operation_id'))) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();

            return $this->operationResponse($service->transition(
                $jobId,
                $expectedRevision,
                $expectedState,
                $nextState,
                $body['platform_sync_pending'],
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
                $attempt,
            ));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function migrations(string $jobId): Response
    {
        try {
            $body = $this->body([
                'operation_id', 'migration_id', 'source', 'fence_operation_id', 'attempt',
            ]);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $source = $this->identity($body['source'] ?? null);
            $fenceOperationId = $this->uuidField($body, 'fence_operation_id');
            unset($source, $fenceOperationId);
            $operationId = $this->uuidField($body, 'operation_id');
            $migrationId = is_string($body['migration_id'] ?? null) ? $body['migration_id'] : '';
            $plan = app()->make(UpgradeMigrationPlanRegistry::class)->migration($jobId, $migrationId);
            $action = UpgradeOperationAttempt::action('migration_' . strtolower($migrationId), $attempt);
            $operations = app()->make(UpgradeOperationStore::class);
            $input = $this->inputChecksum([$plan['sha256'], $attempt]);
            $expected = $operations->operationId($jobId, $action, $input);
            if (!hash_equals($expected, $operationId)) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();
            $existing = $operations->get($operationId);
            if (is_array($existing)) {
                $operations->assertMatches($existing, $jobId, $action, $input);
            }
            if (is_array($existing) && ($existing['state'] !== 'running'
                || $existing['heartbeat_at'] + 15 >= time())) {
                return $this->operationResponse($existing);
            }
            $operation = $operations->claim(
                $jobId,
                $action,
                $input,
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
                is_array($existing),
            );
            if ($operation['state'] !== 'running') {
                return $this->operationResponse($operation);
            }
            ignore_user_abort(true);
            try {
                $result = app()->make(SchemaMigrationService::class)->execute(
                    $jobId,
                    $plan['id'],
                    $plan['version'],
                    $plan['sha256'],
                    $plan['sql'],
                );
                $operation = $operations->complete(
                    $operationId,
                    $owner->bootId,
                    ['migration_id' => $migrationId, 'statement_count' => $result['statement_count']],
                    time(),
                );
            } catch (Throwable $exception) {
                $operation = $operations->fail(
                    $operationId,
                    $owner->bootId,
                    $this->safeCode($exception->getMessage(), 'UPGRADE_MIGRATION_FAILED'),
                    time(),
                    str_contains($exception->getMessage(), 'RECOVERY_BLOCKED'),
                );
            }

            return $this->operationResponse($operation);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function confirmPaused(string $jobId): Response
    {
        try {
            $body = $this->body(['operation_id', 'expected_revision', 'delayed_compatible', 'attempt']);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $expectedRevision = $this->integerField($body, 'expected_revision', 0);
            if (!is_bool($body['delayed_compatible'] ?? null)) {
                throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
            }
            $checksum = $this->inputChecksum([$expectedRevision, $body['delayed_compatible'], $attempt]);

            return $this->shortOperation(
                $jobId,
                UpgradeOperationAttempt::action('confirm_paused', $attempt),
                $checksum,
                $this->uuidField($body, 'operation_id'),
                function () use ($expectedRevision, $body): array {
                    $drain = app()->make(UpgradeDrainCoordinator::class);
                    $gate = $this->agentGate();
                    if ($gate->state->value === 'draining') {
                        $gate = $drain->tryPause($expectedRevision, $body['delayed_compatible']);
                    }
                    $gate = $drain->confirmPaused($gate->revision);

                    return $this->gateProjection($gate);
                },
                [
                    'UPGRADE_DRAIN_BLOCKED',
                    'UPGRADE_DRAIN_NOT_SAFE',
                    'UPGRADE_DELAYED_QUEUE_INCOMPATIBLE',
                ],
            );
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function runtimeFence(string $jobId): Response
    {
        try {
            $body = $this->body(['operation_id', 'expected_revision', 'source', 'target', 'attempt']);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $source = $this->identity($body['source'] ?? null);
            $target = $this->identity($body['target'] ?? null);
            $revision = $this->integerField($body, 'expected_revision', 0);
            $service = app()->make(UpgradeRuntimeFenceService::class);
            $expected = app()->make(UpgradeOperationStore::class)->operationId(
                $jobId,
                UpgradeOperationAttempt::action('runtime_fence', $attempt),
                $this->inputChecksum([$revision, $source->toArray(), $target->toArray(), $attempt]),
            );
            if (!hash_equals($expected, $this->uuidField($body, 'operation_id'))) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();
            $result = $service->advance(
                $jobId,
                $revision,
                $source,
                $target,
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
                $attempt,
            );

            return $this->operationResponse($result);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function resume(string $jobId): Response
    {
        try {
            $body = $this->body([
                'operation_id', 'expected_revision', 'phase', 'control_expected_revision',
                'control_request_id', 'source', 'fence_operation_id', 'attempt',
            ]);
            $source = $this->identity($body['source'] ?? null);
            $fenceOperationId = $this->uuidField($body, 'fence_operation_id');
            unset($source, $fenceOperationId);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $expectedRevision = $this->integerField($body, 'expected_revision', 0);
            $controlExpectedRevision = $this->integerField($body, 'control_expected_revision', 1);
            $controlRequestId = $this->uuidField($body, 'control_request_id');
            $phase = is_string($body['phase'] ?? null) ? UpgradeState::tryFrom($body['phase']) : null;
            if (!$phase instanceof UpgradeState) {
                throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
            }
            $service = app()->make(UpgradeAgentStateTransitionService::class);
            $expected = $service->resumeOperationId(
                $jobId,
                $expectedRevision,
                $phase,
                $controlExpectedRevision,
                $controlRequestId,
                $attempt,
            );
            if (!hash_equals($expected, $this->uuidField($body, 'operation_id'))) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();

            return $this->operationResponse($service->resume(
                $jobId,
                $expectedRevision,
                $phase,
                $controlExpectedRevision,
                $controlRequestId,
                $attempt,
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
            ));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function cancel(string $jobId): Response
    {
        try {
            $body = $this->body([
                'operation_id', 'expected_revision', 'control_expected_revision',
                'control_request_id', 'source', 'fence_operation_id', 'attempt',
            ]);
            $source = $this->identity($body['source'] ?? null);
            $fenceOperationId = $this->uuidField($body, 'fence_operation_id');
            unset($source, $fenceOperationId);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $expectedRevision = $this->integerField($body, 'expected_revision', 0);
            $controlExpectedRevision = $this->integerField($body, 'control_expected_revision', 1);
            $controlRequestId = $this->uuidField($body, 'control_request_id');
            $service = app()->make(UpgradeAgentStateTransitionService::class);
            $expected = $service->cancelOperationId(
                $jobId,
                $expectedRevision,
                $controlExpectedRevision,
                $controlRequestId,
                $attempt,
            );
            if (!hash_equals($expected, $this->uuidField($body, 'operation_id'))) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();

            return $this->operationResponse($service->cancel(
                $jobId,
                $expectedRevision,
                $controlExpectedRevision,
                $controlRequestId,
                $attempt,
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
            ));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function platformReceipt(string $jobId): Response
    {
        try {
            $body = $this->body([
                'operation_id', 'expected_revision', 'terminal_state',
                'normal_transition_operation_id', 'source', 'fence_operation_id', 'attempt',
            ]);
            $source = $this->identity($body['source'] ?? null);
            $fenceOperationId = $this->uuidField($body, 'fence_operation_id');
            unset($source, $fenceOperationId);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $expectedRevision = $this->integerField($body, 'expected_revision', 0);
            $terminalState = is_string($body['terminal_state'] ?? null)
                ? UpgradeState::tryFrom($body['terminal_state']) : null;
            if (!$terminalState instanceof UpgradeState) {
                throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
            }
            $normalOperationId = $this->uuidField($body, 'normal_transition_operation_id');
            $service = app()->make(UpgradePlatformReceiptService::class);
            $expected = $service->operationId(
                $jobId,
                $expectedRevision,
                $terminalState,
                $normalOperationId,
                $attempt,
            );
            if (!hash_equals($expected, $this->uuidField($body, 'operation_id'))) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();

            return $this->operationResponse($service->confirm(
                $jobId,
                $expectedRevision,
                $terminalState,
                $normalOperationId,
                $attempt,
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
            ));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function persistentStateVerification(string $jobId): Response
    {
        try {
            $body = $this->body([
                'operation_id', 'artifact', 'expected_merkle_root', 'expected_count',
                'expected_file_mode', 'expected_directory_mode', 'receipt_checksum', 'attempt',
            ]);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            foreach (['artifact', 'expected_merkle_root', 'receipt_checksum'] as $field) {
                if (!is_string($body[$field] ?? null)) {
                    throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
                }
            }
            $count = $this->integerField($body, 'expected_count', 0);
            $fileMode = $this->integerField($body, 'expected_file_mode', 0, 0777);
            $directoryMode = $this->integerField($body, 'expected_directory_mode', 0, 0777);
            $owner = $this->owner();
            $identityDigest = 'sha256:' . hash('sha256', json_encode($owner->identity->toArray(), JSON_THROW_ON_ERROR));
            $service = app()->make(PersistentStateVerificationService::class);
            $input = $this->inputChecksum([
                $body['artifact'], $body['expected_merkle_root'], $count, $fileMode,
                $directoryMode, $identityDigest, $body['receipt_checksum'], $attempt,
            ]);
            $expected = app()->make(UpgradeOperationStore::class)->operationId(
                $jobId,
                UpgradeOperationAttempt::action('verify_' . $body['artifact'], $attempt),
                $input,
            );
            if (!hash_equals($expected, $this->uuidField($body, 'operation_id'))) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $result = $service->verify(
                $jobId,
                $body['artifact'],
                $body['expected_merkle_root'],
                $count,
                $fileMode,
                $directoryMode,
                $identityDigest,
                $body['receipt_checksum'],
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
                $attempt,
            );

            return $this->operationResponse($result);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function reconciliation(string $jobId): Response
    {
        try {
            $body = $this->body(['operation_id', 'window_start', 'cutoff', 'attempt']);
            $attempt = UpgradeOperationAttempt::normalize($body['attempt'] ?? null);
            $windowStart = $this->integerField($body, 'window_start', 0, 4_102_444_800);
            $cutoff = $this->integerField($body, 'cutoff', 0, 4_102_444_800);
            $checksum = $this->inputChecksum([$windowStart, $cutoff, $attempt]);
            $operationId = $this->uuidField($body, 'operation_id');
            $operations = app()->make(UpgradeOperationStore::class);
            $action = UpgradeOperationAttempt::action('reconciliation', $attempt);
            if (!hash_equals($operations->operationId($jobId, $action, $checksum), $operationId)) {
                throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
            }
            $owner = $this->owner();
            $existing = $operations->get($operationId);
            if (is_array($existing)) {
                $operations->assertMatches($existing, $jobId, $action, $checksum);
            }
            if (is_array($existing) && $existing['state'] !== 'running') {
                return $this->operationResponse($existing);
            }
            $operation = $operations->claim(
                $jobId,
                $action,
                $checksum,
                $owner->runtimeInstanceId,
                $owner->bootId,
                time(),
                is_array($existing) && $existing['heartbeat_at'] + 15 < time(),
            );
            if ($operation['state'] !== 'running') {
                return $this->operationResponse($operation);
            }
            $result = app()->make(UpgradePaymentReconciliationService::class)->run($jobId, $windowStart, $cutoff);
            if ($result->complete()) {
                $operation = $operations->complete($operationId, $owner->bootId, [
                    'phase' => $result->phase,
                    'processed' => $result->processed,
                ], time());
            } else {
                $operation = $operations->heartbeat($operationId, $owner->bootId, time());
                $operation['result'] = [
                    'phase' => $result->phase,
                    'processed' => $result->processed,
                    'retry_after_seconds' => $result->quietRemainingSeconds,
                    'error_codes' => $result->errorCodes,
                ];
            }

            return $this->operationResponse($operation);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function operation(string $jobId, string $operationId): Response
    {
        try {
            $operation = app()->make(UpgradeOperationStore::class)->get(strtolower($operationId));
            if (!is_array($operation) || $operation['job_id'] !== strtolower($jobId)) {
                throw new RuntimeException('UPGRADE_OPERATION_NOT_FOUND');
            }

            return $this->operationResponse($operation);
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function health(string $jobId): Response
    {
        try {
            $principal = $this->agentPrincipal();
            $owner = $this->owner();
            $databaseOk = false;
            $databaseBytes = 0;
            try {
                $databaseOk = (int) (Db::query('SELECT 1 AS ok')[0]['ok'] ?? 0) === 1;
                $databaseBytes = (int) (Db::query(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()',
                )[0]['bytes'] ?? 0);
            } catch (Throwable) {
                $databaseOk = false;
            }
            $runtimeRecords = [];
            foreach (app()->make(UpgradeRuntimeRegistry::class)->active() as $record) {
                $runtimeRecords[] = [
                    'runtime_instance_id' => (string) ($record['runtime_instance_id'] ?? ''),
                    'boot_id' => (string) ($record['boot_id'] ?? ''),
                    'role' => (string) ($record['role'] ?? ''),
                    'app_version' => (string) ($record['app_version'] ?? ''),
                    'deployment_id' => (string) ($record['deployment_id'] ?? ''),
                    'storage_layout_version' => (int) ($record['storage_layout_version'] ?? -1),
                    'storage_layout_generation' => (int) ($record['storage_layout_generation'] ?? -1),
                    'observed_deployment_epoch' => (int) ($record['observed_deployment_epoch'] ?? -1),
                    'observed_gate_revision' => (int) ($record['observed_gate_revision'] ?? -1),
                    'identity_fenced' => (bool) ($record['identity_fenced'] ?? true),
                    'paused_ack_revision' => is_int($record['paused_ack_revision'] ?? null)
                        ? $record['paused_ack_revision'] : null,
                    'queues' => is_array($record['queues'] ?? null) ? array_values($record['queues']) : [],
                    'cron_enabled' => (bool) ($record['cron_enabled'] ?? false),
                    'last_seen_at' => (int) ($record['last_seen_at'] ?? -1),
                ];
            }
            $data = [
                'job_id' => strtolower($jobId),
                'database_ok' => $databaseOk,
                'database_estimated_bytes' => max(0, $databaseBytes),
                'runtime' => $owner->toArray(),
                'gate' => $this->gateProjection($principal['gate']),
                'identity_fenced' => !$principal['gate']->acceptsRuntime($owner->identity),
                'required_runtime_roles' => array_values((array) config('upgrade.required_runtime_roles', ['http'])),
                'runtime_lease_seconds' => (int) config('upgrade.runtime_owner_heartbeat_ttl', 15),
                'runtime_records' => $runtimeRecords,
            ];

            return $this->success($data, '升级运行环境检查完成')->header($this->headers());
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function writableSurfaceAudit(string $jobId): Response
    {
        try {
            $gate = $this->agentGate();
            $data = app()->make(UpgradeWritableSurfaceAuditService::class)->audit();
            if ($gate->state === UpgradeState::BackingUp && ($data['supported'] ?? false) === true) {
                $data['artifacts'] = app()->make(PersistentStateVerificationService::class)
                    ->capture(strtolower($jobId));
            }

            return $this->success(
                $data,
                '可写面检查完成',
            )->header($this->headers());
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    /** @param list<string> $fields @return array<string,mixed> */
    private function body(array $fields): array
    {
        $length = trim((string) $this->request->header('Content-Length', ''));
        $contentLength = $length === '' ? null : (int) $length;

        return app()->make(UpgradeStrictJsonDecoder::class)->decode(
            (string) $this->request->getContent(),
            (string) $this->request->header('Content-Type', ''),
            $contentLength,
            $fields,
        );
    }

    /**
     * @param callable():array<string,mixed> $work
     * @param list<string> $pendingCodes
     */
    private function shortOperation(
        string $jobId,
        string $action,
        string $checksum,
        string $providedOperationId,
        callable $work,
        array $pendingCodes = [],
    ): Response {
        $operations = app()->make(UpgradeOperationStore::class);
        $operationId = $operations->operationId($jobId, $action, $checksum);
        if (!hash_equals($operationId, $providedOperationId)) {
            throw new RuntimeException('UPGRADE_OPERATION_ID_MISMATCH');
        }
        $existing = $operations->get($operationId);
        if (is_array($existing)) {
            $operations->assertMatches($existing, $jobId, $action, $checksum);
        }
        if (is_array($existing) && ($existing['state'] !== 'running'
            || $existing['heartbeat_at'] + 15 >= time())) {
            return $this->operationResponse($existing);
        }
        $owner = $this->owner();
        $operation = $operations->claim(
            $jobId,
            $action,
            $checksum,
            $owner->runtimeInstanceId,
            $owner->bootId,
            time(),
            is_array($existing),
        );
        if ($operation['state'] !== 'running') {
            return $this->operationResponse($operation);
        }
        try {
            $operation = $operations->complete($operationId, $owner->bootId, $work(), time());
        } catch (Throwable $exception) {
            $code = $this->safeCode($exception->getMessage(), 'UPGRADE_AGENT_OPERATION_FAILED');
            if (in_array($code, $pendingCodes, true)) {
                // Drain blockers are expected to disappear while the same
                // deterministic operation is retried. Keep the operation
                // running so a transient queue/request/worker ACK cannot
                // permanently poison the upgrade checkpoint.
                $operation = $operations->heartbeat($operationId, $owner->bootId, time());
                $operation['result'] = ['pending_code' => $code];
            } else {
                $operation = $operations->fail(
                    $operationId,
                    $owner->bootId,
                    $code,
                    time(),
                    str_contains($exception->getMessage(), 'RECOVERY_BLOCKED'),
                );
            }
        }

        return $this->operationResponse($operation);
    }

    /** @param array<string,mixed> $operation */
    private function operationResponse(array $operation): Response
    {
        $state = (string) ($operation['state'] ?? '');
        $status = in_array($state, ['running', 'completed', 'failed', 'recovery_blocked'], true)
            ? $state : 'failed';
        $data = [
            'operation_id' => (string) ($operation['operation_id'] ?? ''),
            'status' => $status,
        ];
        if (is_array($operation['result'] ?? null)) {
            $data['result'] = $operation['result'];
        }
        if (is_string($operation['error_code'] ?? null) && $operation['error_code'] !== '') {
            $data['error_code'] = $operation['error_code'];
        }
        $httpStatus = $status === 'running' ? 202 : 200;

        return json([
            'code' => 200,
            'message' => '升级操作状态已更新',
            'data' => $data,
            'timestamp' => time(),
        ], $httpStatus)->header($this->headers());
    }

    private function owner(): UpgradeRuntimeInstance
    {
        return app()->make(UpgradeRuntimeContext::class)->owner('http');
    }

    /** @return array<string,mixed> */
    private function agentPrincipal(): array
    {
        $principal = $this->request->upgrade_agent ?? null;
        if (!is_array($principal) || !($principal['gate'] ?? null) instanceof UpgradeGateSnapshot) {
            throw new RuntimeException('UPGRADE_AGENT_AUTH_INVALID');
        }

        return $principal;
    }

    private function agentGate(): UpgradeGateSnapshot
    {
        return $this->agentPrincipal()['gate'];
    }

    /** @param mixed $value */
    private function identity(mixed $value): UpgradeRuntimeIdentity
    {
        if (!is_array($value) || array_keys($value) !== [
            'version', 'deployment_id', 'storage_layout_version', 'storage_layout_generation',
        ] || !is_string($value['version']) || !is_string($value['deployment_id'])
            || !is_int($value['storage_layout_version']) || !is_int($value['storage_layout_generation'])) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
        }
        try {
            return new UpgradeRuntimeIdentity(
                $value['version'],
                strtolower($value['deployment_id']),
                $value['storage_layout_version'],
                $value['storage_layout_generation'],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID', 0, $exception);
        }
    }

    /** @param array<string,mixed> $body */
    private function uuidField(array $body, string $field): string
    {
        $value = strtolower((string) ($body[$field] ?? ''));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
        }

        return $value;
    }

    /** @param array<string,mixed> $body */
    private function integerField(array $body, string $field, int $minimum, int $maximum = PHP_INT_MAX): int
    {
        $value = $body[$field] ?? null;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('UPGRADE_AGENT_ARGUMENT_INVALID');
        }

        return $value;
    }

    /** @param array<mixed> $input */
    private function inputChecksum(array $input): string
    {
        return 'sha256:' . hash('sha256', json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,mixed> */
    private function gateProjection(UpgradeGateSnapshot $gate): array
    {
        return [
            'state' => $gate->state->value,
            'revision' => $gate->revision,
            'deployment_epoch' => $gate->deploymentEpoch,
            'runtime_identity' => $gate->runtimeIdentity()->toArray(),
        ];
    }

    private function errorResponse(Throwable $exception): Response
    {
        $reason = $this->safeCode($exception->getMessage(), 'UPGRADE_AGENT_OPERATION_FAILED');
        $status = match ($reason) {
            'RUNTIME_IDENTITY_FENCED', 'UPGRADE_OPERATION_ID_MISMATCH',
            'UPGRADE_OPERATION_CONFLICT', 'UPGRADE_DRAIN_STATE_CONFLICT' => 409,
            'UPGRADE_OPERATION_NOT_FOUND' => 404,
            'UPGRADE_AGENT_ARGUMENT_INVALID', 'UPGRADE_JSON_INVALID',
            'UPGRADE_MIGRATION_ARGUMENT_INVALID', 'UPGRADE_MIGRATION_UNKNOWN' => 422,
            default => 503,
        };

        return json([
            'code' => $status,
            'message' => $status === 422 ? '升级操作参数无效' : '升级操作暂未完成',
            'data' => ['reason' => $reason],
            'timestamp' => time(),
        ], $status)->header($this->headers());
    }

    private function safeCode(string $code, string $fallback): string
    {
        return preg_match('/^[A-Z0-9_]{1,64}$/D', $code) === 1 ? $code : $fallback;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
