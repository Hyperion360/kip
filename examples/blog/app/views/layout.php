<?php // app/views/layout.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Blog') ?></title>
  <link rel="icon" href="data:,"><!-- empty data icon: without it browsers request /favicon.ico on every page and log a 404 -->
  <link rel="stylesheet" href="/style.css">
  <style>@view-transition { navigation: auto; }</style>
  <script type="speculationrules">{"prerender": [{"where": {"href_matches": "/*"}, "eagerness": "conservative"}]}</script>
</head>
<body>
  <header>
    <nav>
      <a href="/">My Blog</a>
      <a href="/posts">Posts</a>
      <a href="/posts/create">Write</a>
      <?php if ($isAdmin ?? false): ?><a href="/admin">Admin</a><?php endif; ?>
      <?php if (($loggedIn ?? false) && isset($csrf)): ?>
      <form method="post" action="/auth/logout">
        <input type="hidden" name="_token" value="<?= $this->e($csrf) ?>">
        <button>Log out</button>
      </form>
      <?php else: ?>
      <a href="/auth/login">Log in</a>
      <?php endif; ?>
    </nav>
  </header>
  <main><?= $content ?></main>
</body>
</html>
