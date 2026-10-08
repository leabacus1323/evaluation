</div>
</main>
<footer class="site-footer">
  <div class="container footer-inner">
    <span>&copy; <?= date('Y') ?> <?= h(setting('organization_name', 'Your Institution')) ?></span>
    <span><?= h(setting('site_title', 'Event Evaluation and Feedback Management System')) ?></span>
  </div>
</footer>
<script src="<?= !empty($adminArea) ? '../' : '' ?>assets/js/app.js"></script>
</body>
</html>
