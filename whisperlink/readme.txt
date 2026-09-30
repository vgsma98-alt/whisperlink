=== WhisperLink ===
Contributors: whisperlink
Tags: internal links, seo, arabic, link reports
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent internal-linking tools with English, French, and Arabic interfaces, local keyword suggestions, reports, rules, HTTP checks, and guarded undo. The interface automatically follows the WordPress site language; unsupported languages fall back to English.

== Description ==
WhisperLink indexes published post content, suggests matching keyword links, reports orphan pages and domains, and supports reversible link insertion and URL replacement. Requires PHP mbstring.

This is an initial release, not a clone of or an equivalent-performance claim for Link Whisper. No AI service, click analytics, Search Console connection or page-builder storage integration is included. Suggestions inspect up to 500 indexed pages. CSV exports cover the current page. Test on staging before production.

External URL checking is opt-in. Content is not sent to AI providers. HTTP checks contact linked hosts. Deactivation retains data and links while removing the cron schedule.

== Installation ==
Upload the ZIP through Plugins > Add New. Activate, open WhisperLink and scan content. Review suggestions before applying.

== Changelog ==
= 0.2.1 =
Completed language consistency for form labels, content-type names, history actions, and CSV headers.
= 0.2.0 =
Added automatic English, French, and Arabic admin interfaces based on the WordPress site language.
= 0.1.1 =
Recognize www and non-www site URLs as internal links.
= 0.1.0 =
Initial local linking engine and Arabic dashboard.
