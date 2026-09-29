<?php // src/Admin/views/schema.php ?>
<?php $this->layout('layout'); ?>
<h1>Schema: <?= $this->e($table) ?></h1>
<p><?= $this->e($count) ?> rows.
<a href="/admin/data/<?= $this->e($table) ?>">view data (read-only)</a>
<a href="/admin/browse/<?= $this->e($table) ?>">edit rows</a></p>
<h2>Columns</h2>
<table>
  <tr><th scope="col">Column</th><th scope="col">Type</th><th scope="col">Not null</th><th scope="col">Default</th><th scope="col">Primary key</th></tr>
  <?php foreach ($columns as $c): ?>
  <tr>
    <td><?= $this->e($c['name']) ?></td>
    <td><?= $this->e($c['type']) ?></td>
    <td><?= (int) $c['notnull'] === 1 ? 'yes' : 'no' ?></td>
    <td><?= $this->e($c['dflt_value'] ?? '') ?></td>
    <td><?= (int) $c['pk'] === 1 ? 'yes' : '' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php if ($create !== null): ?>
<h2>CREATE statement</h2>
<pre><code><?= $this->e($create) ?></code></pre>
<?php endif; ?>
