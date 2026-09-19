<?php $this->layout('layout'); ?>
<h1>Log in</h1>
<?php if ($error): ?><p role="alert"><?= $this->e($error) ?></p><?php endif; ?>
<form method="post" action="/auth/attempt">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <label>Email <input type="email" name="email" required></label>
  <label>Password <input type="password" name="password" required></label>
  <button>Log in</button>
</form>
