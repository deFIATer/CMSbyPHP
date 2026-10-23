<?php
/**
 * CMSbyPHP Clean Theme — single.php
 */
require __DIR__ . '/header.php';

$cats = get_post_categories();
$primaryCat = !empty($cats) ? $cats[0] : null;
$adjacent = get_adjacent_posts();
$currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '');
?>

<div class="single-post-wrapper">
    <div class="container">
        <!-- Breadcrumbs -->
        <nav class="breadcrumbs" aria-label="Ścieżka powrotna">
            <a href="<?= site_url('', false) ?>">Główna</a>
            <span>&rsaquo;</span>
            <a href="<?= site_url('blog', false) ?>">Blog</a>
            <?php if ($primaryCat): ?>
                <span>&rsaquo;</span>
                <a href="<?= site_url('blog/category/' . $primaryCat['slug'], false) ?>"><?= htmlspecialchars($primaryCat['name'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endif; ?>
        </nav>

        <!-- Post Header -->
        <header class="post-header">
            <?php if ($primaryCat): ?>
                <div style="margin-bottom: 12px;">
                    <a href="<?= site_url('blog/category/' . $primaryCat['slug'], false) ?>" class="badge-category"><?= htmlspecialchars($primaryCat['name'], ENT_QUOTES, 'UTF-8') ?></a>
                </div>
            <?php endif; ?>

            <h1 class="post-hero-title"><?php page_title(); ?></h1>

            <div class="post-meta-details">
                <span>📅 <?php page_date('d.m.Y'); ?></span>
                <span>&bull;</span>
                <span>✍️ <?php page_author(); ?></span>
                <span>&bull;</span>
                <span>⏱️ <?= reading_time(null, false) ?></span>
            </div>
        </header>

        <!-- Featured Image -->
        <?php if (has_image()): ?>
            <div class="post-featured-banner">
                <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>" width="900" height="480">
            </div>
        <?php endif; ?>

        <!-- Post Prose Content -->
        <article class="post-prose">
            <?php page_content(); ?>
        </article>

        <!-- Tags and Share -->
        <div class="post-footer-meta">
            <?php if (has_tags()): ?>
                <div class="tag-cloud">
                    <span style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--text-muted); align-self:center; margin-right:4px;">Tagi:</span>
                    <?php post_tags(null, 'd-flex gap-2'); ?>
                </div>
            <?php endif; ?>

            <div class="share-bar">
                <span style="font-size:14px; font-weight:700; color:var(--text-main);">Spodobał Ci się ten artykuł?</span>
                <div class="share-links">
                    <button type="button" class="btn btn-secondary btn-sm" data-copy-url="<?= htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8') ?>">
                        🔗 Kopiuj link
                    </button>
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($currentUrl) ?>" 
                       target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                        LinkedIn
                    </a>
                    <a href="https://twitter.com/intent/tweet?url=<?= urlencode($currentUrl) ?>&text=<?= urlencode(ThemeState::$currentPage['title'] ?? '') ?>" 
                       target="_blank" rel="noopener" class="btn btn-secondary btn-sm">
                        X / Twitter
                    </a>
                </div>
            </div>
        </div>

        <!-- Author Bio Box -->
        <div class="author-box">
            <img src="<?= htmlspecialchars(resolve_media_url('uploads/czupryniak-avatar.svg'), ENT_QUOTES, 'UTF-8') ?>" 
                 alt="Mateusz Czupryniak" class="author-box-avatar" width="80" height="80">
            <div>
                <h3 style="font-size: 17px; font-weight: 700; margin-bottom: 6px;">Mateusz Czupryniak</h3>
                <p style="font-size: 14px; color: var(--text-muted); line-height: 1.6; margin-bottom: 12px;">
                    Ekspert ds. automatyzacji procesów i wdrożeń sztucznej inteligencji. Na co dzień przekładam skomplikowane technologie na wymierne oszczędności czasu i kosztów w firmach.
                </p>
                <a href="<?= site_url('kontakt', false) ?>" class="btn btn-secondary btn-sm">Skontaktuj się ze mną &rarr;</a>
            </div>
        </div>

        <!-- Adjacent Posts Navigation -->
        <?php if (!empty($adjacent['prev']) || !empty($adjacent['next'])): ?>
            <nav class="adjacent-nav" aria-label="Nawigacja pomiędzy wpisami">
                <?php if (!empty($adjacent['prev'])): 
                    $pUrl = site_url('blog/' . $adjacent['prev']['slug'], false);
                ?>
                    <a href="<?= $pUrl ?>" class="adjacent-card">
                        <span class="adjacent-label">&larr; Poprzedni wpis</span>
                        <span class="adjacent-title"><?= htmlspecialchars($adjacent['prev']['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php else: ?>
                    <div></div>
                <?php endif; ?>

                <?php if (!empty($adjacent['next'])): 
                    $nUrl = site_url('blog/' . $adjacent['next']['slug'], false);
                ?>
                    <a href="<?= $nUrl ?>" class="adjacent-card" style="text-align: right;">
                        <span class="adjacent-label">Następny wpis &rarr;</span>
                        <span class="adjacent-title"><?= htmlspecialchars($adjacent['next']['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
