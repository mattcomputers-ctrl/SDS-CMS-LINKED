    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; <?= date('Y') ?> <?= e(\SDS\Core\App::config('company.name', '')) ?> — SDS System v<?= e(\SDS\Core\App::config('app.version', '1.0.0')) ?></p>
        </div>
    </footer>

    <script src="/js/app.js"></script>
</body>
</html>
