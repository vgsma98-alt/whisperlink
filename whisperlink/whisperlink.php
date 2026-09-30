<?php
/**
 * Plugin Name: WhisperLink
 * Description: Internal-link suggestions, reports, auto-linking rules, link checks, and guarded undo.
 * Version: 0.2.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: WhisperLink Contributors
 * License: GPL-2.0-or-later
 * Text Domain: whisperlink
 */
defined('ABSPATH') || exit;
define('WHISPERLINK_FILE', __FILE__);
require_once __DIR__ . '/includes/class-i18n.php';
require_once __DIR__ . '/includes/class-content.php';
require_once __DIR__ . '/includes/class-plugin.php';
register_activation_hook(__FILE__, ['WhisperLink_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WhisperLink_Plugin', 'deactivate']);
WhisperLink_Plugin::boot();
