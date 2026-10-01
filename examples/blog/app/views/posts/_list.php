<?php // app/views/posts/_list.php: the shared date/title/excerpt list. Expects $posts. ?>
<ol class="post-list">
<?php foreach ($posts as $p): ?>
  <li>
    <p class="post-date"><time datetime="<?= $this->e(substr((string) $p['created_at'], 0, 10)) ?>"><?= $this->e(\App\Text::dateLine((string) $p['created_at'])) ?></time></p>
    <h2><a href="/posts/show/<?= $this->e((string) $p['id']) ?>"><?= $this->e((string) $p['title']) ?></a></h2>
    <p class="post-excerpt"><?= $this->e(\App\Text::excerpt((string) $p['body'])) ?></p>
  </li>
<?php endforeach; ?>
</ol>
