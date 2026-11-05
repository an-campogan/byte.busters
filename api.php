<?php
// JSON endpoints used by assets/app.js: api.php?a=cart and api.php?a=checkout
require __DIR__ . '/config.php';
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only.'], 405);
if (!user()) json_out(['ok' => false, 'login' => true, 'error' => 'Log in to continue.'], 401);
check_csrf();
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$a = $_GET['a'] ?? '';
if ($a === 'cart') api_cart($pdo, $in);
elseif ($a === 'checkout') api_checkout($pdo, $in);
else json_out(['ok' => false, 'error' => 'Unknown action.'], 404);

function api_cart($pdo, $in) {
    $act = $in['action'] ?? '';
    $pid = (int)($in['product_id'] ?? 0);
    $qty = max(1, (int)($in['qty'] ?? 1));
    $uid = user()['id'];
    if (in_array($act, ['add', 'set', 'remove'], true)) {
        $p = $pdo->prepare('SELECT id, stock FROM products WHERE id = ?');
        $p->execute([$pid]);
        $prod = $p->fetch();
        if (!$prod) json_out(['ok' => false, 'error' => 'Product not found.'], 404);
        if ($act === 'remove') {
            $pdo->prepare('DELETE FROM cart_items WHERE user_id = ? AND product_id = ?')->execute([$uid, $pid]);
        } else {
            if ($prod['stock'] < 1) json_out(['ok' => false, 'error' => 'That item is sold out.']);
            $have = 0;
            if ($act === 'add') {
                $c = $pdo->prepare('SELECT qty FROM cart_items WHERE user_id = ? AND product_id = ?');
                $c->execute([$uid, $pid]);
                $have = (int)$c->fetchColumn();
            }
            $new = min($have + $qty, (int)$prod['stock']);
            $pdo->prepare('INSERT INTO cart_items (user_id, product_id, qty) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE qty = VALUES(qty)')->execute([$uid, $pid, $new]);
        }
    }
    json_out(cart_summary($pdo, $uid));
}

function api_checkout($pdo, $in) {
    $uid = user()['id'];
    $method = ($in['method'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
    $buyNow = $in['buy_now'] ?? null;

    // Items and prices always come from the database, never from the browser
    if ($buyNow) {
        $s = $pdo->prepare('SELECT id, name, price, stock FROM products WHERE id = ?');
        $s->execute([(int)($buyNow['product_id'] ?? 0)]);
        $p = $s->fetch();
        if (!$p) json_out(['ok' => false, 'error' => 'Product not found.']);
        $p['qty'] = max(1, (int)($buyNow['qty'] ?? 1));
        $items = [$p];
    } else {
        $items = cart_rows($pdo, $uid);
    }
    if (!$items) json_out(['ok' => false, 'error' => 'Your bag is empty.']);

    $me = $pdo->prepare('SELECT name, address FROM users WHERE id = ?');
    $me->execute([$uid]);
    $me = $me->fetch();
    if ($method === 'delivery' && trim($me['address']) === '')
        json_out(['ok' => false, 'error' => 'Add a delivery address in your account, or choose pickup.']);

    try {
        $pdo->beginTransaction();
        $sub = 0; $lines = [];
        foreach ($items as $it) {
            $st = $pdo->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
            $st->execute([$it['id']]);
            $stock = (int)$st->fetchColumn();
            if ($stock < $it['qty']) throw new RuntimeException($it['name'] . ' only has ' . $stock . ' left.');
            $line = $it['price'] * $it['qty'];
            $sub += $line;
            $lines[] = ['id' => $it['id'], 'name' => $it['name'], 'qty' => (int)$it['qty'], 'price' => (float)$it['price'], 'line' => (float)$line];
        }
        [$subtotal, $discount, $total] = totals($sub);
        $etaTs = strtotime($method === 'pickup' ? '+1 day' : '+3 days');
        $where = $method === 'pickup' ? PICKUP_POINT : $me['address'];

        $pdo->prepare('INSERT INTO orders (user_id, subtotal, discount, total, method, address, eta_date) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $subtotal, $discount, $total, $method, $where, date('Y-m-d', $etaTs)]);
        $orderId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, price, qty) VALUES (?,?,?,?,?)');
        $dec = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
        foreach ($lines as $l) {
            $ins->execute([$orderId, $l['id'], $l['name'], $l['price'], $l['qty']]);
            $dec->execute([$l['qty'], $l['id']]);
        }
        if (!$buyNow) $pdo->prepare('DELETE FROM cart_items WHERE user_id = ?')->execute([$uid]);
        $pdo->commit();
    } catch (RuntimeException $ex) {
        $pdo->rollBack();
        json_out(['ok' => false, 'error' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_out(['ok' => false, 'error' => 'Could not place the order. Please try again.'], 500);
    }

    json_out(['ok' => true, 'count' => cart_count($pdo), 'receipt' => [
        'no' => sprintf('BB-%06d', $orderId), 'date' => date('M j, Y g:i A'), 'customer' => $me['name'],
        'method' => $method, 'where' => $where, 'eta' => date('M j, Y', $etaTs),
        'items' => array_map(fn($l) => ['name' => $l['name'], 'qty' => $l['qty'], 'price' => $l['price'], 'line' => $l['line']], $lines),
        'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
    ]]);
}
