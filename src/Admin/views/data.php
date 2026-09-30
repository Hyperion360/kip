<?php // src/Admin/views/data.php ?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead">
  <div class="kip-pagehead-intro">
    <nav class="kip-crumb" aria-label="Breadcrumb"><a href="/admin/sql">SQL browser</a><span aria-hidden="true">/</span><span><?= $this->e($table) ?></span></nav>
    <h1><?= $this->e($table) ?></h1>
    <p class="kip-lede">Read-only. Rows come through a handle SQLite opens read-only; there is no edit, create, or delete here.</p>
  </div>
  <span class="kip-handle">Read-only handle</span>
</header>
<nav class="kip-tabs" aria-label="View">
  <a class="kip-tab" href="/admin/schema/<?= $this->e($table) ?>">Schema</a>
  <a class="kip-tab" href="/admin/data/<?= $this->e($table) ?>" aria-current="page">Data</a>
  <a class="kip-tab" href="/admin/browse/<?= $this->e($table) ?>">Edit rows &nearr;</a>
</nav>
<details class="kip-filters"<?= $col === '' ? '' : ' open' ?>>
  <summary class="kip-filters-summary">Filters<?php if ($col !== ''): ?> <span class="kip-filters-active"><?= $this->e($col) ?> <?= $this->e($op) ?></span><?php endif; ?></summary>
  <form class="kip-filters-form" method="get" action="/admin/data/<?= $this->e($table) ?>">
    <label>Column
      <select name="col">
        <option value="">(none)</option>
        <?php foreach ($filterColumns as $c): ?>
        <option value="<?= $this->e($c['name']) ?>"<?= $col === $c['name'] ? ' selected' : '' ?>><?= $this->e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Operator
      <select name="op">
        <?php foreach ($operators as $o): ?>
        <option value="<?= $this->e($o) ?>"<?= $op === $o ? ' selected' : '' ?>><?= $this->e($o) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Value
      <input type="text" name="val" value="<?= $this->e($val) ?>">
    </label>
    <div class="kip-filters-actions"><button type="submit">Apply</button></div>
  </form>
  <p class="kip-filters-note kip-help">For LIKE, type the % yourself. An empty value means no filter. The value matches literally; it can never change the query.</p>
</details>
<div class="kip-tablewrap">
<?php if ($rows === []): ?>
  <p class="kip-empty">No rows match.</p>
<?php else: ?>
  <table>
    <thead><tr>
      <th scope="col">rowid</th>
      <?php foreach ($columns as $c): ?><th scope="col"><?= $this->e($c['name']) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td data-label="rowid" class="kip-mono"><?= $this->e($row['__rid'] ?? '') ?></td>
        <?php foreach ($columns as $c): $name = $c['name']; ?>
        <?php if ($name === 'password_hash'): ?>
        <td data-label="<?= $this->e($name) ?>">••••</td>
        <?php elseif (str_starts_with($name, 'is_') && $row[$name] !== null): ?>
        <td data-label="<?= $this->e($name) ?>"><span class="kip-chip<?= (int) $row[$name] === 1 ? '' : ' kip-chip-no' ?>"><?= (int) $row[$name] === 1 ? 'Yes' : 'No' ?></span></td>
        <?php else: ?>
        <td data-label="<?= $this->e($name) ?>"><?= $this->e($row[$name] ?? '') ?></td>
        <?php endif; ?>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<nav class="kip-pager" aria-label="Pages">
  <span>Newest first · <?= $this->e($perPage) ?> per page</span>
  <div class="kip-pager-group">
    <?php
    $query = static function (int $p) use ($col, $op, $val): string {
        return http_build_query(['col' => $col, 'op' => $op, 'val' => $val, 'page' => $p]);
    };
    ?>
    <?php if ($page > 1): ?><a class="kip-pager-link" href="/admin/data/<?= $this->e($table) ?>?<?= $this->e($query($page - 1)) ?>">&larr; Newer</a>
    <?php else: ?><span class="kip-pager-link" aria-disabled="true">&larr; Newer</span><?php endif; ?>
    <?php if ($hasNext): ?><a class="kip-pager-link" href="/admin/data/<?= $this->e($table) ?>?<?= $this->e($query($page + 1)) ?>">Older &rarr;</a>
    <?php else: ?><span class="kip-pager-link" aria-disabled="true">Older &rarr;</span><?php endif; ?>
  </div>
</nav>
