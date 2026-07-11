<?php
declare(strict_types=1);

namespace app\service\content;

/**
 * 富文本最小安全过滤。
 *
 * 目标是阻断可执行 HTML，不改变正常编辑器标签结构。
 */
class RichTextSanitizer
{
    public function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*?/?>#is', '', $html) ?? $html;
        $html = preg_replace('/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? $html;
        $html = preg_replace_callback(
            '/\s+(href|src)\s*=\s*("|\')([^"\']*)\2/is',
            static function (array $matches): string {
                $value = html_entity_decode(trim((string) ($matches[3] ?? '')), ENT_QUOTES | ENT_HTML5);
                if (preg_match('/^\s*javascript:/i', $value)) {
                    return '';
                }

                return $matches[0];
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/\s+style\s*=\s*("|\')([^"\']*)\1/is',
            static function (array $matches): string {
                $style = (string) ($matches[2] ?? '');
                if (preg_match('/expression\s*\(|javascript:/i', $style)) {
                    return '';
                }

                return $matches[0];
            },
            $html
        ) ?? $html;

        return trim($html);
    }
}
