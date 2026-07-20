<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use app\service\upgrade\UpgradeStrictJsonDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpgradeStrictJsonDecoderTest extends TestCase
{
    public function testDecodesOnlyTheRawExactObjectAndPreservesIntegerTypes(): void
    {
        $raw = '{"request_id":"11111111-1111-4111-8111-111111111111","expected_revision":2}';
        $decoded = (new UpgradeStrictJsonDecoder())->decode(
            $raw,
            'application/json; charset=utf-8',
            strlen($raw),
            ['request_id', 'expected_revision'],
        );

        $this->assertSame(2, $decoded['expected_revision']);
        $this->assertIsInt($decoded['expected_revision']);
    }

    public function testExactEightKibibyteBodyIsAcceptedAndTheNextByteIsRejected(): void
    {
        $prefix = '{"payload":"';
        $suffix = '"}';
        $raw = $prefix . str_repeat('a', 8192 - strlen($prefix) - strlen($suffix)) . $suffix;

        $this->assertSame(8192, strlen($raw));
        $this->assertSame(8178, strlen((new UpgradeStrictJsonDecoder())->decode(
            $raw,
            'application/json',
            null,
            ['payload'],
        )['payload']));
        $this->assertInvalid($raw . ' ');
    }

    #[DataProvider('invalidJsonProvider')]
    public function testRejectsAmbiguousOrMalformedBodies(string $raw, array $fields = ['a']): void
    {
        $this->assertInvalid($raw, $fields);
    }

    /** @return iterable<string,array{string,list<string>}> */
    public static function invalidJsonProvider(): iterable
    {
        yield 'duplicate' => ['{"a":1,"a":2}', ['a']];
        yield 'escaped duplicate' => ['{"a":1,"\\u0061":2}', ['a']];
        yield 'nested duplicate' => ['{"a":{"b":1,"b":2}}', ['a']];
        yield 'multiple values' => ['{"a":1} {}', ['a']];
        yield 'top level list' => ['[]', []];
        yield 'unknown field' => ['{"a":1,"query_override":2}', ['a']];
        yield 'missing field' => ['{}', ['a']];
        yield 'invalid UTF-8' => ["{\"a\":\"\xFF\"}", ['a']];
    }

    public function testRejectsContentTypeAndLengthMismatchesButAllowsMissingLength(): void
    {
        $decoder = new UpgradeStrictJsonDecoder();
        $raw = '{"a":1}';
        $this->assertSame(['a' => 1], $decoder->decode($raw, 'application/json', null, ['a']));

        foreach ([['text/plain', strlen($raw)], ['application/json', strlen($raw) + 1]] as [$type, $length]) {
            try {
                $decoder->decode($raw, $type, $length, ['a']);
                self::fail('expected invalid body metadata');
            } catch (\RuntimeException $exception) {
                self::assertSame('UPGRADE_JSON_INVALID', $exception->getMessage());
            }
        }
    }

    private function assertInvalid(string $raw, array $fields = ['payload']): void
    {
        try {
            (new UpgradeStrictJsonDecoder())->decode($raw, 'application/json', strlen($raw), $fields);
            self::fail('expected strict JSON rejection');
        } catch (\RuntimeException $exception) {
            self::assertSame('UPGRADE_JSON_INVALID', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
}
