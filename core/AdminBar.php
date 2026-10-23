<?php
declare(strict_types=1);

namespace Core;

/**
 * Class AdminBar
 *
 * Renders an authentic WordPress-style floating admin bar on the frontend
 * for logged-in users with management capabilities.
 */
final class AdminBar
{
    private static bool $printedHead = false;
    private static bool $printedBar = false;

    /** Height of the bar in pixels */
    private const HEIGHT = 32;

    public static function init(): void
    {
        // Handle quick cache purge from admin bar
        if (isset($_GET['mab_purge_cache']) && self::isEnabled()) {
            if (Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
                if (class_exists(\Core\PageCache::class)) {
                    \Core\PageCache::purge();
                }
                $cleanUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
                header('Location: ' . $cleanUrl . '?cache_purged=1');
                exit;
            }
        }

        Hooks::addAction('theme-header', [self::class, 'renderHead']);
        Hooks::addAction('theme-footer', [self::class, 'render']);
    }

    /**
     * Whether the bar should be displayed for the current request.
     */
    public static function isEnabled(): bool
    {
        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return false;
        }
        if (!class_exists(Auth::class) || !Auth::check()) {
            return false;
        }
        return Auth::can('manage_pages') || Auth::can('manage_settings');
    }

    /**
     * Resolves an admin URL, honouring a subfolder installation.
     */
    private static function adminUrl(string $path = ''): string
    {
        $base = class_exists('Core\\Router') ? Router::getBaseSubdirectory() : '';
        $path = ltrim($path, '/');
        return $base . '/admin' . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Outputs the stylesheet and the early bootstrap script (runs in <head>).
     */
    public static function renderHead(): void
    {
        if (!self::isEnabled() || self::$printedHead) {
            return;
        }
        self::$printedHead = true;

        $h = self::HEIGHT;
        ?>
<style id="cmsbyphp-admin-bar-css">
html.has-admin-bar body { margin-top: <?= $h ?>px !important; }
html.has-admin-bar .site-header,
html.has-admin-bar .sticky-top,
html.has-admin-bar [data-sticky] { top: <?= $h ?>px !important; }

#wp-admin-bar {
    position: fixed; top: 0; left: 0; right: 0; height: <?= $h ?>px; z-index: 999999;
    background: #1d2327; color: #c3c4c7;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    font-size: 13px; line-height: <?= $h ?>px;
    display: flex; align-items: stretch; justify-content: space-between;
    box-shadow: 0 1px 2px rgba(0,0,0,.25);
    -webkit-font-smoothing: subpixel-antialiased;
    user-select: none;
}
#wp-admin-bar * { box-sizing: border-box; }
#wp-admin-bar a {
    color: #c3c4c7; text-decoration: none; display: inline-flex; align-items: center;
    gap: 6px; padding: 0 10px; height: 100%; transition: color .12s, background .12s;
    white-space: nowrap; font-size: 13px;
}
#wp-admin-bar a:hover { color: #72aee6; background: #2c3338; }
#wp-admin-bar .ab-icon { width: 14px; height: 14px; flex-shrink: 0; fill: currentColor; }
#wp-admin-bar .ab-brand {
    font-weight: 700; color: #fff; background: #2271b1; padding: 0 12px; gap: 8px;
}
#wp-admin-bar .ab-brand:hover { color: #fff; background: #135e96; }
#wp-admin-bar .ab-brand img { width: 18px; height: 18px; object-fit: contain; border-radius: 4px; }
#wp-admin-bar .ab-left, #wp-admin-bar .ab-right { display: flex; align-items: stretch; }

/* Dropdown menus (WordPress-style hover) */
#wp-admin-bar .ab-item { position: relative; display: flex; align-items: stretch; }
#wp-admin-bar .ab-submenu {
    display: none; position: absolute; top: 100%; left: 0;
    min-width: 170px; background: #2c3338;
    box-shadow: 0 4px 12px rgba(0,0,0,.35);
    border-radius: 0 0 4px 4px;
    padding: 4px 0; z-index: 1000000;
}
#wp-admin-bar .ab-submenu.ab-sub-right { left: auto; right: 0; }
#wp-admin-bar .ab-submenu a {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 14px; height: auto; line-height: 1.4;
    color: #c3c4c7; font-size: 12px;
}
#wp-admin-bar .ab-submenu a:hover {
    color: #72aee6; background: #1d2327;
}
#wp-admin-bar .ab-item:hover > .ab-submenu { display: block; }
#wp-admin-bar .ab-avatar {
    width: 18px; height: 18px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #6366f1);
    color: #fff; text-align: center; line-height: 18px;
    font-size: 10px; font-weight: 700; display: inline-block;
}
#wp-admin-bar .ab-toggle-btn {
    background: transparent; border: 0; color: #c3c4c7;
    cursor: pointer; padding: 0 10px; display: inline-flex; align-items: center;
}
#wp-admin-bar .ab-toggle-btn:hover { color: #fff; background: #2c3338; }

#wp-admin-bar-expand {
    position: fixed; top: 0; right: 12px; z-index: 999999; height: 28px; padding: 0 10px;
    background: #1d2327; color: #c3c4c7; border: 0; border-radius: 0 0 6px 6px;
    cursor: pointer; font-size: 12px; display: none; box-shadow: 0 2px 6px rgba(0,0,0,.3);
    align-items: center; gap: 4px; font-weight: 600;
}
#wp-admin-bar-expand:hover { background: #2271b1; color: #fff; }
html.admin-bar-collapsed #wp-admin-bar { display: none !important; }
html.admin-bar-collapsed body { margin-top: 0 !important; }
html.admin-bar-collapsed #wp-admin-bar-expand { display: inline-flex !important; }

@media (max-width: 782px) {
    #wp-admin-bar .ab-hide-mobile { display: none !important; }
}
</style>
<script>
(function () {
    try {
        var collapsed = localStorage.getItem('cmsbyphp_admin_bar_collapsed') === '1';
        document.documentElement.classList.toggle('admin-bar-collapsed', collapsed);
        if (!collapsed) document.documentElement.classList.add('has-admin-bar');
    } catch (e) {
        document.documentElement.classList.add('has-admin-bar');
    }
})();
</script>
        <?php
    }

    /**
     * Outputs the WordPress-style bar markup.
     */
    public static function render(): void
    {
        if (!self::isEnabled() || self::$printedBar) {
            return;
        }
        self::$printedBar = true;

        $user = (string)($_SESSION['user_name'] ?? 'Admin');
        $initial = strtoupper(substr($user, 0, 1));
        $currentPage = \ThemeState::$currentPage ?? null;
        $canPages = Auth::can('manage_pages');
        $canSettings = Auth::can('manage_settings');

        $editUrl = '';
        $editLabel = _e('Edit');
        if ($canPages && is_array($currentPage) && !empty($currentPage['id'])) {
            $isPost = (($currentPage['type'] ?? 'post') === 'post');
            $editLabel = $isPost ? _e('Edit Post') : _e('Edit Page');
            $editUrl = self::adminUrl('page-edit.php?id=' . (int)$currentPage['id']);
        }

        $purgeUrl = self::adminUrl('index.php');
        $csrfToken = Security::generateCsrfToken();
        $currentUri = $_SERVER['REQUEST_URI'] ?? '/';
        $purgeAction = (strpos($currentUri, '?') !== false ? $currentUri . '&' : $currentUri . '?') . 'mab_purge_cache=1&csrf=' . urlencode($csrfToken);

        $siteTitle = Router::getOption('site_title', 'CMSbyPHP');
        ?>
<div id="wp-admin-bar" role="navigation" aria-label="<?= _e('Admin toolbar') ?>">
    <div class="ab-left">
        <!-- Logo / Brand link -->
        <div class="ab-item">
            <a class="ab-brand" href="<?= htmlspecialchars(self::adminUrl('index.php'), ENT_QUOTES, 'UTF-8') ?>" title="CMSbyPHP Kokpit">
                <img src="<?= htmlspecialchars(Router::getSiteUrl() . '/site/assets/img/cmsbyphp-icon-64.png', ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
                <span>CMSbyPHP</span>
            </a>
            <div class="ab-submenu">
                <a href="<?= htmlspecialchars(self::adminUrl('index.php'), ENT_QUOTES, 'UTF-8') ?>">📊 <?= _e('Dashboard') ?></a>
                <a href="<?= htmlspecialchars(self::adminUrl('themes.php'), ENT_QUOTES, 'UTF-8') ?>">🎨 <?= _e('Themes') ?></a>
                <a href="<?= htmlspecialchars(self::adminUrl('menus.php'), ENT_QUOTES, 'UTF-8') ?>">📋 <?= _e('Menus') ?></a>
                <a href="<?= htmlspecialchars(self::adminUrl('settings.php'), ENT_QUOTES, 'UTF-8') ?>">⚙️ <?= _e('Settings') ?></a>
            </div>
        </div>

        <!-- + Nowy (WordPress signature) -->
        <?php if ($canPages): ?>
        <div class="ab-item">
            <a href="<?= htmlspecialchars(self::adminUrl('page-edit.php?type=post'), ENT_QUOTES, 'UTF-8') ?>">
                <svg class="ab-icon" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                <span><?= _e('New') ?></span>
            </a>
            <div class="ab-submenu">
                <a href="<?= htmlspecialchars(self::adminUrl('page-edit.php?type=post'), ENT_QUOTES, 'UTF-8') ?>">✍️ <?= _e('New Post') ?></a>
                <a href="<?= htmlspecialchars(self::adminUrl('media.php'), ENT_QUOTES, 'UTF-8') ?>">🖼️ <?= _e('Media') ?></a>
                <a href="<?= htmlspecialchars(self::adminUrl('page-edit.php?type=page'), ENT_QUOTES, 'UTF-8') ?>">📄 <?= _e('New Page') ?></a>
                <?php if (Auth::can('manage_users')): ?>
                    <a href="<?= htmlspecialchars(self::adminUrl('users.php?action=create'), ENT_QUOTES, 'UTF-8') ?>">👤 <?= _e('User') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Edit current post/page if viewing one -->
        <?php if ($editUrl !== ''): ?>
        <div class="ab-item">
            <a href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>">
                <svg class="ab-icon" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                <span><?= htmlspecialchars($editLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Customize Theme -->
        <?php if ($canSettings): ?>
        <div class="ab-item ab-hide-mobile">
            <a href="<?= htmlspecialchars(self::adminUrl('customize.php'), ENT_QUOTES, 'UTF-8') ?>">
                <svg class="ab-icon" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61L4.35 19.4c-.39.39-.39 1.02 0 1.41.39.39 1.02.39 1.41 0l1.9-1.9C9.27 19.59 10.59 20 12 20c4.97 0 9-4.03 9-9s-4.03-9-9-9zm0 15c-3.31 0-6-2.69-6-6s2.69-6 6-6 6 2.69 6 6-2.69 6-6 6z"/></svg>
                <span><?= _e('Customize') ?></span>
            </a>
        </div>
        <?php endif; ?>

        <!-- Purge Cache Button -->
        <?php if ($canSettings): ?>
        <div class="ab-item ab-hide-mobile">
            <a href="<?= htmlspecialchars($purgeAction, ENT_QUOTES, 'UTF-8') ?>" title="<?= _e('Purge Cache') ?>" onclick="return confirm('Wyczyścić pamięć podręczną witryny?');">
                <svg class="ab-icon" viewBox="0 0 24 24"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                <span><?= _e('Purge Cache') ?></span>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="ab-right">
        <!-- User Profile Dropdown -->
        <div class="ab-item">
            <a href="<?= htmlspecialchars(self::adminUrl('profile.php'), ENT_QUOTES, 'UTF-8') ?>">
                <span>Witaj, <strong><?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="ab-avatar"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <div class="ab-submenu ab-sub-right">
                <a href="<?= htmlspecialchars(self::adminUrl('profile.php'), ENT_QUOTES, 'UTF-8') ?>">👤 <?= _e('Profile') ?></a>
                <a href="<?= htmlspecialchars(self::adminUrl('logout.php'), ENT_QUOTES, 'UTF-8') ?>" style="color: #f87171;">🚪 <?= _e('Log out') ?></a>
            </div>
        </div>

        <!-- Collapse toggle button -->
        <button type="button" class="ab-toggle-btn" id="wp-admin-bar-toggle" title="<?= _e('Collapse') ?>">&#9650;</button>
    </div>
</div>

<button type="button" id="wp-admin-bar-expand" title="<?= _e('Show admin bar') ?>">
    <img src="<?= htmlspecialchars(Router::getSiteUrl() . '/site/assets/img/cmsbyphp-icon-64.png', ENT_QUOTES, 'UTF-8') ?>" alt="" style="width: 14px; height: 14px; object-fit: contain;">
    <span>Admin Bar &#9660;</span>
</button>

<script>
(function () {
    function setCollapsed(on) {
        document.documentElement.classList.toggle('admin-bar-collapsed', on);
        document.documentElement.classList.toggle('has-admin-bar', !on);
        try { localStorage.setItem('cmsbyphp_admin_bar_collapsed', on ? '1' : '0'); } catch (e) {}
    }
    var t = document.getElementById('wp-admin-bar-toggle');
    var e = document.getElementById('wp-admin-bar-expand');
    if (t) t.addEventListener('click', function () { setCollapsed(true); });
    if (e) e.addEventListener('click', function () { setCollapsed(false); });
})();
</script>
        <?php
    }
}
