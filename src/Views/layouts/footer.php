        </main>

        <footer class="footer">
            <p>&copy; <?= date('Y') ?> <?= e(\SDS\Core\App::config('company.name', '')) ?> — SDS System v<?= e(\SDS\Core\App::config('app.version', '1.0.0')) ?></p>
        </footer>
    </div>

    <script src="/js/searchable-select.js"></script>
    <script src="/js/app.js"></script>
    <script>
    (function() {
        var toggle = document.getElementById('pdfModeToggle');
        var label  = document.getElementById('pdfModeLabel');
        if (!toggle) return;

        var isNewTab = document.cookie.indexOf('sds_pdf_download=1') === -1;
        toggle.checked = isNewTab;
        updateLabel(isNewTab);
        applyPdfLinkTargets(isNewTab);

        toggle.addEventListener('change', function() {
            var newTab = toggle.checked;
            if (newTab) {
                document.cookie = 'sds_pdf_download=; path=/; max-age=0';
            } else {
                document.cookie = 'sds_pdf_download=1; path=/; max-age=31536000';
            }
            updateLabel(newTab);
            applyPdfLinkTargets(newTab);
        });

        function updateLabel(newTab) {
            if (label) label.textContent = newTab ? 'Open PDFs in new tab' : 'Download PDFs';
        }

        function applyPdfLinkTargets(newTab) {
            var links = document.querySelectorAll('.pdf-link');
            for (var i = 0; i < links.length; i++) {
                if (newTab) {
                    links[i].setAttribute('target', '_blank');
                } else {
                    links[i].removeAttribute('target');
                }
            }
        }
    })();
    </script>
</body>
</html>
