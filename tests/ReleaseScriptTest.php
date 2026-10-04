<?php

declare(strict_types=1);

namespace Kip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/release: combines changelog.d fragments into a dated CHANGELOG section
 * and tags the release. The script computes its repo root from its own
 * location, so every test copies it into a fresh throwaway git repo.
 */
final class ReleaseScriptTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/kip-release-test-' . uniqid();
        mkdir($this->fixture . '/bin', 0777, true);
        mkdir($this->fixture . '/changelog.d', 0777, true);
        copy(__DIR__ . '/../bin/release', $this->fixture . '/bin/release');
        file_put_contents($this->fixture . '/changelog.d/.gitkeep', '');
        file_put_contents($this->fixture . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n");
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} init -q");
        exec("git -C {$fx} config user.email test@example.com");
        exec("git -C {$fx} config user.name Release Test");
        exec("git -C {$fx} add -A");
        exec("git -C {$fx} commit -qm init");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->fixture));
    }

    /** @return array{int,string} exit code and combined output */
    private function release(string $args): array
    {
        exec(PHP_BINARY . ' ' . escapeshellarg($this->fixture . '/bin/release') . ' ' . $args . ' 2>&1', $lines, $code);
        return [$code, implode("\n", $lines)];
    }

    private function frag(string $name, string $body): void
    {
        file_put_contents($this->fixture . '/changelog.d/' . $name, $body);
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} add " . escapeshellarg('changelog.d/' . $name));
        exec("git -C {$fx} commit -qm fragment");
    }

    private function changelog(): string
    {
        return (string) file_get_contents($this->fixture . '/CHANGELOG.md');
    }

    public function testDryRunAssemblesKeepAChangelogSectionInTypeOrder(): void
    {
        $this->frag('z-feed.md', 'Fixed: feeds validate their language code');
        $this->frag('a-upload.md', 'Added: validated uploads');
        $this->frag('m-webhook.md', 'Security: webhook signatures are checked before parsing');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('=== v9.9.9 ===', $out);
        self::assertStringContainsString('### Security', $out);
        self::assertStringContainsString('### Added', $out);
        self::assertStringContainsString('### Fixed', $out);
        self::assertLessThan(strpos($out, '### Added'), strpos($out, '### Security'));
        self::assertLessThan(strpos($out, '### Fixed'), strpos($out, '### Added'));
        self::assertStringContainsString('- webhook signatures are checked before parsing', $out);
        self::assertStringContainsString('(fragments consumed: 3)', $out);
        self::assertStringContainsString('dry run: nothing written', $out);
        // A dry run writes nothing and deletes nothing.
        self::assertSame("# Changelog\n\n## [Unreleased]\n", $this->changelog());
        self::assertFileExists($this->fixture . '/changelog.d/a-upload.md');
    }

    public function testDryRunCreatesTheUnreleasedHeaderWhenTheFileHasNone(): void
    {
        file_put_contents($this->fixture . '/CHANGELOG.md', "# Changelog\n\n## 0.4.0\nlegacy prose entry\n");
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} add CHANGELOG.md");
        exec("git -C {$fx} commit -qm changelog");
        $this->frag('a-thing.md', 'Added: a thing');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('dry run: nothing written', $out);
        self::assertSame("# Changelog\n\n## 0.4.0\nlegacy prose entry\n", $this->changelog());
    }

    public function testRealRunStampsSectionCommitsTagsAndConsumesFragments(): void
    {
        $this->frag('a-thing.md', 'Added: a thing');
        $this->frag('f-thing.md', 'Fixed: another thing');

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('tagged v9.9.9', $out);
        $log = $this->changelog();
        self::assertStringContainsString("## [Unreleased]\n\n## [9.9.9] - ", $log);
        self::assertMatchesRegularExpression('/## \[9\.9\.9\] - \d{4}-\d{2}-\d{2}/', $log);
        self::assertStringContainsString('- a thing', $log);
        self::assertStringContainsString('- another thing', $log);
        // Fragments are consumed; the keepalive stays; the tree ends clean.
        self::assertFileDoesNotExist($this->fixture . '/changelog.d/a-thing.md');
        self::assertFileExists($this->fixture . '/changelog.d/.gitkeep');
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} status --porcelain", $dirty);
        self::assertSame([], $dirty);
        // The tag is annotated and sits on the release commit.
        exec("git -C {$fx} cat-file -t v9.9.9", $kind);
        self::assertSame('tag', $kind[0] ?? '');
        exec("git -C {$fx} log -1 --format=%s", $subject);
        self::assertSame('chore: release v9.9.9', $subject[0] ?? '');
    }

    public function testEmptyFragmentsAreSkippedNotFatal(): void
    {
        $this->frag('empty.md', "  \n");
        $this->frag('a-real.md', 'Added: the real one');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(0, $code, $out);
        self::assertStringContainsString('(fragments consumed: 1)', $out);
        self::assertStringContainsString('- the real one', $out);
    }

    public function testADirtyTreeIsRefused(): void
    {
        $this->frag('a-x.md', 'Added: x');
        file_put_contents($this->fixture . '/tracked.txt', 'uncommitted');
        exec('git -C ' . escapeshellarg($this->fixture) . ' add tracked.txt');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('working tree is dirty', $out);
    }

    public function testAnExistingTagIsRefused(): void
    {
        $this->frag('a-x.md', 'Added: x');
        exec('git -C ' . escapeshellarg($this->fixture) . ' tag -a v9.9.9 -m preexisting');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('tag v9.9.9 already exists', $out);
    }

    public function testNoFragmentsIsRefused(): void
    {
        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('no changelog.d/*.md fragments', $out);
    }

    public function testAFragmentWithoutAKnownTypePrefixIsRefused(): void
    {
        $this->frag('bad.md', 'Broke: not a Keep a Changelog type');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('fragment bad.md must start with one of', $out);
    }

    public function testAFragmentWithASecondLineIsRefused(): void
    {
        $this->frag('multiline.md', "Added: the real line\n## [0.0.9] - 2026-10-04\nforged section");

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('fragment multiline.md must be exactly one line', $out);
    }

    public function testTheVersionArgumentMustBeASemverTriple(): void
    {
        [$code] = $this->release('not-semver');
        self::assertSame(1, $code);
        [$code] = $this->release('1.2');
        self::assertSame(1, $code);
    }
}
