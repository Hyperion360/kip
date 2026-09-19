<?php $this->layout('layout'); ?>
<h1>Reset your password</h1>
<?php if ($sent): ?>
  <p>If that address has an account, a reset link is on its way. Check your email.</p>
<?php else: ?>
  <form method="post" action="/auth/remind">
    <label>Email <input type="email" name="email" required autocomplete="email"></label>
    <button>Send reset link</button>
  </form>
<?php endif; ?>
