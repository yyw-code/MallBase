<?php

declare(strict_types=1);

namespace app\service\upgrade;

use JsonException;
use RuntimeException;
use Throwable;

/** Strict, bounded JSON object decoder for upgrade control endpoints. */
final readonly class UpgradeStrictJsonDecoder
{
    public function __construct(private int $maximumBytes = 8192)
    {
        if ($this->maximumBytes < 2 || $this->maximumBytes > 262_144) {
            throw new RuntimeException('UPGRADE_JSON_INVALID');
        }
    }

    /** @param list<string> $expectedFields @return array<string, mixed> */
    public function decode(
        string $raw,
        string $contentType,
        ?int $contentLength,
        array $expectedFields,
    ): array {
        $length = strlen($raw);
        if (!$this->validContentType($contentType) || $length < 2 || $length > $this->maximumBytes
            || ($contentLength !== null && $contentLength !== $length)
            || !mb_check_encoding($raw, 'UTF-8')
            || count($expectedFields) !== count(array_unique($expectedFields))) {
            $this->fail();
        }

        $offset = 0;
        try {
            $this->scanValue($raw, $offset, 0);
            $this->skipWhitespace($raw, $offset);
            if ($offset !== $length) {
                $this->fail();
            }
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->fail();
        }
        $emptyObject = $expectedFields === [] && $decoded === [] && str_starts_with(ltrim($raw), '{');
        if (!is_array($decoded) || array_is_list($decoded) && !$emptyObject
            || count($decoded) !== count($expectedFields)
            || array_diff(array_keys($decoded), $expectedFields) !== []
            || array_diff($expectedFields, array_keys($decoded)) !== []) {
            $this->fail();
        }

        return $decoded;
    }

    private function validContentType(string $value): bool
    {
        $parts = array_map('trim', explode(';', strtolower(trim($value))));
        if (array_shift($parts) !== 'application/json') {
            return false;
        }
        if ($parts === []) {
            return true;
        }

        return count($parts) === 1 && $parts[0] === 'charset=utf-8';
    }

    private function scanValue(string $json, int &$offset, int $depth): void
    {
        if ($depth > 32) {
            $this->fail();
        }
        $this->skipWhitespace($json, $offset);
        $character = $json[$offset] ?? '';
        if ($character === '{') {
            $this->scanObject($json, $offset, $depth + 1);

            return;
        }
        if ($character === '[') {
            $this->scanArray($json, $offset, $depth + 1);

            return;
        }
        if ($character === '"') {
            $this->scanString($json, $offset);

            return;
        }
        foreach (['true', 'false', 'null'] as $literal) {
            if (substr($json, $offset, strlen($literal)) === $literal) {
                $offset += strlen($literal);

                return;
            }
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/A', $json, $match, 0, $offset) !== 1) {
            $this->fail();
        }
        $offset += strlen($match[0]);
    }

    private function scanObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $seen = [];
        $this->skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === '}') {
            $offset++;

            return;
        }
        while (true) {
            $this->skipWhitespace($json, $offset);
            $key = $this->scanString($json, $offset);
            if (array_key_exists($key, $seen)) {
                $this->fail();
            }
            $seen[$key] = true;
            $this->skipWhitespace($json, $offset);
            if (($json[$offset] ?? '') !== ':') {
                $this->fail();
            }
            $offset++;
            $this->scanValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === '}') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                $this->fail();
            }
            $offset++;
        }
    }

    private function scanArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipWhitespace($json, $offset);
        if (($json[$offset] ?? '') === ']') {
            $offset++;

            return;
        }
        while (true) {
            $this->scanValue($json, $offset, $depth);
            $this->skipWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? '';
            if ($delimiter === ']') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                $this->fail();
            }
            $offset++;
        }
    }

    private function scanString(string $json, int &$offset): string
    {
        if (($json[$offset] ?? '') !== '"') {
            $this->fail();
        }
        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($character === "\n" || $character === "\r" || ord($character) < 0x20) {
                $this->fail();
            }
            if ($character === '\\') {
                if ($offset >= $length) {
                    $this->fail();
                }
                $escaped = $json[$offset++];
                if (!str_contains('"\\/bfnrtu', $escaped)) {
                    $this->fail();
                }
                if ($escaped === 'u') {
                    $hex = substr($json, $offset, 4);
                    if (strlen($hex) !== 4 || preg_match('/^[0-9a-fA-F]{4}$/D', $hex) !== 1) {
                        $this->fail();
                    }
                    $offset += 4;
                }
                continue;
            }
            if ($character === '"') {
                $encoded = substr($json, $start, $offset - $start);
                $decoded = json_decode($encoded, false, 8, JSON_THROW_ON_ERROR);
                if (!is_string($decoded)) {
                    $this->fail();
                }

                return $decoded;
            }
        }
        $this->fail();
    }

    private function skipWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }

    private function fail(): never
    {
        throw new RuntimeException('UPGRADE_JSON_INVALID');
    }
}
