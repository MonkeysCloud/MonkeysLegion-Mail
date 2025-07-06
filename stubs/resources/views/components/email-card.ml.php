<div class="email-card">
    <?php if (isset($slots['title'])): ?>
        <h3><?php $slots['title'](); ?></h3>
    <?php elseif (isset($title)): ?>
        <h3><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h3>
    <?php endif; ?>

    <?= $slotContent ?>
</div>