<?php
/**
 * CMSbyPHP Clean Theme — footer.php
 */
$themeUrl = htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8');
?>
    </main>

    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid">
                <div>
                    <div class="brand" style="margin-bottom: 14px;">
                        <img src="<?= htmlspecialchars(site_url('site/assets/img/cmsbyphp-icon-64.png', false), ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="brand-badge" style="width:32px; height:32px; object-fit:contain; border-radius:8px;">
                        <span class="brand-title" style="font-size: 17px;"><?= site_title(false) ?></span>
                    </div>
                    <p style="color: var(--text-muted); font-size: 14px; line-height: 1.6; margin-bottom: 16px;">
                        Osobisty notatnik technologiczny Mateusza Czupryniaka. Konkretna wiedza o AI, automatyzacjach no-code i narzędziach, które realnie dają przewagę.
                    </p>
                    <a href="<?= site_url('feed', false) ?>" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 5c7.18 0 13 5.82 13 13M6 11a7 7 0 017 7m-6 0a1 1 0 11-2 0 1 1 0 012 0z"/>
                        </svg>
                        Kanał RSS (.xml)
                    </a>
                </div>

                <div>
                    <h4 class="footer-heading">Nawigacja</h4>
                    <ul class="footer-list">
                        <li><a href="<?= site_url('', false) ?>">Strona główna</a></li>
                        <li><a href="<?= site_url('blog', false) ?>">Wszystkie wpisy</a></li>
                        <li><a href="<?= site_url('o-mnie', false) ?>">O mnie</a></li>
                        <li><a href="<?= site_url('kontakt', false) ?>">Kontakt</a></li>
                        <li><a href="<?= site_url('admin/', false) ?>" target="_blank">Panel CMSbyPHP &rarr;</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="footer-heading">Kategorie</h4>
                    <ul class="footer-list">
                        <li><a href="<?= site_url('blog/category/ai', false) ?>">🤖 Sztuczna Inteligencja</a></li>
                        <li><a href="<?= site_url('blog/category/automatyzacje', false) ?>">⚙️ Automatyzacje w firmie</a></li>
                        <li><a href="<?= site_url('blog/category/co-warto', false) ?>">⭐ Co warto (Narzędzia)</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <span>&copy; <?= date('Y') ?> <?= site_title(false) ?>. Wszelkie prawa zastrzeżone.</span>
                <span>Zbudowano na <strong>CMSbyPHP</strong> &bull; Lekki, szybki i niezależny CMS</span>
            </div>
        </div>
    </footer>

    <button id="backToTop" class="btn btn-primary btn-sm btn-back-to-top" aria-label="Wróć do góry">
        ↑ Na górę
    </button>

    <script src="<?= $themeUrl ?>/assets/js/main.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/main.js') ?>"></script>
    <?php theme_footer(); ?>
</body>
</html>
