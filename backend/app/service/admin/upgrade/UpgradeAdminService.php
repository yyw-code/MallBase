<?php

declare(strict_types=1);

namespace app\service\admin\upgrade;

use app\model\upgrade\UpgradeRecord;
use app\service\install\AgentHeartbeatPayloadFactory;
use app\service\install\InstallLockService;
use Closure;
use mall_base\base\BaseService;
use RuntimeException;

/**
 * @extends BaseService<UpgradeRecord>
 */
final class UpgradeAdminService extends BaseService
{
    private const ENTRY_TICKET_TTL = 60;

    protected string $modelClass = UpgradeRecord::class;

    public function __construct(
        private readonly ?string $configuredRoot = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $ticketFactory = null,
        private readonly ?InstallLockService $installLock = null,
        private readonly ?Closure $currentReleaseReader = null,
        private readonly ?PlatformReleaseCatalogService $releaseCatalog = null,
    ) {
    }

    /** @return array{current:array{version:string,released_at:string,notes:list<string>}} */
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

    /** @return array<string, mixed> */
    public function getReleaseCatalog(): array
    {
        $overview = $this->getOverview();
        $catalog = $this->releaseCatalog ?? app()->make(PlatformReleaseCatalogService::class);

        return $catalog->getCatalog($overview['current']['version']);
    }

    /**
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
     * @return array{upgrade_url:string,expires_at:int}
     */
    public function createEntryTicket(int $adminId, mixed $targetVersion = ''): array
    {
        if ($adminId < 1 || !is_string($targetVersion)
            || ($targetVersion !== ''
                && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $targetVersion) !== 1)) {
            throw new RuntimeException('UPGRADE_ENTRY_ARGUMENT_INVALID');
        }
        $platformToken = $this->platformToken();
        $ticket = $this->newTicket();
        $issuedAt = $this->now();
        $expiresAt = $issuedAt + self::ENTRY_TICKET_TTL;
        $hash = hash('sha256', $ticket);
        $document = [
            'schema_version' => 1,
            'ticket_hash' => $hash,
            'admin_id' => $adminId,
            'platform_token' => $platformToken,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
        if ($targetVersion !== '') {
            $document['target_version'] = $targetVersion;
        }
        $this->model()->writeEntryTicket($this->root(), $document);

        return [
            'upgrade_url' => '/upgrade/?ticket=' . rawurlencode($ticket),
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

    private function platformToken(): string
    {
        $platform = ($this->installLock ?? app()->make(InstallLockService::class))->getPlatformState();
        $token = $platform['token'] ?? null;
        if (($platform['disabled'] ?? false) === true || !is_string($token)
            || strlen($token) < 1 || strlen($token) > 4096
            || preg_match('/^[\x21-\x7E]+$/D', $token) !== 1) {
            throw new RuntimeException('UPGRADE_ENTRY_UNAVAILABLE');
        }

        return $token;
    }
}
