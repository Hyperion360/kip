<?php // src/Admin/views/confirm.php ?>
<?php $this->layout('layout'); ?>
<form class="kip-confirm" method="post" action="/admin/delete/<?= $this->e($table) ?>/<?= $this->e($rid) ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <div class="kip-confirm-head">
    <p class="kip-confirm-kicker"><?= $this->e($table) ?> · row <?= $this->e($rid) ?></p>
    <h1>Delete this row?</h1>
    <p class="kip-confirm-lede">It will be removed from the database for good. There is no trash and no undo.</p>
  </div>
  <?php if ($preview !== []): ?>
  <dl>
    <?php foreach ($preview as $f): ?>
    <dt><?= $this->e($f['label']) ?></dt><dd><?php if ($f['value'] === null): ?><span class="kip-nodata">&mdash;</span><?php else: ?><?= $this->e($f['value']) ?><?php endif; ?></dd>
    <?php endforeach; ?>
  </dl>
  <?php endif; ?>
  <div class="kip-confirm-actions">
    <button type="submit">Yes, delete it</button>
    <a href="/admin/browse/<?= $this->e($table) ?>">Keep it</a>
  </div>
</form>
