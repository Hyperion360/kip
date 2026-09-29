<?php // tests/OpenApi/Fixtures/Plain/SnapshotController.php
namespace Kip\Tests\OpenApi\Fixtures\Plain;

final class SnapshotController
{
    public function doWork(): array { return []; }    // '/snapshot/dowork': action segments are lowercased, never dasherized
    public function list_all(): array { return []; }  // '/snapshot/list_all': underscores stay
}
