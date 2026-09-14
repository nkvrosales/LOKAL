<?php
require_once "common.php";
admin_require_admin();

$flash = [];
$errors = [];

// ── Helper: generate tag from name ───────────────────────────────────────────
$makeTag = static function (string $name): string {
    $tag = strtolower(trim($name));
    $tag = preg_replace("/[^a-z0-9]+/", "-", $tag);
    return trim($tag, "-");
};

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = trim($_POST["action"] ?? "");

    // ── Add new category ──────────────────────────────────────────────────────
    if ($action === "add") {
        $name  = trim($_POST["name"] ?? "");
        $tag   = trim($_POST["tag"] ?? "") !== "" ? $makeTag(trim($_POST["tag"] ?? "")) : $makeTag($name);
        $order = max(0, (int) ($_POST["sort_order"] ?? 0));

        if ($name === "") {
            $errors[] = "Category name is required.";
        } elseif ($tag === "") {
            $errors[] = "Could not generate a valid tag from that name.";
        } else {
            // Check if slug already exists
            $check = $mysqli->prepare("SELECT id FROM categories WHERE slug = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $tag);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $errors[] = "A category with the tag \"$tag\" already exists.";
                }
                $check->close();
            }

            if (!$errors) {
                try {
                    $stmt = $mysqli->prepare(
                        "INSERT INTO categories (name, slug, is_active, sort_order) VALUES (?, ?, 1, ?)"
                    );
                    if ($stmt) {
                        $stmt->bind_param("ssi", $name, $tag, $order);
                        if ($stmt->execute()) {
                            $flash[] = "Category \"" . htmlspecialchars($name) . "\" added.";
                        } else {
                            $errors[] = "Could not add category.";
                        }
                        $stmt->close();
                    }
                } catch (mysqli_sql_exception $e) {
                    $errors[] = $e->getCode() === 1062
                        ? "A category with the tag \"$tag\" already exists."
                        : "Could not add category.";
                }
            }
        }
    }

    // ── Edit category ─────────────────────────────────────────────────────────
    if ($action === "edit") {
        $id    = (int) ($_POST["id"] ?? 0);
        $name  = trim($_POST["name"] ?? "");
        $tag   = trim($_POST["tag"] ?? "") !== "" ? $makeTag(trim($_POST["tag"] ?? "")) : $makeTag($name);
        $order = max(0, (int) ($_POST["sort_order"] ?? 0));

        if ($id <= 0)     { $errors[] = "Invalid category."; }
        if ($name === "") { $errors[] = "Category name is required."; }
        if ($tag === "")  { $errors[] = "Tag is required."; }

        if (!$errors) {
            // Check if slug already exists for another category
            $check = $mysqli->prepare("SELECT id FROM categories WHERE slug = ? AND id != ? LIMIT 1");
            if ($check) {
                $check->bind_param("si", $tag, $id);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $errors[] = "A category with the tag \"$tag\" already exists.";
                }
                $check->close();
            }

            if (!$errors) {
                try {
                    $stmt = $mysqli->prepare(
                        "UPDATE categories SET name = ?, slug = ?, sort_order = ? WHERE id = ?"
                    );
                    if ($stmt) {
                        $stmt->bind_param("ssii", $name, $tag, $order, $id);
                        if ($stmt->execute()) {
                            $flash[] = "Category updated.";
                        } else {
                            $errors[] = "Could not update category.";
                        }
                        $stmt->close();
                    }
                } catch (mysqli_sql_exception $e) {
                    $errors[] = $e->getCode() === 1062
                        ? "A category with the tag \"$tag\" already exists."
                        : "Could not update category.";
                }
            }
        }
    }

    // ── Toggle active / inactive ──────────────────────────────────────────────
    if ($action === "toggle") {
        $id = (int) ($_POST["id"] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $mysqli->prepare(
                    "UPDATE categories SET is_active = 1 - is_active WHERE id = ?"
                );
                if ($stmt) {
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    $flash[] = "Category status updated.";
                }
            } catch (mysqli_sql_exception $e) {
                $errors[] = "Could not update category status.";
            }
        }
    }

    // ── Delete category ───────────────────────────────────────────────────────
    if ($action === "delete") {
        $id = (int) ($_POST["id"] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $mysqli->prepare("DELETE FROM categories WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();
                    $flash[] = "Category deleted.";
                }
            } catch (mysqli_sql_exception $e) {
                $errors[] = "Cannot delete this category because it is in use by products.";
            }
        }
    }

    if (!$errors) {
        header("Location: categories.php?flash=" . urlencode(implode(" ", $flash)));
        exit;
    }
}

// Flash from redirect
if (isset($_GET["flash"]) && $_GET["flash"] !== "") {
    $flash[] = htmlspecialchars(urldecode($_GET["flash"]));
}

$categories = admin_fetch_categories($mysqli);
// Keep modal open on error if it was an add action
$showAddModal = !empty($errors) && ($_POST["action"] ?? "") === "add";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — Categories</title>
    <link rel="stylesheet" href="../assets/styles.css?v=cat-2">
    <link rel="stylesheet" href="../assets/store-admin.css?v=cat-2">
    <link rel="stylesheet" href="assets/admin.css?v=cat-2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ── Shared modal backdrop ───────────────────────────────────── */
        .cat-modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 900;
            display: flex; align-items: center; justify-content: center;
        }
        .cat-modal-backdrop[hidden] { display: none !important; }

        .cat-modal-panel {
            background: #fff;
            border-radius: 18px;
            padding: 26px 28px;
            width: min(480px, 94vw);
            display: grid;
            gap: 14px;
            position: relative;
        }
        .cat-modal-panel h2 {
            margin: 0;
            font-family: "Plus Jakarta Sans", system-ui, sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: #FF5B2E;
        }
        .cat-modal-field { display: grid; gap: 4px; }
        .cat-modal-field label { font-size: 12px; font-weight: 600; color: rgba(0,0,0,.6); }
        .cat-modal-field input,
        .cat-modal-field select {
            height: 42px;
            padding: 0 13px;
            border: 1px solid rgba(255,91,46,.22);
            border-radius: 10px;
            font-size: 13.5px;
            outline: none;
            width: 100%;
            box-sizing: border-box;
            transition: border-color .15s;
            background: #fff;
        }
        .cat-modal-field input:focus,
        .cat-modal-field select:focus { border-color: #FF5B2E; }
        .cat-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 4px; }
        .cat-modal-close {
            position: absolute; top: 14px; right: 14px;
            width: 30px; height: 30px; border: 0; border-radius: 8px;
            background: rgba(0,0,0,.06); cursor: pointer;
            font-size: 17px; display: flex; align-items: center; justify-content: center;
            color: rgba(0,0,0,.55);
        }
        .cat-modal-close:hover { background: rgba(0,0,0,.12); }

        /* ── Badges ─────────────────────────────────────────────────── */
        .cat-badge {
            display: inline-block;
            padding: 3px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.4;
        }
        .cat-badge.active   { background: rgba(34,197,94,.15); color: #16a34a; }
        .cat-badge.inactive { background: rgba(0,0,0,.07);     color: rgba(0,0,0,.45); }

        .cat-tag {
            font-family: monospace;
            font-size: 12.5px;
            background: rgba(255,91,46,.07);
            color: #FF5B2E;
            border-radius: 6px;
            padding: 2px 7px;
        }

        /* ── Notice banners ─────────────────────────────────────────── */
        .cat-notice {
            padding: 11px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .cat-notice.success { background: rgba(34,197,94,.12); color: #15803d; }
        .cat-notice.error   { background: rgba(239,68,68,.1);  color: #b91c1c; }
        .cat-notice-dismiss {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: none;
            background: rgba(0, 0, 0, 0.08);
            color: currentColor;
            font-size: 15px;
            line-height: 1;
            cursor: pointer;
            flex-shrink: 0;
            padding: 0;
            transition: background 0.15s;
        }
        .cat-notice-dismiss:hover { background: rgba(0, 0, 0, 0.16); }

        /* ── Section top actions ─────────────────────────────────────── */
        .cat-section-actions { display: flex; gap: 10px; align-items: center; }

        /* ── Table action buttons ────────────────────────────────────── */
        .cat-btn-toggle-on, .cat-btn-toggle-off, .cat-btn-edit, .cat-btn-delete, .cat-btn-primary {
            height: 32px; padding: 0 14px;
            border: 0; border-radius: 8px;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: background .15s;
        }
        .cat-btn-primary { height: 38px; padding: 0 20px; font-size: 13px; background: #FF5B2E; color: #fff; border-radius: 10px; }
        .cat-btn-primary:hover { background: #e04a1f; }
        .cat-btn-toggle-on  { background: rgba(34,197,94,.13); color: #15803d; }
        .cat-btn-toggle-on:hover  { background: rgba(34,197,94,.24); }
        .cat-btn-toggle-off { background: rgba(0,0,0,.07); color: rgba(0,0,0,.55); }
        .cat-btn-toggle-off:hover { background: rgba(0,0,0,.13); }
        .cat-btn-edit   { background: rgba(255,91,46,.1); color: #FF5B2E; }
        .cat-btn-edit:hover { background: rgba(255,91,46,.2); }
        .cat-btn-delete { background: rgba(239,68,68,.1); color: #dc2626; }
        .cat-btn-delete:hover { background: rgba(239,68,68,.2); }
        .cat-btn-save {
            height: 40px; padding: 0 22px;
            background: #FF5B2E; color: #fff;
            border: 0; border-radius: 10px;
            font-size: 13px; font-weight: 700; cursor: pointer;
            transition: background .15s;
        }
        .cat-btn-save:hover { background: #e04a1f; }
        .cat-btn-cancel {
            height: 40px; padding: 0 18px;
            background: rgba(0,0,0,.07); color: rgba(0,0,0,.7);
            border: 0; border-radius: 10px;
            font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .cat-btn-cancel:hover { background: rgba(0,0,0,.12); }
    </style>
</head>
<body class="store-admin-body admin-body">
    <header class="top-bar">
        <a class="logo admin-header-logo" href="dashboard.php" aria-label="Admin dashboard">
            <img src="../732961553_1045061465131627_5347302832846310517_n.png" alt="Logo">
        </a>
        <?php echo admin_nav("categories"); ?>
    </header>

    <main class="admin-shell">
        <section class="admin-section" style="padding:24px 22px; display:grid; gap:20px;">

            <div class="admin-section-head">
                <div>
                    <h1>Categories</h1>
                    <p>Manage store categories. Active categories appear as filter pills on the home map sidebar.</p>
                </div>
                <div class="cat-section-actions">
                    <button type="button" class="cat-btn-primary" onclick="openAddModal()">+ Add Category</button>
                </div>
            </div>

            <?php if ($flash): ?>
                <?php foreach ($flash as $idx => $msg): ?>
                    <div class="cat-notice success cat-banner" id="cat-flash-<?php echo $idx; ?>">
                        <span><?php echo $msg; ?></span>
                        <button class="cat-notice-dismiss" onclick="dismissCatNotice('cat-flash-<?php echo $idx; ?>')" type="button" aria-label="Dismiss">&times;</button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($errors && !$showAddModal): ?>
                <div class="cat-notice error cat-banner" id="cat-banner-error">
                    <div style="flex:1;">
                        <?php foreach ($errors as $err): ?>
                            <div><?php echo escape($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                    <button class="cat-notice-dismiss" onclick="dismissCatNotice('cat-banner-error')" type="button" aria-label="Dismiss">&times;</button>
                </div>
            <?php endif; ?>

            <!-- ── Categories Table ───────────────────────────────────────────── -->
            <div class="admin-table-wrap">
                <table class="admin-table admin-action-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Tag</th>
                            <th>Status</th>
                            <th>Order</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="6" style="text-align:center;color:rgba(0,0,0,.45);padding:22px;">No categories yet. Click "Add Category" to create one.</td></tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?php echo (int) $cat["id"]; ?></td>
                                    <td><strong><?php echo escape($cat["name"]); ?></strong></td>
                                    <td><span class="cat-tag"><?php echo escape($cat["slug"]); ?></span></td>
                                    <td>
                                        <?php if ($cat["is_active"]): ?>
                                            <span class="cat-badge active">Active</span>
                                        <?php else: ?>
                                            <span class="cat-badge inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $cat["sort_order"]; ?></td>
                                    <td>
                                        <div class="admin-table-actions">
                                            <button type="button"
                                                class="cat-btn-edit"
                                                onclick="openEditModal(<?php echo (int) $cat['id']; ?>, '<?php echo escape(addslashes($cat['name'])); ?>', '<?php echo escape(addslashes($cat['slug'])); ?>', <?php echo (int) $cat['sort_order']; ?>)">
                                                Edit
                                            </button>
                                            <form method="post" style="display:inline" class="category-toggle-form" data-category-name="<?php echo escape($cat['name']); ?>" data-next-state="<?php echo $cat['is_active'] ? 'inactive' : 'active'; ?>">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="id" value="<?php echo (int) $cat['id']; ?>">
                                                <button type="button"
                                                    class="<?php echo $cat['is_active'] ? 'cat-btn-toggle-on' : 'cat-btn-toggle-off'; ?>">
                                                    <?php echo $cat['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </section>
    </main>

    <!-- ── Add Category Modal ─────────────────────────────────────────────────── -->
    <div id="add-modal" class="cat-modal-backdrop" <?php echo $showAddModal ? '' : 'hidden'; ?>>
        <div class="cat-modal-panel" role="dialog" aria-modal="true" aria-labelledby="add-modal-title">
            <button type="button" class="cat-modal-close" onclick="closeAddModal()" aria-label="Close">&times;</button>
            <h2 id="add-modal-title">Add New Category</h2>
            <?php if ($errors && $showAddModal): ?>
                <div class="cat-notice error">
                    <?php foreach ($errors as $err): ?>
                        <div><?php echo escape($err); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="add">
                <div style="display:grid;gap:12px;">
                    <div class="cat-modal-field">
                        <label for="add-name">Name <span style="color:#FF5B2E">*</span></label>
                        <input type="text" id="add-name" name="name"
                            value="<?php echo escape($_POST['name'] ?? ''); ?>"
                            placeholder="e.g. Bakery" required maxlength="80">
                    </div>
                    <div class="cat-modal-field">
                        <label for="add-tag">Tag <small style="color:rgba(0,0,0,.45)">(auto-generated, or override)</small></label>
                        <input type="text" id="add-tag" name="tag"
                            value="<?php echo escape($_POST['tag'] ?? ''); ?>"
                            maxlength="80" placeholder="leave blank to auto-generate from name">
                    </div>
                    <div class="cat-modal-field">
                        <label for="add-order">Sort Order</label>
                        <input type="number" id="add-order" name="sort_order"
                            value="<?php echo (int) ($_POST['sort_order'] ?? 0); ?>"
                            min="0" max="999">
                    </div>
                </div>
                <div class="cat-modal-actions">
                    <button type="button" class="cat-btn-cancel" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="cat-btn-save">Add Category</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toggle-confirm-modal" class="cat-modal-backdrop" hidden>
        <div class="cat-modal-panel" role="dialog" aria-modal="true" aria-labelledby="toggle-confirm-title" style="max-width:420px;">
            <button type="button" class="cat-modal-close" id="close-toggle-confirm" aria-label="Close">&times;</button>
            <h2 id="toggle-confirm-title">Confirm category status</h2>
            <p id="toggle-confirm-message" style="color:#475569;line-height:1.5;"></p>
            <div class="cat-modal-actions">
                <button type="button" class="cat-btn-cancel" id="cancel-toggle-confirm">Cancel</button>
                <button type="button" class="cat-btn-save" id="confirm-toggle">Confirm</button>
            </div>
        </div>
    </div>

    <!-- ── Edit Category Modal ────────────────────────────────────────────────── -->
    <div id="edit-modal" class="cat-modal-backdrop" hidden>
        <div class="cat-modal-panel" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
            <button type="button" class="cat-modal-close" onclick="closeEditModal()" aria-label="Close">&times;</button>
            <h2 id="edit-modal-title">Edit Category</h2>
            <form method="post" id="edit-form" autocomplete="off">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div style="display:grid;gap:12px;">
                    <div class="cat-modal-field">
                        <label for="edit-name">Name <span style="color:#FF5B2E">*</span></label>
                        <input type="text" id="edit-name" name="name" required maxlength="80">
                    </div>
                    <div class="cat-modal-field">
                        <label for="edit-tag">Tag <small style="color:rgba(0,0,0,.45)">(auto-generated, or override)</small></label>
                        <input type="text" id="edit-tag" name="tag" maxlength="80" placeholder="leave blank to auto-generate from name">
                    </div>
                    <div class="cat-modal-field">
                        <label for="edit-order">Sort Order</label>
                        <input type="number" id="edit-order" name="sort_order" min="0" max="999">
                    </div>
                </div>
                <div class="cat-modal-actions">
                    <button type="button" class="cat-btn-cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="cat-btn-save">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/admin-modals.js"></script>
    <script>
        const addModal  = document.getElementById("add-modal");
        const editModal = document.getElementById("edit-modal");
        const toggleModal = document.getElementById("toggle-confirm-modal");
        let pendingToggleForm = null;

        function openAddModal()  { addModal.hidden = false;  document.getElementById("add-name").focus(); }
        function closeAddModal() { addModal.hidden = true; }

        function openEditModal(id, name, tag, order) {
            document.getElementById("edit-id").value    = id;
            document.getElementById("edit-name").value  = name;
            document.getElementById("edit-tag").value   = tag;
            document.getElementById("edit-order").value = order;
            editModal.hidden = false;
            document.getElementById("edit-name").focus();
        }
        function closeEditModal() { editModal.hidden = true; }
        function closeToggleModal() { if (toggleModal) toggleModal.hidden = true; pendingToggleForm = null; }

        document.querySelectorAll(".category-toggle-form").forEach(form => {
            form.querySelector("button[type='button']").addEventListener("click", () => {
                pendingToggleForm = form;
                const categoryName = form.dataset.categoryName || "this category";
                const nextState = form.dataset.nextState || "active";
                document.getElementById("toggle-confirm-message").textContent =
                    `Are you sure you want to ${nextState === "active" ? "activate" : "deactivate"} ${categoryName}?`;
                toggleModal.hidden = false;
            });
        });
        document.getElementById("confirm-toggle").addEventListener("click", () => {
            if (pendingToggleForm) pendingToggleForm.submit();
        });
        document.getElementById("close-toggle-confirm").addEventListener("click", closeToggleModal);
        document.getElementById("cancel-toggle-confirm").addEventListener("click", closeToggleModal);

        // Close on backdrop click
        addModal.addEventListener("click",  e => { if (e.target === addModal)  closeAddModal(); });
        editModal.addEventListener("click", e => { if (e.target === editModal) closeEditModal(); });
        toggleModal.addEventListener("click", e => { if (e.target === toggleModal) closeToggleModal(); });

        document.addEventListener("keydown", e => {
            if (e.key === "Escape") { closeAddModal(); closeEditModal(); closeToggleModal(); }
        });

        function dismissCatNotice(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.style.transition = "opacity 0.25s ease, transform 0.25s ease";
            el.style.opacity = "0";
            el.style.transform = "translateY(-4px)";
            setTimeout(() => el.remove(), 260);
            if (window.history.replaceState) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }

        document.querySelectorAll(".cat-banner").forEach(el => {
            setTimeout(() => dismissCatNotice(el.id), 5000);
        });
    </script>
</body>
</html>
