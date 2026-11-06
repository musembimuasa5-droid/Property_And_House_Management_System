<?php
/**
 * Data for the signed-in app shell: notification bell, sidebar badges and breadcrumbs.
 * Each helper caches its result so the sidebar and top bar share one set of queries.
 */

require_once __DIR__ . '/../config/modules.php';
require_once __DIR__ . '/functions.php';

/** Unread count and latest notifications for the bell menu. */
function shell_notifications(int $userId): array {
    static $cache = [];
    if (!isset($cache[$userId])) {
        $db = db();
        $count = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $count->execute([$userId]);
        $latest = $db->prepare('SELECT id, title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 6');
        $latest->execute([$userId]);
        $cache[$userId] = ['unread' => (int)$count->fetchColumn(), 'latest' => $latest->fetchAll()];
    }
    return $cache[$userId];
}

/** Items waiting for staff action, shown as sidebar badges. A missing optional table shows no badge. */
function shell_module_badges(): array {
    static $badges = null;
    if ($badges !== null) return $badges;
    $queries = [
        'viewings' => "SELECT COUNT(*) FROM viewing_requests WHERE status = 'Pending'",
        'enquiries' => "SELECT COUNT(*) FROM enquiries WHERE status = 'New'",
        'maintenance' => "SELECT COUNT(*) FROM maintenance_requests WHERE status = 'Submitted'",
        'complaints' => "SELECT COUNT(*) FROM complaints WHERE status = 'Open'",
    ];
    $badges = [];
    foreach ($queries as $key => $sql) {
        if (!user_can_access_module($key)) continue;
        try {
            $badges[$key] = (int)db()->query($sql)->fetchColumn();
        } catch (PDOException $e) {
            $badges[$key] = 0;
        }
    }
    return $badges;
}

/** Registry key of the module page being viewed, if any. */
function current_module_key(): ?string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (app_modules() as $key => $module) {
        if (substr($script, -strlen('/' . $module['path'])) === '/' . $module['path']) return $key;
    }
    return null;
}

/** Breadcrumb trail as [label, url|null] pairs; the last item is the current page. */
function shell_breadcrumbs(string $dashboardUrl, ?string $pageTitle): array {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (substr($script, -strlen('/dashboard.php')) === '/dashboard.php') return [['Dashboard', null]];
    $crumbs = [['Dashboard', $dashboardUrl]];
    $key = current_module_key();
    if ($key) {
        $module = app_modules()[$key];
        $crumbs[] = [$module['group'], null];
        if ($pageTitle !== null && $pageTitle !== $module['label']) $crumbs[] = [$module['label'], module_url($key)];
    }
    $crumbs[] = [$pageTitle ?? 'Page', null];
    return $crumbs;
}

function user_initials(string $name): string {
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    return $initials !== '' ? $initials : '?';
}

function time_ago(string $datetime): string {
    $seconds = time() - strtotime($datetime);
    if ($seconds < 60) return 'Just now';
    if ($seconds < 3600) return floor($seconds / 60) . ' min ago';
    if ($seconds < 86400) return floor($seconds / 3600) . ' h ago';
    if ($seconds < 604800) return floor($seconds / 86400) . ' d ago';
    return format_date($datetime);
}
