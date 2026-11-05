<?php
require __DIR__ . '/config.php';
$page = $_GET['page'] ?? 'home';
if (!in_array($page, ['home', 'products', 'product', 'cart', 'account', 'login', 'register', 'logout'], true)) $page = 'home';
('page_' . $page)($pdo);

function page_home($pdo) {
    $popular = $pdo->query('SELECT p.* FROM products p WHERE p.stock > 0
        ORDER BY (SELECT COALESCE(SUM(qty),0) FROM order_items WHERE product_id = p.id) DESC, p.id DESC LIMIT 4')->fetchAll();
    $counts = $pdo->query('SELECT category, COUNT(*) n FROM products GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
    page_header('Small parts, big possibilities'); ?>
    <section class="hero">
        <div class="hero-copy">
            <p class="mono">Electronics parts for students and hobbyists</p>
            <h1>Small parts.<br>Big possibilities.</h1>
            <p class="lede">Resistors, breadboards, Arduino boards and ESP32s with local stock, so your build does not wait on shipping from overseas.</p>
            <a class="btn coral" href="index.php?page=products">Shop components</a>
        </div>
        <div class="hero-art" aria-hidden="true">
            <span class="bubble b1">NE555</span>
            <div class="chip"><b>IC · 555</b></div>
            <span class="bubble b2">1 kΩ</span>
        </div>
    </section>
    <section class="block">
        <div class="block-head"><h2>Shop by category</h2></div>
        <div class="cats">
            <?php foreach (CATEGORIES as $c): ?>
                <a class="cat" href="index.php?page=products&cat=<?= urlencode($c) ?>"><strong><?= e($c) ?></strong><span class="mono muted"><?= (int)($counts[$c] ?? 0) ?> items</span></a>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="block">
        <div class="block-head"><h2>Popular on the workbench</h2><a class="mono" href="index.php?page=products">View all</a></div>
        <div class="grid"><?php foreach ($popular as $p) echo product_card($p); ?></div>
    </section>
    <?php page_footer(); }

function page_products($pdo) {
    $cat = $_GET['cat'] ?? ''; $q = trim($_GET['q'] ?? ''); $max = (int)($_GET['max'] ?? 0);
    $sort = $_GET['sort'] ?? 'featured'; $inStock = isset($_GET['stock']);
    $where = ['1']; $args = [];
    if (in_array($cat, CATEGORIES, true)) { $where[] = 'category = ?'; $args[] = $cat; } else { $cat = ''; }
    if ($q !== '') { $where[] = '(name LIKE ? OR description LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; }
    if ($max > 0) { $where[] = 'price <= ?'; $args[] = $max; }
    if ($inStock) $where[] = 'stock > 0';
    $order = ['price_asc' => 'price ASC', 'price_desc' => 'price DESC', 'newest' => 'id DESC'][$sort] ?? 'id ASC';
    $s = $pdo->prepare('SELECT * FROM products WHERE ' . implode(' AND ', $where) . " ORDER BY $order");
    $s->execute($args);
    $items = $s->fetchAll();
    $counts = $pdo->query('SELECT category, COUNT(*) n FROM products GROUP BY category')->fetchAll(PDO::FETCH_KEY_PAIR);
    page_header('Components'); ?>
    <section class="banner sage">
        <p class="mono">Component directory</p>
        <h1>Find the exact part.</h1>
        <p class="mono muted"><?= count($items) ?> result<?= count($items) === 1 ? '' : 's' ?></p>
    </section>
    <div class="catalog">
        <aside class="side">
            <h2>Shop by category</h2>
            <ul class="catlist">
                <li><a href="index.php?page=products" class="<?= $cat === '' ? 'on' : '' ?>">All components (<?= array_sum($counts) ?>)</a></li>
                <?php foreach (CATEGORIES as $c): ?>
                    <li><a href="index.php?page=products&cat=<?= urlencode($c) ?>" class="<?= $cat === $c ? 'on' : '' ?>"><?= e($c) ?> (<?= (int)($counts[$c] ?? 0) ?>)</a></li>
                <?php endforeach; ?>
            </ul>
            <form method="get" class="filters">
                <input type="hidden" name="page" value="products">
                <input type="hidden" name="cat" value="<?= e($cat) ?>">
                <label>Search <input type="search" name="q" value="<?= e($q) ?>"></label>
                <label>Max price (₱) <input type="number" name="max" min="0" step="10" value="<?= $max ?: '' ?>" placeholder="Any"></label>
                <label>Sort by
                    <select name="sort">
                        <option value="featured">Featured</option>
                        <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: low to high</option>
                        <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: high to low</option>
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                    </select>
                </label>
                <label class="check"><input type="checkbox" name="stock" <?= $inStock ? 'checked' : '' ?>> In stock only</label>
                <button class="btn dark">Apply filters</button>
            </form>
        </aside>
        <section>
            <?php if (!$items): ?>
                <div class="empty"><p>No parts match those filters.</p><a class="btn dark" href="index.php?page=products">Clear filters</a></div>
            <?php else: ?>
                <div class="grid three"><?php foreach ($items as $p) echo product_card($p); ?></div>
            <?php endif; ?>
        </section>
    </div>
    <?php page_footer(); }

function page_product($pdo) {
    $s = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $s->execute([(int)($_GET['id'] ?? 0)]);
    $p = $s->fetch();
    if (!$p) {
        http_response_code(404); page_header('Not found');
        echo '<div class="empty"><p>That product does not exist.</p><a class="btn dark" href="index.php?page=products">Back to components</a></div>';
        page_footer(); return;
    }
    $out = $p['stock'] < 1;
    page_header($p['name']); ?>
    <a class="mono back" href="index.php?page=products">← All components</a>
    <section class="detail">
        <?= product_img($p, 'big') ?>
        <div>
            <p class="mono muted"><?= e($p['category']) ?></p>
            <h1><?= e($p['name']) ?></h1>
            <p class="price"><?= peso($p['price']) ?></p>
            <p class="lede"><?= nl2br(e($p['description'])) ?></p>
            <p class="mono <?= $out ? 'bad' : 'muted' ?>"><?= $out ? 'Sold out' : (int)$p['stock'] . ' in stock' ?></p>
            <?php if (!$out): ?>
                <div class="buy-row">
                    <div class="stepper">
                        <button type="button" class="qty-btn" data-step="-1" aria-label="Decrease quantity">−</button>
                        <input id="qty" class="qty-input" type="number" min="1" max="<?= (int)$p['stock'] ?>" value="1" aria-label="Quantity">
                        <button type="button" class="qty-btn" data-step="1" aria-label="Increase quantity">+</button>
                    </div>
                    <button class="btn dark" data-add="<?= (int)$p['id'] ?>" data-qty-from="#qty">Add to bag</button>
                    <button class="btn coral" data-buy="<?= (int)$p['id'] ?>" data-qty-from="#qty">Buy now</button>
                </div>
                <?php if (!user()): ?><p class="mono muted">You can browse freely. Log in to add items to your bag.</p><?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
    <?php page_footer(); }

function page_cart($pdo) {
    require_login();
    $rows = cart_rows($pdo, user()['id']);
    page_header('Your bag'); ?>
    <h1 class="page-title">Your bag</h1>
    <?php if (!$rows): ?>
        <div class="empty"><p>Your bag is empty. Pick a few parts to get started.</p><a class="btn dark" href="index.php?page=products">Browse components</a></div>
    <?php else: $sub = 0; foreach ($rows as $r) $sub += $r['price'] * $r['qty']; [$s, $d, $t] = totals($sub); ?>
        <div class="cart">
            <div class="cart-list">
                <div class="cart-head mono muted"><span>Product</span><span>Unit price</span><span>Quantity</span><span>Subtotal</span><span></span></div>
                <?php foreach ($rows as $r): ?>
                    <div class="cart-row" data-row="<?= (int)$r['id'] ?>">
                        <div class="who">
                            <a href="index.php?page=product&id=<?= (int)$r['id'] ?>"><?= product_img($r, 'sm') ?></a>
                            <div><a class="card-name" href="index.php?page=product&id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a>
                                <p class="mono muted"><?= (int)$r['stock'] ?> in stock</p></div>
                        </div>
                        <div class="unit"><?= peso($r['price']) ?></div>
                        <div class="stepper">
                            <button type="button" class="qty-btn" data-step="-1" aria-label="Decrease quantity">−</button>
                            <input class="qty-input" type="number" min="1" max="<?= (int)$r['stock'] ?>" value="<?= (int)$r['qty'] ?>" aria-label="Quantity for <?= e($r['name']) ?>">
                            <button type="button" class="qty-btn" data-step="1" aria-label="Increase quantity">+</button>
                        </div>
                        <div class="line" data-line><?= peso($r['price'] * $r['qty']) ?></div>
                        <button class="linkbtn danger" data-remove>Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <aside class="summary">
                <h2>Order summary</h2>
                <div class="sum-row"><span>Subtotal</span><span id="sumSub"><?= peso($s) ?></span></div>
                <div class="sum-row"><span>Discount <small class="muted">(5% off ₱1,000+)</small></span><span id="sumDisc">−<?= peso($d) ?></span></div>
                <div class="sum-row total"><span>Total</span><span id="sumTotal"><?= peso($t) ?></span></div>
                <label>Get it by
                    <select id="method"><option value="delivery">Delivery (about 3 days)</option><option value="pickup">Pickup (ready next day)</option></select>
                </label>
                <button class="btn coral wide" id="checkout">Proceed to checkout</button>
            </aside>
        </div>
    <?php endif; page_footer(); }

function page_account($pdo) {
    require_login();
    $uid = user()['id']; $saved = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $pdo->prepare('UPDATE users SET name=?, phone=?, address=? WHERE id=?')
                    ->execute([mb_substr($name, 0, 80), mb_substr(trim($_POST['phone'] ?? ''), 0, 30), mb_substr(trim($_POST['address'] ?? ''), 0, 255), $uid]);
            $_SESSION['user']['name'] = $name; $saved = true;
        }
    }
    $me = $pdo->prepare('SELECT * FROM users WHERE id = ?'); $me->execute([$uid]); $me = $me->fetch();
    $os = $pdo->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC'); $os->execute([$uid]); $orders = $os->fetchAll();
    $byOrder = [];
    if ($orders) {
        $ids = array_column($orders, 'id');
        $q = $pdo->prepare('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute($ids);
        foreach ($q->fetchAll() as $i) $byOrder[$i['order_id']][] = $i;
    }
    page_header('My account'); ?>
    <h1 class="page-title">My account</h1>
    <div class="two-col">
        <section class="panel">
            <h2>Profile</h2>
            <?php if ($saved): ?><p class="okmsg">Profile saved.</p><?php endif; ?>
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <label>Full name <input name="name" required maxlength="80" value="<?= e($me['name']) ?>"></label>
                <label>Email <input value="<?= e($me['email']) ?>" disabled></label>
                <label>Phone <input name="phone" maxlength="30" value="<?= e($me['phone']) ?>"></label>
                <label>Delivery address <textarea name="address" rows="3" maxlength="255"><?= e($me['address']) ?></textarea></label>
                <button class="btn dark">Save profile</button>
            </form>
        </section>
        <section>
            <h2>Order history</h2>
            <?php if (!$orders): ?>
                <div class="empty"><p>No orders yet.</p><a class="btn dark" href="index.php?page=products">Browse components</a></div>
            <?php endif; foreach ($orders as $o): ?>
                <article class="order">
                    <header>
                        <div><strong>BB-<?= str_pad($o['id'], 6, '0', STR_PAD_LEFT) ?></strong>
                            <p class="mono muted"><?= e(date('M j, Y g:i A', strtotime($o['created_at']))) ?></p></div>
                        <span class="badge s-<?= e($o['status']) ?>"><?= e(ucfirst($o['status'])) ?></span>
                    </header>
                    <ul>
                        <?php foreach ($byOrder[$o['id']] ?? [] as $i): ?>
                            <li><span><?= e($i['name']) ?> × <?= (int)$i['qty'] ?></span><span><?= peso($i['price'] * $i['qty']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <footer>
                        <span class="mono muted"><?= $o['method'] === 'pickup' ? 'Pickup' : 'Delivery' ?> · <?= e(date('M j, Y', strtotime($o['eta_date']))) ?></span>
                        <strong><?= peso($o['total']) ?></strong>
                    </footer>
                </article>
            <?php endforeach; ?>
        </section>
    </div>
    <?php page_footer(); }

function page_login($pdo) {
    $next = safe_next($_GET['next'] ?? $_POST['next'] ?? '');
    if (user()) { header('Location: ' . $next); exit; }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $s = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $s->execute([strtolower(trim($_POST['email'] ?? ''))]);
        $u = $s->fetch();
        if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role']];
            header('Location: ' . ($u['role'] === 'admin' && $next === 'index.php' ? 'admin.php' : $next));
            exit;
        }
        $error = 'Email or password is incorrect.';
    }
    page_header('Log in'); ?>
    <section class="auth">
        <div class="auth-art"><div class="brand"><img src="assets/logo.svg" alt="">byte.busters</div>
            <h2>Your next build starts here.</h2><small>BUILD · TEST · REPEAT</small></div>
        <div class="auth-form">
            <p class="eyebrow">Welcome back</p>
            <h1>Log in to Byte Busters</h1>
            <p class="sub">Your bag and orders are right where you left them.</p>
            <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <label>Email <input type="email" name="email" placeholder="Enter your email" required autocomplete="email"></label>
                <label>Password <input type="password" name="password" placeholder="Enter your password" required autocomplete="current-password"></label>
                <button class="btn dark">Log in</button>
            </form>
            <p class="alt">Don't have an account? <a href="index.php?page=register&next=<?= urlencode($next) ?>">Create account</a></p>
        </div>
    </section>
    <?php page_footer(); }

function page_register($pdo) {
    $next = safe_next($_GET['next'] ?? $_POST['next'] ?? '');
    if (user()) { header('Location: index.php'); exit; }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $name = trim($_POST['name'] ?? ''); $email = strtolower(trim($_POST['email'] ?? '')); $pw = $_POST['password'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter your name and a valid email.';
        elseif (strlen($pw) < 8) $error = 'Use a password with at least 8 characters.';
        elseif ($pw !== ($_POST['confirm'] ?? '')) $error = 'The passwords do not match.';
        else {
            $s = $pdo->prepare('SELECT id FROM users WHERE email = ?'); $s->execute([$email]);
            if ($s->fetch()) $error = 'That email already has an account.';
            else {
                $pdo->prepare('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)')
                        ->execute([mb_substr($name, 0, 80), $email, password_hash($pw, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'role' => 'user'];
                header('Location: ' . $next); exit;
            }
        }
    }
    page_header('Create account'); ?>
    <section class="auth flip">
        <div class="auth-art"><div class="brand"><img src="assets/logo.svg" alt="">byte.busters</div>
            <h2>Make room for the parts that move you forward.</h2><small>BUILD · TEST · REPEAT</small></div>
        <div class="auth-form">
            <p class="eyebrow">Start your build</p>
            <h1>Create your account</h1>
            <p class="sub">One home for your orders, bag and delivery details.</p>
            <?php if ($error): ?><p class="alert"><?= e($error) ?></p><?php endif; ?>
            <form method="post" class="form">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="next" value="<?= e($next) ?>">
                <label>Email <input type="email" name="email" placeholder="you@college.edu" required value="<?= e($_POST['email'] ?? '') ?>"></label>
                <label>Username <input name="name" placeholder="Your name" required maxlength="80" value="<?= e($_POST['name'] ?? '') ?>"></label>
                <label>Password <input type="password" name="password" placeholder="At least 8 characters" required minlength="8" autocomplete="new-password"></label>
                <label>Confirm password <input type="password" name="confirm" placeholder="Repeat your password" required minlength="8" autocomplete="new-password"></label>
                <button class="btn dark">Create free account</button>
            </form>
            <p class="alt">Already have an account? <a href="index.php?page=login&next=<?= urlencode($next) ?>">Log in</a></p>
        </div>
    </section>
    <?php page_footer(); }

function page_logout($pdo) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { check_csrf(); $_SESSION = []; session_destroy(); }
    header('Location: index.php'); exit;
}