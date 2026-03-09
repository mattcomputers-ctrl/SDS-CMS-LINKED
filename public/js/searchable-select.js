/**
 * Searchable Select — replaces <select> elements with a searchable dropdown.
 *
 * Usage: add class "searchable-select" to any <select>, or call
 *        SearchableSelect.init(selectElement) programmatically.
 *
 * Dynamically-added selects (e.g. formula lines) are picked up via
 * MutationObserver automatically.
 */
var SearchableSelect = (function() {
    'use strict';

    var ACTIVE_CLASS = 'ss-active';
    var openInstance = null;

    function init(select) {
        if (select.dataset.ssInit) return;
        select.dataset.ssInit = '1';

        // Build wrapper
        var wrapper = document.createElement('div');
        wrapper.className = 'ss-wrapper';

        // Display input
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'ss-input';
        input.placeholder = getSelectedText(select) || select.options[0]?.text?.trim() || 'Search…';
        input.setAttribute('autocomplete', 'off');

        // Dropdown list
        var dropdown = document.createElement('div');
        dropdown.className = 'ss-dropdown';

        // Clear button
        var clear = document.createElement('span');
        clear.className = 'ss-clear';
        clear.innerHTML = '&times;';
        clear.title = 'Clear selection';
        clear.style.display = select.value ? 'block' : 'none';

        wrapper.appendChild(input);
        wrapper.appendChild(clear);
        wrapper.appendChild(dropdown);

        // Insert wrapper and hide original select
        select.parentNode.insertBefore(wrapper, select);
        select.style.display = 'none';
        select.classList.add('ss-original');

        // Populate dropdown
        function buildOptions(filter) {
            dropdown.innerHTML = '';
            var fragment = document.createDocumentFragment();
            var count = 0;
            var filterLower = (filter || '').toLowerCase();

            for (var i = 0; i < select.options.length; i++) {
                var opt = select.options[i];
                if (!opt.value && opt.textContent.trim().match(/^[-—–]+\s*(select|choose)/i)) continue; // skip placeholder
                if (!opt.value && !opt.textContent.trim()) continue;

                var text = opt.textContent.trim();
                var textLower = text.toLowerCase();

                if (filterLower && textLower.indexOf(filterLower) === -1) continue;

                var item = document.createElement('div');
                item.className = 'ss-option';
                item.dataset.value = opt.value;
                item.dataset.index = i;

                // Highlight matching text
                if (filterLower && filterLower.length > 0) {
                    var matchIdx = textLower.indexOf(filterLower);
                    item.innerHTML = escapeHtml(text.substring(0, matchIdx))
                        + '<strong>' + escapeHtml(text.substring(matchIdx, matchIdx + filterLower.length)) + '</strong>'
                        + escapeHtml(text.substring(matchIdx + filterLower.length));
                } else {
                    item.textContent = text;
                }

                if (opt.value === select.value && opt.value !== '') {
                    item.classList.add('ss-selected');
                }

                fragment.appendChild(item);
                count++;
            }

            if (count === 0) {
                var empty = document.createElement('div');
                empty.className = 'ss-empty';
                empty.textContent = 'No matches';
                fragment.appendChild(empty);
            }

            dropdown.appendChild(fragment);
        }

        // Event: input focus — open dropdown
        input.addEventListener('focus', function() {
            closeAll();
            openInstance = wrapper;
            wrapper.classList.add(ACTIVE_CLASS);
            input.value = '';
            buildOptions('');
            dropdown.style.display = 'block';
            scrollToSelected(dropdown);
        });

        // Event: typing — filter live
        input.addEventListener('input', function() {
            buildOptions(input.value);
        });

        // Event: click option
        dropdown.addEventListener('mousedown', function(e) {
            var item = e.target.closest('.ss-option');
            if (!item) return;
            e.preventDefault();
            selectOption(item.dataset.value, item.textContent.trim());
        });

        // Event: keyboard navigation
        input.addEventListener('keydown', function(e) {
            var items = dropdown.querySelectorAll('.ss-option');
            var current = dropdown.querySelector('.ss-option.ss-hover');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!current) {
                    if (items[0]) items[0].classList.add('ss-hover');
                } else {
                    current.classList.remove('ss-hover');
                    var next = current.nextElementSibling;
                    while (next && !next.classList.contains('ss-option')) next = next.nextElementSibling;
                    if (next) next.classList.add('ss-hover');
                    else if (items[0]) items[0].classList.add('ss-hover');
                }
                scrollToHover(dropdown);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!current) {
                    if (items[items.length - 1]) items[items.length - 1].classList.add('ss-hover');
                } else {
                    current.classList.remove('ss-hover');
                    var prev = current.previousElementSibling;
                    while (prev && !prev.classList.contains('ss-option')) prev = prev.previousElementSibling;
                    if (prev) prev.classList.add('ss-hover');
                    else if (items[items.length - 1]) items[items.length - 1].classList.add('ss-hover');
                }
                scrollToHover(dropdown);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (current) {
                    selectOption(current.dataset.value, current.textContent.trim());
                }
            } else if (e.key === 'Escape') {
                closeDropdown();
                input.blur();
            } else if (e.key === 'Tab') {
                closeDropdown();
            }
        });

        // Event: clear button
        clear.addEventListener('mousedown', function(e) {
            e.preventDefault();
            e.stopPropagation();
            select.value = '';
            input.value = '';
            input.placeholder = select.options[0]?.text?.trim() || 'Search…';
            clear.style.display = 'none';
            triggerChange(select);
            closeDropdown();
        });

        function selectOption(value, text) {
            select.value = value;
            input.value = '';
            input.placeholder = text || 'Search…';
            clear.style.display = value ? 'block' : 'none';
            triggerChange(select);
            closeDropdown();
            input.blur();
        }

        function closeDropdown() {
            wrapper.classList.remove(ACTIVE_CLASS);
            dropdown.style.display = 'none';
            if (openInstance === wrapper) openInstance = null;
            // Restore display text
            var selectedText = getSelectedText(select);
            if (selectedText) {
                input.placeholder = selectedText;
            }
            input.value = '';
        }

        // Sync if select value changes externally
        select.addEventListener('change', function() {
            var text = getSelectedText(select);
            input.placeholder = text || select.options[0]?.text?.trim() || 'Search…';
            clear.style.display = select.value ? 'block' : 'none';
        });

        // If select already has a value, show it
        if (select.value) {
            var text = getSelectedText(select);
            if (text) input.placeholder = text;
        }
    }

    function closeAll() {
        if (openInstance) {
            openInstance.classList.remove(ACTIVE_CLASS);
            var dd = openInstance.querySelector('.ss-dropdown');
            if (dd) dd.style.display = 'none';
            openInstance = null;
        }
    }

    function getSelectedText(select) {
        var opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) return '';
        return opt.textContent.trim();
    }

    function scrollToSelected(dropdown) {
        var sel = dropdown.querySelector('.ss-selected');
        if (sel) sel.scrollIntoView({ block: 'nearest' });
    }

    function scrollToHover(dropdown) {
        var hov = dropdown.querySelector('.ss-hover');
        if (hov) hov.scrollIntoView({ block: 'nearest' });
    }

    function triggerChange(select) {
        var evt = new Event('change', { bubbles: true });
        select.dispatchEvent(evt);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Close dropdown when clicking outside
    document.addEventListener('mousedown', function(e) {
        if (openInstance && !openInstance.contains(e.target)) {
            closeAll();
        }
    });

    // Auto-init existing selects
    function initAll() {
        document.querySelectorAll('select.searchable-select').forEach(function(sel) {
            init(sel);
        });
    }

    // Watch for dynamically added selects
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                if (node.tagName === 'SELECT' && node.classList.contains('searchable-select')) {
                    init(node);
                }
                if (node.querySelectorAll) {
                    node.querySelectorAll('select.searchable-select').forEach(function(sel) {
                        init(sel);
                    });
                }
            });
        });
    });

    // Initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initAll();
            observer.observe(document.body, { childList: true, subtree: true });
        });
    } else {
        initAll();
        observer.observe(document.body, { childList: true, subtree: true });
    }

    return { init: init, initAll: initAll };
})();
