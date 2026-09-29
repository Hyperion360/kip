<?php $this->layout('layout'); ?>
<h1>Log in</h1>
<?php if ($error): ?><p id="form-error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<?php if ($providers): ?>
<nav aria-label="Sign in with a provider">
  <?php foreach ($providers as $name): ?>
  <a href="/oauth/start/<?= $this->e($name) ?>">Sign in with <?= $this->e(ucfirst($name)) ?></a>
  <?php endforeach ?>
</nav>
<?php endif ?>
<form method="post" action="/auth/attempt">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label>Email <input type="email" name="email" required autocomplete="email"<?= isset($error) ? ' aria-describedby="form-error"' : '' ?>></label>
  <label>Password <input type="password" name="password" required autocomplete="current-password"<?= isset($error) ? ' aria-describedby="form-error"' : '' ?>></label>
  <button>Log in</button>
</form>
<p><a href="/auth/forgot">Forgot your password?</a></p>
