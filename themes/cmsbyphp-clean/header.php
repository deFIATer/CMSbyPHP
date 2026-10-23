<?php
/**
 * CMSbyPHP Clean Theme — header.php
 */
if (!function_exists('site_title')) { die('CMSbyPHP theme'); }
$themeUrl = htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8');
$currentUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <link rel="icon" type="image/svg+xml" href="<?= site_url('site/assets/img/favicon.svg', false) ?>">
    <link rel="alternate icon" type="image/png" href="<?= site_url('site/assets/img/favicon.png', false) ?>">
    <link rel="apple-touch-icon" href="<?= site_url('site/assets/img/cmsbyphp-icon-192.png', false) ?>">

    <script>
        (function() {
            var saved = localStorage.getItem('cmsbyphp_theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            } else {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>

    <?php theme_head(); ?>

    <link rel="stylesheet" href="<?= $themeUrl ?>/assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="cmsbyphp-body">
    <div id="reading-progress"></div>

    <header class="site-header">
        <div class="container">
            <div class="header-inner">
                <a href="<?= htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') ?>" class="brand">
                    <img src="<?= htmlspecialchars(site_url('site/assets/img/cmsbyphp-icon-64.png', false), ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="brand-badge" style="width: 36px; height: 36px; object-fit: contain; border-radius: 8px;">
                    <div class="brand-info">
                        <span class="brand-title"><?= site_title(false) ?></span>
                        <?php if (site_desc(false) !== ''): ?>
                            <span class="brand-tagline"><?= site_desc(false) ?></span>
                        <?php endif; ?>
                    </div>
                </a>

                <nav class="nav-container" aria-label="Główna nawigacja">
                    <ul class="nav-links">
                        <li><a href="<?= site_url('', false) ?>" class="nav-link <?= ($currentUrl === '/' || $currentUrl === '') ? 'active' : '' ?>">Strona główna</a></li>
                        <li><a href="<?= site_url('blog', false) ?>" class="nav-link <?= str_starts_with($currentUrl, '/blog') ? 'active' : '' ?>">Blog</a></li>
                        <li><a href="<?= site_url('o-mnie', false) ?>" class="nav-link <?= str_starts_with($currentUrl, '/o-mnie') ? 'active' : '' ?>">O mnie</a></li>
                        <li><a href="<?= site_url('kontakt', false) ?>" class="nav-link <?= str_starts_with($currentUrl, '/kontakt') ? 'active' : '' ?>">Kontakt</a></li>
                    </ul>
                </nav>

                <div class="header-actions">
                    <a href="<?= site_url('feed', false) ?>" class="icon-btn" title="Subskrybuj kanał RSS" target="_blank" rel="noopener">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 5c7.18 0 13 5.82 13 13M6 11a7 7 0 017 7m-6 0a1 1 0 11-2 0 1 1 0 012 0z"/>
                        </svg>
                    </a>

                    <button type="button" id="theme-toggle" class="icon-btn" aria-label="Zmień motyw jasny/ciemny" title="Przełącz motyw">
                        <svg id="icon-moon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                        <svg id="icon-sun" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </button>

                    <button type="button" id="mobile-menu-toggle" class="icon-btn mobile-toggle" aria-label="Menu mobilne">
                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <main id="main-content" class="site-main">
