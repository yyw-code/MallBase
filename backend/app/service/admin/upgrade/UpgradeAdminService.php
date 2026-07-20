<?php

declare(strict_types=1);

namespace app\service\admin\upgrade;

use app\model\upgrade\UpgradeRecord;
use app\service\install\AgentHeartbeatPayloadFactory;
use Closure;
use mall_base\base\BaseService;
use RuntimeException;

/**
 * @extends BaseService<UpgradeRecord>
 */
final class UpgradeAdminService extends BaseService
{
    private const JOB_TICKET_TTL = 600;

    protected string $modelClass = UpgradeRecord::class;

    public function __construct(
        private readonly ?string $configuredRoot = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $ticketFactory = null,
        private readonly ?Closure $jobIdFactory = null,
        private readonly ?Closure $currentReleaseReader = null,
        private readonly ?PlatformReleaseCatalogService $releaseCatalog = null,
    ) {
    }

    /**
     * 读取当前安装版本，供后台展示和查询 Platform 候选版本时使用。
     *
     * @return array{current:array{version:string,released_at:string,notes:list<string>}}
     */
    public function getOverview(): array
    {
        $reader = $this->currentReleaseReader ?? static fn(): array =>
            app()->make(AgentHeartbeatPayloadFactory::class)->currentRelease();
        $release = $reader();
        if (!is_array($release)) {
            throw new RuntimeException('UPGRADE_OVERVIEW_UNAVAILABLE');
        }
        $version = $release['version'] ?? null;
        $releasedAt = $release['released_at'] ?? '';
        $notes = $release['notes'] ?? [];
        if (!is_string($version) || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $version) !== 1
            || !is_string($releasedAt) || strlen($releasedAt) > 128
            || !is_array($notes) || count($notes) > 100) {
            throw new RuntimeException('UPGRADE_OVERVIEW_UNAVAILABLE');
        }
        foreach ($notes as $note) {
            if (!is_string($note) || strlen($note) > 1024 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $note) === 1) {
                throw new RuntimeException('UPGRADE_OVERVIEW_UNAVAILABLE');
            }
        }

        return ['current' => [
            'version' => $version,
            'released_at' => $releasedAt,
            'notes' => array_values($notes),
        ]];
    }

    /**
     * 使用当前版本通过固定 Agent catalog 子命令查询可升级版本。
     * catalog 只负责后台版本发现，不代替任务执行前的认证 resolve。
     *
     * @return array<string, mixed>
     */
    public function getReleaseCatalog(): array
    {
        $overview = $this->getOverview();
        $catalog = $this->releaseCatalog ?? app()->make(PlatformReleaseCatalogService::class);

        return $catalog->getCatalog($overview['current']['version']);
    }

    /**
     * 从 jobs/<job-id>/record.json 分页读取长期任务记录。
     *
     * @return array{total:int,list:list<array<string, int|string>>}
     */
    public function getList(int $page, int $limit): array
    {
        if ($page < 1 || $limit < 1 || $limit > 100) {
            throw new RuntimeException('UPGRADE_RECORD_ARGUMENT_INVALID');
        }
        $records = $this->model()->scan($this->root());
        $total = count($records);
        $list = array_slice($records, ($page - 1) * $limit, $limit);

        return compact('total', 'list');
    }

    /**
     * 创建一次性宿主机任务。请求文件只保存 ticket hash，Platform 凭据由
     * Agent 从 instance.json 读取，避免同一密钥在任务目录中重复落盘。
     *
     * @return array{job_id:string,status:string,status_url:string,expires_at:int}
     */
    public function createJob(int $adminId, mixed $action, mixed $targetVersion = ''): array
    {
        if ($adminId < 1 || !is_string($action) || !in_array($action, ['upgrade', 'rollback'], true)
            || !is_string($targetVersion)
            || ($action === 'upgrade'
                && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $targetVersion) !== 1)
            || ($action === 'rollback' && $targetVersion !== '')) {
            throw new RuntimeException('UPGRADE_ENTRY_ARGUMENT_INVALID');
        }
        $jobId = $this->newJobId();
        $ticket = $this->newTicket();
        $createdAt = $this->now();
        $expiresAt = $createdAt + self::JOB_TICKET_TTL;
        $request = [
            'schema_version' => 1,
            'job_id' => $jobId,
            'action' => $action,
            'target_version' => $targetVersion,
            'requested_by' => $adminId,
            'ticket_hash' => hash('sha256', $ticket),
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ];
        $record = [
            'schema_version' => 1,
            'job_id' => $jobId,
            'action' => $action,
            'source_version' => '',
            'target_version' => $targetVersion,
            'status' => 'queued',
            'backup_path' => '',
            'package_path' => '',
            'created_at' => $createdAt,
            'started_at' => 0,
            'finished_at' => 0,
            'error' => '',
        ];
        $this->model()->createQueuedJob($this->root(), $request, $record);

        return [
            'job_id' => $jobId,
            'status' => 'queued',
            'status_url' => '/upgrade/?ticket=' . rawurlencode($ticket),
            'expires_at' => $expiresAt,
        ];
    }

    private function root(): string
    {
        return $this->configuredRoot ?? (string) config('agent.upgrade_root', '');
    }

    private function now(): int
    {
        return $this->clock === null ? time() : (int) ($this->clock)();
    }

    private function newTicket(): string
    {
        $ticket = $this->ticketFactory === null
            ? rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')
            : (string) ($this->ticketFactory)();
        if (preg_match('/^[0-9A-Za-z_-]{43}$/D', $ticket) !== 1) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }

        return $ticket;
    }

    private function newJobId(): string
    {
        if ($this->jobIdFactory !== null) {
            $jobId = (string) ($this->jobIdFactory)();
        } else {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            $jobId = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
                . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) !== 1) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }

        return $jobId;
    }
}
