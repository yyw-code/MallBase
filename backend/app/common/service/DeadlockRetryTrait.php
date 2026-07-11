<?php
declare(strict_types=1);

namespace app\common\service;

use Throwable;

trait DeadlockRetryTrait
{
    protected function withDeadlockRetry(callable $callback, int $maxAttempts = 3): mixed
    {
        $attempt = 0;
        do {
            try {
                return $callback();
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= $maxAttempts || !$this->isRetryableDeadlock($e)) {
                    throw $e;
                }
                usleep(50_000 * $attempt);
            }
        } while (true);
    }

    protected function isRetryableDeadlock(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'SQLSTATE[40001]')
            || str_contains($message, '1213 Deadlock')
            || str_contains($message, 'Deadlock found')
            || str_contains($message, 'Lock wait timeout exceeded');
    }
}
