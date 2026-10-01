<?php // app/views/posts/show.php ?>
<?php $this->layout('layout'); ?>
<article class="post">
  <header class="post-head">
    <p class="post-meta"><a href="/posts">&larr; All posts</a> &nbsp;&middot;&nbsp; <time datetime="<?= $this->e(substr((string) $post['created_at'], 0, 10)) ?>"><?= $this->e(\App\Text::dateLine((string) $post['created_at'])) ?></time></p>
    <h1><?= $this->e((string) $post['title']) ?></h1>
  </header>
  <div class="post-body">
    <?php foreach (preg_split('/\R{2,}/u', trim((string) $post['body'])) ?: [] as $paragraph): ?>
    <?php if (trim($paragraph) === '') continue; ?>
    <p><?= nl2br($this->e($paragraph)) ?></p>
    <?php endforeach; ?>
  </div>
</article>
<?php require __DIR__ . '/_comments.php'; ?>
