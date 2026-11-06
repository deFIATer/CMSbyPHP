<?php
if (!defined('IN_ADMIN')) {
    define('IN_ADMIN', true);
}
require_once __DIR__ . '/../../core/bootstrap.php';

use Core\Auth;
use Core\Hooks;
use Core\I18n;
use Core\Router;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Distinguish between Post and Page editing for active sidebar links
$isPostActive = ($currentPage === 'posts.php');
$isPageActive = ($currentPage === 'pages.php');

$editType = $_GET['type'] ?? '';
if ($currentPage === 'page-edit.php') {
    if (empty($editType) && isset($_GET['id'])) {
        try {
            $typeStmt = \Core\Database::getConnection()->prepare("SELECT type FROM pages WHERE id = :id LIMIT 1");
            $typeStmt->execute([':id' => (int)$_GET['id']]);
            $editType = $typeStmt->fetchColumn() ?: 'post';
        } catch (\Throwable $e) {
            $editType = 'post';
        }
    }
    if ($editType === 'page') {
        $isPageActive = true;
    } else {
        $isPostActive = true;
    }
}

// WordPress-aligned section active groups
$isDashboardGroup = in_array($currentPage, ['index.php', 'updates.php'], true);
$isPostsGroup = ($isPostActive || $currentPage === 'categories.php');
$isPagesGroup = $isPageActive;
$isMediaGroup = ($currentPage === 'media.php');
$isAppearanceGroup = in_array($currentPage, ['themes.php', 'customize.php', 'menus.php', 'theme-edit.php'], true);
$isPluginsGroup = ($currentPage === 'plugins.php');
$isUsersGroup = in_array($currentPage, ['users.php', 'profile.php'], true);
$isToolsGroup = in_array($currentPage, ['snippets.php', 'backup.php'], true);
$isSettingsGroup = in_array($currentPage, ['settings.php', 'languages.php'], true);

// Dynamically compute the admin directory base URL for asset resolution
$scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$adminBase = ($scriptDir !== '' ? '/' . $scriptDir : '') . '/';
$adminTheme = Auth::adminTheme();
$resetThemeCache = !empty($_SESSION['admin_theme_reset']);
unset($_SESSION['admin_theme_reset']);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18n::getAdminLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= \Core\Security::generateCsrfToken() ?>">
    <base href="<?= htmlspecialchars($adminBase, ENT_QUOTES, 'UTF-8') ?>">
    <title>CMSbyPHP &bull; <?= _e('Dashboard') ?></title>
    <script>window.CMSBYPHP_CSRF = window.MODO_CSRF = document.querySelector('meta[name="csrf-token"]').content;</script>
    
    <!-- Instant Dark Mode Init Script (Prevents FOUC) -->
    <script>
        window.CMSBYPHP_ADMIN_THEME = window.MODO_ADMIN_THEME = <?= json_encode($adminTheme) ?>;
        (function () {
            var KEY = 'cmsbyphp_theme';
            var resetCache = <?= $resetThemeCache ? 'true' : 'false' ?>;
            if (resetCache) {
                try {
                    localStorage.removeItem(KEY);
                    localStorage.removeItem('modo_cms_theme');
                    localStorage.removeItem('clean_cms_theme');
                    localStorage.removeItem('theme');
                } catch (e) {}
            }

            var stored = localStorage.getItem(KEY);
            var legacy = localStorage.getItem('modo_cms_theme') || localStorage.getItem('clean_cms_theme') || localStorage.getItem('theme');
            if (!stored && legacy) {
                stored = legacy;
                try { localStorage.setItem(KEY, stored); } catch (e) {}
            }

            var theme = window.CMSBYPHP_ADMIN_THEME || 'auto';
            if (theme === 'auto') {
                theme = (stored === 'dark' || stored === 'light')
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            }
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <?php Hooks::doAction('admin_head'); ?>
</head>
<body>
    <aside id="admin-sidebar">
        <div class="sidebar-header" style="display:flex; align-items:center; gap:12px; padding: 18px 16px;">
            <img src="assets/img/cmsbyphp-icon-64.png" alt="CMSbyPHP" class="brand-badge" style="width:36px; height:36px; border-radius:10px; object-fit:contain; flex-shrink:0;">
            <div style="display:flex; flex-direction:column; min-width:0;">
                <span class="brand-name" style="font-weight:800; font-size:16px; letter-spacing:-0.02em; color:var(--text-sidebar-bright, #fff);">CMSbyPHP</span>
                <span style="font-size:11px; color:#94a3b8; font-weight:600;">v1.0 &bull; <?= _e('Personal Blog') ?></span>
            </div>
            <button type="button" id="mobile-sidebar-close" class="mobile-sidebar-close-btn" aria-label="<?= _e('Close menu') ?>" style="margin-left: auto; background: none; border: none; color: #94a3b8; font-size: 24px; cursor: pointer; padding: 4px 8px; line-height: 1;">&times;</button>
        </div>
        <nav class="sidebar-nav">
            <span class="nav-section-title"><?= _e('Navigation') ?></span>
            
            <!-- Kokpit / Dashboard (WordPress Style) -->
            <div class="nav-group <?= $isDashboardGroup ? 'open' : '' ?>">
                <a href="index.php" class="nav-link <?= $isDashboardGroup ? 'active' : '' ?>">
                    <div class="nav-link-main">
                        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        <span class="nav-text"><?= _e('Dashboard') ?></span>
                    </div>
                    <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </a>
                <div class="nav-submenu">
                    <a href="index.php" class="nav-sublink <?= $currentPage === 'index.php' ? 'active' : '' ?>"><?= _e('Dashboard') ?></a>
                    <?php if (Auth::can('manage_settings')): ?>
                        <a href="updates.php" class="nav-sublink <?= $currentPage === 'updates.php' ? 'active' : '' ?>"><?= _e('Updates') ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Wpisy / Posts -->
            <?php if (Auth::can('manage_pages')): ?>
                <div class="nav-group <?= $isPostsGroup ? 'open' : '' ?>">
                    <a href="posts.php" class="nav-link <?= $isPostsGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                            <span class="nav-text"><?= _e('Posts') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="posts.php" class="nav-sublink <?= $currentPage === 'posts.php' ? 'active' : '' ?>"><?= _e('All Posts') ?></a>
                        <a href="page-edit.php?type=post" class="nav-sublink <?= ($currentPage === 'page-edit.php' && $editType !== 'page' && !isset($_GET['id'])) ? 'active' : '' ?>"><?= _e('Add New') ?></a>
                        <a href="categories.php" class="nav-sublink <?= $currentPage === 'categories.php' ? 'active' : '' ?>"><?= _e('Categories') ?></a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Media -->
            <div class="nav-group <?= $isMediaGroup ? 'open' : '' ?>">
                <a href="media.php" class="nav-link <?= $isMediaGroup ? 'active' : '' ?>">
                    <div class="nav-link-main">
                        <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span class="nav-text"><?= _e('Media') ?></span>
                    </div>
                    <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </a>
                <div class="nav-submenu">
                    <a href="media.php" class="nav-sublink <?= $currentPage === 'media.php' ? 'active' : '' ?>"><?= _e('Media Library') ?></a>
                </div>
            </div>

            <!-- Strony / Pages -->
            <?php if (Auth::can('manage_pages')): ?>
                <div class="nav-group <?= $isPagesGroup ? 'open' : '' ?>">
                    <a href="pages.php" class="nav-link <?= $isPagesGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <span class="nav-text"><?= _e('Pages') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="pages.php" class="nav-sublink <?= $currentPage === 'pages.php' ? 'active' : '' ?>"><?= _e('All Pages') ?></a>
                        <a href="page-edit.php?type=page" class="nav-sublink <?= ($currentPage === 'page-edit.php' && $editType === 'page' && !isset($_GET['id'])) ? 'active' : '' ?>"><?= _e('Add New') ?></a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (Auth::can('manage_settings')): ?>
                <span class="nav-section-title" style="margin-top: 14px;"><?= _e('Management') ?></span>

                <!-- Wygląd / Appearance -->
                <div class="nav-group <?= $isAppearanceGroup ? 'open' : '' ?>">
                    <a href="themes.php" class="nav-link <?= $isAppearanceGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                            <span class="nav-text"><?= _e('Appearance') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="themes.php" class="nav-sublink <?= $currentPage === 'themes.php' ? 'active' : '' ?>"><?= _e('Themes') ?></a>
                        <a href="customize.php" class="nav-sublink <?= $currentPage === 'customize.php' ? 'active' : '' ?>"><?= _e('Customize') ?></a>
                        <a href="menus.php" class="nav-sublink <?= $currentPage === 'menus.php' ? 'active' : '' ?>"><?= _e('Menus') ?></a>
                        <a href="theme-edit.php" class="nav-sublink <?= $currentPage === 'theme-edit.php' ? 'active' : '' ?>"><?= _e('Theme Editor') ?></a>
                    </div>
                </div>

                <!-- Wtyczki / Plugins -->
                <div class="nav-group <?= $isPluginsGroup ? 'open' : '' ?>">
                    <a href="plugins.php" class="nav-link <?= $isPluginsGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg>
                            <span class="nav-text"><?= _e('Plugins') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="plugins.php" class="nav-sublink <?= ($currentPage === 'plugins.php' && empty($_GET['id'])) ? 'active' : '' ?>"><?= _e('Plugins') ?></a>
                    </div>
                </div>

                <!-- Użytkownicy / Users -->
                <div class="nav-group <?= $isUsersGroup ? 'open' : '' ?>">
                    <a href="users.php" class="nav-link <?= $isUsersGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            <span class="nav-text"><?= _e('Users') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="users.php" class="nav-sublink <?= $currentPage === 'users.php' ? 'active' : '' ?>"><?= _e('All Users') ?></a>
                        <a href="profile.php" class="nav-sublink <?= $currentPage === 'profile.php' ? 'active' : '' ?>"><?= _e('Profile') ?></a>
                    </div>
                </div>

                <!-- Narzędzia / Tools -->
                <div class="nav-group <?= $isToolsGroup ? 'open' : '' ?>">
                    <a href="snippets.php" class="nav-link <?= $isToolsGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span class="nav-text"><?= _e('Tools') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="snippets.php" class="nav-sublink <?= $currentPage === 'snippets.php' ? 'active' : '' ?>"><?= _e('Snippets') ?></a>
                        <a href="backup.php" class="nav-sublink <?= $currentPage === 'backup.php' ? 'active' : '' ?>"><?= _e('Backups') ?></a>
                    </div>
                </div>

                <!-- Ustawienia / Settings -->
                <div class="nav-group <?= $isSettingsGroup ? 'open' : '' ?>">
                    <a href="settings.php" class="nav-link <?= $isSettingsGroup ? 'active' : '' ?>">
                        <div class="nav-link-main">
                            <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                            <span class="nav-text"><?= _e('Settings') ?></span>
                        </div>
                        <svg class="nav-arrow" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                    </a>
                    <div class="nav-submenu">
                        <a href="settings.php" class="nav-sublink <?= $currentPage === 'settings.php' ? 'active' : '' ?>"><?= _e('Settings') ?></a>
                        <a href="languages.php" class="nav-sublink <?= $currentPage === 'languages.php' ? 'active' : '' ?>"><?= _e('Languages') ?></a>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <button id="collapse-trigger" class="collapse-trigger" type="button" title="<?= _e('Collapse / Expand menu') ?>">
                <svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
            </button>
        </div>
    </aside>

    <div id="sidebar-overlay"></div>

    <div id="main-wrapper">
        <header id="topbar">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0;">
                <button type="button" id="mobile-menu-btn" class="mobile-menu-btn" aria-label="<?= _e('Mobile menu') ?>">
                    <svg style="width: 20px; height: 20px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="topbar-visit" style="display: flex; align-items: center; gap: 8px;">
                    <a href="<?= site_url('', false) ?>" target="_blank" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;"><?= _e('Visit Site') ?> ↗</a>
                    <a href="page-edit.php?type=post" class="btn btn-primary" style="font-size: 12px; padding: 6px 14px; font-weight: 600;">+ <?= _e('New Post') ?></a>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 14px;">
                <!-- Dark Mode Toggle Button -->
                <button type="button" id="theme-toggle" class="theme-toggle-btn" title="<?= _e('Toggle dark / light theme') ?>">
                    <svg id="theme-icon-moon" style="width: 16px; height: 16px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    <svg id="theme-icon-sun" style="width: 16px; height: 16px; display: none;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </button>

                <a href="profile.php" style="text-decoration:none; color:inherit;" class="user-profile">
                    <div class="user-avatar" style="background: linear-gradient(135deg, #2563eb, #6366f1); color: #fff;"><?= strtoupper(substr($_SESSION['user_name'] ?? 'C', 0, 1)) ?></div>
                    <span style="font-weight: 600;"><?= htmlspecialchars($_SESSION['user_name'] ?? 'czupryniak', ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="badge" style="background: rgba(37, 99, 235, 0.12); color: #2563eb; font-size:11px; font-weight:700;"><?= strtoupper(Auth::role()) ?></span>
                </a>
                <a href="logout.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;"><?= _e('Logout') ?></a>
            </div>
        </header>
        <main class="content-area">
