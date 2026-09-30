<?php
// Real WordPress HTML parser/formatting, no database or mocked HTML parser.
define('ABSPATH', dirname(__DIR__) . '/.test-runtime/wordpress/');
define('WPINC', 'wp-includes');
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/compat.php';
if (is_file(ABSPATH . WPINC . '/utf8.php')) require ABSPATH . WPINC . '/utf8.php';
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/http.php';
require ABSPATH . WPINC . '/class-wp-http.php';
spl_autoload_register(static function($class) {
    $file = ABSPATH . WPINC . '/html-api/class-' . strtolower(str_replace('_', '-', $class)) . '.php';
    if (is_file($file)) require_once $file;
});
require dirname(__DIR__) . '/whisperlink/includes/class-content.php';
$passed = 0;
function same($expected, $actual, string $label): void {
    global $passed;
    if ($expected !== $actual) { fwrite(STDERR, "FAIL: $label\nExpected: " . var_export($expected,true) . "\nActual: " . var_export($actual,true) . "\n"); exit(1); }
    ++$passed;
    echo "PASS: $label\n";
}
$url = 'https://example.com/target/';
same(true, WhisperLink_Content::internal('http://example.com/page/', 'https://www.example.com/'), 'Recognize non-www HTTP internal links on www HTTPS site');
same(true, WhisperLink_Content::internal('https://www.example.com/page/', 'https://example.com/'), 'Recognize www alias in either direction');
same(false, WhisperLink_Content::internal('https://example.com.evil.test/', 'https://example.com/'), 'Reject suffix lookalike domain');
same(false, WhisperLink_Content::internal('https://other.example.com/', 'https://example.com/'), 'Do not classify arbitrary subdomains as internal');
$a = '<a href="' . $url . '">الربط الداخلي</a>';
same("<p>تعلم $a اليوم.</p>", WhisperLink_Content::insert('<p>تعلم الربط الداخلي اليوم.</p>', 'الربط الداخلي', $url), 'Arabic phrase insertion');
same('<p>كتاباتي كتابات</p>', WhisperLink_Content::insert('<p>كتاباتي كتابات</p>', 'كتاب', $url), 'Unicode word boundaries');
same('<a href="/old">الربط الداخلي</a><p>' . $a . '</p>', WhisperLink_Content::insert('<a href="/old">الربط الداخلي</a><p>الربط الداخلي</p>', 'الربط الداخلي', $url), 'No nested anchors');
foreach (['script','style','code','pre','textarea','button','select','svg','math','h1','h2','h3','iframe','noscript','template'] as $tag) {
    $html = "<$tag>الربط الداخلي</$tag><p>الربط الداخلي</p>";
    same("<$tag>الربط الداخلي</$tag><p>$a</p>", WhisperLink_Content::insert($html, 'الربط الداخلي', $url), "Protect $tag");
}
$block = '<!-- wp:paragraph {"className":"الربط الداخلي"} --><p class="الربط الداخلي">الربط الداخلي</p><!-- /wp:paragraph -->';
same('<!-- wp:paragraph {"className":"الربط الداخلي"} --><p class="الربط الداخلي">' . $a . '</p><!-- /wp:paragraph -->', WhisperLink_Content::insert($block, 'الربط الداخلي', $url), 'Preserve Gutenberg comments and attributes byte-for-byte');
same('<p>' . $a . ' الربط الداخلي</p>', WhisperLink_Content::insert('<p>الربط الداخلي الربط الداخلي</p>', 'الربط الداخلي', $url), 'One occurrence limit');
same('<p>' . $a . ' ' . $a . '</p>', WhisperLink_Content::insert('<p>الربط الداخلي الربط الداخلي</p>', 'الربط الداخلي', $url, 2), 'Explicit occurrence limit');
same('<p>الربط الداخلي</p>', WhisperLink_Content::insert('<p>الربط الداخلي</p>', 'الربط الداخلي', 'javascript:alert(1)'), 'Reject script URLs');
$shortcode = '[box]<p>الربط الداخلي</p>[/box]';
same($shortcode, WhisperLink_Content::insert($shortcode, 'الربط الداخلي', $url), 'Skip multi-token shortcode documents');
same('<p>&amp; amp</p>', WhisperLink_Content::insert('<p>&amp; amp</p>', 'am', $url), 'Do not split HTML entities or words');
same('https://example.com/path/item?x=1&y=2', WhisperLink_Content::url('item?x=1&amp;y=2#heading', 'https://example.com/path/post'), 'Relative URLs entities query and fragments');
same('', WhisperLink_Content::url('#section', $url), 'Skip fragment-only links');
same('', WhisperLink_Content::url('mailto:a@example.com', $url), 'Skip mailto');
same('', WhisperLink_Content::url('data:text/html,x', $url), 'Skip data URLs');
$links = WhisperLink_Content::links('<p><a href="/one" rel="nofollow">One</a><a href="https://outside.example/a">Two</a><a href="#x">fragment</a></p>', $url);
same(2,count($links),'Extract internal and external HTTP links');
same('nofollow',$links[0]['rel'],'Read rel attribute');
same('https://example.com/one',$links[0]['url'],'Normalize root relative href');
$html = '<!-- wp:paragraph --><p data-url="/old"><a class="x" href="/old#part">Text</a> /old <a href="/older">Other</a></p><!-- /wp:paragraph -->';
$expected = '<!-- wp:paragraph --><p data-url="/old"><a class="x" href="https://example.com/new#part">Text</a> /old <a href="/older">Other</a></p><!-- /wp:paragraph -->';
same($expected, WhisperLink_Content::replace_url($html, $url, 'https://example.com/old', 'https://example.com/new'), 'Replace href only, retain fragment and block markup');
same('<a href="https://example.com/new#next">Text</a>', WhisperLink_Content::replace_url('<a href="/old#part">Text</a>', $url, 'https://example.com/old', 'https://example.com/new#next'), 'New explicit fragment takes precedence');
same(['الربط','الداخلي'],WhisperLink_Content::terms('في الرَّبط الداخلي الداخلي'),'Arabic diacritics stop words and uniqueness');
echo "\n$passed tests passed.\n";
