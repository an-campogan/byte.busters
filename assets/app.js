const csrf = document.querySelector('meta[name=csrf]').content;
const $ = (s, r = document) => r.querySelector(s);
const peso = n => '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function api(url, data) {
    let j;
    try {
        const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf}, body: JSON.stringify(data)});
        j = await r.json();
    } catch { j = {ok: false, error: 'Something went wrong. Please try again.'}; }
    if (j.login) { location.href = 'index.php?page=login&next=' + encodeURIComponent(location.pathname + location.search); }
    return j;
}

let toastTimer;
function toast(msg, isErr) {
    const t = $('#toast');
    t.textContent = msg; t.className = 'toast show' + (isErr ? ' err' : '');
    clearTimeout(toastTimer); toastTimer = setTimeout(() => t.className = 'toast', 2600);
}
const setBag = n => { const b = $('#bagCount'); if (b) b.textContent = n; };
const qtyFrom = btn => { const el = btn.dataset.qtyFrom && $(btn.dataset.qtyFrom); return el ? Math.max(1, parseInt(el.value) || 1) : 1; };

function showReceipt(r) {
    const rows = r.items.map(i => `<div class="ri"><span>${esc(i.name)}</span><span>${peso(i.line)}</span><small>${i.qty} pcs × ${peso(i.price)}</small></div>`).join('');
    const pick = r.method === 'pickup';
    const box = $('#receiptOverlay .receipt');
    box.innerHTML = `
    <h2>byte.busters</h2><p class="c">Order receipt</p><hr>
    <div class="rr"><span>Order no.</span><span>${esc(r.no)}</span></div>
    <div class="rr"><span>Date</span><span>${esc(r.date)}</span></div>
    <div class="rr"><span>Customer</span><span>${esc(r.customer)}</span></div><hr>
    ${rows}<hr>
    <div class="rr"><span>Subtotal</span><span>${peso(r.subtotal)}</span></div>
    <div class="rr"><span>Discount</span><span>−${peso(r.discount)}</span></div>
    <div class="rr rt"><span>Total</span><span>${peso(r.total)}</span></div><hr>
    <div class="rr"><span>${pick ? 'Pickup' : 'Delivery'}</span><span>${esc(r.eta)}</span></div>
    <p class="c">${pick ? 'Pick up at: ' : 'Ship to: '}${esc(r.where)}</p>
    <div class="acts"><button class="btn dark" id="rPrint">Print</button><button class="btn coral" id="rDone">View my orders</button></div>`;
    $('#receiptOverlay').hidden = false;
    $('#rDone').focus();
    $('#rPrint').onclick = () => window.print();
    $('#rDone').onclick = () => location.href = 'index.php?page=account';
}
const closeReceipt = () => { if (!$('#receiptOverlay').hidden) location.href = 'index.php?page=account'; };
$('#receiptOverlay').addEventListener('click', e => { if (e.target.id === 'receiptOverlay') closeReceipt(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeReceipt(); });

function applySummary(j) {
    setBag(j.count);
    if (!j.items.length) return location.reload();
    j.items.forEach(i => {
        const row = $(`[data-row="${i.id}"]`); if (!row) return;
        $('.qty-input', row).value = i.qty;
        $('[data-line]', row).textContent = peso(i.line);
    });
    document.querySelectorAll('[data-row]').forEach(r => { if (!j.items.some(i => String(i.id) === r.dataset.row)) r.remove(); });
    $('#sumSub').textContent = peso(j.subtotal);
    $('#sumDisc').textContent = '−' + peso(j.discount);
    $('#sumTotal').textContent = peso(j.total);
}
async function setQty(row, qty) {
    const j = await api('api.php?a=cart', {action: 'set', product_id: +row.dataset.row, qty});
    if (j.ok) applySummary(j); else if (!j.login) toast(j.error, true);
}

document.addEventListener('click', async e => {
    const t = e.target.closest('button'); if (!t) return;

    if (t.dataset.step !== undefined) {
        const wrap = t.closest('.stepper'), input = $('.qty-input', wrap);
        const next = Math.max(1, Math.min(+input.max || 99, (parseInt(input.value) || 1) + +t.dataset.step));
        input.value = next;
        const row = t.closest('[data-row]'); if (row) setQty(row, next);
    } else if (t.dataset.add) {
        const j = await api('api.php?a=cart', {action: 'add', product_id: +t.dataset.add, qty: qtyFrom(t)});
        if (j.ok) { setBag(j.count); toast('Added to your bag'); } else if (!j.login) toast(j.error, true);
    } else if (t.dataset.buy) {
        t.disabled = true;
        const j = await api('api.php?a=checkout', {buy_now: {product_id: +t.dataset.buy, qty: qtyFrom(t)}, method: 'delivery'});
        t.disabled = false;
        if (j.ok) showReceipt(j.receipt); else if (!j.login) toast(j.error, true);
    } else if (t.dataset.remove !== undefined) {
        const row = t.closest('[data-row]');
        const j = await api('api.php?a=cart', {action: 'remove', product_id: +row.dataset.row});
        if (j.ok) applySummary(j);
    } else if (t.id === 'checkout') {
        t.disabled = true;
        const j = await api('api.php?a=checkout', {method: $('#method').value});
        t.disabled = false;
        if (j.ok) { setBag(j.count); showReceipt(j.receipt); } else if (!j.login) toast(j.error, true);
    }
});

document.addEventListener('change', e => {
    const input = e.target.closest('.qty-input'), row = input && input.closest('[data-row]');
    if (!row) return;
    input.value = Math.max(1, Math.min(+input.max || 99, parseInt(input.value) || 1));
    setQty(row, +input.value);
});

(() => {
    const btn = document.getElementById('menuBtn'), menu = document.getElementById('menuPanel');
    if (!btn || !menu) return;
    const set = open => {
        menu.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open);
        btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    };
    btn.addEventListener('click', () => set(!menu.classList.contains('open')));
    document.addEventListener('click', e => { if (!menu.contains(e.target) && !btn.contains(e.target)) set(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') set(false); });
    menu.addEventListener('click', e => { if (e.target.closest('a')) set(false); });
    matchMedia('(min-width: 961px)').addEventListener('change', e => { if (e.matches) set(false); });
})();