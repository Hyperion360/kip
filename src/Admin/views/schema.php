<?php // src/Admin/views/schema.php
// Type tokens take the only highlight. Wrapping structural keywords (CREATE
// TABLE) in spans would break plain-text copying of the statement.
$types = 'INTEGER|INT|BIGINT|TEXT|BLOB|REAL|DOUBLE|FLOAT|NUMERIC|DECIMAL|BOOLEAN|VARCHAR|NVARCHAR|CHAR|DATETIME|DATE';
$highlight = $create === null ? '' : (string) $this->e($create);
if ($highlight !== '') {
    $highlight = preg_replace("/\b($types)\b/i", '<span class="kip-sql-t">$1</span>', $highlight) ?? $highlight;
}
?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead">
  <div class="kip-pagehead-intro">
    <nav class="kip-crumb" aria-label="Breadcrumb"><a href="/admin/sql">SQL browser</a><span aria-hidden="true">/</span><span><?= $this->e($table) ?></span></nav>
    <h1><?= $this->e($table) ?></h1>
  </div>
  <span class="kip-handle">Read-only handle</span>
</header>
<nav class="kip-tabs" aria-label="View">
  <a class="kip-tab" href="/admin/schema/<?= $this->e($table) ?>" aria-current="page">Schema</a>
  <a class="kip-tab" href="/admin/data/<?= $this->e($table) ?>">Data · <?= $this->e(number_format($count)) ?> rows</a>
  <a class="kip-tab" href="/admin/browse/<?= $this->e($table) ?>">Edit rows &nearr;</a>
</nav>
<div class="kip-schema-grid">
  <div class="kip-tablewrap">
    <table>
      <thead><tr>
        <th scope="col">Column</th><th scope="col">Type</th><th scope="col">Null</th><th scope="col">Default</th>
      </tr></thead>
      <tbody>
      <?php foreach ($columns as $c): ?>
        <tr>
          <td class="kip-colname"><?= $this->e($c['name']) ?><?php if ((int) $c['pk'] === 1): ?> <span class="kip-pk">PK</span><?php endif; ?></td>
          <td class="kip-dim"><?= $this->e($c['type']) ?></td>
          <td class="kip-dim"><?= (int) $c['notnull'] === 1 ? 'no' : 'yes' ?></td>
          <td><?php if ($c['dflt_value'] === null): ?><span class="kip-nodata">&mdash;</span><?php else: ?><span class="kip-mono"><?= $this->e((string) $c['dflt_value']) ?></span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <figure class="kip-create">
    <figcaption>CREATE statement</figcaption>
    <?php if ($create !== null): ?><pre><code><?= $highlight ?></code></pre><?php endif; ?>
    <p class="kip-help">Need an ad-hoc query? Use <code>kip db</code> from the shell.</p>
  </figure>
</div>
