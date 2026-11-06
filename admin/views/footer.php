<?php
use Core\Hooks;
?>
            <footer style="margin-top: 40px; padding: 20px 0; border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted); flex-wrap: wrap; gap: 8px;">
                <span><strong>CMSbyPHP v<?= \Core\Version::CURRENT ?></strong> &bull; Lekki, szybki i niezależny CMS</span>
                <span>Zalogowano jako: <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'czupryniak', ENT_QUOTES, 'UTF-8') ?></strong></span>
            </footer>
        </main>
    </div>

    <!-- Global UI translations (localized labels for ui.js modals) -->
    <script>
        window.CMSBYPHP_UI = window.MODO_UI = {
            confirm: <?= json_encode(__('Confirm')) ?>,
            cancel: <?= json_encode(__('Cancel')) ?>,
            ok: <?= json_encode(__('OK')) ?>,
            areYouSure: <?= json_encode(__('Are you sure?')) ?>
        };
    </script>

    <!-- Theme Toggle & Admin Global Scripts -->
    <script src="assets/js/ui.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/ui.js') ?>"></script>
    <script src="assets/js/admin.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('theme-toggle');
        const sunIcon = document.getElementById('theme-icon-sun');
        const moonIcon = document.getElementById('theme-icon-moon');

        const updateThemeUI = () => {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            if (sunIcon && moonIcon) {
                if (currentTheme === 'dark') {
                    sunIcon.style.display = 'inline-block';
                    moonIcon.style.display = 'none';
                } else {
                    sunIcon.style.display = 'none';
                    moonIcon.style.display = 'inline-block';
                }
            }
        };

        const saveThemePreference = (theme) => {
            const body = new URLSearchParams();
            body.set('theme', theme);
            body.set('csrf_token', window.CMSBYPHP_CSRF || window.MODO_CSRF || '');

            return fetch('theme.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).catch(() => null);
        };

        if (toggleBtn) {
            updateThemeUI();

            toggleBtn.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
                const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';

                document.documentElement.setAttribute('data-theme', nextTheme);
                try { localStorage.setItem('cmsbyphp_theme', nextTheme); } catch (e) {}
                updateThemeUI();
                saveThemePreference(nextTheme);
            });
        }
    });
    </script>

    <?php Hooks::doAction('admin_footer'); ?>
</body>
</html>
