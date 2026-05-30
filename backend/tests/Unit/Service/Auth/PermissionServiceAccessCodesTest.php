<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use app\model\auth\Admin;
use app\service\admin\auth\PermissionService;
use PHPUnit\Framework\TestCase;

final class PermissionServiceAccessCodesTest extends TestCase
{
    public function testSuperAdminAccessCodesIncludeRouteFallbackCodes(): void
    {
        $dbQuery = new class {
            public function whereIn(string $field, array $values): self
            {
                return $this;
            }

            public function where(string $field, mixed $value): self
            {
                return $this;
            }

            /**
             * @return array<int, string>
             */
            public function column(string $field): array
            {
                return ['SystemOrderClose'];
            }
        };

        $service = new class ($dbQuery) extends PermissionService {
            public function __construct(private object $dbQuery)
            {
            }

            protected function model(?string $modelClass = null): object
            {
                return $this->dbQuery;
            }

            protected function routeAccessCodes(): array
            {
                return ['SystemOrderAdjustPrice', 'SystemOrderClose'];
            }
        };

        $result = $service->getAccessCodes(Admin::SUPER_ADMIN_ID);

        $this->assertSame(
            ['SystemOrderClose', 'SystemOrderAdjustPrice'],
            $result['access_codes']
        );
    }
}
