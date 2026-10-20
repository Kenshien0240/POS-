/**
 * Point of Sale screen logic.
 * The browser computes totals for display only; the server always recalculates
 * them during checkout (see cashier/checkout.php).
 */
(function () {
    'use strict';

    var CFG = window.POS_CONFIG || {};
    var currency = CFG.currency || 'P';

    var state = {
        products: [],
        cart: new Map(),
        category: 0,
        query: '',
        lastSaleId: null,
        checkoutKey: null
    };

    var el = {
        grid: document.getElementById('productGrid'),
        search: document.getElementById('productSearch'),
        searchClear: document.getElementById('searchClear'),
        filters: document.getElementById('posFilters'),
        cartList: document.getElementById('cartList'),
        cartCount: document.getElementById('cartCount'),
        clearCart: document.getElementById('clearCart'),
        subtotal: document.getElementById('totalSubtotal'),
        discount: document.getElementById('totalDiscount'),
        tax: document.getElementById('totalTax'),
        grand: document.getElementById('totalGrand'),
        checkout: document.getElementById('checkoutBtn'),
        checkoutLabel: document.getElementById('checkoutLabel'),
        discountInput: document.getElementById('discountPercent')
    };

    // ---------------------------------------------------------------------
    // Formatting
    // ---------------------------------------------------------------------
    function fmt(cents) {
        var v = (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return currency + v;
    }
    function toCents(v) { return Math.round((parseFloat(v) || 0) * 100); }

    // Unique key sent with each checkout so a retry (double-click, network
    // hiccup, reload) returns the original sale instead of creating a duplicate.
    function newCheckoutKey() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        return 'ck-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ---------------------------------------------------------------------
    // Load / render products
    // ---------------------------------------------------------------------
    var loadTimer = null;

    function loadProducts() {
        var params = new URLSearchParams();
        if (state.query) params.set('q', state.query);
        if (state.category) params.set('category', String(state.category));
        el.grid.innerHTML = '<div class="loading-overlay" style="grid-column:1/-1"><span class="spinner"></span> Loading…</div>';

        fetch(CFG.apiProducts + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || 'Unable to load products.');
                state.products = data.products;
                renderProducts();
            })
            .catch(function (err) {
                el.grid.innerHTML = '<div class="state" style="grid-column:1/-1"><div class="state__icon">!</div><p class="state__title">Could not load products</p><p class="state__text">' + esc(err.message) + '</p></div>';
            });
    }

    function renderProducts() {
        if (!state.products.length) {
            el.grid.innerHTML = '<div class="state" style="grid-column:1/-1"><div class="state__icon">' +
                '<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>' +
                '</div><p class="state__title">No products found</p><p class="state__text">Try another search term or category.</p></div>';
            return;
        }
        var html = state.products.map(function (p) {
            var out = p.stock <= 0;
            var inCart = state.cart.has(p.id) ? state.cart.get(p.id).qty : 0;
            var stockClass = out ? 'is-out' : '';
            return '<button type="button" class="product-card ' + stockClass + '" data-id="' + p.id + '"' + (out ? ' disabled' : '') + '>' +
                '<span class="product-card__code">' + esc(p.code) + '</span>' +
                '<span class="product-card__name">' + esc(p.name) + '</span>' +
                '<span class="product-card__foot">' +
                    '<span class="product-card__price">' + esc(p.price_fmt) + '</span>' +
                    '<span class="product-card__stock">' + (out ? 'Out of stock' : p.stock + (inCart ? ' · ' + inCart + ' in cart' : '')) + '</span>' +
                '</span>' +
            '</button>';
        }).join('');
        el.grid.innerHTML = html;
    }

    // ---------------------------------------------------------------------
    // Cart operations
    // ---------------------------------------------------------------------
    function addToCart(id, qty) {
        qty = qty || 1;
        var product = state.products.find(function (p) { return p.id === id; });
        if (!product) return;
        var existing = state.cart.get(id);
        var current = existing ? existing.qty : 0;
        if (current + qty > product.stock) {
            toast('Only ' + product.stock + ' in stock for ' + product.name + '.', 'warning');
            qty = product.stock - current;
            if (qty <= 0) return;
        }
        state.cart.set(id, {
            id: id,
            code: product.code,
            name: product.name,
            price: product.price,
            stock: product.stock,
            qty: current + qty
        });
        renderCart();
        renderProducts();
    }

    function setQty(id, qty) {
        var item = state.cart.get(id);
        if (!item) return;
        qty = parseInt(qty, 10);
        if (isNaN(qty) || qty <= 0) { removeFromCart(id); return; }
        if (qty > item.stock) {
            toast('Only ' + item.stock + ' in stock for ' + item.name + '.', 'warning');
            qty = item.stock;
        }
        item.qty = qty;
        state.cart.set(id, item);
        renderCart();
        renderProducts();
    }

    function removeFromCart(id) {
        state.cart.delete(id);
        renderCart();
        renderProducts();
    }

    function clearCart() {
        if (!state.cart.size) return;
        if (!window.confirm('Clear all items from the current sale?')) return;
        state.cart.clear();
        renderCart();
        renderProducts();
    }

    function totals() {
        var subtotal = 0;
        state.cart.forEach(function (i) { subtotal += toCents(i.price) * i.qty; });
        var discountPct = CFG.discountEnabled ? Math.max(0, Math.min(CFG.discountMax, parseFloat(el.discountInput.value) || 0)) : 0;
        var discount = Math.round(subtotal * discountPct / 100);
        var taxable = subtotal - discount;
        var tax = CFG.taxEnabled ? Math.round(taxable * CFG.taxRate / 100) : 0;
        var grand = taxable + tax;
        return { subtotal: subtotal, discount: discount, discountPct: discountPct, tax: tax, grand: grand };
    }

    function renderCart() {
        var count = 0;
        state.cart.forEach(function (i) { count += i.qty; });
        el.cartCount.textContent = count;
        el.clearCart.disabled = count === 0;

        if (!state.cart.size) {
            el.cartList.innerHTML = '<div class="state"><div class="state__icon">' +
                '<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>' +
                '</div><p class="state__title">Cart is empty</p><p class="state__text">Search or tap a product to add it to the sale.</p></div>';
        } else {
            var html = '';
            state.cart.forEach(function (i) {
                html += '<div class="cart-item" data-id="' + i.id + '">' +
                    '<div>' +
                        '<div class="cart-item__name">' + esc(i.name) + '</div>' +
                        '<div class="cart-item__meta">' + esc(i.code) + ' · ' + esc(fmt(toCents(i.price))) + ' each</div>' +
                    '</div>' +
                    '<div class="cart-item__right">' +
                        '<span class="cart-item__line">' + esc(fmt(toCents(i.price) * i.qty)) + '</span>' +
                        '<span class="qty">' +
                            '<button type="button" data-dec aria-label="Decrease quantity">−</button>' +
                            '<input type="text" inputmode="numeric" value="' + i.qty + '" data-qty aria-label="Quantity">' +
                            '<button type="button" data-inc aria-label="Increase quantity">+</button>' +
                        '</span>' +
                        '<button type="button" class="cart-item__remove" data-remove aria-label="Remove item">' +
                            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>' +
                        '</button>' +
                    '</div>' +
                '</div>';
            });
            el.cartList.innerHTML = html;
        }

        var t = totals();
        el.subtotal.textContent = fmt(t.subtotal);
        if (el.discount) el.discount.textContent = '-' + fmt(t.discount);
        if (el.tax) el.tax.textContent = fmt(t.tax);
        el.grand.textContent = fmt(t.grand);
        el.checkoutLabel.textContent = 'Charge ' + fmt(t.grand);
        el.checkout.disabled = count === 0;

        // Any change to the cart starts a new logical sale, so the previous
        // idempotency key must not be reused.
        state.checkoutKey = null;
    }

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------
    el.search.addEventListener('input', function () {
        state.query = el.search.value.trim();
        clearTimeout(loadTimer);
        loadTimer = setTimeout(loadProducts, 250);
    });

    el.search.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var q = el.search.value.trim();
        if (!q) return;
        // Barcode-style: exact code match adds immediately.
        var exact = state.products.find(function (p) { return p.code.toLowerCase() === q.toLowerCase(); });
        if (exact && exact.stock > 0) {
            addToCart(exact.id, 1);
            el.search.value = '';
            state.query = '';
            loadProducts();
        } else if (state.products.length === 1 && state.products[0].stock > 0) {
            addToCart(state.products[0].id, 1);
        }
    });

    el.searchClear.addEventListener('click', function () {
        el.search.value = '';
        state.query = '';
        loadProducts();
        el.search.focus();
    });

    if (el.filters) {
        el.filters.addEventListener('click', function (e) {
            var chip = e.target.closest('.chip');
            if (!chip) return;
            el.filters.querySelectorAll('.chip').forEach(function (c) { c.classList.remove('is-active'); });
            chip.classList.add('is-active');
            state.category = parseInt(chip.getAttribute('data-category'), 10) || 0;
            loadProducts();
        });
    }

    el.grid.addEventListener('click', function (e) {
        var card = e.target.closest('.product-card');
        if (card && !card.disabled) addToCart(parseInt(card.getAttribute('data-id'), 10), 1);
    });

    el.cartList.addEventListener('click', function (e) {
        var row = e.target.closest('.cart-item');
        if (!row) return;
        var id = parseInt(row.getAttribute('data-id'), 10);
        var item = state.cart.get(id);
        if (!item) return;
        if (e.target.closest('[data-inc]')) setQty(id, item.qty + 1);
        else if (e.target.closest('[data-dec]')) setQty(id, item.qty - 1);
        else if (e.target.closest('[data-remove]')) removeFromCart(id);
    });

    el.cartList.addEventListener('change', function (e) {
        var input = e.target.closest('[data-qty]');
        if (!input) return;
        var row = input.closest('.cart-item');
        setQty(parseInt(row.getAttribute('data-id'), 10), input.value);
    });

    el.clearCart.addEventListener('click', clearCart);

    if (el.discountInput) el.discountInput.addEventListener('input', renderCart);

    // ---------------------------------------------------------------------
    // Payment
    // ---------------------------------------------------------------------
    var payment = { method: 'cash' };
    var payModal = document.getElementById('paymentModal');
    var tenderedInput = document.getElementById('tendered');
    var referenceField = document.getElementById('referenceField');
    var referenceInput = document.getElementById('paymentReference');
    var tenderedField = document.getElementById('tenderedField');
    var payChange = document.getElementById('payChange');
    var payTotal = document.getElementById('payTotal');
    var payError = document.getElementById('payError');
    var confirmBtn = document.getElementById('confirmPayment');
    var quickCash = document.getElementById('quickCash');

    el.checkout.addEventListener('click', function () {
        var t = totals();
        if (!t.grand) return;
        payTotal.textContent = fmt(t.grand);
        tenderedInput.value = '';
        referenceInput.value = '';
        setMethod('cash');
        payError.classList.add('hidden');
        updateQuickCash(t.grand);
        updateChange();
        openModal('paymentModal');
        setTimeout(function () { tenderedInput.focus(); }, 50);
    });

    function setMethod(m) {
        payment.method = m;
        document.querySelectorAll('#payMethods .pay-method').forEach(function (b) {
            var active = b.getAttribute('data-method') === m;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        tenderedField.classList.toggle('hidden', m !== 'cash');
        referenceField.classList.toggle('hidden', m === 'cash');
        updateChange();
    }

    document.getElementById('payMethods').addEventListener('click', function (e) {
        var btn = e.target.closest('.pay-method');
        if (btn) setMethod(btn.getAttribute('data-method'));
    });

    function updateQuickCash(total) {
        var suggestions = [total, total + 10000, total + 50000, total + 100000];
        var seen = {};
        var html = '<button type="button" class="chip" data-cash="exact">Exact</button>';
        suggestions.forEach(function (c) {
            if (c === total || seen[c]) return;
            seen[c] = true;
            html += '<button type="button" class="chip" data-cash="' + (c / 100).toFixed(2) + '">' + esc(fmt(c)) + '</button>';
        });
        quickCash.innerHTML = html;
    }

    quickCash.addEventListener('click', function (e) {
        var chip = e.target.closest('[data-cash]');
        if (!chip) return;
        var t = totals();
        if (chip.getAttribute('data-cash') === 'exact') tenderedInput.value = (t.grand / 100).toFixed(2);
        else tenderedInput.value = chip.getAttribute('data-cash');
        updateChange();
    });

    function updateChange() {
        var t = totals();
        var tendered = toCents(tenderedInput.value);
        var change = Math.max(0, tendered - t.grand);
        payChange.textContent = fmt(change);
        payChange.classList.toggle('text-danger', tendered < t.grand && payment.method === 'cash');
        payChange.classList.toggle('text-success', tendered >= t.grand);
    }

    tenderedInput.addEventListener('input', updateChange);

    confirmBtn.addEventListener('click', function () {
        if (confirmBtn.disabled) return; // guard against double submission
        var t = totals();
        payError.classList.add('hidden');

        if (payment.method === 'cash') {
            var tendered0 = toCents(tenderedInput.value);
            if (tendered0 < t.grand) {
                return showPayError('Amount tendered is less than the total due.');
            }
        }

        var cart = [];
        state.cart.forEach(function (i) { cart.push({ product_id: i.id, quantity: i.qty }); });

        // Reuse the same key for retries; generate one for a fresh attempt.
        if (!state.checkoutKey) state.checkoutKey = newCheckoutKey();

        var payload = {
            cart: cart,
            discount_percent: t.discountPct,
            idempotency_key: state.checkoutKey,
            payment: {
                method: payment.method,
                tendered: payment.method === 'cash' ? (tenderedInput.value || '0') : (t.grand / 100).toFixed(2),
                reference: referenceInput.value.trim() || null
            }
        };

        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner" style="border-top-color:#fff"></span> Processing…';

        fetch(CFG.apiCheckout, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': CFG.csrf
            },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
            .then(function (res) {
                if (!res.data.ok) throw new Error(res.data.message || 'Checkout failed.');
                if (res.data.duplicate) toast('This sale was already recorded — showing the original receipt.', 'info');
                return showReceipt(res.data.sale_id);
            })
            .then(function () {
                closeModal('paymentModal');
                state.cart.clear();
                renderCart();
                loadProducts();
            })
            .catch(function (err) {
                showPayError(err.message);
            })
            .finally(function () {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Complete sale';
            });
    });

    function showPayError(msg) {
        payError.querySelector('.alert__text').textContent = msg;
        payError.classList.remove('hidden');
    }

    // ---------------------------------------------------------------------
    // Receipt
    // ---------------------------------------------------------------------
    function showReceipt(saleId) {
        state.lastSaleId = saleId;
        return fetch(CFG.receiptUrl + '?id=' + saleId + '&embed=1', { headers: { 'Accept': 'text/html' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                document.getElementById('receiptContent').innerHTML = html;
                openModal('receiptModal');
            });
    }

    document.getElementById('printReceipt').addEventListener('click', function () {
        if (!state.lastSaleId) return;
        window.open(CFG.receiptUrl + '?id=' + state.lastSaleId + '&autoprint=1', '_blank', 'width=420,height=640');
    });

    document.getElementById('newSale').addEventListener('click', function () {
        closeModal('receiptModal');
        el.search.focus();
    });

    // ---------------------------------------------------------------------
    // Small toast (reuses alert styling, appended to cart panel)
    // ---------------------------------------------------------------------
    function toast(message, type) {
        var div = document.createElement('div');
        div.className = 'alert alert--' + (type || 'info');
        div.style.position = 'fixed';
        div.style.bottom = '1.2rem';
        div.style.right = '1.2rem';
        div.style.zIndex = '120';
        div.style.maxWidth = '320px';
        div.style.boxShadow = 'var(--shadow-lg)';
        div.innerHTML = '<span class="alert__text">' + esc(message) + '</span>';
        document.body.appendChild(div);
        setTimeout(function () { div.style.transition = 'opacity .3s'; div.style.opacity = '0'; setTimeout(function () { div.remove(); }, 300); }, 2600);
    }

    loadProducts();
})();
