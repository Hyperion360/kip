<?php // src/DevErrorPage.php

declare(strict_types=1);
namespace Kip;

final class DevErrorPage
{
    /**
     * The one escaping policy for every dynamic value on the page: quotes for
     * attribute contexts, and ENT_SUBSTITUTE with an explicit charset so invalid
     * UTF-8 (exception text can carry it) becomes U+FFFD instead of an empty string.
     */
    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The dev-mode 500 page: a styled, self-contained HTML document carrying the
     * exception, where it was thrown, the request that triggered it, and the stack
     * trace. One inline stylesheet, no external requests, no scripting, so it renders
     * identically offline; every dynamic value is escaped because exception text can
     * carry user-influenced input even in dev. Production never calls this.
     */
    public function render(\Throwable $e, string $method, string $path): string
    {
        $class   = self::esc($e::class);
        $message = self::esc($e->getMessage());
        $where   = self::esc($e->getFile() . ':' . $e->getLine());
        $trace   = self::esc($e->getTraceAsString());
        $request = self::esc($method . ' ' . $path);
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>500 · {$class}</title>
<style>
  body { margin: 0; font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; color: #1a1a1a; background: #faf9f7; }
  main { max-width: 50rem; margin: 0 auto; padding: 3rem 1.25rem 4rem; }
  .status { margin: 0; font-size: .8125rem; font-weight: 600; letter-spacing: .08em; color: #b3261e; }
  h1 { font-size: 1.375rem; margin: .35rem 0 1.25rem; text-wrap: balance; }
  h1 code { font-size: 1em; }
  .message { margin: 0; font-size: 1.0625rem; background: #fff; border: 1px solid #e5e1da; border-left: 3px solid #b3261e; padding: .8rem 1rem; overflow-wrap: anywhere; }
  .meta { margin-top: 1.5rem; font-size: .875rem; color: #55524c; }
  .meta div { margin-top: .35rem; }
  code { font: .9375em ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
  pre { margin-top: 1.5rem; padding: 1rem; background: #21201c; color: #ece7de; border-radius: 4px; overflow-x: auto; font: .8125rem/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; white-space: pre-wrap; overflow-wrap: anywhere; }
  footer { margin-top: 2rem; font-size: .8125rem; color: #6f6a5f; }
</style>
</head>
<body>
<main>
  <p class="status">500 · SERVER ERROR</p>
  <h1><code>{$class}</code></h1>
  <p class="message">{$message}</p>
  <div class="meta">
    <div>thrown at <code>{$where}</code></div>
    <div>request <code>{$request}</code></div>
  </div>
  <pre>{$trace}</pre>
  <footer>Kip dev error: shown because this app runs with env dev. Production answers with the generic message and logs the full detail.</footer>
</main>
</body>
</html>
HTML;
    }
}
