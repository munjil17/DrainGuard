// C:\xampp\htdocs\DrainGuard\js\citizen\sidebar.js

(function () {
    'use strict';

    /* ── ELEMENTS ──────────────────────────────── */
    const sidebar      = document.getElementById('sidebar');
    const overlay      = document.getElementById('sidebarOverlay');
    const mobileToggle = document.getElementById('mobileToggle');

    if (!sidebar || !overlay || !mobileToggle) return;

    /* ── OPEN SIDEBAR ──────────────────────────── */
    function openSidebar() {
        sidebar.classList.add('active');
        overlay.classList.add('active');
        document.body.classList.add('sidebar-open');
        mobileToggle.setAttribute('aria-expanded', 'true');
        mobileToggle.setAttribute('aria-label', 'Close sidebar');
    }

    /* ── CLOSE SIDEBAR ─────────────────────────── */
    function closeSidebar() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
        mobileToggle.setAttribute('aria-expanded', 'false');
        mobileToggle.setAttribute('aria-label', 'Open sidebar');
    }

    /* ── TOGGLE ────────────────────────────────── */
    function toggleSidebar() {
        sidebar.classList.contains('active') ? closeSidebar() : openSidebar();
    }

    /* ── EVENTS ────────────────────────────────── */

    // Hamburger button click
    mobileToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleSidebar();
    });

    // Overlay click — close sidebar
    overlay.addEventListener('click', closeSidebar);

    // ESC key — close sidebar
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar.classList.contains('active')) {
            closeSidebar();
        }
    });

    // Window resize — auto close on desktop
    window.addEventListener('resize', function () {
        if (window.innerWidth > 992) {
            closeSidebar();
        }
    });

    /* ── ACTIVE MENU HIGHLIGHT ─────────────────── */
    // Current page URL থেকে active link highlight করে
    const currentPath = window.location.pathname.split('/').pop();

    document.querySelectorAll('.menu-link').forEach(function (link) {
        const linkPath = link.getAttribute('href');
        if (linkPath && linkPath === currentPath) {
            link.classList.add('active');
            link.setAttribute('aria-current', 'page');
        }
    });

})();