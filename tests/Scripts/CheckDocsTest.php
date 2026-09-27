<?php // tests/Scripts/CheckDocsTest.php
namespace Kip\Tests\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * scripts/check-docs.php check 2 (src file-count claims) failure paths. The
 * plan verified these by hand (edit a doc, re-run, restore), so no committed
 * test pins them: a regex or ledger regression would surface only the next
 * time real docs drift. Each test builds a throwaway fixture repo (the real
 * script and skeleton/bin/kip copied verbatim, src/ with a known file count,
 * docs/ with claims) and runs the script as a subprocess, the same way
 * composer check-docs does.
 */
final class CheckDocsTest extends TestCase
{
    /** @var list<string> fixture roots awaiting cleanup */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        set_error_handler(static fn(): bool => true);
        try {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    if (is_dir($f) && !is_link($f)) $rm($f); else unlink($f);
                }
                rmdir($dir);
            };
            foreach ($this->fixtures as $dir) {
                if (is_dir($dir)) $rm($dir);
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array<string, string> $docs rel path => content, written into the fixture
     * @return array{0: string, 1: int} [combined output, exit code]
     */
    private function runCheckDocs(int $srcFiles, array $docs): array
    {
        $repo = dirname(__DIR__, 2);
        $fixture = sys_get_temp_dir() . '/kip-checkdocs-' . bin2hex(random_bytes(4));
        $this->fixtures[] = $fixture;
        foreach (['scripts', 'src', 'docs/guide', 'skeleton/bin'] as $sub) {
            mkdir($fixture . '/' . $sub, 0777, true);
        }
        copy($repo . '/scripts/check-docs.php', $fixture . '/scripts/check-docs.php');
        copy($repo . '/skeleton/bin/kip', $fixture . '/skeleton/bin/kip'); // kipCommands() exits without it
        for ($i = 0; $i < $srcFiles; $i++) {
            file_put_contents($fixture . '/src/mod' . $i . '.php', "<?php // fixture\n");
        }
        foreach ($docs as $rel => $content) {
            file_put_contents($fixture . '/' . $rel, $content);
        }
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture . '/scripts/check-docs.php') . ' 2>&1', $lines, $code);
        return [implode("\n", $lines), $code];
    }

    /** The check 2 result line, label plus stat, from a full script run. */
    private function countClaimLine(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'Src file count claim')) return $line;
        }
        $this->fail('no Src file count claim line in output: ' . $output);
    }

    public function test_a_wrong_digit_claim_fails_and_a_missing_ledger_target_reads_as_stale(): void
    {
        // The versioning page's count claim was wrong at 19 files once and nothing
        // caught it; a mismatched digit must FAIL with file, claim, and actual.
        // And a fixture without the ledger's excused sentence (11-testing.md "4
        // files") must fail the ledger entry as stale, not silently keep excusing it.
        [$out, $code] = $this->runCheckDocs(2, [
            'docs/guide/README.md' => "Kip is two files.\n",
            'docs/guide/x.md' => "Kip ships 3 files.\n",
            'docs/guide/08-cli.md' => "# CLI\nNo invocations.\n",
        ]);
        $this->assertSame(1, $code, $out);
        $this->assertStringStartsWith('FAIL', $this->countClaimLine($out));
        $this->assertStringContainsString('docs/guide/x.md claims "3 files" but src/ contains 2 PHP file(s)', $out);
        $this->assertStringContainsString(
            'exception for docs/guide/11-testing.md number 4 no longer matches any claim',
            $out
        );
    }

    public function test_the_ledger_shields_a_non_claim_and_the_hyphen_form_is_matched(): void
    {
        // "4-file" (the hyphen singular, as in the versioning page's "35-file
        // core") is a claim shape the digit pass must match, and the ledger must
        // excuse it without failing the run; everything else in the fixture is
        // consistent, so the script exits zero.
        [$out, $code] = $this->runCheckDocs(2, [
            'docs/guide/README.md' => "Kip is two files.\n",
            'docs/guide/11-testing.md' => "Consider a 4-file example.\n",
            'docs/guide/08-cli.md' => "# CLI\nNo invocations.\n",
        ]);
        $this->assertSame(0, $code, $out);
        $line = $this->countClaimLine($out);
        $this->assertStringStartsWith('PASS', $line);
        $this->assertStringContainsString('exception(s) applied: 1', $line);
    }
}
