<?php // app/views/auth/login.php ?>
<?php $this->layout('layout'); ?>
<div class="login-card">
  <div class="login-head">
    <h1>Log in</h1>
    <p>Only needed to write or edit posts. Reading and commenting are open to everyone.</p>
  </div>
  <?php if ($error): ?><p class="alert" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
  <form method="post" action="/auth/attempt">
    <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
    <label class="field">Email
      <input type="email" name="email" required autocomplete="email" value="<?= $this->e($email ?? '') ?>">
    </label>
    <label class="field">Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="button-primary button-block" type="submit">Log in</button>
  </form>
</div>
