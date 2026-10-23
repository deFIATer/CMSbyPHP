<?php
/**
 * CMSbyPHP Clean Theme — front-page.php
 */
require __DIR__ . '/header.php';
$recentPosts = get_recent_posts(6);
?>

<section class="hero-section">
    <div class="container">
        <div class="hero-grid">
            <img src="<?= htmlspecialchars(resolve_media_url('uploads/czupryniak-avatar.svg'), ENT_QUOTES, 'UTF-8') ?>" 
                 alt="Mateusz Czupryniak" class="hero-avatar" width="170" height="170">
            <div>
                <div class="hero-badge">⚡ Osobisty Blog Technologiczny</div>
                <h1 class="hero-title">Cześć! Nazywam się Mateusz Czupryniak.</h1>
                <p class="hero-desc">
                    Pomagam przedsiębiorcom i zespołom wykorzystywać <strong>sztuczną inteligencję</strong> oraz <strong>automatyzacje bezkodowe</strong>, by eliminować powtarzalną rutynę i budować realną przewagę rynkową.
                </p>
                <div class="hero-cta-group">
                    <a href="<?= site_url('blog', false) ?>" class="btn btn-primary">Przeglądaj artykuły &rarr;</a>
                    <a href="<?= site_url('o-mnie', false) ?>" class="btn btn-secondary">Więcej o mnie</a>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pillars-section">
    <div class="container">
        <div class="section-header">
            <span class="section-subtitle">Główne obszary tematyczne</span>
            <h2 class="section-title">O czym tutaj piszę?</h2>
        </div>
        <div class="pillars-grid">
            <div class="pillar-card">
                <div class="pillar-icon">🤖</div>
                <h3 class="pillar-title">Sztuczna Inteligencja</h3>
                <p class="pillar-desc">Praktyczne zastosowania modeli LLM, prompt chaining, asystenci wiedzy firmowej i automatyzacja researchu bez marketingowego szumu.</p>
                <a href="<?= site_url('blog/category/ai', false) ?>" class="btn btn-secondary btn-sm">Wpisy z tej kategorii &rarr;</a>
            </div>

            <div class="pillar-card">
                <div class="pillar-icon">⚙️</div>
                <h3 class="pillar-title">Automatyzacje w firmie</h3>
                <p class="pillar-desc">Łączenie narzędzi przez API, Make, n8n i budowanie stabilnych procesów, które działają 24/7 i uwalniają dziesiątki godzin miesięcznie.</p>
                <a href="<?= site_url('blog/category/automatyzacje', false) ?>" class="btn btn-secondary btn-sm">Wpisy z tej kategorii &rarr;</a>
            </div>

            <div class="pillar-card">
                <div class="pillar-icon">⭐</div>
                <h3 class="pillar-title">Co warto (Narzędzia)</h3>
                <p class="pillar-desc">Szczere, przetestowane w boju rekomendacje aplikacji, SaaS i rozwiązań minimalizujących chaos cyfrowy w jednoosobowych i małych firmach.</p>
                <a href="<?= site_url('blog/category/co-warto', false) ?>" class="btn btn-secondary btn-sm">Wpisy z tej kategorii &rarr;</a>
            </div>
        </div>
    </div>
</section>

<section class="posts-section">
    <div class="container">
        <div class="section-header" style="display:flex; justify-content:space-between; align-items:flex-end; text-align:left; flex-wrap:wrap; gap:16px;">
            <div>
                <span class="section-subtitle">Najnowsze publikacje</span>
                <h2 class="section-title" style="margin-bottom:0;">Ostatnie wpisy</h2>
            </div>
            <a href="<?= site_url('blog', false) ?>" class="btn btn-secondary btn-sm">Zobacz wszystkie wpisy &rarr;</a>
        </div>

        <?php if (!empty($recentPosts)): ?>
            <div class="posts-grid" style="margin-top: 36px;">
                <?php foreach ($recentPosts as $post): 
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
                            <h3 class="post-card-title">
                                <a href="<?= $postUrl ?>"><?= htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8') ?></a>
                            </h3>
                            <p class="post-card-excerpt">
                                <?= htmlspecialchars(content_to_text($post['content'] ?? '', 120), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <div class="post-card-footer">
                                <a href="<?= $postUrl ?>" class="btn btn-secondary btn-sm" style="width:100%; text-align:center;">Czytaj artykuł &rarr;</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align:center; color:var(--text-muted); padding:40px 0;">Brak wpisów do wyświetlenia.</p>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
