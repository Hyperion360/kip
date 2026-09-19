<?php // app/views/posts/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Posts</h1>
<ul>
<?php foreach ($posts as $p): ?>
  <li><a href="/posts/show/<?= $this->e($p['id']) ?>"><?= $this->e($p['title']) ?></a></li>
<?php endforeach; ?>
</ul>
<nav>
  <?php if ($page > 1): ?><a href="/posts?page=<?= $this->e($page - 1) ?>">&larr; Newer</a><?php endif; ?>
  <?php if ($hasNext): ?><a href="/posts?page=<?= $this->e($page + 1) ?>">Older &rarr;</a><?php endif; ?>
</nav>
