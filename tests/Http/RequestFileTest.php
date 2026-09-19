<?php // tests/Http/RequestFileTest.php
namespace Kip\Tests\Http;
use Kip\App;
use Kip\Http\Request;
use Kip\Testing\TestClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RequestFileTest extends TestCase
{
    private function entry(int $error = UPLOAD_ERR_OK): array
    {
        return ['name' => 'a.png', 'tmp_name' => '/tmp/x', 'size' => 3, 'error' => $error, 'type' => ''];
    }

    public function test_file_returns_valid_entry(): void
    {
        $r = new Request('POST', '/x', [], [], [], '', [], ['doc' => $this->entry()]);
        $this->assertSame('a.png', $r->file('doc')['name']);
    }

    public function test_file_null_for_missing_key(): void
    {
        $this->assertNull((new Request('POST', '/x', [], [], []))->file('doc'));
    }

    public function test_file_null_for_upload_error(): void
    {
        $r = new Request('POST', '/x', [], [], [], '', [], ['doc' => $this->entry(UPLOAD_ERR_PARTIAL)]);
        $this->assertNull($r->file('doc'));
    }

    public function test_file_null_for_malformed_entry(): void
    {
        $r = new Request('POST', '/x', [], [], [], '', [], ['doc' => ['name' => 'a.png', 'tmp_name' => ['not-a-string'], 'error' => UPLOAD_ERR_OK]]);
        $this->assertNull($r->file('doc'));
    }

    public function test_postWithFile_reaches_controller(): void
    {
        $app = new App([
            'env' => 'dev',
            'controller_namespace' => 'Kip\\Tests\\Fixtures\\Controllers\\',
            'views' => dirname(__DIR__) . '/Fixtures/views',
        ]);
        $client = (new TestClient($app))->actingAs(1);
        $res = $client->postWithFile('/uploads/store', [], 'doc', $this->entry());
        $this->assertSame(200, $res->status);
        $this->assertSame('got:a.png', $res->body);
        $this->assertSame(422, $client->postWithToken('/uploads/store', [])->status); // no file → controller's 422 path
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_from_globals_carries_files(): void // the SAPI capture path, not just the constructor
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/x';
        $_FILES = ['doc' => $this->entry()];
        $this->assertSame('a.png', Request::fromGlobals()->file('doc')['name']);
        unset($_FILES);
    }
}
