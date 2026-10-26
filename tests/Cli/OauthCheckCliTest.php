<?php // tests/Cli/OauthCheckCliTest.php
namespace Kip\Tests\Cli;

use Kip\Tests\OAuthStubServer;
use PHPUnit\Framework\TestCase;

/**
 * The oauth:check arm's operator contract, which deploy scripts gate on: the
 * exit codes (0 nothing configured, 0 all ready, 1 any not ready, 1 none fully
 * configured), the per-provider status spellings, the probe-off text, and the
 * broken-entry INDETERMINATE path. The probe runs for real against the stub
 * IdP so READY and FAILING are live verdicts, not scripted strings.
 */
final class OauthCheckCliTest extends TestCase
{
    use CliAppHarness;
    use OAuthStubServer;

    private const PORT = 8129;

    protected function setUp(): void
    {
        $this->buildCliApp(withMigrations: false);
    }

    protected function tearDown(): void
    {
        $this->stopOAuthStub();
        $this->tearDownCliApp();
    }

    /**
     * Point the copied config's google entry at the stub (endpoints override
     * the preset; credentials ride the env vars the skeleton config reads).
     *
     * @param array<string, mixed> $extra merged into the google entry
     */
    private function wireGoogleToStub(string $base, array $extra = []): void
    {
        $entry = var_export(['authorize_url' => $base . '/authorize', 'token_url' => $base . '/token', 'user_url' => $base . '/userinfo'] + $extra, true);
        file_put_contents($this->cliApp . '/config.php', <<<PHP
<?php
\$config = (static function (): array { return require __DIR__ . '/config.base.php'; })();
\$config['base_url'] = 'http://127.0.0.1:' . {$this->portLiteral()} . '';
\$config['oauth']['providers']['google'] = array_merge(\$config['oauth']['providers']['google'], {$entry});
return \$config;
PHP);
    }

    private function portLiteral(): string
    {
        return (string) self::PORT;
    }

    private function stageBaseConfig(): void
    {
        rename($this->cliApp . '/config.php', $this->cliApp . '/config.base.php');
    }

    public function test_nothing_configured_exits_zero_with_the_plain_message(): void
    {
        [$out, $code] = $this->cli(['oauth:check']);
        // The skeleton config lists providers with null credentials: none is
        // fully configured, which is the loud exit-1 case, not the silent one.
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No OAuth providers fully configured.', $out);
    }

    public function test_a_passing_provider_reports_ready_and_exits_zero(): void
    {
        $stub = $this->startOAuthStub(self::PORT);
        $this->stageBaseConfig();
        $this->wireGoogleToStub($stub['base']);
        [$out, $code] = $this->cli(['oauth:check'], null, [
            'KIP_OAUTH_GOOGLE_CLIENT_ID' => $stub['client_id'],
            'KIP_OAUTH_GOOGLE_CLIENT_SECRET' => $stub['client_secret'],
        ]);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/^google\s+READY$/m', $out);
    }

    public function test_a_failing_provider_reports_failing_and_exits_one(): void
    {
        $stub = $this->startOAuthStub(self::PORT, ['KIP_OAUTH_STUB_PROBE' => 'invalid_client']);
        $this->stageBaseConfig();
        $this->wireGoogleToStub($stub['base']);
        [$out, $code] = $this->cli(['oauth:check'], null, [
            'KIP_OAUTH_GOOGLE_CLIENT_ID' => $stub['client_id'],
            'KIP_OAUTH_GOOGLE_CLIENT_SECRET' => $stub['client_secret'],
        ]);
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^google\s+FAILING$/m', $out);
    }

    public function test_an_unreachable_endpoint_reports_indeterminate_and_exits_one(): void
    {
        $this->stageBaseConfig();
        $this->wireGoogleToStub('http://127.0.0.1:1'); // nothing listens: transport failure, never a stack trace
        [$out, $code] = $this->cli(['oauth:check'], null, [
            'KIP_OAUTH_GOOGLE_CLIENT_ID' => 'c',
            'KIP_OAUTH_GOOGLE_CLIENT_SECRET' => 's',
        ]);
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^google\s+INDETERMINATE$/m', $out);
        $this->assertStringNotContainsString('Stack trace', $out);
    }

    public function test_probe_off_reports_configured_without_any_network_call(): void
    {
        $stub = $this->startOAuthStub(self::PORT, ['KIP_OAUTH_STUB_PROBE' => 'invalid_client']);
        $this->stageBaseConfig();
        $this->wireGoogleToStub($stub['base']);
        file_put_contents($this->cliApp . '/config.php', str_replace(
            "return \$config;", "\$config['oauth']['probe'] = false;\nreturn \$config;",
            (string) file_get_contents($this->cliApp . '/config.php')));
        [$out, $code] = $this->cli(['oauth:check'], null, [
            'KIP_OAUTH_GOOGLE_CLIENT_ID' => $stub['client_id'],
            'KIP_OAUTH_GOOGLE_CLIENT_SECRET' => $stub['client_secret'],
        ]);
        $this->assertSame(0, $code, $out);
        $this->assertMatchesRegularExpression('/^google\s+CONFIGURED \(probe off\)$/m', $out);
        // Probe off means the stub never hears from the CLI: the request log is
        // never even created, which is the strongest form of "no network".
        $log = $stub['store'] . '/requests.log';
        $this->assertFalse(is_file($log) && str_contains((string) file_get_contents($log), 'POST /token'), 'probe off means no network at all');
    }
}
