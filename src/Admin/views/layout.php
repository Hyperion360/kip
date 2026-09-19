<?php // src/Admin/views/layout.php ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $this->e($title ?? 'Kip Admin') ?></title>
  <style>
    /* Classless-first: semantic HTML looks right with zero classes to learn. */
    html { color-scheme: light; }
    body { font: 16px/1.6 system-ui, sans-serif; margin: 0; color: #1d2429; background: #f6f7f5; }
    header { background: #1f4f42; color: #fff; padding: .6rem 1rem; display: flex; gap: 1rem; align-items: baseline; }
    header a { color: #cfe8de; text-decoration: none; font-weight: 600; }
    header a + a { font-weight: 400; }
    main { max-width: 72rem; margin: 1.25rem auto; padding: 0 1rem; overflow-x: auto; }
    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid #e2e6e1; max-width: 26rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    th { font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: #5c665f; }
    a { color: #1f4f42; }
    form.inline { display: inline; }
    button { font: inherit; padding: .3rem .7rem; border: 1px solid #1f4f42; border-radius: 4px; background: #1f4f42; color: #fff; cursor: pointer; }
    button:hover { background: #2a6a58; border-color: #2a6a58; }
    button:active { background: #174036; border-color: #174036; }
    button.danger { background: #fff; color: #9c2f1d; border-color: #9c2f1d; }
    button.danger:hover { background: #9c2f1d; color: #fff; }
    label { display: block; margin: .8rem 0 .2rem; font-weight: 600; }
    input[type=text], input[type=password], input[type=number], textarea { width: 100%; max-width: 34rem; padding: .4rem; border: 1px solid #6f7a72; border-radius: 4px; font: inherit; }
    textarea { min-height: 8rem; }
    :focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
    nav.pager { margin: 1rem 0; display: flex; gap: 1rem; }
  </style>
</head>
<body>
  <header><a href="/admin">Kip Admin</a><a href="/">← back to site</a></header>
  <main><?= $content ?></main>
</body>
</html>
