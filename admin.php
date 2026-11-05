<?php
require __DIR__ . '/config.php';
require_admin();
$msg = ''; $err = '';

function save_upload($f) {
    if (!isset($f) || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) throw new Exception('Use a JPG, PNG or WebP image.');
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/uploads/' . $name)) throw new Exception('Could not save the image.');
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $a = $_POST['action'] ?? '';
    try {
        if ($a === 'add_product') {
            $name = trim($_POST['name'] ?? ''); $cat = $_POST['category'] ?? '';
            $price = (float)($_POST['price'] ?? 0); $stock = (int)($_POST['stock'] ?? 0);
            if ($name === '' || !in_array($cat, CATEGORIES, true) || $price <= 0 || $stock < 0) throw new Exception('Fill in the name, category, a price above zero and the stock.');
            $img = save_upload($_FILES['image'] ?? null);
            $pdo->prepare('INSERT INTO products (name, category, description, price, stock, image) VALUES (?,?,?,?,?,?)')
                    ->execute([mb_substr($name, 0, 120), $cat, trim($_POST['description'] ?? ''), $price, $stock, $img]);
            $msg = 'Product added.';
        } elseif ($a === 'edit_product') {
            $id = (int)($_POST['product_id'] ?? 0);
            $name = trim($_POST['name'] ?? ''); $cat = $_POST['category'] ?? '';
            $price = (float)($_POST['price'] ?? 0); $stock = (int)($_POST['stock'] ?? 0);
            if ($name === '' || !in_array($cat, CATEGORIES, true) || $price <= 0 || $stock < 0) throw new Exception('Fill in the name, category, a price above zero and the stock.');
            $old = $pdo->prepare('SELECT image FROM products WHERE id = ?'); $old->execute([$id]);
            $oldImg = $old->fetchColumn();
            if ($oldImg === false) throw new Exception('Product not found.');
            $new = save_upload($_FILES['image'] ?? null);
            $remove = isset($_POST['remove_image']);
            $img = $new ?? ($remove ? null : $oldImg);
            $pdo->prepare('UPDATE products SET name=?, category=?, description=?, price=?, stock=?, image=? WHERE id=?')
                    ->execute([mb_substr($name, 0, 120), $cat, trim($_POST['description'] ?? ''), $price, $stock, $img, $id]);
            if ($oldImg && $img !== $oldImg) @unlink(__DIR__ . '/uploads/' . basename($oldImg));
            $msg = 'Product updated.';
        } elseif ($a === 'set_status') {
            if (in_array($_POST['status'] ?? '', ['pending', 'processing', 'shipped', 'completed', 'cancelled'], true))
                $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$_POST['status'], (int)$_POST['order_id']]);
            $msg = 'Order status updated.';
        } elseif ($a === 'delete_product') {
            $d = $pdo->prepare('SELECT image FROM products WHERE id = ?'); $d->execute([(int)$_POST['product_id']]);
            $dImg = $d->fetchColumn();
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$_POST['product_id']]);
            if ($dImg) @unlink(__DIR__ . '/uploads/' . basename($dImg));
            $msg = 'Product deleted.';
        }
    } catch (Exception $ex) { $err = $ex->getMessage(); }
}

$editP = null;
if (isset($_GET['edit'])) { $q = $pdo->prepare('SELECT * FROM products WHERE id = ?'); $q->execute([(int)$_GET['edit']]); $editP = $q->fetch() ?: null; }

$kpi = $pdo->query("SELECT COALESCE(SUM(total),0) rev, COUNT(*) orders FROM orders WHERE status <> 'cancelled'")->fetch();
$pcs = (int)$pdo->query("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status <> 'cancelled'")->fetchColumn();
$perItem = $pdo->query("SELECT oi.name, SUM(oi.qty) pcs, SUM(oi.qty * oi.price) rev FROM order_items oi
    JOIN orders o ON o.id = oi.order_id WHERE o.status <> 'cancelled' GROUP BY oi.product_id, oi.name ORDER BY pcs DESC")->fetchAll();
$sales = $pdo->query('SELECT o.*, u.name customer FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 50')->fetchAll();
$products = $pdo->query('SELECT * FROM products ORDER BY id DESC')->fetchAll();

page_header('Admin');
?>
    <h1 class="page-title">Admin dashboard</h1>
<?php if ($msg): ?><p class="okmsg"><?= e($msg) ?></p><?php endif; ?>
<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>

<?php if ($editP): ?>
    <section class="panel" id="edit">
        <h2>Edit: <?= e($editP['name']) ?></h2>
        <form method="post" enctype="multipart/form-data" class="form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="edit_product">
            <input type="hidden" name="product_id" value="<?= (int)$editP['id'] ?>">
            <label>Product name <input name="name" required maxlength="120" value="<?= e($editP['name']) ?>"></label>
            <label>Category <select name="category" required><?php foreach (CATEGORIES as $c) echo '<option' . ($editP['category'] === $c ? ' selected' : '') . '>' . e($c) . '</option>'; ?></select></label>
            <div class="pair">
                <label>Price (₱) <input type="number" name="price" min="0.01" step="0.01" required value="<?= e($editP['price']) ?>"></label>
                <label>Stock <input type="number" name="stock" min="0" required value="<?= (int)$editP['stock'] ?>"></label>
            </div>
            <label>Details <textarea name="description" rows="4"><?= e($editP['description']) ?></textarea></label>
            <div class="who"><?= product_img($editP, 'sm') ?><span class="muted small">Current photo</span></div>
            <label>Replace photo (JPG, PNG or WebP, up to 2 MB) <input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            <?php if ($editP['image']): ?><label class="check"><input type="checkbox" name="remove_image"> Remove current photo</label><?php endif; ?>
            <div class="inline-form"><button class="btn dark">Save changes</button><a class="btn small" href="admin.php">Cancel</a></div>
        </form>
    </section>
<?php endif; ?>

    <div class="kpis">
        <div class="kpi"><span class="mono muted">Total revenue</span><strong><?= peso($kpi['rev']) ?></strong></div>
        <div class="kpi"><span class="mono muted">Orders</span><strong><?= (int)$kpi['orders'] ?></strong></div>
        <div class="kpi"><span class="mono muted">Pieces sold</span><strong><?= $pcs ?></strong></div>
    </div>
    <p class="muted small">Cancelled orders are not counted.</p>

    <div class="two-col">
        <section class="panel">
            <h2>Pieces sold per item</h2>
            <?php if (!$perItem): ?><p class="muted">No sales yet.</p><?php else: ?>
                <div class="scroll"><table><thead><tr><th>Item</th><th class="r">Pieces</th><th class="r">Revenue</th></tr></thead><tbody>
                    <?php foreach ($perItem as $i): ?><tr><td><?= e($i['name']) ?></td><td class="r"><?= (int)$i['pcs'] ?></td><td class="r"><?= peso($i['rev']) ?></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
        </section>

        <section class="panel">
            <h2>Add a product</h2>
            <form method="post" enctype="multipart/form-data" class="form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_product">
                <label>Product name <input name="name" required maxlength="120"></label>
                <label>Category <select name="category" required><?php foreach (CATEGORIES as $c) echo '<option>' . e($c) . '</option>'; ?></select></label>
                <div class="pair">
                    <label>Price (₱) <input type="number" name="price" min="0.01" step="0.01" required></label>
                    <label>Stock <input type="number" name="stock" min="0" value="0" required></label>
                </div>
                <label>Details <textarea name="description" rows="3"></textarea></label>
                <label>Image (JPG, PNG or WebP, up to 2 MB) <input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
                <button class="btn dark">Add product</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <h2>Sales records</h2>
        <?php if (!$sales): ?><p class="muted">No orders yet.</p><?php else: ?>
            <div class="scroll"><table><thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Method</th><th class="r">Total</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($sales as $o): ?>
                    <tr>
                        <td>BB-<?= str_pad($o['id'], 6, '0', STR_PAD_LEFT) ?></td>
                        <td><?= e(date('M j, Y', strtotime($o['created_at']))) ?></td>
                        <td><?= e($o['customer']) ?></td>
                        <td><?= e(ucfirst($o['method'])) ?></td>
                        <td class="r"><?= peso($o['total']) ?></td>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="set_status">
                                <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                                <select name="status" aria-label="Status for order <?= (int)$o['id'] ?>">
                                    <?php foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $s) echo '<option value="' . $s . '"' . ($o['status'] === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; ?>
                                </select>
                                <button class="btn small">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
    </section>

    <section class="panel">
        <h2>Products</h2>
        <div class="scroll"><table><thead><tr><th></th><th>Name</th><th>Category</th><th class="r">Price</th><th class="r">Stock</th><th></th><th></th></tr></thead><tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><?= product_img($p, 'xs') ?></td>
                        <td><?= e($p['name']) ?></td><td><?= e($p['category']) ?></td>
                        <td class="r"><?= peso($p['price']) ?></td><td class="r"><?= (int)$p['stock'] ?></td>
                        <td><a class="linkbtn" href="admin.php?edit=<?= (int)$p['id'] ?>#edit">Edit</a></td>
                        <td><form method="post" onsubmit="return confirm('Delete this product?')">
                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete_product"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                                <button class="linkbtn danger">Delete</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
    </section>
<?php page_footer(); ?>