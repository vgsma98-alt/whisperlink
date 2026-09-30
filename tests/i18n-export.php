<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['whisperlink_test_locale'] = $argv[1] ?? 'en_US';
function get_option($name, $default = false) { return $name === 'WPLANG' ? $GLOBALS['whisperlink_test_locale'] : $default; }
function get_locale() { return $GLOBALS['whisperlink_test_locale']; }
require dirname(__DIR__) . '/whisperlink/includes/class-i18n.php';
echo json_encode(['lang' => WhisperLink_I18n::lang(), 'dir' => WhisperLink_I18n::dir(), 'i18n' => WhisperLink_I18n::js()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
