<?php $this->layout('layout'); ?>
<h1>Choose a new password</h1>
<?php if ($error !== null): ?><p id="form-error" role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/auth/confirm">
  <input type="hidden" name="token" value="<?= $this->e($token) ?>">
  <label>New password <input type="password" name="password" minlength="8" required autocomplete="new-password"<?= $error !== null ? ' aria-describedby="form-error"' : '' ?>></label>
  <button>Set password</button>
</form>
