<?php // app/views/posts/index.php ?>
<?php $this->layout('layout'); ?>
<div class="page-head">
  <h1>Posts</h1>
  <p>Newest first.</p>
</div>
<?php require __DIR__ . '/_list.php'; ?>
<nav class="pager" aria-label="Pages">
  <?php if ($page > 1): ?>
  <a class="pager-link" href="/posts?page=<?= $this->e((string) ($page - 1)) ?>">&larr; Newer posts</a>
  <?php else: ?>
  <span class="pager-spacer"></span>
  <?php endif; ?>
  <?php if ($hasNext): ?>
  <a class="pager-link" href="/posts?page=<?= $this->e((string) ($page + 1)) ?>">Older posts &rarr;</a>
  <?php else: ?>
  <span class="pager-spacer"></span>
  <?php endif; ?>
</nav>
