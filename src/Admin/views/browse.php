<?php // src/Admin/views/browse.php ?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead">
  <div class="kip-pagehead-intro">
    <nav class="kip-crumb" aria-label="Breadcrumb"><a href="/admin">Tables</a><span aria-hidden="true">/</span><span><?= $this->e($table) ?></span></nav>
    <h1><?= $this->e($table) ?></h1>
    <p class="kip-lede"><?= $this->e(number_format($total)) ?> rows · newest first · <?= $this->e($perPage) ?> per page</p>
  </div>
  <div class="kip-head-actions">
    <a class="kip-btn-ghost" href="/admin/schema/<?= $this->e($table) ?>">View schema</a>
    <a class="kip-btn-solid" href="/admin/create/<?= $this->e($table) ?>">+ New row</a>
  </div>
</header>
<div class="kip-tablewrap">
<?php if ($rows === []): ?>
  <p class="kip-empty">No rows yet.</p>
<?php else: ?>
  <table>
    <thead><tr>
      <?php foreach ($columns as $c): ?><th scope="col"><?= $this->e($c['name']) ?></th><?php endforeach; ?>
      <th scope="col" class="kip-right"><span class="kip-sr">Actions</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <?php foreach ($columns as $c): $name = $c['name']; $value = $row[$name] ?? null; ?>
        <?php if ($name === 'password_hash'): ?>
        <td data-label="<?= $this->e($name) ?>">••••</td> <?php /* never the hash, not even in a title */ ?>
        <?php elseif (str_starts_with($name, 'is_')): ?>
        <td data-label="<?= $this->e($name) ?>"><?php if ($value === null): ?><span class="kip-nodata">&mdash;</span><?php else: ?><span class="kip-chip<?= (int) $value === 1 ? '' : ' kip-chip-no' ?>"><?= (int) $value === 1 ? 'Yes' : 'No' ?></span><?php endif; ?></td>
        <?php else: ?>
        <td data-label="<?= $this->e($name) ?>"<?= strtoupper((string) $c['type']) === 'INTEGER' || str_ends_with($name, '_at') ? ' class="kip-mono"' : '' ?>><?= $this->e($value ?? '') ?></td>
        <?php endif; ?>
        <?php endforeach; ?>
        <td class="kip-rowactions kip-right">
          <a class="kip-edit" href="/admin/edit/<?= $this->e($table) ?>/<?= $this->e($row['__rid']) ?>" aria-label="Edit <?= $this->e($table) ?> row <?= $this->e($row['__rid']) ?>">Edit</a>
          <a class="kip-delete" href="/admin/confirmdelete/<?= $this->e($table) ?>/<?= $this->e($row['__rid']) ?>" aria-label="Delete <?= $this->e($table) ?> row <?= $this->e($row['__rid']) ?>">Delete</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<nav class="kip-pager" aria-label="Pages">
  <span>Page <?= $this->e($page) ?> of <?= $this->e(max(1, (int) ceil($total / max(1, $perPage)))) ?></span>
  <div class="kip-pager-group">
    <?php if ($page > 1): ?><a class="kip-pager-link" href="/admin/browse/<?= $this->e($table) ?>?page=<?= $this->e($page - 1) ?>">&larr; Newer</a>
    <?php else: ?><span class="kip-pager-link" aria-disabled="true">&larr; Newer</span><?php endif; ?>
    <?php if ($hasNext): ?><a class="kip-pager-link" href="/admin/browse/<?= $this->e($table) ?>?page=<?= $this->e($page + 1) ?>">Older &rarr;</a>
    <?php else: ?><span class="kip-pager-link" aria-disabled="true">Older &rarr;</span><?php endif; ?>
  </div>
</nav>
