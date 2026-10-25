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
}
