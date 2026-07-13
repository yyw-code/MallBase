<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use app\service\install\AgentBinaryTrustValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentBinaryTrustValidatorTest extends TestCase
{
    private string $root;
    private string $binary;
    private string $checksums;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-agent-trust-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0755);
        mkdir($this->root . '/bin', 0755);
        $this->binary = $this->root . '/bin/mallbase-agent-linux-amd64';
        $this->checksums = $this->root . '/bin/checksums.sha256';
        file_put_contents($this->binary, "trusted-agent\n");
        chmod($this->binary, 0555);
        file_put_contents($this->checksums, hash_file('sha256', $this->binary) . "  mallbase-agent-linux-amd64\n");
        chmod($this->checksums, 0444);
        chmod($this->root . '/bin', 0555);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root . '/bin')) {
            chmod($this->root . '/bin', 0755);
        }
        foreach ([$this->binary, $this->checksums] as $file) {
            if (is_link($file) || is_file($file)) {
                chmod($file, 0644);
                unlink($file);
            }
        }
        if (is_dir($this->root . '/bin')) {
            chmod($this->root . '/bin', 0755);
            rmdir($this->root . '/bin');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function testExactReadOnlyMountAndChecksumAreTrusted(): void
    {
        $validator = $this->validator(
            static fn(string $path): bool => str_ends_with($path, '/bin'),
            static fn(): bool => true,
            static fn(): bool => false,
        );

        $validator->validate($this->binary);

        self::assertTrue(true);
    }

    public function testPhpOwnedTrustRootIsRejectedEvenWhenModeIsReadOnly(): void
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        new AgentBinaryTrustValidator($uid, $uid, static fn(): bool => true);
    }

    public function testUnsafeBinaryShapesAndChecksumsFailClosed(): void
    {
        $mutations = [
            'wrong checksum' => function (): void {
                chmod($this->checksums, 0644);
                file_put_contents($this->checksums, str_repeat('0', 64) . "  mallbase-agent-linux-amd64\n");
                chmod($this->checksums, 0444);
            },
            'duplicate checksum' => function (): void {
                $line = (string) file_get_contents($this->checksums);
                chmod($this->checksums, 0644);
                file_put_contents($this->checksums, $line . $line);
                chmod($this->checksums, 0444);
            },
            'writable binary' => fn() => chmod($this->binary, 0755),
            'writable checksum' => fn() => chmod($this->checksums, 0644),
            'writable bin directory' => fn() => chmod($this->root . '/bin', 0755),
        ];

        foreach ($mutations as $name => $mutate) {
            $this->resetFixture();
            $mutate();
            try {
                $this->validator(static fn(): bool => true)->validate($this->binary);
                self::fail($name . ' was trusted');
            } catch (RuntimeException $exception) {
                self::assertSame('AGENT_BINARY_UNTRUSTED', $exception->getMessage(), $name);
            }
        }
    }

    public function testSymlinkBinaryAndUnexpectedNameAreRejected(): void
    {
        $target = $this->root . '/real-agent';
        file_put_contents($target, 'agent');
        chmod($target, 0555);
        chmod($this->root . '/bin', 0755);
        chmod($this->binary, 0644);
        unlink($this->binary);
        symlink($target, $this->binary);

        $this->assertUntrusted($this->binary);
        $this->assertUntrusted($target);

        unlink($this->binary);
        chmod($target, 0644);
        unlink($target);
        file_put_contents($this->binary, "trusted-agent\n");
        chmod($this->binary, 0555);
    }

    public function testDirectDeploymentRequiresEveryParentToBeNonWritableByPhp(): void
    {
        $validator = $this->validator(
            static fn(): bool => false,
            static fn(): bool => false,
            static fn(): bool => true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        $validator->validate($this->binary);
    }

    public function testReadOnlyMountCannotBypassMutableContainerAncestors(): void
    {
        $validator = $this->validator(
            static fn(): bool => true,
            static fn(): bool => false,
            static fn(): bool => true,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        $validator->validate($this->binary);
    }

    public function testDirectDeploymentMustProveFileCapabilitiesAreAbsent(): void
    {
        $validator = $this->validator(
            static fn(): bool => false,
            static fn(): bool => true,
            static fn(): bool => false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AGENT_BINARY_UNTRUSTED');
        $validator->validate($this->binary);
    }

    private function validator(
        \Closure $mountProof,
        ?\Closure $ancestorProof = null,
        ?\Closure $capabilityAbsentProof = null,
    ): AgentBinaryTrustValidator
    {
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
        chmod($this->root . '/bin', 0755);
        if (is_link($this->binary)) {
            unlink($this->binary);
        }
        if (!is_file($this->binary)) {
            file_put_contents($this->binary, "trusted-agent\n");
        }
        chmod($this->binary, 0555);
        chmod($this->checksums, 0644);
        file_put_contents($this->checksums, hash_file('sha256', $this->binary) . "  mallbase-agent-linux-amd64\n");
        chmod($this->checksums, 0444);
        chmod($this->root . '/bin', 0555);
    }
}
