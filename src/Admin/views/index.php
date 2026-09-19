<?php // src/Admin/views/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Tables</h1>
<table>
  <tr><th scope="col">Table</th><th scope="col">Rows</th><th scope="col">Actions</th></tr>
  <?php foreach ($tables as $t => $count): ?>
  <tr>
    <td><a href="/admin/browse/<?= $this->e($t) ?>"><?= $this->e($t) ?></a></td>
    <td><?= $this->e($count) ?></td>
    <td><a href="/admin/create/<?= $this->e($t) ?>">+ new row</a></td>
  </tr>
  <?php endforeach; ?>
</table>
