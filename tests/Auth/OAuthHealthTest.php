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
