let cart = [];
let metodeBayar = 'Cash';
let promoAktif = null; // { id_promo, nama_promo, diskon }

function formatRupiah(number) {
    return 'Rp ' + Number(number).toLocaleString('id-ID');
}

// Escape teks sebelum dimasukkan ke innerHTML (cegah XSS dari nama menu, dll.)
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
    ));
}

// Semua request POST ke server wajib membawa token CSRF
function postJson(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': APP.csrfToken },
        body: JSON.stringify(payload)
    }).then(r => r.json());
}

document.addEventListener('DOMContentLoaded', function () {
    if (APP.cartAwal && APP.cartAwal.length > 0) {
        cart = APP.cartAwal;
        renderCart();
    }

    document.getElementById('searchMenu').addEventListener('input', function () {
        const keyword = this.value.toLowerCase();
        const aktifKategori = document.querySelector('.tab-kategori .nav-link.active')?.dataset.kategori || 'semua';
        document.querySelectorAll('.menu-wrapper').forEach(item => {
            const name = item.getAttribute('data-name');
            const kat  = item.getAttribute('data-kategori');
            const matchKategori = aktifKategori === 'semua' || kat === aktifKategori;
            item.style.display = (name.includes(keyword) && matchKategori) ? '' : 'none';
        });
    });
});

function filterKategori(kategori, el) {
    document.querySelectorAll('.tab-kategori .nav-link').forEach(a => a.classList.remove('active'));
    el.classList.add('active');
    el.dataset.kategori = kategori;
    const keyword = document.getElementById('searchMenu').value.toLowerCase();
    document.querySelectorAll('.menu-wrapper').forEach(item => {
        const name = item.getAttribute('data-name');
        const kat  = item.getAttribute('data-kategori');
        const matchKategori = kategori === 'semua' || kat === kategori;
        item.style.display = (matchKategori && name.includes(keyword)) ? '' : 'none';
    });
    return false;
}

// Baca data dari data-attribute, lebih aman dari karakter khusus
function handleMenuClickFromEl(cardEl) {
    const wrapper  = cardEl.closest('.menu-wrapper');
    const soldOut  = wrapper.dataset.soldout === '1';
    if (soldOut) return;

    const id       = parseInt(wrapper.dataset.id);
    const name     = wrapper.dataset.nama;
    const price    = parseInt(wrapper.dataset.harga);
    const variants = JSON.parse(wrapper.dataset.varian || '[]');

    if (variants.length > 0) {
        showVarianModal(id, name, price, variants);
    } else {
        addToCart(id, name, price, null);
    }
}

// Popup pilih varian
function showVarianModal(id, name, price, variants) {
    const existing = document.getElementById('varianModal');
    if (existing) existing.remove();

    const varianHtml = variants.map((v, i) => {
        const hargaFinal = price + v.harga_tambah;
        const isSoldOut  = v.is_stok_aktif && v.stok !== null && v.stok <= 0;
        const stokInfo   = v.is_stok_aktif && v.stok !== null ? ` (sisa ${Number(v.stok)})` : '';
        return `
        <div class="col-12">
            <button type="button" class="btn w-100 text-start fw-bold rounded-3 mb-2 varian-btn ${isSoldOut ? 'btn-secondary disabled' : 'btn-outline-secondary'}"
                style="padding:12px 16px;" data-index="${i}" ${isSoldOut ? 'disabled' : ''}>
                <div class="d-flex justify-content-between align-items-center">
                    <span>${escapeHtml(v.nama_varian)}${isSoldOut ? ' <span class="badge bg-danger ms-1">Sold Out</span>' : ''}</span>
                    <span style="color:#d62828;">${formatRupiah(hargaFinal)}${stokInfo}</span>
                </div>
            </button>
        </div>`;
    }).join('');

    const modalHtml = `
    <div class="modal fade" id="varianModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0" style="background:linear-gradient(135deg,#d62828,#f77f00);">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="fa-solid fa-list me-2"></i>${escapeHtml(name)}
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Pilih varian:</p>
                    <div class="row g-0">${varianHtml}</div>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modalEl = document.getElementById('varianModal');
    modalEl.querySelectorAll('.varian-btn:not([disabled])').forEach(btn => {
        btn.addEventListener('click', () => {
            const v = variants[Number(btn.dataset.index)];
            pilihVarian(id, `${name} - ${v.nama_varian}`, price + v.harga_tambah, v.id_varian);
        });
    });
    new bootstrap.Modal(modalEl).show();
}

function pilihVarian(id, name, price, id_varian) {
    bootstrap.Modal.getInstance(document.getElementById('varianModal')).hide();
    addToCart(id, name, price, id_varian, null);
}

function addToCart(id, name, price, id_varian, extra) {
    const key = id_varian ? `${id}_${id_varian}` : `${id}`;
    const existingItem = cart.find(item => item.key === key);
    if (existingItem) { existingItem.qty += 1; }
    else { cart.push({ key, id, name, price, qty: 1, id_varian: id_varian || null }); }
    renderCart();
    autoSimpanOrder();
}

function increaseQty(key) {
    const item = cart.find(item => item.key === key);
    if (item) { item.qty += 1; renderCart(); autoSimpanOrder(); }
}

function decreaseQty(key) {
    const item = cart.find(item => item.key === key);
    if (!item) return;
    item.qty -= 1;
    if (item.qty <= 0) { cart = cart.filter(i => i.key !== key); }
    renderCart();
    autoSimpanOrder();
}

function removeItem(key) {
    cart = cart.filter(item => item.key !== key);
    renderCart();
    autoSimpanOrder();
}

function clearCart() {
    cart = [];
    promoAktif = null;
    document.getElementById('uangBayar').value = '';
    document.getElementById('kodePromo').value = '';
    metodeBayar = 'Cash';
    renderCart();
    updatePromoUI();
    showMessage('', '');
    autoSimpanOrder();
}

function getSubtotal() {
    return cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
}

function getTotal() {
    const subtotal = getSubtotal();
    const diskon   = promoAktif ? promoAktif.diskon : 0;
    return Math.max(0, subtotal - diskon);
}

function renderCart() {
    const cartItems = document.getElementById('cartItems');

    if (cart.length === 0) {
        cartItems.innerHTML = `
            <div class="empty-cart">
                <i class="fa-solid fa-basket-shopping fa-3x mb-3"></i>
                <p class="mb-0">Belum ada item dipilih.</p>
            </div>`;
    } else {
        cartItems.innerHTML = cart.map(item => `
            <div class="cart-item">
                <div class="d-flex justify-content-between gap-2">
                    <div>
                        <strong>${escapeHtml(item.name)}</strong>
                        <div class="text-muted small">${formatRupiah(item.price)} x ${item.qty}</div>
                    </div>
                    <button class="btn btn-sm btn-link text-danger p-0" onclick="removeItem('${escapeHtml(item.key)}')">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div class="d-flex align-items-center gap-2">
                        <button class="qty-btn" onclick="decreaseQty('${escapeHtml(item.key)}')">-</button>
                        <strong>${item.qty}</strong>
                        <button class="qty-btn" onclick="increaseQty('${escapeHtml(item.key)}')">+</button>
                    </div>
                    <strong>${formatRupiah(item.price * item.qty)}</strong>
                </div>
            </div>
        `).join('');
    }

    document.getElementById('subtotalText').innerText = formatRupiah(getSubtotal());
    document.getElementById('totalText').innerText    = formatRupiah(getTotal());
    calculateChange();
}

function calculateChange() {
    const total    = getTotal();
    const bayar    = Number(document.getElementById('uangBayar').value || 0);
    const kembalian = bayar - total;
    document.getElementById('kembalianText').innerText = formatRupiah(kembalian > 0 ? kembalian : 0);
}

// ===== PROMO =====
function terapkanPromo() {
    const kode  = document.getElementById('kodePromo').value.trim().toUpperCase();
    const total = getSubtotal();

    if (!kode) { showPromoMessage('danger', 'Masukkan kode promo dulu.'); return; }
    if (cart.length === 0) { showPromoMessage('danger', 'Keranjang masih kosong.'); return; }

    postJson(APP.cekPromoUrl, { kode_promo: kode, total: total })
    .then(data => {
        if (data.status === 'success') {
            promoAktif = { id_promo: data.id_promo, nama_promo: data.nama_promo, diskon: data.diskon };
            updatePromoUI();
            renderCart();
            showPromoMessage('success', `✓ ${data.nama_promo} — hemat ${formatRupiah(data.diskon)}!`);
        } else {
            showPromoMessage('danger', data.message);
        }
    })
    .catch(() => showPromoMessage('danger', 'Terjadi kesalahan.'));
}

function hapusPromo() {
    promoAktif = null;
    document.getElementById('kodePromo').value = '';
    updatePromoUI();
    renderCart();
    showPromoMessage('', '');
}

function updatePromoUI() {
    const promoBox      = document.getElementById('promoBox');
    const btnHapus      = document.getElementById('btnHapusPromo');
    if (promoAktif) {
        promoBox.style.display = 'block';
        btnHapus.style.display = 'inline-block';
        document.getElementById('promoNama').innerText  = promoAktif.nama_promo;
        document.getElementById('promoDiskon').innerText = '- ' + formatRupiah(promoAktif.diskon);
    } else {
        promoBox.style.display  = 'none';
        btnHapus.style.display  = 'none';
    }
}

function showPromoMessage(type, message) {
    const el = document.getElementById('promoMessage');
    if (!message) { el.innerHTML = ''; return; }
    el.innerHTML = `<div class="alert alert-${type} py-2 mb-0 small">${escapeHtml(message)}</div>`;
}

// ===== PAYMENT =====
function showMessage(type, message) {
    const paymentMessage = document.getElementById('paymentMessage');
    if (!message) { paymentMessage.innerHTML = ''; return; }
    paymentMessage.innerHTML = `<div class="alert alert-${type} mb-0">${escapeHtml(message)}</div>`;
}

function processPayment() {
    const total = getTotal();
    const bayar = Number(document.getElementById('uangBayar').value || 0);
    const kembalian = bayar - total;

    if (cart.length === 0) { showMessage('warning', 'Keranjang masih kosong.'); return; }
    if (bayar < total) { showMessage('danger', 'Uang pelanggan belum cukup.'); return; }

    showMetodeModal(total, bayar, kembalian);
}

function showMetodeModal(total, bayar, kembalian) {
    const existing = document.getElementById('metodeModal');
    if (existing) existing.remove();

    const metodes = [
        { id: 'Cash',       icon: 'fa-money-bill',   bg: '#e8f5e9', color: '#2e7d32' },
        { id: 'QRIS',       icon: 'fa-qrcode',       bg: '#e3f2fd', color: '#1565c0' },
        { id: 'Debit',      icon: 'fa-credit-card',  bg: '#f3e5f5', color: '#6a1b9a' },
        { id: 'Kredit',     icon: 'fa-credit-card',  bg: '#fce4ec', color: '#c62828' },
        { id: 'Gojek',      icon: 'fa-motorcycle',   bg: '#e8f5e9', color: '#1b5e20' },
        { id: 'GrabFood',   icon: 'fa-car',          bg: '#fff8e1', color: '#e65100' },
        { id: 'ShopeeFood', icon: 'fa-bag-shopping', bg: '#fce4ec', color: '#b71c1c' },
    ];

    const modalHtml = `
    <div class="modal fade" id="metodeModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-0" style="background:linear-gradient(135deg,#d62828,#f77f00);">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="fa-solid fa-wallet me-2"></i>Pilih Metode Pembayaran
                    </h5>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Total: <strong>${formatRupiah(total)}</strong></p>
                    <div class="row g-2">
                        ${metodes.map(m => `
                            <div class="col-6">
                                <button class="btn w-100 fw-bold metode-btn"
                                    style="background:${m.bg};color:${m.color};border:2px solid ${metodeBayar === m.id ? m.color : 'transparent'};border-radius:12px;padding:12px;"
                                    onclick="selectMetode('${m.id}', '${m.color}', event)">
                                    <i class="fa-solid ${m.icon} me-2"></i>${m.id}
                                </button>
                            </div>
                        `).join('')}
                    </div>
                    <div class="mt-3 p-3 rounded-3" style="background:#f8f9fa;">
                        <div class="d-flex justify-content-between">
                            <span>Metode dipilih:</span>
                            <strong id="selectedMetodeText">${metodeBayar}</strong>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Batal</button>
                    <button class="btn fw-bold rounded-3 text-white"
                        style="background:linear-gradient(135deg,#d62828,#f77f00);border:none;"
                        onclick="confirmPayment(${total}, ${bayar}, ${kembalian})">
                        <i class="fa-solid fa-check me-2"></i>Konfirmasi Bayar
                    </button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    new bootstrap.Modal(document.getElementById('metodeModal')).show();
}

function selectMetode(id, color, event) {
    metodeBayar = id;
    document.getElementById('selectedMetodeText').innerText = id;
    document.querySelectorAll('.metode-btn').forEach(btn => btn.style.border = '2px solid transparent');
    event.currentTarget.style.border = `2px solid ${color}`;
}

let sedangMembayar = false;

function confirmPayment(total, bayar, kembalian) {
    if (sedangMembayar) return;   // cegah klik ganda
    sedangMembayar = true;

    bootstrap.Modal.getInstance(document.getElementById('metodeModal')).hide();

    // Server hanya dikirimi APA yang dibeli. Harga, diskon, total, dan kembalian
    // dihitung ulang di server (lihat ajax/simpan_transaksi.php).
    postJson(APP.ajaxUrl, {
        metode_bayar: metodeBayar,
        bayar: bayar,
        id_meja: APP.idMeja,
        id_promo: promoAktif ? promoAktif.id_promo : null,
        cart: cart.map(i => ({ id: i.id, id_varian: i.id_varian, qty: i.qty }))
    })
    .then(data => {
        if (data.status === 'success') {
            fillReceipt(data, new Date().toLocaleString('id-ID'));
            showMessage('success', 'Transaksi berhasil! Struk siap dicetak.');
            window.print();
            setTimeout(() => {
                window.location.href = APP.baseUrl + '/pages/meja/index.php';
            }, 1000);
        } else {
            sedangMembayar = false;
            showMessage('danger', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        sedangMembayar = false;
        showMessage('danger', 'Terjadi kesalahan sistem.');
    });
}

// Struk memakai angka yang dikembalikan server, bukan hitungan di browser
function fillReceipt(data, tanggal) {
    document.getElementById('strukArea').style.display = 'block';
    document.getElementById('strukKode').innerText      = data.kode_transaksi;
    document.getElementById('strukTanggal').innerText   = tanggal;
    document.getElementById('strukTotal').innerText     = formatRupiah(data.total);
    document.getElementById('strukBayar').innerText     = formatRupiah(data.bayar);
    document.getElementById('strukKembalian').innerText = formatRupiah(data.kembalian);
    document.getElementById('strukMetode').innerText    = metodeBayar;

    document.getElementById('strukItems').innerHTML = data.items.map(item => `
        <div style="margin-bottom:8px;">
            <div>${escapeHtml(item.name)}</div>
            <div style="display:flex;justify-content:space-between;">
                <span>${Number(item.qty)} x ${formatRupiah(item.price)}</span>
                <span>${formatRupiah(item.subtotal)}</span>
            </div>
        </div>
    `).join('') + (data.diskon > 0 ? `
        <div style="display:flex;justify-content:space-between;color:#2e7d32;">
            <span>Diskon (${escapeHtml(data.nama_promo)})</span>
            <span>- ${formatRupiah(data.diskon)}</span>
        </div>` : '');
}

// ===== AUTO SAVE ORDER =====
function autoSimpanOrder() {
    if (!APP.simpanOrderUrl || !APP.idMeja) return;
    postJson(APP.simpanOrderUrl, { id_meja: APP.idMeja, cart: cart, total: getTotal() })
        .catch(err => console.error('Auto save error:', err));
}

function simpanOrder() {
    if (cart.length === 0) { showMessage('warning', 'Keranjang masih kosong.'); return; }
    postJson(APP.simpanOrderUrl, { id_meja: APP.idMeja, cart: cart, total: getTotal() })
        .then(data => {
            if (data.status === 'success') {
                showMessage('success', 'Order disimpan! Pelanggan bisa bayar nanti.');
            } else {
                showMessage('danger', data.message);
            }
        })
        .catch(() => showMessage('danger', 'Terjadi kesalahan sistem.'));
}
