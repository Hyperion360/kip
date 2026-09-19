<?php // app/views/layout.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Blog') ?></title>
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
    </nav>
  </header>
  <main><?= $content ?></main>
</body>
</html>
