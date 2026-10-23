<?php
/**
 * CMSbyPHP Clean Theme — 404.php
 */
require __DIR__ . '/header.php';
?>

<div class="container" style="padding: 100px 24px; text-align: center;">
    <div style="font-size: 72px; font-weight: 800; color: var(--accent); line-height: 1; margin-bottom: 20px;">404</div>
    <h1 style="font-size: 28px; font-weight: 800; margin-bottom: 16px;">Nie znaleziono strony</h1>
    <p style="color: var(--text-muted); font-size: 16px; max-width: 500px; margin: 0 auto 30px;">
        Strona, której szukasz, mogła zostać przeniesiona, usunięta lub adres został wpisany z błędem.
    </p>
    <div style="display:flex; justify-content:center; gap:12px;">
        <a href="<?= site_url('', false) ?>" class="btn btn-primary">&larr; Wróć na stronę główną</a>
        <a href="<?= site_url('blog', false) ?>" class="btn btn-secondary">Przejdź do bloga</a>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
