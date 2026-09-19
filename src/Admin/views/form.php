<?php // src/Admin/views/form.php ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
<form method="post" action="<?= $this->e($action) ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <?php foreach ($columns as $c): $name = $c['name']; $value = $record[$name] ?? ''; ?>
    <?php if ($name === 'password_hash'): ?>
      <label><?= $this->e($name) ?> <small>(leave blank to keep current)</small><br>
        <input type="password" name="password_hash" value="" autocomplete="new-password"></label>
    <?php elseif (str_starts_with($name, 'is_')): ?>
      <label><input type="checkbox" name="<?= $this->e($name) ?>" value="1" <?= ((int) $value === 1) ? 'checked' : '' ?>> <?= $this->e($name) ?></label>
    <?php elseif (strtoupper((string) $c['type']) === 'INTEGER'): ?>
      <label><?= $this->e($name) ?><br>
        <input type="number" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"></label>
    <?php elseif (in_array($name, ['body', 'content', 'description', 'notes'], true)): ?>
      <label><?= $this->e($name) ?><br>
        <textarea name="<?= $this->e($name) ?>"><?= $this->e($value) ?></textarea></label>
    <?php else: ?>
      <label><?= $this->e($name) ?><br>
        <input type="text" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"></label>
    <?php endif; ?>
  <?php endforeach; ?>
  <p><button>Save</button> <a href="/admin/browse/<?= $this->e($table) ?>">cancel</a></p>
</form>
