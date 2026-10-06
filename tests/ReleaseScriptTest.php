<?php

declare(strict_types=1);

namespace Kip\Tests;

use PHPUnit\Framework\TestCase;

/**
 * bin/release: combines changelog.d fragments into a dated CHANGELOG section
 * and tags the release. The script computes its repo root from its own
 * location, so every test copies it into a fresh throwaway git repo. The
 * git-failure tests pin the ordering: each git step is checked before the
 * next runs, so a failed add can never cascade into an empty commit and an
 * orphan tag.
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

    /** Commits a CHANGELOG whose [Unreleased] carries real entries (the pre-fragment seed shape). */
    private function seedChangelog(string $unreleasedBody): void
    {
        file_put_contents(
            $this->fixture . '/CHANGELOG.md',
            "# Changelog\n\n## [Unreleased]\n" . $unreleasedBody . "\n## [0.4.0]\nlegacy prose entry\n"
        );
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} add CHANGELOG.md");
        exec("git -C {$fx} commit -qm changelog");
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

    public function testTheAnnotatedTagCarriesTheCompiledNotes(): void
    {
        $this->frag('a-x.md', 'Added: a distinctive fragment line');

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(0, $code, $out);
        exec('git -C ' . escapeshellarg($this->fixture) . ' cat-file tag v9.9.9', $tagLines);
        $tag = implode("\n", $tagLines);
        self::assertStringContainsString('Release v9.9.9', $tag);
        // The type headings survive git's message cleanup (commentChar moved off '#').
        self::assertStringContainsString('### Added', $tag);
        self::assertStringContainsString('- a distinctive fragment line', $tag);
    }

    public function testASeededUnreleasedBodyIsMergedNotStranded(): void
    {
        $this->seedChangelog("\n### Fixed\n\n- seeded fix from the seed\n\n### Added\n\n- seeded addition\n");
        $this->frag('a-frag.md', 'Added: fragment entry');

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(0, $code, $out);
        $log = $this->changelog();
        // Seed entries fold under their type headings beside the fragment entries,
        // never stranded as a second set of ### blocks beneath the new section.
        self::assertSame(1, substr_count($log, '### Added'));
        self::assertSame(1, substr_count($log, '### Fixed'));
        self::assertStringContainsString("### Added\n- fragment entry\n- seeded addition\n", $log);
        self::assertStringContainsString("### Fixed\n- seeded fix from the seed\n", $log);
        self::assertSame(1, substr_count($log, 'seeded fix from the seed'));
        self::assertStringContainsString("## [Unreleased]\n\n## [9.9.9] - ", $log);
        self::assertStringContainsString("## [0.4.0]\nlegacy prose entry", $log);
    }

    public function testTheSectionInsertsAtTheFirstUnreleasedOccurrenceOnly(): void
    {
        // Prose quoting the heading later in the file must not receive a second copy.
        file_put_contents(
            $this->fixture . '/CHANGELOG.md',
            "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- seeded add\n\n## [0.4.0]\nOlder notes mention the ## [Unreleased] heading in prose.\n"
        );
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} add CHANGELOG.md");
        exec("git -C {$fx} commit -qm changelog");
        $this->frag('a-new.md', 'Added: new entry');

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(0, $code, $out);
        $log = $this->changelog();
        self::assertSame(1, substr_count($log, '## [9.9.9] - '));
        self::assertSame(2, substr_count($log, '## [Unreleased]'));
        self::assertStringContainsString('Older notes mention the ## [Unreleased] heading in prose.', $log);
        self::assertSame(1, substr_count($log, '### Added'));
        self::assertStringContainsString("- new entry\n- seeded add\n", $log);
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

    public function testASeededEntryUnderAnUnknownHeadingIsRefused(): void
    {
        $this->seedChangelog("\n### Notes\n\n- freeform prose line\n");
        $this->frag('a-x.md', 'Added: x');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('seeded Unreleased entry under ### Notes must start with one of', $out);
    }

    public function testAGitAddFailureAbortsBeforeCommitAndTag(): void
    {
        $this->frag('a-x.md', 'Added: x');
        // A stale index lock makes git add fail deterministically.
        file_put_contents($this->fixture . '/.git/index.lock', '');

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(1, $code);
        self::assertStringContainsString('git add failed', $out);
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} log -1 --format=%s", $subject);
        self::assertSame('fragment', $subject[0] ?? '');
        exec("git -C {$fx} tag -l v9.9.9", $tags);
        self::assertSame([], $tags);
    }

    public function testACommitFailureAbortsBeforeTheTag(): void
    {
        $this->frag('a-x.md', 'Added: x');
        file_put_contents($this->fixture . '/.git/hooks/pre-commit', "#!/bin/sh\nexit 1\n");
        chmod($this->fixture . '/.git/hooks/pre-commit', 0755);

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(1, $code);
        self::assertStringContainsString('git commit failed', $out);
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} log -1 --format=%s", $subject);
        self::assertSame('fragment', $subject[0] ?? '');
        exec("git -C {$fx} tag -l v9.9.9", $tags);
        self::assertSame([], $tags);
    }

    public function testATagFailureExitsOneAndLeavesTheCommitWithoutATag(): void
    {
        $this->frag('a-x.md', 'Added: x');
        // A reference-transaction hook refusing refs/tags/* makes only the tag
        // step fail (branch updates pass), deterministically and without gpg.
        file_put_contents(
            $this->fixture . '/.git/hooks/reference-transaction',
            "#!/bin/sh\nif grep -q 'refs/tags/'; then exit 1; fi\nexit 0\n"
        );
        chmod($this->fixture . '/.git/hooks/reference-transaction', 0755);

        [$code, $out] = $this->release('9.9.9');

        self::assertSame(1, $code);
        self::assertStringContainsString('git tag failed', $out);
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} tag -l v9.9.9", $tags);
        self::assertSame([], $tags, 'no orphan tag');
        exec("git -C {$fx} log -1 --format=%s", $subject);
        self::assertSame('chore: release v9.9.9', $subject[0] ?? '');
        // The release commit carries the changelog and the fragment deletions,
        // so the tree ends clean: recovery is a manual `git tag`, not a rebase.
        exec("git -C {$fx} status --porcelain", $dirty);
        self::assertSame([], $dirty);
    }

    public function testAChangelogWithNoPlaceableHeadingIsRefused(): void
    {
        // Neither a [Unreleased] heading nor a "# Changelog" header: the
        // section cannot be placed, and the script must say so instead of
        // guessing where the new section belongs.
        file_put_contents($this->fixture . '/CHANGELOG.md', "# Project History\n\n## 0.1.0\nfirst\n");
        $fx = escapeshellarg($this->fixture);
        exec("git -C {$fx} add CHANGELOG.md");
        exec("git -C {$fx} commit -qm changelog");
        $this->frag('a-x.md', 'Added: x');

        [$code, $out] = $this->release('9.9.9 --dry-run');

        self::assertSame(1, $code);
        self::assertStringContainsString('could not place the section under Unreleased', $out);
    }

    public function testAGitStatusFailureIsReported(): void
    {
        // The script computes its root from its own location, so a copy in a
        // directory outside any git repository makes the status step itself
        // fail; the run must stop there with the named reason.
        $bare = sys_get_temp_dir() . '/kip-release-bare-' . uniqid();
        mkdir($bare . '/bin', 0777, true);
        copy(__DIR__ . '/../bin/release', $bare . '/bin/release');
        try {
            exec(PHP_BINARY . ' ' . escapeshellarg($bare . '/bin/release') . ' 9.9.9 2>&1', $lines, $code);
            self::assertSame(1, $code);
            self::assertStringContainsString('git status failed', implode("\n", $lines));
        } finally {
            exec('rm -rf ' . escapeshellarg($bare));
        }
    }

    public function testTheVersionArgumentMustBeASemverTriple(): void
    {
        [$code] = $this->release('not-semver');
        self::assertSame(1, $code);
        [$code] = $this->release('1.2');
        self::assertSame(1, $code);
    }
}
