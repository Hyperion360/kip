<?php // tests/StrictTypesDeclarationTest.php
namespace Kip\Tests;
use PHPUnit\Framework\TestCase;

/**
 * Every autoloaded framework file declares strict_types except the one file
 * that must not (src/Routing/RouteMatch.php, the app-call boundary) and the
 * admin view templates, which are require'd into View::render()'s scope where
 * declare() cannot apply. A new file without the declaration fails here, not
 * in an app at runtime.
 */
final class StrictTypesDeclarationTest extends TestCase
{
    public function test_every_eligible_src_file_declares_strict_types(): void
    {
        $missing = [];
        $root = dirname(__DIR__) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            $rel = str_replace($root . '/', '', $f->getPathname());
            if (str_starts_with($rel, 'Admin/views/')) continue;   // templates, not autoloaded
            if ($rel === 'Routing/RouteMatch.php') continue;       // the app-call boundary, exempt by design
            // Anchored, not substring: a comment mentioning the declaration must not satisfy the gate.
            if (preg_match('/^declare\(strict_types=1\);/m', (string) file_get_contents($f->getPathname())) !== 1) {
                $missing[] = $rel;
            }
        }
        $this->assertSame([], $missing, 'files missing declare(strict_types=1): ' . implode(', ', $missing));
    }
}
