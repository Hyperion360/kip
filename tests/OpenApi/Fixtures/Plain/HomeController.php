<?php // tests/OpenApi/Fixtures/Plain/HomeController.php
namespace Kip\Tests\OpenApi\Fixtures\Plain;

final class HomeController
{
    public function index(): string { return 'home'; }   // the root path '/'
    public function about(): string { return 'about'; }  // '/home/about': bare '/home' 404s, deeper paths route
}
