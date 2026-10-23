<?php
/**
 * CMSbyPHP Clean Theme — archive.php
 */
require __DIR__ . '/header.php';
global $clean_posts_iterator;

$title = !empty($archiveTitle) ? $archiveTitle : 'Wszystkie wpisy';
$currentUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>

<div class="container" style="padding-top: 40px; padding-bottom: 70px;">
    <div style="text-align: center; max-width: 680px; margin: 0 auto 36px;">
        <span class="section-subtitle">Baza wiedzy & publikacje</span>
        <h1 class="hero-title" style="margin-bottom: 12px;"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <p style="color: var(--text-muted); font-size: 16px;">
            Praktyczne przewodniki, analizy narzędzi i wdrożenia automatyzacji biznesowych.
        </p>
    </div>

    <!-- Live Filter Bar -->
    <div class="filter-bar">
        <div class="search-wrapper">
            <svg class="search-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" id="blog-search-input" class="search-input" 
                   placeholder="Szukaj wpisu (np. Make, n8n, AI, procesy, narzędzia)..." 
                   autocomplete="off">
        </div>

        <div class="category-pills">
            <a href="<?= site_url('blog', false) ?>" class="cat-pill <?= ($currentUrl === '/blog' || $currentUrl === '/blog/') ? 'active' : '' ?>">Wszystkie</a>
            <a href="<?= site_url('blog/category/ai', false) ?>" class="cat-pill <?= str_contains($currentUrl, '/category/ai') ? 'active' : '' ?>">🤖 AI</a>
            <a href="<?= site_url('blog/category/automatyzacje', false) ?>" class="cat-pill <?= str_contains($currentUrl, '/category/automatyzacje') ? 'active' : '' ?>">⚙️ Automatyzacje</a>
            <a href="<?= site_url('blog/category/co-warto', false) ?>" class="cat-pill <?= str_contains($currentUrl, '/category/co-warto') ? 'active' : '' ?>">⭐ Co warto</a>
        </div>
    </div>

    <div id="no-search-results" style="display:none; text-align:center; padding: 40px; color: var(--text-muted);">
        <p style="font-size: 18px; margin-bottom: 8px;">Nie znaleziono wpisów pasujących do Twojego zapytania.</p>
        <span style="font-size: 14px;">Spróbuj wpisać inne słowo kluczowe.</span>
    </div>

    <?php if ($clean_posts_iterator && $clean_posts_iterator->count() > 0): ?>
        <div class="posts-grid" id="posts-grid-container">
            <?php foreach ($clean_posts_iterator as $post): 
                $postUrl = htmlspecialchars(page_url($post, false), ENT_QUOTES, 'UTF-8');
                $imgUrl = !empty($post['featured_image']) ? resolve_media_url($post['featured_image']) : '';
                $cats = get_post_categories($post['id']);
                $cat = !empty($cats) ? $cats[0] : null;
            ?>
                <article class="post-card">
                    <?php if ($imgUrl !== ''): ?>
                        <a href="<?= $postUrl ?>" class="post-card-thumb-link">
                            <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" 
                                 alt="<?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                 class="post-card-thumb" loading="lazy">
                        </a>
                    <?php endif; ?>
                    <div class="post-card-body">
                        <div class="post-card-meta">
                            <?php if ($cat): ?>
                                <a href="<?= site_url('blog/category/' . $cat['slug'], false) ?>" class="badge-category"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></a>
                                <span>&bull;</span>
                            <?php endif; ?>
                            <span><?= date('d.m.Y', strtotime($post['created_at'] ?? 'now')) ?></span>
                            <span>&bull;</span>
                            <span class="reading-time-badge">⏱️ <?= reading_time($post['content'] ?? '', false) ?></span>
                        </div>
                        <h2 class="post-card-title">
                            <a href="<?= $postUrl ?>"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></a>
                        </h2>
                        <p class="post-card-excerpt">
                            <?= htmlspecialchars(content_to_text($post['content'] ?? '', 130), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="post-card-footer">
                            <a href="<?= $postUrl ?>" class="btn btn-secondary btn-sm" style="width:100%; text-align:center;">Czytaj wpis &rarr;</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if (isset($totalPages) && $totalPages > 1): ?>
            <div style="display:flex; justify-content:center; gap:8px; margin-top:50px;">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="btn <?= $i === $currentPage ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <p style="text-align:center; color:var(--text-muted); padding:60px 0;">Brak opublikowanych wpisów w tym widoku.</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
