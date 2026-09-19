<?php $this->layout('layout'); ?>
<h1><?= $this->e($title) ?></h1>
<form method="post" action="<?= $post['id'] ? '/posts/update/' . $this->e($post['id']) : '/posts/store' ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label>Title <input name="title" value="<?= $this->e($post['title']) ?>" required></label>
  <label>Body <textarea name="body" required><?= $this->e($post['body']) ?></textarea></label>
  <button>Save</button>
</form>
<form method="post" action="/auth/logout">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button>Log out</button>
</form>
