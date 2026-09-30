<?php // src/Admin/views/index.php ?>
<?php $this->layout('layout'); ?>
<header class="kip-pagehead-intro">
  <p class="kip-kicker">data.sqlite</p>
  <h1>Your tables</h1>
  <p class="kip-lede">Pick a table to browse its rows, or add a new one. Schema changes live in migrations, not here.</p>
</header>
<?php if ($tables === []): ?>
<p class="kip-empty">No tables yet. Your first migration creates one (guide chapter 5).</p>
<?php else: ?>
<ul class="kip-cards">
  <?php foreach ($tables as $t => $count): ?>
  <li class="kip-card">
    <div class="kip-card-top">
      <a class="kip-card-name" href="/admin/browse/<?= $this->e($t) ?>"><?= $this->e($t) ?></a>
      <span class="kip-card-count"><?= $this->e(number_format((int) $count)) ?></span>
    </div>
    <div class="kip-card-actions">
      <a class="kip-card-browse" href="/admin/browse/<?= $this->e($t) ?>">Browse rows</a>
      <a class="kip-card-new" href="/admin/create/<?= $this->e($t) ?>">+ New</a>
    </div>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<aside class="kip-callout">
  <p><strong>Just looking?</strong> The SQL browser reads through a handle SQLite opens read-only, so nothing there can change your data.</p>
  <a class="kip-btn-solid" href="/admin/sql">Open SQL browser</a>
</aside>
