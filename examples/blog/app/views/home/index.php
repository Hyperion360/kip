<?php // app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<div class="hero">
  <h1>Hello from Kip</h1>
  <p>Short notes on building small, durable websites with plain HTML, a little PHP, and one SQLite file.</p>
</div>
<section class="latest" aria-labelledby="latest-title">
  <h2 class="eyebrow" id="latest-title">Latest</h2>
  <?php require __DIR__ . '/../posts/_list.php'; ?>
  <a class="all-posts" href="/posts">All posts &rarr;</a>
</section>
