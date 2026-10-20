        </main>
        <footer class="app-footer">
            <span>&copy; <?= e(date('Y')) ?> <?= e(setting('store_name', 'Group 6 Retail')) ?> &middot; Retail POS System</span>
            <span>Group 6 Project &middot; PHP &amp; MySQL</span>
        </footer>
    </div>
</div>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($_page_scripts)) { echo $_page_scripts; } ?>
</body>
</html>
