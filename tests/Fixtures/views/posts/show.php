<?php // app/views/posts/show.php ?>
<?php $this->layout('layout'); ?>
<article>
  <h1><?= $this->e($post['title']) ?></h1>
  <p><?= nl2br($this->e($post['body'])) ?></p>
</article>
<?php require __DIR__ . '/_comments.php'; ?>
