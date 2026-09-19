<?php // src/Admin/views/browse.php ?>
<?php $this->layout('layout'); ?>
<h1><?= $this->e($table) ?></h1>
<p><a href="/admin/create/<?= $this->e($table) ?>">+ new row</a></p>
<table>
  <tr>
    <?php foreach ($columns as $c): ?><th scope="col"><?= $this->e($c['name']) ?></th><?php endforeach; ?>
    <th scope="col">Actions</th>
  </tr>
  <?php foreach ($rows as $row): ?>
  <tr>
    <?php foreach ($columns as $c): ?>
      <td title="<?= $this->e($row[$c['name']] ?? '') ?>"><?= $c['name'] === 'password_hash' ? '••••' : $this->e($row[$c['name']] ?? '') ?></td>
    <?php endforeach; ?>
    <td>
      <a href="/admin/edit/<?= $this->e($table) ?>/<?= $this->e($row['__rid']) ?>" aria-label="edit <?= $this->e($table) ?> row <?= $this->e($row['__rid']) ?>">edit</a>
      <a href="/admin/confirmdelete/<?= $this->e($table) ?>/<?= $this->e($row['__rid']) ?>" aria-label="delete <?= $this->e($table) ?> row <?= $this->e($row['__rid']) ?>">delete</a>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<nav class="pager">
  <?php if ($page > 1): ?><a href="/admin/browse/<?= $this->e($table) ?>?page=<?= $this->e($page - 1) ?>">&larr; Prev</a><?php endif; ?>
  <?php if ($hasNext): ?><a href="/admin/browse/<?= $this->e($table) ?>?page=<?= $this->e($page + 1) ?>">Next &rarr;</a><?php endif; ?>
</nav>
