<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;
use Core\Updater;
use Core\I18n;

Auth::requireCapability('manage_settings');

$msg = '';
$msgType = 'success';
$updateResult = null;

// Handle manual check request
if (isset($_GET['action']) && $_GET['action'] === 'check') {
    Updater::check(true);
    header('Location: updates.php?checked=1');
    exit;
}

// Handle update execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'do_update') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Błąd weryfikacji tokenu CSRF.');
    }

    $autoBackup = !empty($_POST['auto_backup']);
    $updateResult = Updater::update($autoBackup);

    if ($updateResult['success']) {
        $msg = $updateResult['message'];
        $msgType = 'success';
    } else {
        $msg = $updateResult['message'];
        $msgType = 'danger';
    }
}

$status = Updater::check();
$lastCheckedTime = isset($status['checked_at']) ? date('d.m.Y H:i', (int)$status['checked_at']) : 'Nigdy';

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <h1 class="page-title" style="margin: 0;"><?= _e('CMSbyPHP Updates') ?></h1>
            <span class="badge" style="background: rgba(37, 99, 235, 0.12); color: #2563eb; font-weight: 700; font-size: 11px;">v<?= Updater::VERSION ?></span>
        </div>
        <p style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
            <?= _e('Check for system updates, review release changelogs and perform safe 1-click updates with automatic backup.') ?>
        </p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="updates.php?action=check" class="btn btn-secondary" style="font-size: 13px;">
            <svg style="width: 14px; height: 14px; margin-right: 6px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <?= _e('Check Again') ?>
        </a>
    </div>
</div>

<?php if (isset($_GET['checked'])): ?>
    <div class="card" style="border-left: 4px solid var(--primary); background: rgba(37, 99, 235, 0.08); padding: 12px 16px; margin-bottom: 20px; font-weight: 500; font-size: 13px;">
        ✓ <?= _e('Successfully checked for updates with GitHub.') ?> (<?= date('H:i:s') ?>)
    </div>
<?php endif; ?>

<?php if ($msg): ?>
    <div class="card" style="border-left: 4px solid <?= $msgType === 'success' ? 'var(--success, #10b981)' : 'var(--danger, #ef4444)' ?>; background: <?= $msgType === 'success' ? 'var(--success-subtle, rgba(16, 185, 129, 0.1))' : 'rgba(239, 68, 68, 0.1)' ?>; padding: 14px 18px; margin-bottom: 20px;">
        <strong style="color: <?= $msgType === 'success' ? 'var(--success, #10b981)' : 'var(--danger, #ef4444)' ?>; font-size: 14px;"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></strong>
        <?php if (!empty($updateResult['backup_file'])): ?>
            <p style="margin: 6px 0 0; font-size: 12px; color: var(--text-muted);">
                <?= _e('Backup file created:') ?> <code><?= htmlspecialchars(basename($updateResult['backup_file']), ENT_QUOTES, 'UTF-8') ?></code> (<a href="backup.php" style="color: var(--primary);"><?= _e('View Backups') ?> &rarr;</a>)
            </p>
        <?php endif; ?>
        <?php if (!empty($updateResult['log'])): ?>
            <div style="margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.2); border-radius: 6px; font-family: monospace; font-size: 11px; max-height: 140px; overflow-y: auto;">
                <?php foreach ($updateResult['log'] as $l): ?>
                    <div>&bull; <?= htmlspecialchars($l, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Status Card (WordPress Style) -->
<div class="card" style="margin-bottom: 24px; padding: 24px;">
    <?php if ($status['has_update']): ?>
        <!-- Update Available Banner -->
        <div style="display: flex; gap: 16px; align-items: flex-start; margin-bottom: 20px;">
            <div style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245, 158, 11, 0.15); color: #f59e0b; display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0;">
                ⚡
            </div>
            <div>
                <h2 style="font-size: 18px; font-weight: 700; margin: 0 0 6px; color: var(--text-main);">
                    <?= _e('A new version of CMSbyPHP is available!') ?>
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin: 0; line-height: 1.5;">
                    <?= _e('Installed version:') ?> <strong><?= Updater::VERSION ?></strong> &bull; 
                    <?= _e('Latest version:') ?> <strong style="color: #10b981;"><?= htmlspecialchars($status['latest_version'], ENT_QUOTES, 'UTF-8') ?></strong>
                </p>
            </div>
        </div>

        <?php if (!empty($status['release_body'])): ?>
            <div style="background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;">
                <span style="font-size: 11px; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 0.05em;"><?= _e('Release Notes') ?>:</span>
                <div style="font-size: 13px; color: var(--text-main); margin-top: 6px; white-space: pre-wrap; line-height: 1.6;">
                    <?= htmlspecialchars($status['release_body'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="" onsubmit="return confirm('<?= _e('Proceed with the automatic CMSbyPHP update?') ?>');">
            <input type="hidden" name="action" value="do_update">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div style="margin-bottom: 18px; padding: 12px 14px; background: rgba(16, 185, 129, 0.08); border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.2);">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-main);">
                    <input type="checkbox" name="auto_backup" value="1" checked style="width: 17px; height: 17px; cursor: pointer;">
                    <span><?= _e('Automatically create a full backup (database & files) before updating (Recommended)') ?></span>
                </label>
                <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0 27px;">
                    <?= _e('A portable ZIP snapshot will be saved in data/backups/ allowing instant rollback if needed.') ?>
                </p>
            </div>

            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-size: 14px; font-weight: 600;">
                ⚡ <?= _e('Update CMSbyPHP Now') ?>
            </button>
        </form>

    <?php else: ?>
        <!-- Up To Date State -->
        <div style="display: flex; gap: 16px; align-items: center;">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(16, 185, 129, 0.15); color: #10b981; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0;">
                ✓
            </div>
            <div>
                <h2 style="font-size: 17px; font-weight: 700; margin: 0 0 4px; color: var(--text-main);">
                    <?= _e('You have the latest version of CMSbyPHP.') ?>
                </h2>
                <p style="font-size: 13px; color: var(--text-muted); margin: 0;">
                    <?= _e('Current version:') ?> <strong><?= Updater::VERSION ?></strong> &bull; <?= _e('Last checked:') ?> <?= $lastCheckedTime ?>
                </p>
            </div>
        </div>

        <hr style="margin: 20px 0; border: none; border-top: 1px solid var(--border-subtle);">

        <form method="POST" action="" onsubmit="return confirm('<?= _e('Re-install current version from GitHub?') ?>');">
            <input type="hidden" name="action" value="do_update">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 12px; color: var(--text-muted);">
                    <input type="checkbox" name="auto_backup" value="1" checked style="cursor: pointer;">
                    <span><?= _e('Create a backup before re-installing') ?></span>
                </label>
                <button type="submit" class="btn btn-secondary" style="font-size: 12px; padding: 6px 14px;">
                    🔄 <?= _e('Re-install version') ?> <?= Updater::VERSION ?>
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<!-- GitHub Source & Integrity Info -->
<div class="card" style="padding: 20px;">
    <h3 style="font-size: 14px; font-weight: 700; margin: 0 0 10px; color: var(--text-main);">
        <?= _e('Official Source Repository') ?>
    </h3>
    <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 14px; line-height: 1.5;">
        CMSbyPHP pobiera oficjalne wydania i aktualizacje bezpośrednio z publicznego repozytorium GitHub.
    </p>
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <a href="https://github.com/<?= htmlspecialchars(Updater::GITHUB_REPO, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="font-size: 12px;">
            <svg style="width: 14px; height: 14px; margin-right: 6px;" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
            github.com/<?= htmlspecialchars(Updater::GITHUB_REPO, ENT_QUOTES, 'UTF-8') ?> ↗
        </a>
        <span style="font-size: 12px; color: var(--text-muted);">
            Gałąź bazowa: <strong>main</strong> &bull; Protokół: <strong>HTTPS / GitHub API v3</strong>
        </span>
    </div>
</div>

<?php require_once __DIR__ . '/views/footer.php'; ?>
