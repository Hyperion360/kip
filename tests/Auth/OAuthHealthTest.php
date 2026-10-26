<?php // tests/Auth/OAuthHealthTest.php
namespace Kip\Tests\Auth;

use Kip\Auth\OAuthHealth;
use Kip\Database;
use PHPUnit\Framework\TestCase;

final class OAuthHealthTest extends TestCase
{
    private Database $cache;

    protected function setUp(): void
    {
        $this->cache = new Database('sqlite::memory:');
        OAuthHealth::resetClaims(); // the cache-less single-flight memo is process-wide
    }

    /** @param array<string, mixed> $verdicts provider name => true|false|null */
    private function health(array $verdicts, ?Database $cache = null, bool $probe = true): OAuthHealth
    {
        $providers = [];
        foreach ($verdicts as $name => $v) {
            $providers[$name] = $v === 'unconfigured' ? ['client_id' => null] : ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'https://a.example/c'];
        }
        return new OAuthHealth($cache ?? $this->cache, $providers, $probe,
            fn(string $name): ?bool => $verdicts[$name] === 'unconfigured' ? null : $verdicts[$name]);
    }

    public function test_ready_lists_only_probed_healthy_providers(): void
    {
        $this->assertSame(['ok'], $this->health(['ok' => true, 'bad' => false, 'wild' => null, 'gone' => 'unconfigured'])->ready());
    }

    public function test_probe_disabled_means_config_complete_only(): void
    {
        $this->assertSame(['ok', 'bad', 'wild'], $this->health(['ok' => true, 'bad' => false, 'wild' => null], probe: false)->ready());
    }

    public function test_verdicts_cache_across_instances_and_expire(): void
    {
        $health = $this->health(['ok' => true, 'bad' => false]);
        $health->ready(); // writes both verdicts
        // A new instance with NO prober (would throw if called) reads the cache.
        $readonly = new OAuthHealth($this->cache, ['ok' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x'], 'bad' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => throw new \RuntimeException('must not probe'));
        $this->assertSame(['ok'], $readonly->ready());
        // Age the rows past their TTLs: the cache goes silent, probing resumes.
        $this->cache->query('UPDATE oauth_health SET checked_at = checked_at - 4000 WHERE healthy = 1');
        $this->cache->query('UPDATE oauth_health SET checked_at = checked_at - 400 WHERE healthy = 0');
        $expired = $this->health(['ok' => false]);
        $this->assertSame([], $expired->ready());
    }

    public function test_indeterminate_keeps_the_previous_verdict_and_fails_closed_without_one(): void
    {
        $this->health(['ok' => true])->ready(); // seed a healthy verdict
        $flaky = new OAuthHealth($this->cache, ['ok' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => null);
        $this->assertTrue($flaky->healthy('ok', ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']), 'one timeout must not blink a working provider off');

        $never = new OAuthHealth(new Database('sqlite::memory:'), ['ok' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => null);
        $this->assertFalse($never->healthy('ok', ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']), 'never probed + indeterminate: hidden');
    }

    public function test_no_cache_db_still_gates_per_request(): void
    {
        $health = new OAuthHealth(null, ['ok' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => true);
        $this->assertSame(['ok'], $health->ready());
    }

    public function test_an_indeterminate_keep_prior_re_stamps_the_row_so_the_window_slides(): void
    {
        $this->health(['ok' => true])->ready(); // seed a healthy verdict
        $this->cache->query('UPDATE oauth_health SET checked_at = checked_at - 4000'); // expire it
        $flaky = new OAuthHealth($this->cache, ['ok' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => null);
        $this->assertTrue($flaky->healthy('ok', ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']), 'the prior verdict is kept');
        $stamp = (int) $this->cache->one("SELECT checked_at c FROM oauth_health WHERE provider = 'ok'")['c'];
        $this->assertGreaterThanOrEqual(time() - 5, $stamp, 'the kept verdict was re-stamped: the window slides');
        // The fresh stamp suppresses the next probe: an instance whose prober
        // fails loudly must answer from the cache alone.
        $readonly = new OAuthHealth($this->cache, ['ok' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => throw new \RuntimeException('must not probe'));
        $this->assertTrue($readonly->healthy('ok', ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']));
    }

    public function test_a_cache_db_that_fails_after_connect_degrades_to_memory_and_never_throws(): void
    {
        // A cache that connects but cannot take the table: the file is a valid
        // database made read-only, so the lazy CREATE TABLE raises and the gate
        // must fall back to per-request memory instead of failing the page.
        // (A file that is not a database at all fails inside Database's own
        // constructor, which the controller wraps; this pins the class's half.)
        $file = (string) tempnam(sys_get_temp_dir(), 'kip-oauth-ro-');
        $cache = new Database('sqlite:' . $file);
        $cache->query('CREATE TABLE unrelated (x INTEGER)'); // a real database
        $cache->query('PRAGMA query_only = 1');            // every later write raises
        try {
            $entry = ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x'];
            $health = new OAuthHealth($cache, ['ok' => $entry], true, fn() => true);
            $this->assertTrue($health->healthy('ok', $entry), 'the prober still decides; the unwritable cache is bypassed');
            $this->assertSame(['ok'], $health->ready());
            $this->assertNull($cache->one("SELECT name FROM sqlite_master WHERE name = 'oauth_health'"), 'the table was never created, the write path degraded');
        } finally {
            foreach ([$file, $file . '-wal', $file . '-shm'] as $f) { if (is_file($f)) @unlink($f); }
        }
    }

    public function test_a_configured_but_unreadable_apple_key_gates_off_without_throwing(): void
    {
        // configured() counts an apple_key_file path without reading it; if the
        // file vanished in production, the DEFAULT prober's constructor throws
        // inside probeProvider() and only its catch stands between that and a
        // 500 login page. No prober closure here: the real path runs.
        $entry = ['client_id' => 'org.example.signin', 'apple_team_id' => 'T', 'apple_key_id' => 'K',
            'apple_key_file' => sys_get_temp_dir() . '/kip-no-such-key-' . bin2hex(random_bytes(4)) . '.p8',
            'redirect_uri' => 'https://app.example.org/oauth/callback/apple'];
        $health = new OAuthHealth($this->cache, ['apple' => $entry]);
        $this->assertFalse($health->healthy('apple', $entry), 'a broken entry is hidden, never a thrown page');
        $this->assertSame([], $health->ready());
    }

    public function test_concurrent_cache_misses_coalesce_onto_one_probe(): void
    {
        // Two renders hit the same cold provider. The first claims the row
        // before probing; the second, arriving while that probe is in flight
        // (or simply after the claim), reads a fresh verdict and never probes.
        // Across all workers: one probe per provider per window, whatever the
        // request rate.
        $entry = ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x'];
        $probes = 0;
        $first = new OAuthHealth($this->cache, ['ok' => $entry], true, function () use (&$probes): ?bool { $probes++; return true; });
        $second = new OAuthHealth($this->cache, ['ok' => $entry], true, function () use (&$probes): ?bool { $probes++; return true; });
        $this->assertTrue($first->healthy('ok', $entry));
        $this->assertTrue($second->healthy('ok', $entry), 'the second render reads the first one\'s verdict');
        $this->assertSame(1, $probes, 'one probe for two renders');

        // And during an outage (indeterminate, no prior): the claim itself is the
        // failed verdict, so a flood of renders costs one probe, not N timeouts.
        $this->cache->query('DELETE FROM oauth_health');
        $probes = 0;
        $flood = new OAuthHealth($this->cache, ['down' => $entry], true, function () use (&$probes): ?bool { $probes++; return null; });
        $again = new OAuthHealth($this->cache, ['down' => $entry], true, function () use (&$probes): ?bool { $probes++; return null; });
        $this->assertFalse($flood->healthy('down', $entry));
        $this->assertFalse($again->healthy('down', $entry));
        $this->assertSame(1, $probes, 'an outage probes once per window, the rest read the claim');
    }

    public function test_cache_less_renders_are_bounded_by_the_failed_ttl(): void
    {
        // No cache DB: the process-local claim still prevents a per-render
        // probe storm; the first render probes, the next within TTL_FAILED
        // answers hidden without network.
        $entry = ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x'];
        $probes = 0;
        $prober = function () use (&$probes): ?bool { $probes++; return null; };
        $this->assertFalse((new OAuthHealth(null, ['down' => $entry], true, $prober))->healthy('down', $entry));
        $this->assertFalse((new OAuthHealth(null, ['down' => $entry], true, $prober))->healthy('down', $entry));
        $this->assertSame(1, $probes, 'cache-less: one probe per process per failed TTL');
    }

    public function test_a_failed_verdict_expires_after_its_ttl(): void
    {
        $this->health(['bad' => false])->ready(); // seed a failed verdict
        $stillFresh = new OAuthHealth($this->cache, ['bad' => ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x']], true, fn() => throw new \RuntimeException('a fresh failed verdict must gate without probing'));
        $entry = ['client_id' => 'c', 'client_secret' => 's', 'redirect_uri' => 'x'];
        $this->assertFalse($stillFresh->healthy('bad', $entry), 'within the failed TTL the row gates, no probe');
        $this->cache->query('UPDATE oauth_health SET checked_at = checked_at - ' . (string) (OAuthHealth::TTL_FAILED + 1));
        $recovered = new OAuthHealth($this->cache, ['bad' => $entry], true, fn() => true);
        $this->assertTrue($recovered->healthy('bad', $entry), 'past TTL_FAILED the failed verdict expires and a healthy probe un-hides the provider');
    }
}
