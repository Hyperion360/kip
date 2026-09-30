<?php // src/Admin/views/form.php ?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead-intro">
  <nav class="kip-crumb" aria-label="Breadcrumb">
    <a href="/admin">Tables</a><span aria-hidden="true">/</span>
    <a href="/admin/browse/<?= $this->e($table) ?>"><?= $this->e($table) ?></a><span aria-hidden="true">/</span>
    <span><?= $creating ? 'new row' : 'row ' . $this->e($record['__rid'] ?? '') ?></span>
  </nav>
  <h1><?= $creating ? 'New ' . $this->e($table) . ' row' : 'Edit ' . $this->e($table) . ' row ' . $this->e($record['__rid'] ?? '') ?></h1>
</header>
<div class="kip-two-col">
  <form class="kip-form" id="kip-row-form" method="post" action="<?= $this->e($action) ?>">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <?php foreach ($columns as $c): $name = $c['name']; $value = $record[$name] ?? ''; $required = (int) $c['notnull'] === 1 && $c['dflt_value'] === null; ?>
    <?php if ($name === 'password_hash'): ?>
    <label class="kip-field">
      <span class="kip-label"><?= $this->e($name) ?></span>
      <input type="password" name="password_hash" value="" autocomplete="new-password" placeholder="Leave blank to keep the current password">
      <span class="kip-help">Write-only. The stored hash never reaches the browser; a new value is hashed on save.</span>
    </label>
    <?php elseif (str_starts_with($name, 'is_')): ?>
    <label class="kip-check">
      <input type="checkbox" name="<?= $this->e($name) ?>" value="1"<?= (int) $value === 1 ? ' checked' : '' ?>>
      <span class="kip-check-body">
        <span class="kip-label"><?= $this->e($name) ?></span>
        <?php if ($table === 'users' && $name === 'is_admin'): ?>
        <span class="kip-help">Can open this panel. Unchecking your own account locks you out; recover with <code>kip user:create --admin</code>.</span>
        <?php endif; ?>
      </span>
    </label>
    <?php else: ?>
    <label class="kip-field">
      <span class="kip-label"><?= $this->e($name) ?><?php if ($required): ?> <span class="kip-required">· required</span><?php endif; ?></span>
      <?php if (in_array($name, ['body', 'content', 'description', 'notes'], true)): ?>
      <textarea name="<?= $this->e($name) ?>"<?= $required ? ' required' : '' ?>><?= $this->e($value) ?></textarea>
      <?php elseif (strtoupper((string) $c['type']) === 'INTEGER'): ?>
      <input type="number" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"<?= $required ? ' required' : '' ?>>
      <?php else: ?>
      <input type="text" name="<?= $this->e($name) ?>" value="<?= $this->e($value) ?>"<?= $required ? ' required' : '' ?>>
      <?php endif; ?>
    </label>
    <?php endif; ?>
    <?php endforeach; ?>
  </form>
  <?php if (!$creating): ?>
  <aside class="kip-meta">
    <p class="kip-nav-title">Set by Kip</p>
    <dl>
      <dt>id</dt><dd><?= $this->e($record['__rid'] ?? '') ?></dd>
      <?php if (isset($record['created_at'])): ?><dt>created_at</dt><dd><?= $this->e($record['created_at']) ?></dd><?php endif; ?>
    </dl>
    <p class="kip-help">Primary keys and <code>*_at</code> columns are never editable.</p>
  </aside>
  <?php endif; ?>
</div>
<div class="kip-formbar">
  <button class="kip-submit" type="submit" form="kip-row-form">Save changes</button>
  <a class="kip-cancel" href="/admin/browse/<?= $this->e($table) ?>">Cancel</a>
  <?php if (!$creating): ?><a class="kip-delete-link" href="/admin/confirmdelete/<?= $this->e($table) ?>/<?= $this->e($record['__rid'] ?? '') ?>">Delete this row…</a><?php endif; ?>
</div>
