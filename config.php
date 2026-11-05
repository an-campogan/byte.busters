<?php
date_default_timezone_set('Asia/Manila');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

const DB_HOST = 'localhost', DB_NAME = 'byte_busters', DB_USER = 'root', DB_PASS = '';
const CATEGORIES = ['Microcontrollers', 'Sensors', 'Passives', 'Prototyping', 'Kits'];
const PICKUP_POINT = 'Byte Busters pickup counter, Davao City';
const DISCOUNT_MIN = 1000;   // orders at or above this get the discount
const DISCOUNT_RATE = 0.05;  // 5%

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $ex) {
    http_response_code(500);
    exit('Database connection failed. Check the settings at the top of config.php.');
}

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function peso($n) { return '₱' . number_format((float)$n, 2); }
function user() { return $_SESSION['user'] ?? null; }
function json_out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

function csrf_token() { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function check_csrf() {
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Your session token expired. Go back, refresh the page and try again.');
    }
}

function safe_next($n) {
    $n = (string)$n;
    if ($n === '' || str_starts_with($n, '//') || !preg_match('~^/?[\w\-/\.]+(\?[\w=&\-\.%]*)?$~', $n)) return 'index.php';
    return $n;
}
function require_login() {
    if (!user()) { header('Location: index.php?page=login&next=' . urlencode($_SERVER['REQUEST_URI'])); exit; }
}
function require_admin() {
    require_login();
    if (user()['role'] !== 'admin') { http_response_code(403); exit('Admins only.'); }
}

function cart_rows($pdo, $uid) {
    $s = $pdo->prepare('SELECT p.id, p.name, p.price, p.image, p.stock, p.category, c.qty
                        FROM cart_items c JOIN products p ON p.id = c.product_id
                        WHERE c.user_id = ? ORDER BY c.id');
    $s->execute([$uid]);
    return $s->fetchAll();
}
function cart_count($pdo) {
    if (!user()) return 0;
    $s = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE user_id = ?');
    $s->execute([user()['id']]);
    return (int)$s->fetchColumn();
}
function totals($subtotal) {
    $d = $subtotal >= DISCOUNT_MIN ? round($subtotal * DISCOUNT_RATE, 2) : 0;
    return [(float)$subtotal, (float)$d, (float)$subtotal - $d];
}
function cart_summary($pdo, $uid) {
    $items = []; $sub = 0; $count = 0;
    foreach (cart_rows($pdo, $uid) as $r) {
        $line = $r['price'] * $r['qty'];
        $sub += $line; $count += $r['qty'];
        $items[] = ['id' => (int)$r['id'], 'qty' => (int)$r['qty'], 'line' => $line];
    }
    [$s, $d, $t] = totals($sub);
    return ['ok' => true, 'count' => $count, 'items' => $items, 'subtotal' => $s, 'discount' => $d, 'total' => $t];
}

function product_img($p, $cls = '') {
    $inner = $p['image']
        ? '<img src="' . 'uploads/' . e($p['image']) . '" alt="' . e($p['name']) . '">'
        : '<span class="ph">' . e(mb_strtoupper(mb_substr($p['name'], 0, 2))) . '</span>';
    return '<div class="thumb t-' . (crc32($p['category']) % 4) . ' ' . $cls . '">' . $inner . '</div>';
}
function product_card($p) {
    $out = $p['stock'] < 1;
    return '<article class="card">
      <a href="index.php?page=product&id=' . (int)$p['id'] . '">' . product_img($p) . '</a>
      <div class="card-body">
        <a class="card-name" href="index.php?page=product&id=' . (int)$p['id'] . '">' . e($p['name']) . '</a>
        <p class="mono muted">' . e($p['category']) . ' · ' . ($out ? 'sold out' : (int)$p['stock'] . ' in stock') . '</p>
        <div class="card-foot"><strong>' . peso($p['price']) . '</strong>
        <button class="round" data-add="' . (int)$p['id'] . '" aria-label="Add ' . e($p['name']) . ' to bag"' . ($out ? ' disabled' : '') . '>+</button></div>
      </div></article>';
}

function page_header($title) {
    global $pdo; $u = user(); ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf" content="<?= e(csrf_token()) ?>">
        <title><?= e($title) ?> · Byte Busters</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Mono&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
    <header class="site-header"><div class="wrap bar">
            <a class="logo" href="index.php"><img src="assets/logo.svg" alt="">byte<em>.</em>busters</a>
            <nav class="nav" aria-label="Main">
                <a href="index.php?page=products">Components</a>
                <a href="index.php?page=products&cat=Kits">Project kits</a>
                <?php if ($u && $u['role'] === 'admin'): ?><a href="admin.php">Admin</a><?php endif; ?>
            </nav>
            <form class="search" action="index.php" role="search">
                <input type="hidden" name="page" value="products">
                <input type="search" name="q" placeholder="Search parts" aria-label="Search parts" value="<?= e($_GET['q'] ?? '') ?>">
            </form>
            <div class="actions">
                <?php if ($u): ?>
                    <a class="bag" href="index.php?page=cart" aria-label="Cart">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h8.8a1 1 0 0 0 1-.8L20 7H6"/></svg>
                        <span id="bagCount"><?= cart_count($pdo) ?></span>
                    </a>
                    <a class="icon-btn" href="index.php?page=account" aria-label="Account" title="Account">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    </a>
                    <form method="post" action="index.php?page=logout" class="inline">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <button class="icon-btn" aria-label="Log out" title="Log out">
                            <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
                        </button>
                    </form>
                <?php else: ?>
                    <a class="icon-btn" href="index.php?page=login" aria-label="Log in" title="Log in">
                        <svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2-2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main class="wrap main">
<?php }

function page_footer() { ?>
    </main>
    <footer class="site-footer"><div class="wrap">byte.busters · Components for students and hobbyists</div></footer>
    <div id="receiptOverlay" class="overlay" hidden><div class="receipt" role="dialog" aria-modal="true" aria-label="Order receipt"></div></div>
    <div id="toast" class="toast" role="status" aria-live="polite"></div>
    <script src="assets/app.js"></script>
    </body>
    </html>
<?php }
