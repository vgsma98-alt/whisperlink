<?php
defined('ABSPATH') || exit;

final class WhisperLink_Plugin {
    private static bool $writing = false;
    public static function table(string $name): string { global $wpdb; return $wpdb->prefix . 'whisperlink_' . $name; }
    public static function settings(): array {
        return wp_parse_args(get_option('whisperlink_settings', []), ['types' => ['post','page'], 'exclude' => [], 'limit' => 3, 'external' => false, 'automatic' => false]);
    }
    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('wp_ajax_whisperlink', [self::class, 'ajax']);
        add_action('whisperlink_tick', [self::class, 'tick']);
        add_action('save_post', [self::class, 'saved'], 30, 2);
        add_action('before_delete_post', [self::class, 'removed']);
        add_action('add_meta_boxes', static function () {
            foreach (self::settings()['types'] as $type) add_meta_box('whisperlink', 'WhisperLink — ' . WhisperLink_I18n::t('الروابط الداخلية'), [self::class, 'metabox'], $type, 'side');
        });
    }
    public static function activate(): void {
        global $wpdb;
        if (!extension_loaded('mbstring')) wp_die(WhisperLink_I18n::t('يتطلب WhisperLink امتداد PHP mbstring.'));
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $nodes = self::table('nodes'); $edges = self::table('edges'); $history = self::table('history');
        dbDelta("CREATE TABLE $nodes (
            post_id bigint(20) unsigned NOT NULL,
            title text NOT NULL,
            url text NOT NULL,
            keywords text NOT NULL,
            indexed_at datetime NOT NULL,
            PRIMARY KEY  (post_id)
        ) $charset;");
        dbDelta("CREATE TABLE $edges (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source bigint(20) unsigned NOT NULL,
            target bigint(20) unsigned NOT NULL DEFAULT 0,
            url text NOT NULL,
            url_hash char(64) NOT NULL,
            host varchar(255) NOT NULL,
            internal tinyint NOT NULL DEFAULT 0,
            rel varchar(255) NOT NULL DEFAULT '',
            status int NOT NULL DEFAULT 0,
            checked_at datetime NULL,
            PRIMARY KEY  (id),
            KEY source (source),
            KEY target (target),
            KEY url_hash (url_hash),
            KEY checked_at (checked_at)
        ) $charset;");
        dbDelta("CREATE TABLE $history (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            actor bigint(20) unsigned NOT NULL,
            action varchar(100) NOT NULL,
            before_content longtext NOT NULL,
            after_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            undone tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY post_id (post_id)
        ) $charset;");
        update_option('whisperlink_scan', ['cursor' => 0, 'active' => true, 'processed' => 0], false);
        if (!wp_next_scheduled('whisperlink_tick')) wp_schedule_event(time() + 30, 'hourly', 'whisperlink_tick');
    }
    public static function deactivate(): void { wp_clear_scheduled_hook('whisperlink_tick'); }
    public static function eligible($post): bool {
        $s = self::settings();
        return $post && $post->post_status === 'publish' && $post->post_password === '' && in_array($post->post_type, $s['types'], true) && !in_array((int) $post->ID, $s['exclude'], true);
    }
    public static function removed(int $id): void {
        global $wpdb;
        $wpdb->delete(self::table('nodes'), ['post_id' => $id]);
        $wpdb->delete(self::table('edges'), ['source' => $id]);
    }
    public static function keywords(int $id): array {
        $parts = [(string) get_post_meta($id, '_whisperlink_keywords', true), (string) get_post_meta($id, '_yoast_wpseo_focuskw', true), (string) get_post_meta($id, 'rank_math_focus_keyword', true), get_the_title($id)];
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,،\r\n]+/u', implode(',', $parts))))));
    }
    public static function index(int $id): void {
        global $wpdb;
        $post = get_post($id);
        if (!self::eligible($post)) { self::removed($id); return; }
        $url = get_permalink($id);
        $wpdb->replace(self::table('nodes'), ['post_id' => $id, 'title' => $post->post_title, 'url' => $url, 'keywords' => wp_json_encode(self::keywords($id)), 'indexed_at' => current_time('mysql', true)]);
        $old = $wpdb->get_results($wpdb->prepare('SELECT url_hash,status,checked_at FROM ' . self::table('edges') . ' WHERE source=%d', $id), OBJECT_K);
        $wpdb->delete(self::table('edges'), ['source' => $id]);
        foreach (WhisperLink_Content::links($post->post_content, $url) as $link) {
            $host = strtolower((string) wp_parse_url($link['url'], PHP_URL_HOST));
            $internal = WhisperLink_Content::internal($link['url'], home_url());
            $hash = hash('sha256', $link['url']);
            $cached = $old[$hash] ?? null;
            $wpdb->insert(self::table('edges'), ['source' => $id, 'target' => $internal ? url_to_postid($link['url']) : 0, 'url' => $link['url'], 'url_hash' => $hash, 'host' => $host, 'internal' => (int) $internal, 'rel' => substr($link['rel'], 0, 255), 'status' => $cached ? $cached->status : 0, 'checked_at' => $cached ? $cached->checked_at : null]);
        }
    }
    public static function saved(int $id, $post): void {
        if (self::$writing || wp_is_post_revision($id) || wp_is_post_autosave($id)) return;
        self::index($id);
        if (self::settings()['automatic'] && self::eligible($post) && current_user_can('manage_options') && current_user_can('edit_post', $id)) {
            // A builder or a locked document must never make WordPress saving fail.
            try { self::apply_rules($id); } catch (RuntimeException $e) { /* Leave the saved post intact. */ }
        }
    }
    public static function tick(): void { self::scan_batch(); self::check_batch(); }
    public static function scan_batch(): array {
        global $wpdb;
        // Atomic option lock prevents overlapping browser/cron batches; stale locks expire.
        if (!add_option('whisperlink_scan_lock', time(), '', false)) {
            if ((int) get_option('whisperlink_scan_lock') < time() - 180) delete_option('whisperlink_scan_lock');
            return get_option('whisperlink_scan', []);
        }
        try {
            $state = get_option('whisperlink_scan', ['active' => false, 'cursor' => 0, 'processed' => 0]);
            if (empty($state['active'])) return $state;
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID>%d AND post_status='publish' ORDER BY ID LIMIT 25", $state['cursor']));
            foreach ($ids as $id) { self::index((int) $id); $state['cursor'] = (int) $id; ++$state['processed']; }
            if (count($ids) < 25) $state['active'] = false;
            update_option('whisperlink_scan', $state, false);
            return $state;
        } finally { delete_option('whisperlink_scan_lock'); }
    }
    public static function check_batch(): int {
        global $wpdb;
        $table = self::table('edges');
        $external = self::settings()['external'] ? '' : ' AND internal=1';
        $rows = $wpdb->get_results("SELECT url_hash,MAX(url) AS url FROM $table WHERE (checked_at IS NULL OR checked_at < UTC_TIMESTAMP() - INTERVAL 7 DAY)$external GROUP BY url_hash LIMIT 3");
        foreach ($rows as $row) {
            $response = wp_safe_remote_head($row->url, ['timeout' => 4, 'redirection' => 3, 'user-agent' => 'WhisperLink/0.1; ' . home_url()]);
            if (!is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), [405,501], true)) $response = wp_safe_remote_get($row->url, ['timeout' => 4, 'redirection' => 3, 'limit_response_size' => 1024]);
            $status = is_wp_error($response) ? -1 : wp_remote_retrieve_response_code($response);
            $wpdb->update($table, ['status' => $status, 'checked_at' => current_time('mysql', true)], ['url_hash' => $row->url_hash]);
        }
        return count($rows);
    }
    private static function editable(int $id): WP_Post {
        $post = get_post($id);
        if (!self::eligible($post) || !current_user_can('edit_post', $id)) throw new RuntimeException(WhisperLink_I18n::t('لا تملك صلاحية تعديل هذه الصفحة أو أنها خارج نطاق الفحص.'));
        if (get_post_meta($id, '_elementor_edit_mode', true) === 'builder' || get_post_meta($id, '_et_pb_use_builder', true) === 'on') throw new RuntimeException(WhisperLink_I18n::t('تعديل محتوى منشئ الصفحات غير مدعوم في هذا الإصدار.'));
        require_once ABSPATH . 'wp-admin/includes/post.php';
        if (wp_check_post_lock($id)) throw new RuntimeException(WhisperLink_I18n::t('الصفحة مفتوحة للتعديل بواسطة مستخدم آخر.'));
        return $post;
    }
    private static function write(int $id, string $before, string $after, string $action): bool {
        $key = 'whisperlink_write_lock_' . $id;
        if (!add_option($key, time(), '', false)) {
            if ((int) get_option($key) < time() - 180) delete_option($key);
            throw new RuntimeException(WhisperLink_I18n::t('توجد عملية تعديل أخرى لهذا المقال. أعد المحاولة بعد قليل.'));
        }
        try { return self::write_locked($id, $before, $after, $action); }
        finally { delete_option($key); }
    }
    private static function write_locked(int $id, string $before, string $after, string $action): bool {
        global $wpdb;
        if ($before === $after) return false;
        clean_post_cache($id);
        $post = self::editable($id);
        if ($post->post_content !== $before) throw new RuntimeException(WhisperLink_I18n::t('تغير المحتوى. حدّث الاقتراحات قبل المحاولة مجددًا.'));
        $wpdb->insert(self::table('history'), ['post_id' => $id, 'actor' => get_current_user_id(), 'action' => $action, 'before_content' => $before, 'after_hash' => hash('sha256', $after), 'created_at' => current_time('mysql', true)]);
        $history_id = $wpdb->insert_id;
        if (!$history_id) throw new RuntimeException(WhisperLink_I18n::t('تعذر حفظ نسخة التراجع، لم يتغير المحتوى.'));
        self::$writing = true;
        try { $result = wp_update_post(wp_slash(['ID' => $id, 'post_content' => $after]), true); }
        finally { self::$writing = false; }
        if (is_wp_error($result)) { $wpdb->delete(self::table('history'), ['id' => $history_id]); throw new RuntimeException($result->get_error_message()); }
        // Account for content filters applied by WordPress before storing the undo guard.
        $wpdb->update(self::table('history'), ['after_hash' => hash('sha256', get_post($id)->post_content)], ['id' => $history_id]);
        self::index($id);
        return true;
    }
    public static function suggestions(int $id, string $direction = 'out'): array {
        global $wpdb;
        $post = self::editable($id);
        $nodes = $wpdb->get_results('SELECT * FROM ' . self::table('nodes') . ' ORDER BY post_id DESC LIMIT 500');
        $results = [];
        foreach ($nodes as $node) {
            $other = (int) $node->post_id;
            if ($other === $id) continue;
            $source = $direction === 'in' ? get_post($other) : $post;
            $target = $direction === 'in' ? $post : get_post($other);
            if (!self::eligible($source) || !self::eligible($target) || !current_user_can('edit_post', $source->ID)) continue;
            $url = get_permalink($target->ID);
            $exists = false;
            foreach (WhisperLink_Content::links($source->post_content, get_permalink($source->ID)) as $link) if ($link['url'] === WhisperLink_Content::url($url, $url)) $exists = true;
            if ($exists) continue;
            foreach (self::keywords($target->ID) as $phrase) {
                if (mb_strlen($phrase) < 3 || mb_strlen($phrase) > 120) continue;
                if (WhisperLink_Content::insert($source->post_content, $phrase, $url) === $source->post_content) continue;
                $source_terms = WhisperLink_Content::terms($source->post_title . ' ' . $source->post_content);
                $target_terms = WhisperLink_Content::terms($target->post_title . ' ' . $target->post_content);
                $overlap = count(array_intersect($source_terms, $target_terms));
                $score = round(100 * $overlap / max(1, count(array_unique(array_merge($source_terms, $target_terms)))));
                $text = wp_strip_all_tags($source->post_content);
                $pos = mb_stripos($text, $phrase);
                $results[] = ['source' => $source->ID, 'target' => $target->ID, 'source_title' => $source->post_title, 'target_title' => $target->post_title, 'phrase' => $phrase, 'url' => $url, 'score' => $score, 'context' => mb_substr($text, max(0, (int) $pos - 80), 220), 'hash' => hash('sha256', $source->post_content)];
                break;
            }
        }
        usort($results, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($results, 0, 40);
    }
    public static function apply_rules(int $id, bool $preview = false): array {
        $post = self::editable($id); $html = $post->post_content; $matches = [];
        $limit = (int) self::settings()['limit'];
        foreach (get_option('whisperlink_rules', []) as $rule) {
            if (count($matches) >= $limit) break;
            $url = get_permalink((int) $rule['target']);
            if (!$url || (int) $rule['target'] === $id || !self::eligible(get_post((int) $rule['target']))) continue;
            $existing = array_column(WhisperLink_Content::links($html, get_permalink($id)), 'url');
            if (in_array(WhisperLink_Content::url($url, $url), $existing, true)) continue;
            $next = WhisperLink_Content::insert($html, $rule['phrase'], $url);
            if ($html !== $next) { $matches[] = $rule; $html = $next; }
        }
        if (!$preview) self::write($id, $post->post_content, $html, 'auto-link');
        return $matches;
    }
    public static function report(int $page, string $filter, string $search): array {
        global $wpdb;
        $n = self::table('nodes'); $e = self::table('edges');
        $where = $search !== '' ? $wpdb->prepare(' WHERE n.title LIKE %s', '%' . $wpdb->esc_like($search) . '%') : '';
        $having = $filter === 'orphans' ? ' HAVING inbound=0' : ($filter === 'deadends' ? ' HAVING outgoing=0' : '');
        $sql = "SELECT n.*, (SELECT COUNT(DISTINCT source) FROM $e WHERE target=n.post_id AND source<>n.post_id) AS inbound, (SELECT COUNT(*) FROM $e WHERE source=n.post_id AND internal=1) AS outgoing, (SELECT COUNT(*) FROM $e WHERE source=n.post_id AND internal=0) AS external_count FROM $n n$where$having ORDER BY n.post_id DESC";
        $rows = $wpdb->get_results($sql . $wpdb->prepare(' LIMIT 50 OFFSET %d', ($page - 1) * 50));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $n");
        $orphans = (int) $wpdb->get_var("SELECT COUNT(*) FROM $n n WHERE NOT EXISTS (SELECT 1 FROM $e e WHERE e.target=n.post_id AND e.source<>n.post_id)");
        return ['rows' => $rows, 'page' => $page, 'total' => $total, 'orphans' => $orphans, 'coverage' => $total ? round(100 * ($total - $orphans) / $total) : 0, 'links' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $e"), 'broken' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $e WHERE status IN (404,410)"), 'scan' => get_option('whisperlink_scan', [])];
    }
    public static function ajax(): void {
        check_ajax_referer('whisperlink', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => WhisperLink_I18n::t('غير مصرح.')], 403);
        $d = json_decode(wp_unslash($_POST['data'] ?? '{}'), true);
        if (!is_array($d)) wp_send_json_error(['message' => WhisperLink_I18n::t('طلب غير صالح.')], 400);
        try { wp_send_json_success(self::dispatch(sanitize_key($_POST['op'] ?? ''), $d)); }
        catch (Throwable $e) { wp_send_json_error(['message' => $e->getMessage()], 400); }
    }
    private static function dispatch(string $op, array $d) {
        global $wpdb;
        $id = absint($d['id'] ?? 0); $page = max(1, absint($d['page'] ?? 1));
        switch ($op) {
            case 'report': return self::report($page, sanitize_key($d['filter'] ?? ''), sanitize_text_field($d['search'] ?? ''));
            case 'scan_start':
                update_option('whisperlink_scan', ['active' => true, 'cursor' => 0, 'processed' => 0], false);
                // Prune records that no longer belong to the configured scope.
                foreach ($wpdb->get_col('SELECT post_id FROM ' . self::table('nodes')) as $pid) if (!self::eligible(get_post($pid))) self::removed((int) $pid);
                return self::scan_batch();
            case 'scan': return self::scan_batch();
            case 'check': return ['checked' => self::check_batch()];
            case 'suggestions': return self::suggestions($id, ($d['direction'] ?? '') === 'in' ? 'in' : 'out');
            case 'insert':
                $post = self::editable($id); $target = get_post(absint($d['target'] ?? 0));
                if (!self::eligible($target) || $target->ID === $id) throw new RuntimeException(WhisperLink_I18n::t('وجهة غير صالحة.'));
                if (!hash_equals(hash('sha256', $post->post_content), (string) ($d['hash'] ?? ''))) throw new RuntimeException(WhisperLink_I18n::t('تغير المقال. أعد توليد الاقتراحات.'));
                $url = get_permalink($target->ID);
                foreach (WhisperLink_Content::links($post->post_content, get_permalink($id)) as $link) if ($link['url'] === WhisperLink_Content::url($url, $url)) throw new RuntimeException(WhisperLink_I18n::t('يوجد رابط لهذه الوجهة بالفعل.'));
                $phrase = sanitize_text_field($d['phrase'] ?? '');
                if ($phrase === '' || mb_strlen($phrase) > 120) throw new RuntimeException(WhisperLink_I18n::t('نص الرابط غير صالح.'));
                return ['changed' => self::write($id, $post->post_content, WhisperLink_Content::insert($post->post_content, $phrase, $url), 'suggestion')];
            case 'keywords':
                self::editable($id);
                update_post_meta($id, '_whisperlink_keywords', sanitize_textarea_field($d['keywords'] ?? ''));
                self::index($id); return ['saved' => true];
            case 'rules_get': return get_option('whisperlink_rules', []);
            case 'rules_save':
                $rules = [];
                foreach (array_slice((array) ($d['rules'] ?? []), 0, 200) as $r) {
                    $phrase = sanitize_text_field($r['phrase'] ?? ''); $target = absint($r['target'] ?? 0);
                    if (mb_strlen($phrase) < 3 || mb_strlen($phrase) > 120 || !self::eligible(get_post($target))) throw new RuntimeException(WhisperLink_I18n::t('كل قاعدة تحتاج عبارة من 3–120 حرفًا ورقم صفحة منشورة ضمن النطاق.'));
                    $rules[] = ['phrase' => $phrase, 'target' => $target];
                }
                update_option('whisperlink_rules', $rules, false); return ['saved' => count($rules)];
            case 'rules_preview': return ['matches' => self::apply_rules($id, true), 'hash' => hash('sha256', get_post($id)->post_content), 'rules_hash' => hash('sha256', wp_json_encode([get_option('whisperlink_rules', []), self::settings()]))];
            case 'rules_apply':
                $post = self::editable($id);
                if (!hash_equals(hash('sha256', $post->post_content), (string) ($d['hash'] ?? ''))) throw new RuntimeException(WhisperLink_I18n::t('تغير المقال. أعد المعاينة.'));
                if (!hash_equals(hash('sha256', wp_json_encode([get_option('whisperlink_rules', []), self::settings()])), (string) ($d['rules_hash'] ?? ''))) throw new RuntimeException(WhisperLink_I18n::t('تغيرت القواعد أو الإعدادات. أعد المعاينة.'));
                return self::apply_rules($id);
            case 'urls_preview':
                $old = self::valid_url($d['old'] ?? ''); $new = self::valid_url($d['new'] ?? '');
                $ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT source FROM ' . self::table('edges') . ' WHERE url_hash=%s LIMIT 100', hash('sha256', $old)));
                $rows = [];
                foreach ($ids as $pid) {
                    try { $p = self::editable((int) $pid); } catch (Throwable $e) { continue; }
                    if ($p->post_content !== WhisperLink_Content::replace_url($p->post_content, get_permalink($pid), $old, $new)) $rows[] = ['id' => (int) $pid, 'title' => $p->post_title, 'hash' => hash('sha256', $p->post_content)];
                }
                return $rows;
            case 'urls_apply':
                $p = self::editable($id);
                if (!hash_equals(hash('sha256', $p->post_content), (string) ($d['hash'] ?? ''))) throw new RuntimeException(WhisperLink_I18n::t('تغير المقال. أعد المعاينة.'));
                return ['changed' => self::write($id, $p->post_content, WhisperLink_Content::replace_url($p->post_content, get_permalink($id), self::valid_url($d['old'] ?? ''), self::valid_url($d['new'] ?? '')), 'url-change')];
            case 'edges':
                $where = ($d['filter'] ?? '') === 'errors' ? ' WHERE status>=400 OR status=-1' : '';
                return $wpdb->get_results('SELECT e.*,p.post_title FROM ' . self::table('edges') . " e LEFT JOIN {$wpdb->posts} p ON p.ID=e.source$where ORDER BY e.id DESC" . $wpdb->prepare(' LIMIT 50 OFFSET %d', ($page - 1) * 50));
            case 'domains': return $wpdb->get_results('SELECT host,COUNT(*) AS links,COUNT(DISTINCT source) AS pages FROM ' . self::table('edges') . ' GROUP BY host ORDER BY links DESC LIMIT 200');
            case 'history': return $wpdb->get_results('SELECT id,post_id,actor,action,created_at,undone FROM ' . self::table('history') . ' ORDER BY id DESC' . $wpdb->prepare(' LIMIT 50 OFFSET %d', ($page - 1) * 50));
            case 'undo':
                $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('history') . ' WHERE id=%d AND undone=0', $id));
                if (!$row) throw new RuntimeException(WhisperLink_I18n::t('السجل غير متاح.'));
                $post = self::editable((int) $row->post_id);
                if (!hash_equals($row->after_hash, hash('sha256', $post->post_content))) throw new RuntimeException(WhisperLink_I18n::t('يوجد تعديل أحدث. تراجع عنه أولًا، أو استخدم مراجعات ووردبريس.'));
                self::write((int) $row->post_id, $post->post_content, $row->before_content, 'undo');
                $wpdb->update(self::table('history'), ['undone' => 1], ['id' => $id]); return ['undone' => true];
            case 'settings_get':
                $types = [];
                foreach (get_post_types(['public' => true], 'objects') as $type) $types[$type->name] = $type->labels->name ?? $type->name;
                return ['settings' => self::settings(), 'types' => $types];
            case 'settings_save':
                $types = array_values(array_intersect((array) ($d['types'] ?? []), array_values(get_post_types(['public' => true], 'names'))));
                if (!$types) throw new RuntimeException(WhisperLink_I18n::t('اختر نوع محتوى واحدًا على الأقل.'));
                update_option('whisperlink_settings', ['types' => $types, 'exclude' => array_values(array_filter(array_map('absint', preg_split('/[\s,،]+/u', (string) ($d['exclude'] ?? ''))))), 'limit' => max(1, min(10, absint($d['limit'] ?? 3))), 'automatic' => !empty($d['automatic']), 'external' => !empty($d['external'])], false);
                return ['saved' => true];
            default: throw new RuntimeException(WhisperLink_I18n::t('عملية غير معروفة.'));
        }
    }
    private static function valid_url(string $value): string {
        $url = esc_url_raw(trim($value), ['http', 'https']);
        if (!$url || !wp_parse_url($url, PHP_URL_HOST)) throw new RuntimeException(WhisperLink_I18n::t('أدخل عنوان HTTP أو HTTPS كاملًا.'));
        return $url;
    }
    public static function menu(): void { add_menu_page('WhisperLink', 'WhisperLink', 'manage_options', 'whisperlink', [self::class, 'page'], 'dashicons-admin-links', 81); }
    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_whisperlink') return;
        wp_enqueue_style('whisperlink', plugins_url('assets/admin.css', WHISPERLINK_FILE), [], '0.2.1');
        wp_enqueue_script('whisperlink', plugins_url('assets/admin.js', WHISPERLINK_FILE), [], '0.2.1', true);
        wp_localize_script('whisperlink', 'WhisperLink', ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('whisperlink'), 'post' => absint($_GET['post'] ?? 0), 'lang' => WhisperLink_I18n::lang(), 'dir' => WhisperLink_I18n::dir(), 'i18n' => WhisperLink_I18n::js()]);
    }
    public static function metabox($post): void {
        if (!current_user_can('manage_options')) { echo '<p>' . esc_html(WhisperLink_I18n::t('تقارير الروابط متاحة لمدير الموقع.')) . '</p>'; return; }
        echo '<p>' . esc_html(WhisperLink_I18n::t('احفظ المقال ثم افتح الاقتراحات لمراجعة الروابط.')) . '</p><a class="button" href="' . esc_url(admin_url('admin.php?page=whisperlink&post=' . $post->ID)) . '">' . esc_html(WhisperLink_I18n::t('اقتراح روابط لهذا المقال')) . '</a>';
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        $t = static fn(string $text): string => esc_html(WhisperLink_I18n::t($text));
        echo '<div id="whisperlink" lang="' . esc_attr(WhisperLink_I18n::lang()) . '" dir="' . esc_attr(WhisperLink_I18n::dir()) . '"><header><div><span class="wl-eyebrow">WHISPERLINK · 0.2.1</span><h1>' . $t('روابط أفضل، محتوى مترابط.') . '</h1><p>' . $t('راجع فرص الربط الداخلي، أصلح الروابط وتابع تغطية صفحاتك.') . '</p></div><button id="wl-scan" class="wl-primary">' . $t('فحص المحتوى') . '</button></header><div id="wl-message" role="status" aria-live="polite"></div><nav aria-label="' . $t('أقسام الإضافة') . '" id="wl-nav"></nav><main id="wl-main"><p>' . $t('جارٍ التحميل…') . '</p></main><footer>' . $t('الاقتراحات تعتمد على تطابق الكلمات والتشابه النصي المحلي. افحص ملاءمة السياق قبل الإدراج.') . '</footer></div>';
    }
}
