<?php // app/views/posts/_comments.php ?>
<section>
  <h2>Comments</h2>
  <?php foreach ($comments as $c): ?>
    <p><strong><?= $this->e($c['author']) ?></strong>: <?= nl2br($this->e($c['body'])) ?></p>
  <?php endforeach; ?>
  <form method="post" action="/comments/store/<?= $this->e($post['id']) ?>">
    <label>Name <input name="author" required></label>
    <label>Comment <textarea name="body" required></textarea></label>
    <button>Add comment</button>
  </form>
</section>
