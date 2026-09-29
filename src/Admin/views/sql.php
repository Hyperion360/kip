<?php // src/Admin/views/sql.php ?>
<?php $this->layout('layout'); ?>
<h1>SQL browser</h1>
<p>Read-only views over every user table. These pages run on a second
database handle that SQLite itself opens read-only, so nothing here can
write, whatever happens. For ad-hoc queries, use <code>kip db</code> from
the shell (guide chapter 8).</p>
<table>
  <tr><th scope="col">Table</th><th scope="col">Rows</th><th scope="col">Read</th></tr>
  <?php foreach ($tables as $t => $count): ?>
  <tr>
    <td><?= $this->e($t) ?></td>
    <td><?= $this->e($count) ?></td>
    <td>
      <a href="/admin/schema/<?= $this->e($t) ?>">schema</a>
      <a href="/admin/data/<?= $this->e($t) ?>">data</a>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
