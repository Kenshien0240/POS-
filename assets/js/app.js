/**
 * Group 6 Retail POS - shared UI behaviour
 */
(function () {
    'use strict';

    // ---------------------------------------------------------------------
    // Sidebar: off-canvas on small screens, collapse on desktop
    // ---------------------------------------------------------------------
    var body = document.body;
    var toggle = document.getElementById('sidebarToggle');
    var backdrop = document.getElementById('sidebarBackdrop');
    var COLLAPSE_KEY = 'pos.sidebarCollapsed';

    if (window.matchMedia('(min-width: 1025px)').matches && localStorage.getItem(COLLAPSE_KEY) === '1') {
        body.classList.add('sidebar-collapsed');
    }

    function openSidebar() {
        body.classList.add('sidebar-open');
        if (backdrop) backdrop.hidden = false;
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }
    function closeSidebar() {
        body.classList.remove('sidebar-open');
        if (backdrop) backdrop.hidden = true;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (window.matchMedia('(min-width: 1025px)').matches) {
                body.classList.toggle('sidebar-collapsed');
                localStorage.setItem(COLLAPSE_KEY, body.classList.contains('sidebar-collapsed') ? '1' : '0');
            } else if (body.classList.contains('sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeSidebar(); closeModal(); }
    });

    // ---------------------------------------------------------------------
    // Dismissible alerts
    // ---------------------------------------------------------------------
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-dismiss]');
        if (btn) {
            var alert = btn.closest('.alert');
            if (alert) alert.remove();
        }
    });
    document.querySelectorAll('.alert[data-dismissible]').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity .4s, transform .4s';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-6px)';
            setTimeout(function () { alert.remove(); }, 400);
        }, 6000);
    });

    // ---------------------------------------------------------------------
    // Modal helpers (global)
    // ---------------------------------------------------------------------
    var lastFocused = null;

    window.openModal = function (id) {
        var modal = document.getElementById(id);
        if (!modal) return;
        lastFocused = document.activeElement;
        modal.hidden = false;
        var focusable = modal.querySelector('input, select, textarea, button');
        if (focusable) focusable.focus();
    };
    window.closeModal = function (id) {
        var modal = id ? document.getElementById(id) : document.querySelector('.modal:not([hidden])');
        if (!modal) return;
        modal.hidden = true;
        if (lastFocused && lastFocused.focus) lastFocused.focus();
    };

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-modal-open]');
        if (opener) { e.preventDefault(); openModal(opener.getAttribute('data-modal-open')); }
        var closer = e.target.closest('[data-modal-close]');
        if (closer) { e.preventDefault(); closeModal(closer.getAttribute('data-modal-close') || undefined); }
        // click on backdrop closes
        if (e.target.classList && e.target.classList.contains('modal')) closeModal(e.target.id);
    });

    // ---------------------------------------------------------------------
    // Confirmation dialogs
    // ---------------------------------------------------------------------
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });
    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[data-confirm]');
        if (link && !window.confirm(link.getAttribute('data-confirm'))) e.preventDefault();
    });

    // ---------------------------------------------------------------------
    // Auto-submit filter forms
    // ---------------------------------------------------------------------
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.matches('[data-autosubmit]')) {
            var form = el.closest('form');
            if (form) {
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
        }
    });
})();
