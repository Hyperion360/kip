<?php // app/views/posts/edit.php ?>
<?php $this->layout('layout'); ?>
<form class="editor" method="post" action="<?= $post['id'] ? '/posts/update/' . $this->e((string) $post['id']) : '/posts/store' ?>">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <div class="editor-head">
    <h1 class="eyebrow"><?= $this->e($title) ?></h1>
    <span class="editor-hint">Plain text &middot; blank line for a new paragraph</span>
  </div>
  <label class="editor-title">
    <span class="field-label">Title</span>
    <input name="title" value="<?= $this->e((string) $post['title']) ?>" required placeholder="A good title is short">
  </label>
  <label class="editor-body">
    <span class="field-label">Body</span>
    <textarea name="body" required rows="14"><?= $this->e((string) $post['body']) ?></textarea>
  </label>
  <div class="editor-actions">
    <a class="cancel-link" href="<?= $post['id'] ? '/posts/show/' . $this->e((string) $post['id']) : '/posts' ?>">Cancel</a>
    <span class="spacer"></span>
    <button class="button-primary" type="submit"><?= $post['id'] ? 'Save changes' : 'Publish post' ?></button>
  </div>
</form>
