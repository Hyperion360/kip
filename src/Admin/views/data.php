<?php // src/Admin/views/data.php ?>
<?php $this->layout('layout'); ?>
<h1>Data: <?= $this->e($table) ?></h1>
<p>Read-only. This page reads through a database handle SQLite opens
read-only; there is no edit, create, or delete here.</p>
<form method="get" action="/admin/data/<?= $this->e($table) ?>">
  <label for="col">Filter</label>
  <select name="col" id="col">
    <option value="">(none)</option>
    <?php foreach ($filterColumns as $c): ?>
    <option value="<?= $this->e($c['name']) ?>"<?= $col === $c['name'] ? ' selected' : '' ?>><?= $this->e($c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="op" id="op">
    <?php foreach ($operators as $o): ?>
    <option value="<?= $this->e($o) ?>"<?= $op === $o ? ' selected' : '' ?>><?= $this->e($o) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="text" name="val" value="<?= $this->e($val) ?>" aria-label="filter value">
  <button>Apply</button>
  <p>For LIKE, type the % yourself. An empty value means no filter.
The value matches literally; it can never change the query.</p>
</form>
<table>
  <tr><th scope="col">rowid</th>
    <?php foreach ($columns as $c): ?><th scope="col"><?= $this->e($c['name']) ?></th><?php endforeach; ?>
  </tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <td><?= $this->e($row['__rid'] ?? '') ?></td>
    <?php foreach ($columns as $c): ?>
    <?php $shown = $c['name'] === 'password_hash' ? '••••' : $this->e($row[$c['name']] ?? ''); /* never the hash, not even in title */ ?>
    <td title="<?= $shown ?>"><?= $shown ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
</table>
<nav class="pager">
  <?php
  $query = static function (int $p) use ($col, $op, $val): string {
    return http_build_query(['col' => $col, 'op' => $op, 'val' => $val, 'page' => $p]);
  };
  ?>
  <?php if ($page > 1): ?><a href="/admin/data/<?= $this->e($table) ?>?<?= $this->e($query($page - 1)) ?>">&larr; Prev</a><?php endif; ?>
  <?php if ($hasNext): ?><a href="/admin/data/<?= $this->e($table) ?>?<?= $this->e($query($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
</nav>
