<?php
/**
 * CMSbyPHP Clean Theme — page.php
 */
require __DIR__ . '/header.php';
?>

<div class="container" style="padding-top: 50px; padding-bottom: 80px;">
    <div style="max-width: var(--max-w-prose); margin: 0 auto;">
        <nav class="breadcrumbs" aria-label="Ścieżka powrotna">
            <a href="<?= site_url('', false) ?>">Główna</a>
            <span>&rsaquo;</span>
            <span><?php page_title(); ?></span>
        </nav>

        <header style="margin-bottom: 36px; text-align: center;">
            <h1 class="hero-title"><?php page_title(); ?></h1>
        </header>

        <?php if (has_image()): ?>
            <div style="margin-bottom: 36px; border-radius: var(--radius-md); overflow: hidden; border: 1px solid var(--border-color);">
                <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>" style="width: 100%; height: auto;">
            </div>
        <?php endif; ?>

        <div class="post-prose">
            <?php page_content(); ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
