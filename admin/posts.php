<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\Sitemap;
use Core\Router;
use Core\I18n;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$currentStatus = $_GET['status'] ?? 'all';
$currentCategory = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$currentLang = $_GET['lang'] ?? 'all';

// Fetch all pages to resolve slugs
$allPagesStmt = $db->query("SELECT id, parent_id, slug, lang, type FROM pages");
$lookupPages = [];
while ($p = $allPagesStmt->fetch()) {
    $lookupPages[(int)$p['id']] = $p;
}

$postsPageSlug = Router::getPostsPageSlug();

function resolvePostUrl(array $item, string $postsSlug): string {
    return '/' . $postsSlug . '/' . ltrim($item['slug'], '/');
}

/**
 * Cascade deletion of a post
 */
function deletePostCascade(\PDO $db, int $delId, array $lookup): bool {
    $checkStmt = $db->prepare("SELECT * FROM pages WHERE id = :id AND type = 'post' LIMIT 1");
    $checkStmt->execute([':id' => $delId]);
    $pageToDelete = $checkStmt->fetch();

    if (!$pageToDelete) {
        return false;
    }

    if (!Auth::can('edit_others_pages') && (int)$pageToDelete['author_id'] !== (int)Auth::id()) {
        return false;
    }

    // 1. Remove associated tags and categories
    $db->prepare("DELETE FROM page_tags WHERE page_id = :id")->execute([':id' => $delId]);
    $db->prepare("DELETE FROM page_categories WHERE page_id = :id")->execute([':id' => $delId]);
    $db->prepare("DELETE FROM page_meta WHERE page_id = :id")->execute([':id' => $delId]);

    // 2. Remove from menus
    $rawSlug = $pageToDelete['slug'];
    $postUrl = '/' . Router::getPostsPageSlug() . '/' . $rawSlug;
    $db->prepare("DELETE FROM menu_items WHERE url = :u1 OR url = :u2")->execute([
        ':u1' => $postUrl,
        ':u2' => '/' . $rawSlug
    ]);

    // 3. Delete the post record
    $delStmt = $db->prepare("DELETE FROM pages WHERE id = :id");
    $delStmt->execute([':id' => $delId]);
    return $delStmt->rowCount() > 0;
}

// Handle single post deletion
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        $delId = (int)$_GET['delete'];
        Auth::requireCapability('delete_pages');

        if (deletePostCascade($db, $delId, $lookupPages)) {
            if (class_exists('Core\Sitemap')) {
                try { Sitemap::generate(); } catch (\Throwable $e) {}
            }
        }

        header('Location: posts.php?status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang) . '&deleted=1');
        exit;
    }
}

// Handle Bulk Actions for posts
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

    if (!empty($selectedIds) && in_array($bulkType, ['delete', 'status', 'author', 'lang'], true)) {
        $idPlaceholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $baseParams = $selectedIds;

        $ownershipClause = '';
        if (!Auth::can('edit_others_pages')) {
            $ownershipClause = ' AND author_id = ?';
            $baseParams[] = Auth::id();
        }

        if ($bulkType === 'delete') {
            Auth::requireCapability('delete_pages');
            foreach ($selectedIds as $id) {
                if (deletePostCascade($db, (int)$id, $lookupPages)) {
                    $affected++;
                }
            }
            if ($affected > 0 && class_exists('Core\Sitemap')) {
                try { Sitemap::generate(); } catch (\Throwable $e) {}
            }
            $bulkResult = 'deleted';
        } elseif ($bulkType === 'status') {
            Auth::requireCapability('publish_pages');
            $newStatus = ($_POST['bulk_status'] ?? '') === 'published' ? 'published' : 'draft';
            $params = array_merge([$newStatus], $baseParams);
            $stmt = $db->prepare("UPDATE pages SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'post'$ownershipClause");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            $bulkResult = 'updated';
        } elseif ($bulkType === 'author') {
            Auth::requireCapability('edit_others_pages');
            $newAuthor = (int)($_POST['bulk_author'] ?? 0);
            if ($newAuthor > 0) {
                $params = array_merge([$newAuthor], $baseParams);
                $stmt = $db->prepare("UPDATE pages SET author_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'post'$ownershipClause");
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                $bulkResult = 'updated';
            }
        } elseif ($bulkType === 'lang') {
            Auth::requireCapability('manage_pages');
            $newLang = trim((string)($_POST['bulk_lang'] ?? ''));
            if ($newLang !== '' && array_key_exists($newLang, I18n::getAvailableLanguages())) {
                $params = array_merge([$newLang], $baseParams);
                $stmt = $db->prepare("UPDATE pages SET lang = ?, updated_at = CURRENT_TIMESTAMP WHERE id IN ($idPlaceholders) AND type = 'post'$ownershipClause");
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                $bulkResult = 'updated';
            }
        }
    }

    header('Location: posts.php?status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang) . '&bulk=' . urlencode($bulkResult) . '&count=' . (int)$affected);
    exit;
}

// Build query for posts
$conditions = ["p.type = 'post'"];
$params = [];

if ($currentStatus === 'published' || $currentStatus === 'draft') {
    $conditions[] = "p.status = :st";
    $params[':st'] = $currentStatus;
}

if ($currentLang !== 'all') {
    $conditions[] = "p.lang = :lng";
    $params[':lng'] = $currentLang;
}

if ($currentCategory > 0) {
    $conditions[] = "p.id IN (SELECT page_id FROM page_categories WHERE category_id = :cat)";
    $params[':cat'] = $currentCategory;
}

$whereClause = implode(" AND ", $conditions);

$sql = "
    SELECT p.*, u.username as author_name 
    FROM pages p 
    LEFT JOIN users u ON p.author_id = u.id 
    WHERE $whereClause 
    ORDER BY p.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$posts = $stmt->fetchAll();

// Counts for filter pills
$countAllPosts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post'")->fetchColumn();
$countPublished = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'published'")->fetchColumn();
$countDrafts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'draft'")->fetchColumn();

// Fetch all categories for filter and display
$allCategories = $db->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetchAll();

// Map categories to posts
$pageCategoriesMap = [];
try {
    $catQuery = $db->query("
        SELECT pc.page_id, c.id, c.name, c.slug 
        FROM page_categories pc 
        JOIN categories c ON pc.category_id = c.id
    ");
    while ($row = $catQuery->fetch()) {
        $pageCategoriesMap[(int)$row['page_id']][] = $row;
    }
} catch (\Throwable $e) {}

// Authors list for bulk change
$allUsers = [];
if (Auth::can('edit_others_pages')) {
    $allUsers = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
}

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Posts') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage blog articles, publications, categories and authors') ?></p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="page-edit.php?type=post" class="btn btn-primary">+ <?= _e('New Post') ?></a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: var(--success-subtle); padding: 12px 16px; margin-bottom: 20px; color: var(--success); font-weight: 500;">
        <?= _e('Post deleted successfully.') ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['bulk']) && $_GET['bulk'] !== 'none'): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: var(--success-subtle); padding: 12px 16px; margin-bottom: 20px; color: var(--success); font-weight: 500;">
        <?= _e('Bulk action applied to') ?> <strong><?= (int)($_GET['count'] ?? 0) ?></strong> <?= _e('item(s).') ?>
    </div>
<?php endif; ?>

<!-- Filters Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <!-- Status Tabs (WordPress style) -->
    <div class="status-tabs-group" style="display: flex; gap: 8px; align-items: center; max-width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; padding-bottom: 4px;">
        <a href="posts.php?status=all&cat=<?= $currentCategory ?>&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentStatus === 'all' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('All') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countAllPosts ?>)</span>
        </a>
        <a href="posts.php?status=published&cat=<?= $currentCategory ?>&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentStatus === 'published' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Published') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countPublished ?>)</span>
        </a>
        <a href="posts.php?status=draft&cat=<?= $currentCategory ?>&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentStatus === 'draft' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Drafts') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countDrafts ?>)</span>
        </a>
    </div>

    <!-- Quick Dropdown Filters -->
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <!-- Category Filter -->
        <select class="form-control" style="width: auto; padding: 5px 10px; font-size: 12px;" onchange="location.href='posts.php?status=<?= urlencode($currentStatus) ?>&lang=<?= urlencode($currentLang) ?>&cat=' + this.value">
            <option value="0"><?= _e('All Categories') ?></option>
            <?php foreach ($allCategories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $currentCategory === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Language Filter -->
        <?php if (count(I18n::getAvailableLanguages()) > 1): ?>
            <select class="form-control" style="width: auto; padding: 5px 10px; font-size: 12px;" onchange="location.href='posts.php?status=<?= urlencode($currentStatus) ?>&cat=<?= $currentCategory ?>&lang=' + this.value">
                <option value="all" <?= $currentLang === 'all' ? 'selected' : '' ?>><?= _e('All Languages') ?></option>
                <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                    <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)</option>
                <?php endforeach; ?>
            </select>
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
        <?php if (Auth::can('delete_pages')): ?>
            <option value="delete"><?= _e('Delete') ?></option>
        <?php endif; ?>
        <?php if (Auth::can('publish_pages')): ?>
            <option value="status"><?= _e('Change status') ?></option>
        <?php endif; ?>
        <?php if (Auth::can('edit_others_pages')): ?>
            <option value="author"><?= _e('Change author') ?></option>
        <?php endif; ?>
        <option value="lang"><?= _e('Change language') ?></option>
    </select>

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
                <th style="width: 140px;"><?= _e('Categories') ?></th>
                <th style="width: 110px;"><?= _e('Author') ?></th>
                <th style="width: 90px; text-align: center;"><?= _e('Status') ?></th>
                <th style="width: 110px;"><?= _e('Date') ?></th>
                <th style="text-align: right; width: 100px;"><?= _e('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($posts)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 48px; color: var(--text-muted);">
                        <?= _e('No posts found.') ?> <a href="page-edit.php?type=post" style="color: var(--primary); font-weight: 600;"><?= _e('Add your first post &rarr;') ?></a>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($posts as $post):
                    $route = resolvePostUrl($post, $postsPageSlug);
                    $fullLiveUrl = resolve_media_url($route);
                    $canEdit = Auth::can('edit_others_pages') || (int)$post['author_id'] === (int)Auth::id();
                    $deleteUrl = "posts.php?delete={$post['id']}&csrf=" . Security::generateCsrfToken() . '&status=' . urlencode($currentStatus) . '&lang=' . urlencode($currentLang);
                    $postCats = $pageCategoriesMap[$post['id']] ?? [];
                ?>
                    <tr>
                        <td style="text-align: center;">
                            <input type="checkbox" name="ids[]" value="<?= (int)$post['id'] ?>" class="row-select" style="cursor: pointer;">
                        </td>

                        <!-- Featured image thumbnail -->
                        <td>
                            <?php if (!empty($post['featured_image'])): ?>
                                <img src="<?= htmlspecialchars(resolve_media_url($post['featured_image']), ENT_QUOTES, 'UTF-8') ?>" 
                                     alt="" 
                                     style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px; display: block; border: 1px solid var(--border-subtle); background: var(--bg-subtle);">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; border-radius: 6px; background: var(--bg-subtle); border: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 16px;">
                                    ✍️
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Title and route -->
                        <td>
                            <div style="font-weight: 600; font-size: 14px;">
                                <?php if ($canEdit): ?>
                                    <a href="page-edit.php?id=<?= $post['id'] ?>" style="color: var(--text-main); text-decoration: none;" class="post-title-link">
                                        <?= Security::sanitize($post['title']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-main);"><?= Security::sanitize($post['title']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="font-size: 11px; color: var(--text-muted); font-family: ui-monospace, SFMono-Regular, monospace; margin-top: 2px;">
                                <?= htmlspecialchars($route, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <!-- WordPress Signature Row Actions (visible on hover) -->
                            <div class="row-actions">
                                <?php if ($canEdit): ?>
                                    <span class="edit"><a href="page-edit.php?id=<?= $post['id'] ?>"><?= _e('Edit') ?></a></span>
                                    <span class="row-actions-sep">|</span>
                                <?php endif; ?>
                                <span class="view"><a href="<?= htmlspecialchars($fullLiveUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= _e('View') ?> ↗</a></span>
                                <?php if (Auth::can('delete_pages') && $canEdit): ?>
                                    <span class="row-actions-sep">|</span>
                                    <span class="trash"><button type="button" onclick="confirmDeletePost('<?= htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($post['title']), ENT_QUOTES, 'UTF-8') ?>')"><?= _e('Trash') ?></button></span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Categories -->
                        <td>
                            <?php if (!empty($postCats)): ?>
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    <?php foreach ($postCats as $c): ?>
                                        <a href="posts.php?cat=<?= $c['id'] ?>" class="badge" style="background: var(--bg-subtle); border: 1px solid var(--border-subtle); color: var(--text-main); font-size: 11px; text-decoration: none; padding: 2px 6px;">
                                            <?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-size: 12px;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Author -->
                        <td style="font-size: 12px; color: var(--text-muted);">
                            <?= Security::sanitize($post['author_name'] ?? 'Admin') ?>
                        </td>

                        <!-- Status badge -->
                        <td style="text-align: center;">
                            <?php if ($post['status'] === 'published'): ?>
                                <span class="badge badge-success" style="font-size: 11px;"><?= _e('Published') ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size: 11px;"><?= _e('Draft') ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Date -->
                        <td style="font-size: 12px; color: var(--text-muted); white-space: nowrap;">
                            <?= date('d.m.Y', strtotime($post['created_at'])) ?>
                        </td>

                        <!-- Actions -->
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 4px; align-items: center;">
                                <?php if ($canEdit): ?>
                                    <a href="page-edit.php?id=<?= $post['id'] ?>" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" title="<?= _e('Edit Post') ?>">
                                        <?= _e('Edit') ?>
                                    </a>
                                <?php endif; ?>

                                <a href="<?= htmlspecialchars($fullLiveUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px;" title="<?= _e('View Post Live') ?>">
                                    ↗
                                </a>

                                <?php if (Auth::can('delete_pages') && $canEdit): ?>
                                    <button type="button" class="btn btn-danger-ghost" style="padding: 3px 6px; font-size: 13px; font-weight: bold; line-height: 1;" onclick="confirmDeletePost('<?= htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($post['title']), ENT_QUOTES, 'UTF-8') ?>')" title="<?= _e('Delete Post') ?>">
                                        &times;
                                    </button>
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
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 8px; color: var(--text-main);"><?= _e('Delete Blog Post') ?></h3>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 20px; line-height: 1.5;">
            <?= _e('Are you sure you want to permanently delete') ?> <strong id="delete-post-title" style="color: var(--text-main);"></strong>? <?= _e('This action cannot be undone.') ?>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1; padding: 9px;" onclick="closeDeleteModal()"><?= _e('Cancel') ?></button>
            <a id="confirm-delete-btn" href="#" class="btn btn-primary" style="background: var(--danger); border-color: var(--danger); flex: 1; padding: 9px; text-decoration: none; text-align: center;">
                <?= _e('Delete') ?>
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
            document.getElementById('bulk-param-status').style.display = 'block';
        } else if (val === 'author') {
            const el = document.getElementById('bulk-param-author');
            if (el) el.style.display = 'block';
        } else if (val === 'lang') {
            document.getElementById('bulk-param-lang').style.display = 'block';
        }
    });
}

function confirmBulkAction() {
    const val = bulkActionPicker.value;
    if (!val) {
        alert('<?= _e('Please select a bulk action.') ?>');
        return false;
    }
    if (val === 'delete') {
        return confirm('<?= _e('Are you sure you want to delete all selected posts?') ?>');
    }
    return true;
}

// Modal handling
const deleteModal = document.getElementById('delete-modal');
const deleteTitlePlaceholder = document.getElementById('delete-post-title');
const confirmDeleteBtn = document.getElementById('confirm-delete-btn');

function confirmDeletePost(url, title) {
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
