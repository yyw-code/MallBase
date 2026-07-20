<?php

declare(strict_types=1);

namespace app\service\upgrade;

use RuntimeException;
use stdClass;
use Throwable;

/** 固定 Agent -> PHP CLI JSON 边界；业务状态全部委托给无状态 Runtime Service。 */
final readonly class SimpleUpgradeCliService
{
    private const OPERATIONS = [
        'pause',
        'backup_database',
        'migrate',
        'restore_database',
        'awaiting_restart',
        'resume',
    ];

    public function __construct(
        private SimpleUpgradeRuntimeService $runtime,
        private UpgradeStrictJsonDecoder $decoder,
    ) {
    }

    /** @return array{exit_code:int,stdout:string,stderr:string} */
    public function handle(string $stdin): array
    {
        $operation = null;
        $jobId = null;
        try {
            $request = $this->decoder->decode(
                $stdin,
                'application/json',
                strlen($stdin),
                ['schema_version', 'operation', 'job_id', 'payload'],
            );
            $object = json_decode($stdin, false, 32, JSON_THROW_ON_ERROR);
            if (!$object instanceof stdClass || !$object->payload instanceof stdClass
                || ($request['schema_version'] ?? null) !== 1
                || !is_string($request['operation'] ?? null)
                || !in_array($request['operation'], self::OPERATIONS, true)
                || !is_string($request['job_id'] ?? null)) {
                throw new RuntimeException('SIMPLE_UPGRADE_INPUT_INVALID');
            }
            $operation = $request['operation'];
            $jobId = $request['job_id'];
            $payload = get_object_vars($object->payload);
            $data = match ($operation) {
                'pause' => $this->runtime->pause($jobId, $payload),
                'backup_database' => $this->runtime->backup($jobId, $payload),
                'migrate' => $this->runtime->migrate($jobId, $payload),
                'restore_database' => $this->runtime->restore($jobId, $payload),
                'awaiting_restart' => $this->runtime->awaitingRestart($jobId, $payload),
                'resume' => $this->runtime->resume($jobId, $payload),
            };

            return $this->result(0, true, $operation, $jobId, $data, null);
        } catch (Throwable $exception) {
            $code = $this->errorCode($exception);

            return $this->result(
                $this->exitCode($code),
                false,
                $operation,
                $jobId,
                null,
                ['code' => $code],
            );
        }
    }

    private function errorCode(Throwable $exception): string
    {
        $code = $exception->getMessage();
        if (preg_match('/^(?:UPGRADE|SIMPLE)_[A-Z0-9_]{1,119}$/D', $code) !== 1) {
            return 'UPGRADE_RUNTIME_FAILED';
        }

        return $code;
    }

    private function exitCode(string $code): int
    {
        if ($code === 'UPGRADE_JSON_INVALID'
            || str_ends_with($code, '_INPUT_INVALID')
            || str_ends_with($code, '_ARGUMENT_INVALID')
            || str_ends_with($code, '_JOB_INVALID')) {
            return 2;
        }
        if (in_array($code, [
            'SIMPLE_UPGRADE_GATE_NOT_PAUSED',
            'SIMPLE_UPGRADE_STATE_CONFLICT',
            'SIMPLE_MIGRATION_CHECKPOINT_CONFLICT',
            'SIMPLE_MIGRATION_PREVIOUSLY_FAILED',
        ], true)) {
            return 3;
        }

        return 1;
    }

    /**
     * @param array<string,mixed>|null $data
     * @param array{code:string}|null $error
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function result(
        int $exitCode,
        bool $ok,
        ?string $operation,
        ?string $jobId,
        ?array $data,
        ?array $error,
    ): array {
        $stdout = json_encode([
            'schema_version' => 1,
            'ok' => $ok,
            'operation' => $operation,
            'job_id' => $jobId,
            'data' => $data,
            'error' => $error,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => ''];
    }
}
