<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['whisperlink_test_locale'] = 'en_US';
function get_option($name, $default = false) { return $name === 'WPLANG' ? $GLOBALS['whisperlink_test_locale'] : $default; }
function get_locale() { return $GLOBALS['whisperlink_test_locale']; }
require dirname(__DIR__) . '/whisperlink/includes/class-i18n.php';
$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } ++$checks; echo "PASS: $label\n"; }
$GLOBALS['whisperlink_test_locale'] = 'fr_FR';
check(WhisperLink_I18n::lang() === 'fr', 'French locale selection');
check(WhisperLink_I18n::dir() === 'ltr', 'French direction');
check(WhisperLink_I18n::t('فحص المحتوى') === 'Analyser le contenu', 'French PHP translation');
check(WhisperLink_I18n::js()['نظرة عامة'] === 'Vue d’ensemble', 'French JavaScript translation');
$GLOBALS['whisperlink_test_locale'] = 'en_GB';
check(WhisperLink_I18n::lang() === 'en', 'English locale selection');
check(WhisperLink_I18n::t('فحص المحتوى') === 'Scan content', 'English PHP translation');
check(WhisperLink_I18n::js()['نظرة عامة'] === 'Overview', 'English JavaScript translation');
$GLOBALS['whisperlink_test_locale'] = 'ar';
check(WhisperLink_I18n::dir() === 'rtl', 'Arabic direction');
check(WhisperLink_I18n::t('فحص المحتوى') === 'فحص المحتوى', 'Arabic source fallback');
$GLOBALS['whisperlink_test_locale'] = 'de_DE';
check(WhisperLink_I18n::lang() === 'en', 'Unsupported locale falls back to English');
check(count(WhisperLink_I18n::js()) >= 100, 'Complete JavaScript dictionary');
echo "\n$checks translation checks passed.\n";
