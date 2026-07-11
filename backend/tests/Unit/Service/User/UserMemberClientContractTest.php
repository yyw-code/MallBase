<?php

declare(strict_types=1);

namespace Tests\Unit\Service\User;

use PHPUnit\Framework\TestCase;

final class UserMemberClientContractTest extends TestCase
{
    public function testClientUserInfoIncludesMemberSummary(): void
    {
        $userService = (string) file_get_contents(dirname(__DIR__, 4) . '/app/service/client/UserService.php');

        $this->assertStringContainsString('UserMemberService::class', $userService);
        $this->assertStringContainsString("['member']", $userService);
        $this->assertStringContainsString('->clientSummary($userId)', $userService);
    }

    public function testMemberClientSummaryKeepsFrontendContractFields(): void
    {
        $memberService = (string) file_get_contents(dirname(__DIR__, 4) . '/app/service/user/UserMemberService.php');

        foreach ([
            'public function clientSummary',
            "'enabled' =>",
            "'level_enabled' =>",
            "'price_enabled' =>",
            "'growth_enabled' =>",
            "'account' =>",
            "'level' =>",
            "'next_level' =>",
            "'growth_value' =>",
            "'total_growth_value' =>",
            "'growth_to_next' =>",
            "'progress_percent' =>",
            "'discount_percent' =>",
            "'discount_text' =>",
            "'level_locked' =>",
        ] as $needle) {
            $this->assertStringContainsString($needle, $memberService);
        }
    }
}
