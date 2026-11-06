<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/views/header.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\Hooks;
use Core\Sitemap;
use Core\Router;
use Core\SiteHealth;

$db = Database::getConnection();
$msg = '';

// Handle removal of installer file (install.php)
if (isset($_GET['action']) && $_GET['action'] === 'remove_installer') {
    if (Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        $installerPath = dirname(__DIR__) . '/install.php';
        if (file_exists($installerPath)) {
            if (@unlink($installerPath)) {
                $msg = 'Plik instalatora (install.php) został pomyślnie usunięty ze względów bezpieczeństwa.';
            } else {
                $msg = 'Nie udało się automatycznie usunąć pliku install.php (sprawdź uprawnienia do zapisu).';
            }
        } else {
            $msg = 'Plik instalatora (install.php) nie istnieje na serwerze.';
        }
    }
}

// Handle Quick Draft submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_draft') {
    if (Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $draftTitle = trim($_POST['draft_title'] ?? '');
        $draftContent = trim($_POST['draft_content'] ?? '');
        $catId = (int)($_POST['category_id'] ?? 0);

        if ($draftTitle !== '') {
            // Transliterate Polish characters for cleaner slugs
            $plMap = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z','Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ź'=>'z','Ż'=>'z'];
            $cleanTitle = strtr($draftTitle, $plMap);
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $cleanTitle), '-'));

            $stmt = $db->prepare("INSERT INTO pages (title, slug, content, type, status, lang, translation_group, author_id) VALUES (:t, :s, :c, 'post', 'draft', :l, :g, :a)");
            $stmt->execute([
                ':t' => $draftTitle,
                ':s' => $slug ?: 'szkic-' . bin2hex(random_bytes(3)),
                ':c' => '<p>' . nl2br(htmlspecialchars($draftContent, ENT_QUOTES, 'UTF-8')) . '</p>',
                ':l' => \Core\I18n::getDefaultLocale(),
                ':g' => bin2hex(random_bytes(8)),
                ':a' => Auth::id()
            ]);
            $newPostId = (int)$db->lastInsertId();

            if ($catId > 0 && $newPostId > 0) {
                $cStmt = $db->prepare("INSERT INTO page_categories (page_id, category_id) VALUES (:p, :c)");
                $cStmt->execute([':p' => $newPostId, ':c' => $catId]);
            }

            // Automatyczna aktualizacja sitemapy Google
            if (class_exists('Core\Sitemap')) {
                try {
                    Sitemap::generate();
                } catch (\Throwable $e) {}
            }

            $msg = 'Szkic wpisu został pomyślnie zapisany!';
        }
    }
}

// 1. Metric counts
$totalPages = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND deleted_at IS NULL")->fetchColumn();
$totalPosts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND deleted_at IS NULL")->fetchColumn();
$totalCategories = (int)$db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalTags = (int)$db->query("SELECT COUNT(*) FROM tags")->fetchColumn();

// 2. Traffic analytics (today and last 7 days)
$todayVisits = (int)$db->query("SELECT COUNT(*) FROM visits WHERE visited_at = DATE('now')")->fetchColumn();
$weekVisits  = (int)$db->query("SELECT COUNT(*) FROM visits WHERE visited_at >= DATE('now', '-6 days')")->fetchColumn();

// 7-day sparkline bar chart data aggregation
$chartQuery = $db->query("
    SELECT d.dt as date, COUNT(v.id) as count 
    FROM (
        SELECT DATE('now', '-6 days') as dt UNION ALL
        SELECT DATE('now', '-5 days') UNION ALL
        SELECT DATE('now', '-4 days') UNION ALL
        SELECT DATE('now', '-3 days') UNION ALL
        SELECT DATE('now', '-2 days') UNION ALL
        SELECT DATE('now', '-1 days') UNION ALL
        SELECT DATE('now')
    ) d
    LEFT JOIN visits v ON v.visited_at = d.dt
    GROUP BY d.dt ORDER BY d.dt ASC
");
$chartData = $chartQuery->fetchAll();

// Top visited paths (last 7 days)
$topPages = $db->query("
    SELECT path, COUNT(*) as hits 
    FROM visits 
    WHERE visited_at >= DATE('now', '-6 days')
    GROUP BY path 
    ORDER BY hits DESC 
    LIMIT 5
")->fetchAll();

// 3. Recently updated pages and blog posts
$recentContent = $db->query("
    SELECT p.id, p.title, p.slug, p.type, p.status, p.updated_at, u.username as author_name 
    FROM pages p 
    LEFT JOIN users u ON p.author_id = u.id 
    WHERE p.deleted_at IS NULL
    ORDER BY p.updated_at DESC 
    LIMIT 6
")->fetchAll();

// Database file size calculation
$dbFilePath = __DIR__ . '/../data/cms.sqlite';
$dbFileSize = file_exists($dbFilePath) ? round(filesize($dbFilePath) / 1024, 1) . ' KB' : 'N/A';

// Fetch categories for quick draft
$categoriesList = $db->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll();

// Site Health checks
$healthRows = class_exists('Core\SiteHealth') ? SiteHealth::checks() : [];
$healthScore = !empty($healthRows) ? SiteHealth::score($healthRows) : 100;
$healthIssues = !empty($healthRows) ? SiteHealth::issues($healthRows) : [];
$healthOk = count($healthRows) - count($healthIssues);
?>

<div class="page-header">
    <div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <h1 class="page-title" style="margin-bottom: 2px;">Pulpit CMSbyPHP</h1>
            <span class="badge" style="background: rgba(37, 99, 235, 0.12); color: #2563eb; font-weight: 700; font-size: 11px;">v<?= \Core\Version::CURRENT ?></span>
        </div>
        <p style="color: var(--text-muted); font-size: 13px;">Witaj, <?= htmlspecialchars($_SESSION['user_name'] ?? 'czupryniak', ENT_QUOTES, 'UTF-8') ?>! Podsumowanie aktywności Twojego bloga i stan systemu.</p>
    </div>
    <div style="display:flex; gap: 8px;">
        <a href="page-edit.php?type=post" class="btn btn-primary">+ Nowy wpis</a>
        <a href="page-edit.php?type=page" class="btn btn-secondary">+ Nowa strona</a>
    </div>
</div>

<?php if ($msg): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px; font-weight: 600;">
        <?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- Site Health Overview Card -->
<?php if (!empty($healthRows)): ?>
<div class="card" style="padding: 18px 20px; margin-bottom: 22px; border-left: 4px solid <?= $healthScore >= 80 ? 'var(--success, #10b981)' : ($healthScore >= 50 ? 'var(--warning, #f59e0b)' : 'var(--danger, #ef4444)') ?>;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 12px;">
        <div>
            <h2 style="font-size: 15px; font-weight: 700; margin: 0 0 4px; color: var(--text-main);"><?= _e('Site Health') ?></h2>
            <p style="font-size: 12px; color: var(--text-muted); margin: 0;">
                <?= sprintf(__('%d of %d checks are healthy.'), $healthOk, count($healthRows)) ?>
            </p>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
            <strong style="font-size: 24px; color: <?= $healthScore >= 80 ? 'var(--success, #10b981)' : ($healthScore >= 50 ? 'var(--warning, #f59e0b)' : 'var(--danger, #ef4444)') ?>;"><?= (int)$healthScore ?>%</strong>
            <a href="settings.php?tab=security" class="btn btn-secondary" style="font-size: 12px;"><?= _e('System status') ?></a>
        </div>
    </div>

    <?php if (empty($healthIssues)): ?>
        <p style="font-size: 13px; color: var(--success, #10b981); margin: 0;"><?= _e('All checks passed. Your site is in good shape.') ?></p>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px;">
            <?php foreach (array_slice($healthIssues, 0, 4) as $issue): ?>
                <div style="display: flex; gap: 10px; align-items: flex-start; background: var(--bg-subtle, #1e293b); border: 1px solid var(--border-subtle); border-radius: 8px; padding: 10px 12px;">
                    <span style="width: 8px; height: 8px; border-radius: 50%; margin-top: 5px; flex: 0 0 auto; background: <?= $issue['status'] === 'error' ? 'var(--danger, #ef4444)' : 'var(--warning, #f59e0b)' ?>;"></span>
                    <div style="min-width: 0;">
                        <div style="font-size: 12px; font-weight: 700; color: var(--text-main);"><?= Security::sanitize($issue['label']) ?></div>
                        <div style="font-size: 11px; color: var(--text-muted); line-height: 1.45; margin-top: 2px;"><?= Security::sanitize($issue['detail']) ?></div>
                        <?php if (!empty($issue['action'])): ?>
                            <?php if ($issue['id'] === 'installer'): ?>
                                <a href="<?= htmlspecialchars($issue['action'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" style="font-size: 11px; padding: 3px 8px; margin-top: 6px; display: inline-flex; align-items: center; gap: 4px; font-weight: 600; color: #ef4444; border-color: rgba(239, 68, 68, 0.3);">
                                    🗑️ <?= _e('Fix (Delete install.php)') ?>
                                </a>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($issue['action'], ENT_QUOTES, 'UTF-8') ?>" style="font-size: 11px; color: var(--primary); display: inline-block; margin-top: 4px;"><?= _e('Fix') ?> &rarr;</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($healthIssues) > 4): ?>
            <p style="font-size: 11px; color: var(--text-muted); margin: 10px 0 0;"><?= sprintf(__('%d more check(s) need attention.'), count($healthIssues) - 4) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Primary Metrics Row -->
<div class="metrics-grid">
    <a href="posts.php" class="metric-card" style="text-decoration: none; cursor: pointer;">
        <span class="metric-label">Wpisy na blogu</span>
        <span class="metric-value" style="color: var(--primary);"><?= $totalPosts ?></span>
    </a>
    <a href="pages.php" class="metric-card" style="text-decoration: none; cursor: pointer;">
        <span class="metric-label">Strony statyczne</span>
        <span class="metric-value"><?= $totalPages ?></span>
    </a>
    <a href="categories.php" class="metric-card" style="text-decoration: none; cursor: pointer;">
        <span class="metric-label">Kategorie & Tagi</span>
        <span class="metric-value"><?= $totalCategories ?> <span style="font-size:16px; font-weight:normal; color:var(--text-muted);">/ <?= $totalTags ?></span></span>
    </a>
    <div class="metric-card">
        <span class="metric-label">Wizyty (Dziś / 7 dni)</span>
        <span class="metric-value"><?= $todayVisits ?> <span style="font-size:16px; font-weight:normal; color:var(--text-muted);">/ <?= $weekVisits ?></span></span>
    </div>
</div>

<!-- Dashboard Main Grid -->
<div class="dashboard-layout">

    <!-- LEFT COLUMN: Traffic Analytics & Recent Content -->
    <div>
        <!-- Analytics & Popular Pages Card -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px;">
                <h2 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;">Ruch na stronie (ostatnie 7 dni)</h2>
                <span class="badge" style="background: var(--primary-subtle); color: var(--primary);">Unikalne wizyty</span>
            </div>

            <!-- CSS Bar Chart (Zero dependencies) -->
            <?php
            $maxVisits = 1;
            foreach ($chartData as $cd) {
                if ($cd['count'] > $maxVisits) $maxVisits = $cd['count'];
            }
            ?>
            <div class="dashboard-chart" style="display: flex; align-items: flex-end; gap: 12px; height: 110px; padding-top: 15px; border-bottom: 1px solid var(--border-subtle);">
                <?php foreach ($chartData as $bar): 
                    $h = max(6, (int)(($bar['count'] / $maxVisits) * 85));
                    $dayLabel = date('d.m', strtotime($bar['date']));
                ?>
                    <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end;">
                        <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);"><?= $bar['count'] ?></span>
                        <div style="width: 100%; max-width: 32px; height: <?= $h ?>px; background: linear-gradient(180deg, var(--primary), #818cf8); border-radius: 4px 4px 0 0;" title="<?= $bar['date'] ?>: <?= $bar['count'] ?> wyświetleń"></div>
                        <span style="font-size: 10px; color: var(--text-muted);"><?= $dayLabel ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Top Visited Routes -->
            <div style="margin-top: 16px;">
                <span style="font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">Najczęściej odwiedzane strony</span>
                <table class="pro-table" style="margin-top: 8px;">
                    <tbody>
                        <?php if (empty($topPages)): ?>
                            <tr><td style="color: var(--text-muted); font-size: 12px; padding: 12px 0; border: none;">Brak zarejestrowanych wyświetleń w tym tygodniu.</td></tr>
                        <?php else: ?>
                            <?php foreach ($topPages as $tp): ?>
                                <tr>
                                    <td style="font-family: monospace; font-size: 12px; padding: 6px 0; border: none; color: var(--text-main);"><?= Security::sanitize($tp['path']) ?></td>
                                    <td style="text-align: right; font-weight: 600; padding: 6px 0; border: none; color: var(--text-main);"><?= $tp['hits'] ?> wizyt</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Content Card -->
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 14px;">
                <h2 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;">Ostatnio edytowane</h2>
                <div style="display: flex; gap: 12px; font-size: 12px;">
                    <a href="posts.php" style="color: var(--primary); text-decoration: none; font-weight: 600;">Wpisy &rarr;</a>
                    <a href="pages.php" style="color: var(--text-muted); text-decoration: none; font-weight: 600;">Strony &rarr;</a>
                </div>
            </div>

            <div class="table-container" style="border: none; box-shadow: none;">
                <table class="pro-table">
                    <thead>
                        <tr>
                            <th>Tytuł</th>
                            <th>Typ</th>
                            <th>Status</th>
                            <th>Zaktualizowano</th>
                            <th style="text-align: right;">Akcja</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentContent as $rc): ?>
                            <tr>
                                <td>
                                    <strong><a href="page-edit.php?id=<?= $rc['id'] ?>" style="color: var(--text-main); text-decoration: none;"><?= Security::sanitize($rc['title']) ?></a></strong>
                                    <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">/<?= Security::sanitize($rc['slug']) ?></div>
                                </td>
                                <td>
                                    <span class="badge" style="background: var(--bg-subtle); color: var(--text-muted); font-size: 11px; border: 1px solid var(--border-subtle);">
                                        <?= $rc['type'] === 'post' ? 'Wpis' : 'Strona' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $rc['status'] === 'published' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 11px;">
                                        <?= $rc['status'] === 'published' ? 'Opublikowany' : 'Szkic' ?>
                                    </span>
                                </td>
                                <td style="font-size: 12px; color: var(--text-muted);"><?= date('d.m H:i', strtotime($rc['updated_at'])) ?></td>
                                <td style="text-align: right;">
                                    <a href="page-edit.php?id=<?= $rc['id'] ?>" class="btn btn-secondary" style="padding: 3px 8px; font-size: 11px;">Edytuj</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT COLUMN: Quick Draft & System Specs -->
    <div>
        <!-- Quick Draft Widget -->
        <div class="card">
            <h2 style="font-size: 15px; font-weight: 700; margin-bottom: 6px; color: var(--text-main);">⚡ Szybki szkic wpisu</h2>
            <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 14px;">Zanotuj pomysł na artykuł. Zostanie zapisany jako nieopublikowany szkic.</p>

            <form method="POST" action="">
                <input type="hidden" name="action" value="quick_draft">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" style="font-size: 12px;">Tytuł wpisu</label>
                    <input type="text" name="draft_title" class="form-control" placeholder="np. Jak automatyzować faktury w Make..." required>
                </div>

                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" style="font-size: 12px;">Kategoria</label>
                    <select name="category_id" class="form-control">
                        <option value="0">-- Bez kategorii --</option>
                        <?php foreach ($categoriesList as $cl): ?>
                            <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-size: 12px;">Treść / Główne punkty</label>
                    <textarea name="draft_content" class="form-control" rows="4" placeholder="Opisz wstępny zarys lub konspekt wpisu..."></textarea>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 13px;">Zapisz jako szkic</button>
            </form>
        </div>

        <!-- System & Environment Information Widget -->
        <div class="card">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);">Stan systemu CMSbyPHP</h3>
            <ul style="list-style: none; font-size: 13px; display: flex; flex-direction: column; gap: 10px; color: var(--text-muted); padding: 0; margin: 0;">
                <li style="display: flex; justify-content: space-between;">
                    <span>Wersja CMS:</span> <strong style="color: var(--primary);">CMSbyPHP v<?= \Core\Version::CURRENT ?></strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Wersja PHP:</span> <strong style="color: var(--text-main);"><?= PHP_VERSION ?></strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Silnik bazy:</span> <strong style="color: var(--text-main);">SQLite 3 (WAL Mode ⚡)</strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Rozmiar bazy:</span> <strong style="color: var(--text-main);"><?= $dbFileSize ?></strong>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Aktywny motyw:</span> <strong style="color: var(--text-main);"><?= htmlspecialchars(Router::getOption('active_theme', 'cmsbyphp-clean'), ENT_QUOTES, 'UTF-8') ?></strong>
                </li>
                <li style="display: flex; justify-content: space-between; padding-top: 6px; border-top: 1px solid var(--border-subtle);">
                    <span>Kanał RSS:</span> <a href="<?= site_url('feed', false) ?>" target="_blank" style="color:var(--primary); font-weight:600;">/feed ↗</a>
                </li>
                <li style="display: flex; justify-content: space-between;">
                    <span>Mapa witryny:</span> <a href="<?= site_url('sitemap.xml', false) ?>" target="_blank" style="color:var(--primary); font-weight:600;">/sitemap.xml ↗</a>
                </li>
            </ul>
        </div>

        <?php Hooks::doAction('admin_dashboard_sidebar'); ?>
    </div>
</div>

<?php Hooks::doAction('admin_dashboard_widgets'); ?>
<?php require_once __DIR__ . '/views/footer.php'; ?>
