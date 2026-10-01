<?php // app/views/posts/_comments.php ?>
<section class="comments" aria-labelledby="comments-title">
  <h2 id="comments-title">Comments <span class="count"><?= count($comments) ?></span></h2>
  <?php if ($comments !== []): ?>
  <ol class="comment-list">
    <?php foreach ($comments as $c): ?>
    <li>
      <p class="comment-head"><strong><?= $this->e((string) $c['author']) ?></strong> <time class="comment-date" datetime="<?= $this->e((string) $c['created_at']) ?>"><?= $this->e(\App\Text::dateTimeLine((string) $c['created_at'])) ?></time></p>
      <p class="comment-body"><?= nl2br($this->e((string) $c['body'])) ?></p>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <form class="comment-form" method="post" action="/comments/store/<?= $this->e((string) $post['id']) ?>">
    <p class="form-title">Leave a comment</p>
    <label class="field">Name
      <input name="author" required autocomplete="name">
    </label>
    <label class="field">Comment
      <textarea name="body" required rows="4"></textarea>
    </label>
    <div class="form-actions"><button class="button-primary" type="submit">Add comment</button></div>
  </form>
</section>
