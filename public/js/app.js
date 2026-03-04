/**
 * SDS System — Application JavaScript
 */

(function() {
    'use strict';

    // ── Mobile navbar toggle ──────────────────────────────
    var navToggle = document.getElementById('navToggle');
    var navCollapse = document.getElementById('navCollapse');
    if (navToggle && navCollapse) {
        navToggle.addEventListener('click', function() {
            navToggle.classList.toggle('active');
            navCollapse.classList.toggle('open');
        });

        // Mobile dropdown toggle (tap instead of hover)
        var dropdowns = navCollapse.querySelectorAll('.dropdown > a');
        dropdowns.forEach(function(link) {
            link.addEventListener('click', function(e) {
                if (window.innerWidth <= 900) {
                    e.preventDefault();
                    link.parentElement.classList.toggle('open');
                }
            });
        });

        // Close menu when clicking a nav link (not dropdown toggles)
        navCollapse.querySelectorAll('.navbar-menu a').forEach(function(link) {
            if (!link.parentElement.classList.contains('dropdown')) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 900) {
                        navToggle.classList.remove('active');
                        navCollapse.classList.remove('open');
                    }
                });
            }
        });
        navCollapse.querySelectorAll('.dropdown-menu a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 900) {
                    navToggle.classList.remove('active');
                    navCollapse.classList.remove('open');
                }
            });
        });
    }

    // ── Wrap tables for horizontal scrolling on mobile ────
    document.querySelectorAll('.table').forEach(function(table) {
        if (table.parentElement.classList.contains('table-responsive')) return;
        // Skip tables already inside compact containers (e.g. nested in cards with little data)
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });

    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.3s';
            alert.style.opacity = '0';
            setTimeout(function() { alert.remove(); }, 300);
        }, 5000);
    });

    // Close button for alerts
    document.querySelectorAll('.btn-close').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var alert = btn.closest('.alert');
            if (alert) alert.remove();
        });
    });

    // Confirm before delete actions
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(el.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // CAS number validation on blur
    document.querySelectorAll('input[name^="cas_number"]').forEach(function(input) {
        input.addEventListener('blur', function() {
            var val = input.value.trim();
            if (val === '') return;

            if (!/^\d{2,7}-\d{2}-\d$/.test(val)) {
                input.style.borderColor = '#dc3545';
                input.title = 'Invalid CAS format. Expected: XXXXXXX-YY-Z';
            } else {
                // Checksum validation
                var parts = val.split('-');
                var digits = parts[0] + parts[1];
                var check = parseInt(parts[2], 10);
                var sum = 0;
                var weight = 1;
                for (var i = digits.length - 1; i >= 0; i--) {
                    sum += parseInt(digits[i], 10) * weight;
                    weight++;
                }
                if (sum % 10 !== check) {
                    input.style.borderColor = '#fd7e14';
                    input.title = 'CAS checksum mismatch — verify number';
                } else {
                    input.style.borderColor = '#198754';
                    input.title = '';
                }
            }
        });
    });

    // Table sorting: highlight sorted column header
    var params = new URLSearchParams(window.location.search);
    var sortCol = params.get('sort');
    if (sortCol) {
        document.querySelectorAll('th a').forEach(function(a) {
            if (a.href && a.href.includes('sort=' + sortCol)) {
                a.style.fontWeight = '700';
                a.style.color = '#003366';
            }
        });
    }
})();
