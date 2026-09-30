<?php
defined('ABSPATH') || exit;

/** Byte-preserving edits: never serialize the entire document or Gutenberg blocks. */
final class WhisperLink_Content {
    public static function internal(string $url, string $home): bool {
        $host = static fn(string $value): string => preg_replace('/^www\./i', '', strtolower((string) wp_parse_url($value, PHP_URL_HOST)));
        return $host($home) !== '' && $host($url) === $host($home);
    }
    public static function url(string $url, string $base): string {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || $url[0] === '#' || preg_match('~^(?:mailto|tel|javascript|data):~i', $url)) return '';
        $url = WP_Http::make_absolute_url($url, $base);
        if (!in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) return '';
        return preg_replace('/#.*$/', '', $url);
    }

    public static function links(string $html, string $base): array {
        $out = [];
        $p = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag('A')) {
            $url = self::url((string) $p->get_attribute('href'), $base);
            if ($url !== '') $out[] = ['url' => $url, 'rel' => (string) $p->get_attribute('rel')];
        }
        return $out;
    }

    public static function replace_url(string $html, string $base, string $old, string $new): string {
        $p = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag('A')) {
            $raw = (string) $p->get_attribute('href');
            if (self::url($raw, $base) === $old) {
                $fragment = wp_parse_url(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_FRAGMENT);
                $p->set_attribute('href', $new . ($fragment !== null && !str_contains($new, '#') ? '#' . $fragment : ''));
            }
        }
        return $p->get_updated_html();
    }

    /** Only plain text outside links, headings, raw content and shortcode-bearing chunks. */
    public static function insert(string $html, string $phrase, string $url, int $limit = 1): string {
        if (trim($phrase) === '' || $limit < 1) return $html;
        // Shortcodes may span multiple HTML tokens. Leave such documents untouched.
        if (preg_match('/\[(?:\/?)[A-Za-z_][^\]]*\]/', $html)) return $html;
        if (!esc_url($url, ['http', 'https'])) return $html;
        $chunks = wp_html_split($html);
        $blocked = [];
        $remaining = $limit;
        foreach ($chunks as &$chunk) {
            if ($chunk === '') continue;
            if ($chunk[0] === '<') {
                if (preg_match('~^<\s*(/?)\s*([a-z0-9]+)\b~i', $chunk, $m)) {
                    $tag = strtolower($m[2]);
                    if (in_array($tag, ['a','script','style','code','pre','textarea','button','select','svg','math','h1','h2','h3','h4','h5','h6','iframe','noscript','template'], true)) {
                        if ($m[1] === '/') {
                            if (end($blocked) === $tag) array_pop($blocked);
                        } elseif (!str_ends_with(rtrim($chunk), '/>')) $blocked[] = $tag;
                    }
                }
                continue;
            }
            if ($blocked || $remaining < 1 || str_contains($chunk, '[') || str_contains($chunk, ']')) continue;
            $pattern = '~(?<![\p{L}\p{N}_&])(' . preg_quote($phrase, '~') . ')(?![\p{L}\p{N}_;])~iu';
            $chunk = preg_replace_callback($pattern, static function ($m) use ($url, &$remaining) {
                --$remaining;
                return '<a href="' . esc_url($url) . '">' . $m[0] . '</a>';
            }, $chunk, $remaining);
        }
        unset($chunk);
        return implode('', $chunks);
    }

    public static function terms(string $text): array {
        $text = mb_strtolower(wp_strip_all_tags($text), 'UTF-8');
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0640}]/u', '', $text);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['the','and','for','with','this','that','from','your','في','من','على','إلى','الى','عن','هذا','هذه','التي','الذي','وهو','كما','مع','كان'];
        return array_values(array_unique(array_filter($words, static fn($w) => mb_strlen($w) > 2 && !in_array($w, $stop, true))));
    }
}
