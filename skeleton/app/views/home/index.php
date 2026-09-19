<?php // app/views/home/index.php ?>
<?php $this->layout('layout'); ?>
<h1>Welcome to Kip</h1>
<p>
  This is the Kip skeleton, a minimal starting point with auth already
  wired up (login, logout, throttling, CSRF) and nothing else opinionated.
</p>
<p>
  Follow <code>docs/tutorial.md</code> to build your first feature on top of
  this skeleton, or look at <code>examples/blog</code> for a complete
  end-to-end app built the same way.
</p>
<?php if ($loggedIn): ?>
<form method="post" action="/auth/logout">
  <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
  <button>Log out</button>
</form>
<?php endif; ?>
