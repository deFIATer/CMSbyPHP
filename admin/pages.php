<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\Preview;
use Core\Sitemap;
use Core\Router;
use Core\I18n;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$currentStatus = $_GET['status'] ?? 'all';
$currentLang = $_GET['lang'] ?? 'all';
$isTrashView = ($currentStatus === 'trash');

// Fetch all pages to calculate full hierarchical URL paths
$allPagesStmt = $db->query("SELECT id, parent_id, slug, lang, type FROM pages");
$lookupPages = [];
while ($p = $allPagesStmt->fetch()) {
    $lookupPages[(int)$p['id']] = $p;
}

function resolveFullSlug(array $item, array $lookup): string {
    $slugs = [];
    $curr = (int)$item['id'];
    $visited = [];
    $limit = 20;

    while ($curr > 0 && isset($lookup[$curr]) && !in_array($curr, $visited, true) && $limit-- > 0) {
        $visited[] = $curr;
        if (!empty($lookup[$curr]['slug']) && $lookup[$curr]['slug'] !== 'home') {
            array_unshift($slugs, $lookup[$curr]['slug']);
        }
        $curr = (int)($lookup[$curr]['parent_id'] ?? 0);
    }
    return '/' . implode('/', $slugs);
}

/**
 * Cascade permanent deletion of a static page
 */
function deletePageCascade(\PDO $db, int $delId, array $lookup): bool {
    $checkStmt = $db->prepare("SELECT * FROM pages WHERE id = :id AND type = 'page' LIMIT 1");
    $checkStmt->execute([':id' => $delId]);
    $pageToDelete = $checkStmt->fetch();

    if (!$pageToDelete) {
        return false;
    }

    if (!Auth::can('edit_others_pages') && (int)$pageToDelete['author_id'] !== (int)Auth::id()) {
        return false;
    }

    // 1. Remove from menus
    $rawSlug = $pageToDelete['slug'];
    $hierarchicalPath = resolveFullSlug($pageToDelete, $lookup);
    $candidateUrls = ['/' . $rawSlug, $rawSlug, $hierarchicalPath, ltrim($hierarchicalPath, '/')];
    if ($rawSlug === 'home') $candidateUrls[] = '/';

    $menuPlaceholder = implode(',', array_fill(0, count($candidateUrls), '?'));
    $db->prepare("DELETE FROM menu_items WHERE url IN ($menuPlaceholder)")->execute($candidateUrls);

    // 2. Remove meta & revisions
    $db->prepare("DELETE FROM page_meta WHERE page_id = :id")->execute([':id' => $delId]);
    $db->prepare("DELETE FROM page_revisions WHERE page_id = :id")->execute([':id' => $delId]);

    // 3. Re-assign or orphan children
    $db->prepare("UPDATE pages SET parent_id = 0 WHERE parent_id = :id")->execute([':id' => $delId]);

    // 4. Delete the page
    $delStmt = $db->prepare("DELETE FROM pages WHERE id = :id");
    $delStmt->execute([':id' => $delId]);
    return $delStmt->rowCount() > 0;
}

// Handle Move to Trash (Soft Delete)
if (isset($_GET['trash'], $_GET['csrf']) && Security::verifyCsrfToken($_GET['csrf'])) {
    $delId = (int)$_GET['trash'];
    Auth::requireCapability('delete_pages');

    $owned = $db->prepare("SELECT author_id, slug FROM pages WHERE id = :id AND type = 'page' LIMIT 1");
    $owned->execute([':id' => $delId]);
    $pageData = $owned->fetch();

    if ($pageData && $pageData['slug'] !== 'home' && (Auth::can('edit_others_pages') || (int)$pageData['author_id'] === Auth::id())) {
        $db->prepare("UPDATE pages SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $delId]);
        if (class_exists('Core\PageCache')) {
            \Core\PageCache::purge();
        }
        if (class_exists('Core\Sitemap')) {
            try { Sitemap::generate(); } catch (\Throwable $e) {}
        }
    }

    header('Location: pages.php?status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang) . '&trashed=1');
    exit;
}

// Handle Restore from Trash
if (isset($_GET['restore'], $_GET['csrf']) && Security::verifyCsrfToken($_GET['csrf'])) {
    $resId = (int)$_GET['restore'];
    Auth::requireCapability('delete_pages');

    $db->prepare("UPDATE pages SET deleted_at = NULL WHERE id = :id AND type = 'page'")->execute([':id' => $resId]);
    if (class_exists('Core\PageCache')) {
        \Core\PageCache::purge();
    }
    if (class_exists('Core\Sitemap')) {
        try { Sitemap::generate(); } catch (\Throwable $e) {}
    }

    header('Location: pages.php?status=trash&lang=' . urlencode($currentLang) . '&restored=1');
    exit;
}

// Handle Permanent Single Delete
if (isset($_GET['delete_permanent'], $_GET['csrf']) && Security::verifyCsrfToken($_GET['csrf'])) {
    $delId = (int)$_GET['delete_permanent'];
    Auth::requireCapability('delete_pages');

    if (deletePageCascade($db, $delId, $lookupPages)) {
        if (class_exists('Core\PageCache')) {
            \Core\PageCache::purge();
        }
        if (class_exists('Core\Sitemap')) {
            try { Sitemap::generate(); } catch (\Throwable $e) {}
        }
    }

    header('Location: pages.php?status=trash&lang=' . urlencode($currentLang) . '&deleted_permanent=1');
    exit;
}

// Handle Empty Trash
if (isset($_GET['empty_trash'], $_GET['csrf']) && Security::verifyCsrfToken($_GET['csrf'])) {
    Auth::requireCapability('delete_pages');

    $trashIds = $db->query("SELECT id FROM pages WHERE type = 'page' AND deleted_at IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    foreach ($trashIds as $tid) {
        deletePageCascade($db, (int)$tid, $lookupPages);
    }
    if (!empty($trashIds)) {
        if (class_exists('Core\PageCache')) {
            \Core\PageCache::purge();
        }
        if (class_exists('Core\Sitemap')) {
            try { Sitemap::generate(); } catch (\Throwable $e) {}
        }
    }

    header('Location: pages.php?status=trash&lang=' . urlencode($currentLang) . '&emptied=1');
    exit;
}

// Legacy fallback
if (isset($_GET['delete'], $_GET['csrf']) && Security::verifyCsrfToken($_GET['csrf'])) {
    header('Location: pages.php?trash=' . (int)$_GET['delete'] . '&csrf=' . urlencode($_GET['csrf']) . '&status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang));
    exit;
}

// Handle Bulk Actions for pages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_action') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF');
    }

    $bulkType = (string)($_POST['bulk_action_type'] ?? '');
    $selectedIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['ids'] ?? [])),
        static fn($id) => $id > 0
    )));

    $affected = 0;
    $bulkResult = 'none';

    if (!empty($selectedIds)) {
        $idPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $baseParams = $selectedIds;

        $ownershipClause = '';
        if (!Auth::can('edit_others_pages')) {
            $ownershipClause = ' AND author_id = ?';
            $baseParams[] = Auth::id();
        }

        if ($bulkType === 'trash') {
            Auth::requireCapability('delete_pages');
            $stmt = $db->prepare("UPDATE pages SET deleted_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'page' AND slug != 'home'$ownershipClause");
            $stmt->execute($baseParams);
            $affected = $stmt->rowCount();
            $bulkResult = 'trashed';
        } elseif ($bulkType === 'restore') {
            Auth::requireCapability('delete_pages');
            $stmt = $db->prepare("UPDATE pages SET deleted_at = NULL WHERE id IN ($idPlaceholders) AND type = 'page'$ownershipClause");
            $stmt->execute($baseParams);
            $affected = $stmt->rowCount();
            $bulkResult = 'restored';
        } elseif ($bulkType === 'delete_permanent') {
            Auth::requireCapability('delete_pages');
            foreach ($selectedIds as $id) {
                if (deletePageCascade($db, (int)$id, $lookupPages)) {
                    $affected++;
                }
            }
            $bulkResult = 'deleted_permanent';
        } elseif ($bulkType === 'status') {
            Auth::requireCapability('publish_pages');
            $newStatus = ($_POST['bulk_status'] ?? '') === 'published' ? 'published' : 'draft';
            $params = array_merge([$newStatus], $baseParams);
            $stmt = $db->prepare("UPDATE pages SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'page'$ownershipClause");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            $bulkResult = 'updated';
        } elseif ($bulkType === 'author') {
            Auth::requireCapability('edit_others_pages');
            $newAuthor = (int)($_POST['bulk_author'] ?? 0);
            if ($newAuthor > 0) {
                $params = array_merge([$newAuthor], $baseParams);
                $stmt = $db->prepare("UPDATE pages SET author_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'page'$ownershipClause");
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                $bulkResult = 'updated';
            }
        } elseif ($bulkType === 'lang') {
            Auth::requireCapability('manage_pages');
            $newLang = trim((string)($_POST['bulk_lang'] ?? ''));
            if ($newLang !== '' && array_key_exists($newLang, I18n::getAvailableLanguages())) {
                $params = array_merge([$newLang], $baseParams);
                $stmt = $db->prepare("UPDATE pages SET lang = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'page'$ownershipClause");
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                $bulkResult = 'updated';
            }
        }

        if ($affected > 0) {
            if (class_exists('Core\PageCache')) {
                \Core\PageCache::purge();
            }
            if (class_exists('Core\Sitemap')) {
                try { Sitemap::generate(); } catch (\Throwable $e) {}
            }
        }
    }

    header('Location: pages.php?status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang) . '&bulk=' . urlencode($bulkResult) . '&count=' . (int)$affected);
    exit;
}

// Build query for static pages
$conditions = ["p.type = 'page'"];
$params = [];

if ($isTrashView) {
    $conditions[] = "p.deleted_at IS NOT NULL";
} else {
    $conditions[] = "p.deleted_at IS NULL";
    if ($currentStatus === 'published' || $currentStatus === 'draft') {
        $conditions[] = "p.status = :st";
        $params[':st'] = $currentStatus;
    }
}

if ($currentLang !== 'all') {
    $conditions[] = "p.lang = :lng";
    $params[':lng'] = $currentLang;
}

$whereClause = implode(" AND ", $conditions);
$orderClause = $isTrashView ? "p.deleted_at DESC" : "p.parent_id ASC, p.id ASC";

$sql = "
    SELECT p.*, u.username as author_name 
    FROM pages p 
    LEFT JOIN users u ON p.author_id = u.id 
    WHERE $whereClause 
    ORDER BY $orderClause
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$pages = $stmt->fetchAll();

// Counts for filter pills
$countAllPages = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND deleted_at IS NULL")->fetchColumn();
$countPublished = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND status = 'published' AND deleted_at IS NULL")->fetchColumn();
$countDrafts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND status = 'draft' AND deleted_at IS NULL")->fetchColumn();
$countTrash = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND deleted_at IS NOT NULL")->fetchColumn();

// Authors list for bulk actions
$allUsers = [];
if (Auth::can('edit_others_pages')) {
    $allUsers = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
}

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Pages') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage static site pages, homepage, hierarchy and multilingual content') ?></p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="page-edit.php?type=page" class="btn btn-primary">+ <?= _e('New Page') ?></a>
    </div>
</div>

<?php if (isset($_GET['trashed'])): ?>
    <div class="card" style="border-left: 4px solid var(--warning, #f59e0b); background: rgba(245, 158, 11, 0.1); padding: 12px 16px; margin-bottom: 20px; font-weight: 500;">
        <?= _e('1 page moved to the Trash.') ?>
    </div>
<?php elseif (isset($_GET['restored'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: var(--success-subtle); padding: 12px 16px; margin-bottom: 20px; color: var(--success); font-weight: 500;">
        <?= _e('1 page restored from the Trash.') ?>
    </div>
<?php elseif (isset($_GET['deleted_permanent'])): ?>
    <div class="card" style="border-left: 4px solid var(--danger, #ef4444); background: rgba(239, 68, 68, 0.1); padding: 12px 16px; margin-bottom: 20px; color: var(--danger); font-weight: 500;">
        <?= _e('Page permanently deleted.') ?>
    </div>
<?php elseif (isset($_GET['emptied'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: var(--success-subtle); padding: 12px 16px; margin-bottom: 20px; color: var(--success); font-weight: 500;">
        <?= _e('Trash emptied successfully.') ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['bulk']) && $_GET['bulk'] !== 'none'): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: var(--success-subtle); padding: 12px 16px; margin-bottom: 20px; color: var(--success); font-weight: 500;">
        <?= _e('Bulk action applied to') ?> <strong><?= (int)($_GET['count'] ?? 0) ?></strong> <?= _e('item(s).') ?>
    </div>
<?php endif; ?>

<!-- Filters Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <!-- Status Tabs -->
    <div class="status-tabs-group" style="display: flex; gap: 8px; align-items: center; max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 4px;">
        <a href="pages.php?status=all&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentStatus === 'all' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('All') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countAllPages ?>)</span>
        </a>
        <a href="pages.php?status=published&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentStatus === 'published' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Published') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countPublished ?>)</span>
        </a>
        <a href="pages.php?status=draft&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentStatus === 'draft' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Drafts') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countDrafts ?>)</span>
        </a>
        <?php if ($countTrash > 0 || $isTrashView): ?>
            <a href="pages.php?status=trash&lang=<?= urlencode($currentLang) ?>" 
               class="btn <?= $isTrashView ? 'btn-danger' : 'btn-secondary' ?>" 
               style="padding: 6px 14px; font-size: 12px;">
                🗑️ <?= _e('Trash') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countTrash ?>)</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- Quick Actions & Language Filter -->
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <?php if ($isTrashView && $countTrash > 0 && Auth::can('delete_pages')): ?>
            <a href="pages.php?empty_trash=1&csrf=<?= urlencode(Security::generateCsrfToken()) ?>" 
               class="btn btn-secondary" 
               style="padding: 5px 12px; font-size: 12px; color: #ef4444;"
               onclick="return confirm('<?= _e('Permanently delete all items from trash? This cannot be undone.') ?>');">
                🗑️ <?= _e('Empty Trash') ?>
            </a>
        <?php endif; ?>

        <?php if (count(I18n::getAvailableLanguages()) > 1): ?>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Language:') ?></span>
                <select class="form-control" style="width: auto; padding: 5px 10px; font-size: 12px;" onchange="location.href='pages.php?status=<?= urlencode($currentStatus) ?>&lang=' + this.value">
                    <option value="all" <?= $currentLang === 'all' ? 'selected' : '' ?>><?= _e('All Languages') ?></option>
                    <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                        <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
</div>

<form method="post" id="bulk-form" style="margin: 0;">
<input type="hidden" name="action" value="bulk_action">
<input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
<input type="hidden" name="bulk_action_type" id="bulk-action-type" value="">

<!-- Bulk Actions Bar -->
<div id="bulk-bar" class="card" style="display: none; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px; margin-bottom: 16px; border: 1px solid var(--border-subtle);">
    <span id="bulk-count" style="font-size: 13px; font-weight: 600; color: var(--text-main);"></span>
    <select class="form-control" id="bulk-action-picker" style="width: auto; padding: 6px 10px; font-size: 13px;">
        <option value=""><?= _e('Bulk actions') ?></option>
        <?php if ($isTrashView): ?>
            <option value="restore"><?= _e('Restore') ?></option>
            <?php if (Auth::can('delete_pages')): ?>
                <option value="delete_permanent"><?= _e('Delete Permanently') ?></option>
            <?php endif; ?>
        <?php else: ?>
            <?php if (Auth::can('delete_pages')): ?>
                <option value="trash"><?= _e('Move to Trash') ?></option>
            <?php endif; ?>
            <?php if (Auth::can('publish_pages')): ?>
                <option value="status"><?= _e('Change status') ?></option>
            <?php endif; ?>
            <?php if (Auth::can('edit_others_pages')): ?>
                <option value="author"><?= _e('Change author') ?></option>
            <?php endif; ?>
            <option value="lang"><?= _e('Change language') ?></option>
        <?php endif; ?>
    </select>

    <?php if (!$isTrashView): ?>
        <div id="bulk-param-status" class="bulk-param" style="display: none;">
            <select name="bulk_status" class="form-control" style="width: auto; padding: 6px 10px; font-size: 13px;">
                <option value="published"><?= _e('Published') ?></option>
                <option value="draft"><?= _e('Draft') ?></option>
            </select>
        </div>

        <?php if (Auth::can('edit_others_pages') && !empty($allUsers)): ?>
            <div id="bulk-param-author" class="bulk-param" style="display: none;">
                <select name="bulk_author" class="form-control" style="width: auto; padding: 6px 10px; font-size: 13px;">
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= Security::sanitize($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div id="bulk-param-lang" class="bulk-param" style="display: none;">
            <select name="bulk_lang" class="form-control" style="width: auto; padding: 6px 10px; font-size: 13px;">
                <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                    <option value="<?= $code ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary" style="padding: 6px 14px; font-size: 13px;" onclick="return confirmBulkAction();"><?= _e('Apply') ?></button>
    <button type="button" class="btn btn-secondary" style="padding: 6px 10px; font-size: 13px;" onclick="clearBulkSelection();"><?= _e('Clear selection') ?></button>
</div>

<div class="table-container">
    <table class="pro-table">
        <thead>
            <tr>
                <th style="width: 36px; text-align: center;">
                    <input type="checkbox" id="select-all" style="cursor: pointer;">
                </th>
                <th style="width: 54px;"><?= _e('Image') ?></th>
                <th><?= _e('Title & Route') ?></th>
                <th style="width: 140px;"><?= _e('Author') ?></th>
                <th style="width: 90px; text-align: center;"><?= _e('Status') ?></th>
                <th style="width: 130px;"><?= $isTrashView ? _e('Date Deleted') : _e('Date') ?></th>
                <th style="text-align: right; width: 140px;"><?= _e('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pages)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 48px; color: var(--text-muted);">
                        <?php if ($isTrashView): ?>
                            <?= _e('No pages in the Trash.') ?>
                        <?php else: ?>
                            <?= _e('No static pages found.') ?> <a href="page-edit.php?type=page" style="color: var(--primary); font-weight: 600;"><?= _e('Add your first page &rarr;') ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($pages as $page):
                    $route = resolveFullSlug($page, $lookupPages);
                    $fullLiveUrl = resolve_media_url($route);
                    $previewUrl = ($page['status'] === 'draft' && class_exists('Core\Preview')) 
                        ? Preview::url((int)$page['id']) 
                        : $fullLiveUrl;
                    $canEdit = Auth::can('edit_others_pages') || (int)$page['author_id'] === (int)Auth::id();
                    $isHome = ($page['slug'] === 'home' || $page['slug'] === '');
                    $isChild = ((int)$page['parent_id'] > 0);
                    $trashUrl = "pages.php?trash={$page['id']}&csrf=" . Security::generateCsrfToken() . '&status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang);
                    $restoreUrl = "pages.php?restore={$page['id']}&csrf=" . Security::generateCsrfToken() . '&lang=' . urlencode($currentLang);
                    $deletePermUrl = "pages.php?delete_permanent={$page['id']}&csrf=" . Security::generateCsrfToken() . '&lang=' . urlencode($currentLang);
                ?>
                    <tr style="<?= $isTrashView ? 'opacity: 0.85;' : '' ?>">
                        <td style="text-align: center;">
                            <input type="checkbox" name="ids[]" value="<?= (int)$page['id'] ?>" class="row-select" style="cursor: pointer;">
                        </td>

                        <!-- Thumbnail -->
                        <td>
                            <?php if (!empty($page['featured_image'])): ?>
                                <img src="<?= htmlspecialchars(resolve_media_url($page['featured_image']), ENT_QUOTES, 'UTF-8') ?>" 
                                     alt="" 
                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; display: block; border: 1px solid var(--border-subtle); background: var(--bg-subtle);">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; border-radius: 6px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 16px;">
                                    📄
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Title and path -->
                        <td>
                            <div style="font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 6px;">
                                <?php if ($isChild && !$isTrashView): ?>
                                    <span style="color: var(--text-muted); font-weight: normal;">&mdash;</span>
                                <?php endif; ?>

                                <?php if ($canEdit && !$isTrashView): ?>
                                    <a href="page-edit.php?id=<?= $page['id'] ?>" style="color: var(--text-main); text-decoration: none;" class="page-title-link">
                                        <?= Security::sanitize($page['title']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-main);"><?= Security::sanitize($page['title']) ?></span>
                                <?php endif; ?>

                                <?php if ($isHome): ?>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--primary); font-size: 10px; font-weight: 700;">Strona główna</span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); font-family: ui-monospace, SFMono-Regular, monospace; margin-top: 2px;">
                                <?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <!-- WordPress Signature Row Actions (visible on hover) -->
                            <div class="row-actions">
                                <?php if ($isTrashView): ?>
                                    <span class="restore"><a href="<?= htmlspecialchars($restoreUrl, ENT_QUOTES, 'UTF-8') ?>"><?= _e('Restore') ?></a></span>
                                    <?php if (Auth::can('delete_pages') && $canEdit): ?>
                                        <span class="row-actions-sep">|</span>
                                        <span class="delete"><button type="button" onclick="confirmDeletePage('<?= htmlspecialchars($deletePermUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($page['title']), ENT_QUOTES, 'UTF-8') ?>')"><?= _e('Delete Permanently') ?></button></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($canEdit): ?>
                                        <span class="edit"><a href="page-edit.php?id=<?= $page['id'] ?>"><?= _e('Edit') ?></a></span>
                                        <span class="row-actions-sep">|</span>
                                    <?php endif; ?>
                                    <span class="view"><a href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= ($page['status'] === 'draft' ? _e('Preview') : _e('View')) ?> ↗</a></span>
                                    <?php if (Auth::can('delete_pages') && $canEdit && !$isHome): ?>
                                        <span class="row-actions-sep">|</span>
                                        <span class="trash"><a href="<?= htmlspecialchars($trashUrl, ENT_QUOTES, 'UTF-8') ?>" style="color: var(--danger);"><?= _e('Trash') ?></a></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Author -->
                        <td style="font-size: 12px; color: var(--text-muted);">
                            <?= Security::sanitize($page['author_name'] ?? 'Admin') ?>
                        </td>

                        <!-- Status -->
                        <td style="text-align: center;">
                            <?php if ($isTrashView): ?>
                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; font-size: 11px;"><?= _e('Trash') ?></span>
                            <?php elseif ($page['status'] === 'published'): ?>
                                <span class="badge badge-success" style="font-size: 11px;"><?= _e('Published') ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size: 11px;"><?= _e('Draft') ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Date -->
                        <td style="font-size: 12px; color: var(--text-muted); white-space: nowrap;">
                            <?php if ($isTrashView && !empty($page['deleted_at'])): ?>
                                <?= date('d.m.Y H:i', strtotime($page['deleted_at'])) ?>
                            <?php else: ?>
                                <?= date('d.m.Y', strtotime($page['created_at'])) ?>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 4px; align-items: center;">
                                <?php if ($isTrashView): ?>
                                    <a href="<?= htmlspecialchars($restoreUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" title="<?= _e('Restore Page') ?>">
                                        <?= _e('Restore') ?>
                                    </a>
                                    <?php if (Auth::can('delete_pages') && $canEdit): ?>
                                        <button type="button" class="btn btn-danger-ghost" style="padding: 3px 6px; font-size: 13px; font-weight: bold; line-height: 1;" onclick="confirmDeletePage('<?= htmlspecialchars($deletePermUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($page['title']), ENT_QUOTES, 'UTF-8') ?>')" title="<?= _e('Delete Permanently') ?>">
                                            &times;
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($canEdit): ?>
                                        <a href="page-edit.php?id=<?= $page['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" title="<?= _e('Edit Page') ?>">
                                            <?= _e('Edit') ?>
                                        </a>
                                    <?php endif; ?>

                                    <a href="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" title="<?= _e('View Page') ?>">
                                        ↗
                                    </a>

                                    <?php if (Auth::can('delete_pages') && $canEdit && !$isHome): ?>
                                        <a href="<?= htmlspecialchars($trashUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-danger-ghost" style="padding: 3px 6px; font-size: 13px; font-weight: bold; line-height: 1;" title="<?= _e('Move to Trash') ?>">
                                            &times;
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</form>

<!-- Modal: Delete Confirmation -->
<div id="delete-modal" style="display: none; position: fixed; inset: 0; background: var(--modal-overlay); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 420px; padding: 24px; background: var(--modal-bg); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle); text-align: center;">
        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: var(--danger); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 8px; color: var(--text-main);"><?= _e('Delete Static Page Permanently') ?></h3>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 20px; line-height: 1.5;">
            <?= _e('Are you sure you want to permanently delete') ?> <strong id="delete-page-title" style="color: var(--text-main);"></strong>? <?= _e('This action cannot be undone.') ?>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1; padding: 9px;" onclick="closeDeleteModal()"><?= _e('Cancel') ?></button>
            <a id="confirm-delete-btn" href="#" class="btn btn-primary" style="background: var(--danger); border-color: var(--danger); flex: 1; padding: 9px; text-decoration: none; text-align: center;">
                <?= _e('Delete Permanently') ?>
            </a>
        </div>
    </div>
</div>

<script>
// Select All Checkbox Logic
const selectAll = document.getElementById('select-all');
const rowCheckboxes = document.querySelectorAll('.row-select');
const bulkBar = document.getElementById('bulk-bar');
const bulkCount = document.getElementById('bulk-count');
const bulkActionPicker = document.getElementById('bulk-action-picker');
const bulkActionType = document.getElementById('bulk-action-type');

function updateBulkState() {
    const checked = Array.from(rowCheckboxes).filter(cb => cb.checked);
    if (checked.length > 0) {
        bulkBar.style.display = 'flex';
        bulkCount.textContent = 'Zaznaczono: ' + checked.length;
    } else {
        bulkBar.style.display = 'none';
        if (selectAll) selectAll.checked = false;
    }
}

if (selectAll) {
    selectAll.addEventListener('change', () => {
        rowCheckboxes.forEach(cb => cb.checked = selectAll.checked);
        updateBulkState();
    });
}

rowCheckboxes.forEach(cb => {
    cb.addEventListener('change', updateBulkState);
});

function clearBulkSelection() {
    rowCheckboxes.forEach(cb => cb.checked = false);
    if (selectAll) selectAll.checked = false;
    updateBulkState();
}

if (bulkActionPicker) {
    bulkActionPicker.addEventListener('change', () => {
        const val = bulkActionPicker.value;
        bulkActionType.value = val;
        document.querySelectorAll('.bulk-param').forEach(el => el.style.display = 'none');
        if (val === 'status') {
            const el = document.getElementById('bulk-param-status');
            if (el) el.style.display = 'block';
        } else if (val === 'author') {
            const el = document.getElementById('bulk-param-author');
            if (el) el.style.display = 'block';
        } else if (val === 'lang') {
            const el = document.getElementById('bulk-param-lang');
            if (el) el.style.display = 'block';
        }
    });
}

function confirmBulkAction() {
    const val = bulkActionPicker.value;
    if (!val) {
        alert('<?= _e('Please select a bulk action.') ?>');
        return false;
    }
    if (val === 'delete_permanent') {
        return confirm('<?= _e('Permanently delete all selected items? This cannot be undone.') ?>');
    }
    if (val === 'trash') {
        return confirm('<?= _e('Move all selected items to trash?') ?>');
    }
    return true;
}

// Modal handling
const deleteModal = document.getElementById('delete-modal');
const deleteTitlePlaceholder = document.getElementById('delete-page-title');
const confirmDeleteBtn = document.getElementById('confirm-delete-btn');

function confirmDeletePage(url, title) {
    deleteTitlePlaceholder.textContent = title;
    confirmDeleteBtn.href = url;
    deleteModal.style.display = 'flex';
}

function closeDeleteModal() {
    deleteModal.style.display = 'none';
}

deleteModal.addEventListener('click', (e) => {
    if (e.target === deleteModal) closeDeleteModal();
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
