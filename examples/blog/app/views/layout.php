<?php // app/views/layout.php
$theme = $theme ?? '';
$path = $path ?? '/';
$back = ($query ?? '') !== '' ? $path . '?' . $query : $path;
$isWrite = str_starts_with($path, '/posts/create') || str_starts_with($path, '/posts/edit');
$isPosts = !$isWrite && str_starts_with($path, '/posts');
?>
<!doctype html>
<html lang="en"<?= $theme !== '' ? ' data-theme="' . $this->e($theme) . '"' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'My Blog') ?></title>
  <link rel="icon" href="data:,"><!-- empty data icon: without it browsers request /favicon.ico on every page and log a 404 -->
  <link rel="stylesheet" href="/style.css">
  <style>@view-transition { navigation: auto; }</style>
  <script type="speculationrules">{"prerender": [{"where": {"href_matches": "/*"}, "eagerness": "conservative"}]}</script>
</head>
<body>
  <header class="site-header">
    <nav class="site-nav" aria-label="Site">
      <a class="brand" href="/">My Blog</a>
      <a class="nav-link" href="/posts"<?= $isPosts ? ' aria-current="page"' : '' ?>>Posts</a>
      <a class="nav-link" href="/posts/create"<?= $isWrite ? ' aria-current="page"' : '' ?>>Write</a>
      <?php if ($isAdmin ?? false): ?><a class="nav-link" href="/admin">Admin</a><?php endif; ?>
      <?php if (($loggedIn ?? false) && isset($csrf)): ?>
      <form class="nav-form" method="post" action="/auth/logout">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button class="nav-button">Log out</button>
      </form>
      <?php else: ?>
      <a class="nav-button<?= $path === '/auth/login' ? ' is-active' : '' ?>" href="/auth/login">Log in</a>
      <?php endif; ?>
    </nav>
  </header>
  <main class="column<?= !empty($wide) ? ' column-wide' : '' ?><?= !empty($narrow) ? ' column-narrow' : '' ?>"><?= $content ?></main>
  <footer class="site-footer">
    <div class="footer-inner">
      <span>My Blog · built with Kip</span>
      <?php if (!$isWrite): // submitting the switch reloads the page; never discard an unsaved draft ?>
      <form class="theme-switch" method="post" action="/theme" aria-label="Appearance">
        <input type="hidden" name="back" value="<?= $this->e($back) ?>">
        <button class="theme-option" name="theme" value="auto" aria-pressed="<?= $theme === '' ? 'true' : 'false' ?>">Auto</button><button class="theme-option" name="theme" value="light" aria-pressed="<?= $theme === 'light' ? 'true' : 'false' ?>">Light</button><button class="theme-option" name="theme" value="dark" aria-pressed="<?= $theme === 'dark' ? 'true' : 'false' ?>">Dark</button>
      </form>
      <?php endif; ?>
    </div>
  </footer>
</body>
</html>
