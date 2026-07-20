<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentBinaryTrustValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentBinaryTrustValidatorTest extends TestCase
{
    private string $root;
    private string $active;
    private string $binary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-agent-trust-' . bin2hex(random_bytes(8));
        $this->active = $this->root . '/bin/active';
        $this->binary = $this->active . '/mallbase-agent';
        mkdir($this->active, 0750, true);
        file_put_contents($this->binary, "trusted-agent\n");
        chmod($this->binary, 0755);
        chmod($this->active, 0750);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->active)) {
            chmod($this->active, 0750);
        }
        if (is_link($this->binary) || is_file($this->binary)) {
            chmod($this->binary, 0644);
            unlink($this->binary);
        }
        @rmdir($this->active);
        @rmdir($this->root . '/bin');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testFixedActiveBinaryIsTrusted(): void
    {
        $this->validator(
            static fn(string $path): bool => str_ends_with($path, '/bin/active'),
            static fn(): bool => true,
            static fn(): bool => false,
        )->validate($this->binary);

        self::assertTrue(true);
    }

    public function testPhpOwnedTrustRootIsRejectedEvenWhenModeIsReadOnly(): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        new AgentBinaryTrustValidator($uid, $uid, static fn(): bool => true);
    }

    public function testOnlyExactActiveFileShapeIsAccepted(): void
    {
        $cases = [
            'non-contract binary mode' => function (): string {
                chmod($this->binary, 0775);

                return $this->binary;
            },
            'writable active directory' => function (): string {
                chmod($this->active, 0770);

                return $this->binary;
            },
            'wrong file name' => function (): string {
                $other = $this->active . '/other-agent';
                file_put_contents($other, 'agent');
                chmod($other, 0755);

                return $other;
            },
            'wrong parent name' => function (): string {
                $otherDirectory = $this->root . '/other';
                mkdir($otherDirectory, 0750);
                $other = $otherDirectory . '/mallbase-agent';
                file_put_contents($other, 'agent');
                chmod($other, 0755);

                return $other;
            },
        ];

        foreach ($cases as $name => $mutate) {
            $this->resetFixture();
            $path = $mutate();
            try {
                $this->validator(static fn(): bool => true)->validate($path);
                self::fail($name . ' was trusted');
            } catch (RuntimeException $exception) {
                self::assertSame('AGENT_BINARY_UNTRUSTED', $exception->getMessage(), $name);
            } finally {
                if ($path !== $this->binary && is_file($path)) {
                    chmod($path, 0644);
                    unlink($path);
                    @rmdir(dirname($path));
                }
            }
        }
    }

    public function testSymlinkBinaryIsRejected(): void
    {
        $target = $this->root . '/real-agent';
        file_put_contents($target, 'agent');
        chmod($target, 0755);
        chmod($this->binary, 0644);
        unlink($this->binary);
        symlink($target, $this->binary);

        try {
            $this->assertUntrusted($this->binary);
        } finally {
            unlink($this->binary);
            chmod($target, 0644);
            unlink($target);
            file_put_contents($this->binary, "trusted-agent\n");
            chmod($this->binary, 0755);
        }
    }

    public function testEveryAncestorMustBeNonWritableByPhp(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        $this->validator(
            static fn(): bool => false,
            static fn(): bool => false,
            static fn(): bool => true,
        )->validate($this->binary);
    }

    public function testReadOnlyMountCannotBypassMutableAncestors(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        $this->validator(
            static fn(): bool => true,
            static fn(): bool => false,
            static fn(): bool => true,
        )->validate($this->binary);
    }

    public function testDirectDeploymentMustProveFileCapabilitiesAreAbsent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        $this->validator(
            static fn(): bool => false,
            static fn(): bool => true,
            static fn(): bool => false,
        )->validate($this->binary);
    }

    private function validator(
        \Closure $mountProof,
        ?\Closure $ancestorProof = null,
        ?\Closure $capabilityAbsentProof = null,
    ): AgentBinaryTrustValidator {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        return new AgentBinaryTrustValidator(
            $uid,
            $uid + 1,
            $mountProof,
            $ancestorProof ?? static fn(): bool => true,
            $capabilityAbsentProof ?? static fn(): bool => true,
        );
    }

    private function assertUntrusted(string $path): void
    {
        try {
            $this->validator(static fn(): bool => true)->validate($path);
            self::fail('unsafe path was trusted: ' . $path);
        } catch (RuntimeException $exception) {
            self::assertSame('AGENT_BINARY_UNTRUSTED', $exception->getMessage());
        }
    }

    private function resetFixture(): void
    {
        chmod($this->active, 0750);
        if (is_link($this->binary)) {
            unlink($this->binary);
        }
        if (!is_file($this->binary)) {
            file_put_contents($this->binary, "trusted-agent\n");
        }
        chmod($this->binary, 0755);
    }
}
