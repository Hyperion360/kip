#!/usr/bin/env php
<?php // scripts/check-docs.php, Kip documentation-consistency checker.
//
// Zero dependencies, PHP 8.3+. Run from the repo root:
//
//     php scripts/check-docs.php
//
// Exit code 0 = every check passed, 1 = at least one check failed.
// One PASS/FAIL line is printed per check, followed by a summary count.
//
// Checks:
//   1. Link integrity. Relative markdown links in the doc set resolve to
//                              existing files (fenced code blocks are ignored).
//   2. Src file count claim, the "<Word> files" claim in docs/guide/README.md
//                              matches the actual recursive *.php count under src/.
//   3. Referenced src paths, every literal `src/.../*.php` mention in the doc
//                              set points to an existing file.
//   4. CLI chapter commands, every `bin/kip <command>` invocation in
//                              docs/guide/08-cli.md uses a command that exists in
//                              skeleton/bin/kip's match/arm array.

declare(strict_types=1);

error_reporting(E_ALL);
mb_internal_encoding('UTF-8');

$repoRoot = dirname(__DIR__);

/* ---------------------------------------------------------------- helpers */

/** @return list<string> absolute paths of every markdown file in the doc set */
function markdownSet(string $repoRoot): array
{
    $files = [];
    foreach (
        ['README.md', 'CHANGELOG.md', 'skeleton/README.md', 'examples/blog/README.md']
        as $rel
    ) {
        if (is_file($repoRoot . '/' . $rel)) {
            $files[] = $repoRoot . '/' . $rel;
        }
    }
    if (is_dir($repoRoot . '/docs')) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot . '/docs', FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (str_starts_with($f->getPathname(), $repoRoot . '/docs/superpowers/')) { continue; } // workspace-private, never part of the repo
            if ($f->isFile() && $f->getExtension() === 'md') {
                $files[] = $f->getPathname();
            }
        }
    }
    $files = array_values(array_unique($files));
    sort($files);
    return $files;
}

/** Path relative to the repo root, for readable diagnostics. */
function rel(string $repoRoot, string $path): string
{
    return str_starts_with($path, $repoRoot . '/') ? substr($path, strlen($repoRoot) + 1) : $path;
}

/**
 * Blank out fenced code blocks (``` or ~~~), line by line, so their contents
 * can be skipped. Fence-marker lines themselves are blanked too.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function stripFencedCode(array $lines): array
{
    $inFence = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(?:```|~~~)/', $line) === 1) {
            $inFence = !$inFence;
            $lines[$i] = '';
            continue;
        }
        if ($inFence) {
            $lines[$i] = '';
        }
    }
    return $lines;
}

/** Recursively count *.php files under a directory. */
function countPhpFiles(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $n++;
        }
    }
    return $n;
}

/* -------------------------------------------------- check 1: link integrity */

/**
 * @param list<string> $mdFiles
 * @return array{0: bool, 1: list<string>, 2: string} ok, failure details, stat line
 */
function checkLinkIntegrity(string $repoRoot, array $mdFiles): array
{
    $details = [];
    $checked = 0;

    foreach ($mdFiles as $file) {
        $lines = stripFencedCode(explode("\n", (string) file_get_contents($file)));
        $dir = dirname($file);
        foreach ($lines as $n => $line) {
            preg_match_all('/\[([^\]]*)\]\(([^)]+)\)/', $line, $m, PREG_SET_ORDER);
            foreach ($m as $link) {
                // Drop an optional quoted title: keep only the first token.
                $target = preg_split('/\s+/', trim($link[2]))[0];
                if ($target === '' || $target === null) {
                    continue;
                }
                $lower = strtolower($target);
                if (
                    str_starts_with($lower, 'http://')
                    || str_starts_with($lower, 'https://')
                    || str_starts_with($lower, 'mailto:')
                    || str_starts_with($target, '#')
                ) {
                    continue; // external link or same-page anchor, not our concern
                }
                $path = explode('#', $target, 2)[0]; // strip #anchor suffix
                if ($path === '') {
                    continue;
                }
                $checked++;
                $resolved = $dir . '/' . $path;
                if (!file_exists($resolved)) {
                    $details[] = sprintf(
                        '%s:%d broken link target "%s"',
                        rel($repoRoot, $file),
                        $n + 1,
                        $target
                    );
                }
            }
        }
    }

    $stat = sprintf('%d relative link(s) in %d file(s)', $checked, count($mdFiles));
    return [ $details === [], $details, $stat ];
}

/* ----------------------------------------------- check 2: src file count claim */

const WORD_NUMBERS = [
    'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
    'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
    'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
    'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
    'nineteen' => 19, 'twenty' => 20, 'twenty-one' => 21, 'twenty-two' => 22,
    'twenty-three' => 23, 'twenty-four' => 24, 'twenty-five' => 25,
    'twenty-six' => 26, 'twenty-seven' => 27, 'twenty-eight' => 28,
    'twenty-nine' => 29, 'thirty' => 30,
];

/**
 * @return array{0: bool, 1: list<string>, 2: string}
 */
function checkSrcFileCount(string $repoRoot): array
{
    $docRel = 'docs/guide/README.md';
    $doc = $repoRoot . '/' . $docRel;
    if (!is_file($doc)) {
        return [false, ["{$docRel} not found, cannot verify the src/ file-count claim"], ''];
    }

    $actual = countPhpFiles($repoRoot . '/src');

    // Collapse whitespace so a "<Word> files" claim wrapped across lines still matches.
    // Digit claims ("31 files") are accepted alongside word numbers so the check
    // stays usable past the word-number table's ceiling.
    $text = preg_replace('/\s+/', ' ', (string) file_get_contents($doc)) ?? '';
    $wordAlt = implode('|', array_keys(WORD_NUMBERS));
    preg_match_all('/\b(' . $wordAlt . '|\d+)\s+files\b/i', $text, $m, PREG_SET_ORDER);

    if ($m === []) {
        return [
            false,
            [sprintf('%s contains no "<N> files" claim to compare against src/ (found %d PHP files)', $docRel, $actual)],
            sprintf('src/ contains %d PHP file(s)', $actual),
        ];
    }

    $details = [];
    foreach ($m as $match) {
        $claimed = ctype_digit($match[1]) ? (int) $match[1] : WORD_NUMBERS[strtolower($match[1])];
        if ($claimed !== $actual) {
            $details[] = sprintf(
                '%s claims "%s files" but src/ contains %d PHP file(s)',
                $docRel,
                $match[1],
                $actual
            );
        }
    }

    return [ $details === [], $details, sprintf('src/ contains %d PHP file(s); claim(s) found: %s', $actual, implode(', ', array_column($m, 1))) ];
}

/* ------------------------------------------ check 3: referenced src/ paths exist */

/**
 * A `src/.../*.php` mention is accepted when the file exists at the repo root
 * (the framework's own src/) or under one of the app trees whose src/ the docs
 * legitimately reference relative to the app root.
 *
 * @param list<string> $mdFiles
 * @return array{0: bool, 1: list<string>, 2: string}
 */
function checkReferencedSrcPaths(string $repoRoot, array $mdFiles): array
{
    $roots = [
        $repoRoot,
        $repoRoot . '/examples/blog/app',
        $repoRoot . '/skeleton/app',
    ];
    $details = [];
    $checked = 0;

    foreach ($mdFiles as $file) {
        $lines = explode("\n", (string) file_get_contents($file));
        foreach ($lines as $n => $line) {
            preg_match_all('~src/[A-Za-z0-9_/]+\.php~', $line, $m);
            if ($m[0] === []) {
                continue;
            }
            foreach (array_unique($m[0]) as $path) {
                $checked++;
                $exists = false;
                foreach ($roots as $root) {
                    if (is_file($root . '/' . $path)) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $details[] = sprintf(
                        '%s:%d mentions "%s" but no such file exists (checked repo root, examples/blog/app/, skeleton/app/)',
                        rel($repoRoot, $file),
                        $n + 1,
                        $path
                    );
                }
            }
        }
    }

    $stat = sprintf('%d src/ path mention(s) in %d file(s)', $checked, count($mdFiles));
    return [ $details === [], $details, $stat ];
}

/* --------------------------------------------- check 4: CLI chapter commands */

/**
 * Command names accepted by skeleton/bin/kip: the literal string keys of its
 * top-level match arms (e.g. 'migrate' => ...). Anchored to the match's own
 * indentation (four spaces) so nested config-array keys inside an arm.
 * like the backup arm's 'data'/'logs'/'cache' DSN labels, don't masquerade
 * as commands.
 *
 * @return list<string>
 */
function kipCommands(string $repoRoot): array
{
    $kip = $repoRoot . '/skeleton/bin/kip';
    if (!is_file($kip)) {
        fwrite(STDERR, "ERROR: skeleton/bin/kip not found, cannot determine the command list.\n");
        exit(1);
    }
    preg_match_all("/^    '([A-Za-z0-9:_-]+)'\s*=>/m", (string) file_get_contents($kip), $m);
    $commands = array_values(array_unique($m[1]));
    if ($commands === []) {
        fwrite(STDERR, "ERROR: could not parse any command from skeleton/bin/kip, checker regex out of sync with the file's formatting.\n");
        exit(1);
    }
    return $commands;
}

/**
 * @param list<string> $commands
 * @return array{0: bool, 1: list<string>, 2: string}
 */
function checkCliChapter(string $repoRoot, array $commands): array
{
    $docRel = 'docs/guide/08-cli.md';
    $doc = $repoRoot . '/' . $docRel;
    if (!is_file($doc)) {
        return [false, ["{$docRel} not found, cannot verify bin/kip command usage"], ''];
    }

    $valid = array_fill_keys($commands, true);
    $details = [];
    $checked = 0;

    // Match on a single line only ("bin/kip" + spaces + the command token) so a
    // bare trailing "bin/kip" at end of line never swallows the next line's text.
    foreach (explode("\n", (string) file_get_contents($doc)) as $n => $line) {
        preg_match_all('~bin/kip[ \t]+([A-Za-z0-9:_-]+)~', $line, $m, PREG_SET_ORDER);
        foreach ($m as $inv) {
            $checked++;
            if (!isset($valid[$inv[1]])) {
                $details[] = sprintf(
                    '%s:%d invokes "bin/kip %s" but skeleton/bin/kip has no such command (known: %s)',
                    $docRel,
                    $n + 1,
                    $inv[1],
                    implode(', ', $commands)
                );
            }
        }
    }

    $stat = sprintf(
        '%d bin/kip invocation(s) against %d known command(s): %s',
        $checked,
        count($commands),
        implode(', ', $commands)
    );
    return [ $details === [], $details, $stat ];
}

/* ------------------------------------------------------------------- main */

$mdFiles = markdownSet($repoRoot);
if ($mdFiles === []) {
    fwrite(STDERR, "ERROR: no markdown files found under " . $repoRoot . "\n");
    exit(1);
}

$checks = [
    'Link integrity'              => checkLinkIntegrity($repoRoot, $mdFiles),
    'Src file count claim'        => checkSrcFileCount($repoRoot),
    'Referenced src/ paths exist' => checkReferencedSrcPaths($repoRoot, $mdFiles),
    'CLI chapter commands'        => checkCliChapter($repoRoot, kipCommands($repoRoot)),
];

$passed = 0;
$failed = 0;
$i = 0;
foreach ($checks as $label => [$ok, $details, $stat]) {
    $i++;
    printf("%s  %d/%d %s%s\n", $ok ? 'PASS' : 'FAIL', $i, count($checks), $label, $stat === '' ? '' : ', ' . $stat);
    foreach ($details as $d) {
        echo '       · ' . $d . "\n";
    }
    $ok ? $passed++ : $failed++;
}

printf("\nSummary: %d passed, %d failed, %d total\n", $passed, $failed, count($checks));
exit($failed > 0 ? 1 : 0);
