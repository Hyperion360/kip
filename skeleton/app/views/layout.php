<?php // app/views/layout.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Kip') ?></title>
  <link rel="stylesheet" href="/style.css">
  <style>@media (prefers-reduced-motion: no-preference) { @view-transition { navigation: auto; } }</style>
  <script type="speculationrules">{"prerender": [{"where": {"href_matches": "/*"}, "eagerness": "conservative"}]}</script>
</head>
<body>
  <header>
    <nav>
      <a href="/">Home</a>
      <a href="/auth/login">Log in</a>
    </nav>
  </header>
  <main><?= $content ?></main>
</body>
</html>
