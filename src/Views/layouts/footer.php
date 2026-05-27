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

        var downloadMode = document.cookie.indexOf('sds_pdf_download=1') !== -1;
        toggle.checked = !downloadMode;
        updateLabel(!downloadMode);
        applyPdfLinkTargets(!downloadMode);

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
                    links[i].removeAttribute('download');
                } else {
                    links[i].removeAttribute('target');
                    links[i].removeAttribute('download');
                }
            }
        }

        document.addEventListener('click', function(e) {
            if (document.cookie.indexOf('sds_pdf_download=1') === -1) return;
            var link = e.target.closest('.pdf-link');
            if (!link) return;
            e.preventDefault();
            var a = document.createElement('a');
            a.href = link.href;
            a.download = '';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        });
    })();
    </script>
</body>
</html>
