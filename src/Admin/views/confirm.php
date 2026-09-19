<?php // src/Admin/views/confirm.php ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
<p>This cannot be undone.</p>
<form method="post" action="/admin/delete/<?= $this->e($table) ?>/<?= $this->e($rid) ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button class="danger">Yes, delete it</button>
  <a href="/admin/browse/<?= $this->e($table) ?>">cancel</a>
</form>
