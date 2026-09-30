<?php // src/Admin/views/sql.php ?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead-intro">
  <p class="kip-kicker">data.sqlite · read only</p>
  <h1>SQL browser</h1>
  <p class="kip-lede">Read-only views over every user table. These pages run on a second
database handle that SQLite itself opens read-only, so nothing here can
write, whatever happens. For ad-hoc queries, use <code>kip db</code> from
the shell (guide chapter 8).</p>
</header>
<ul class="kip-cards">
  <?php foreach ($tables as $t => $count): ?>
  <li class="kip-card">
    <div class="kip-card-top">
      <a class="kip-card-name" href="/admin/schema/<?= $this->e($t) ?>"><?= $this->e($t) ?></a>
      <span class="kip-card-count"><?= $this->e(number_format((int) $count)) ?></span>
    </div>
    <div class="kip-card-actions">
      <a class="kip-card-browse" href="/admin/schema/<?= $this->e($t) ?>">Schema</a>
      <a class="kip-card-new" href="/admin/data/<?= $this->e($t) ?>">Data</a>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
