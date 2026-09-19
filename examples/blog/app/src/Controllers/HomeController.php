<?php // app/src/Controllers/HomeController.php
namespace App\Controllers;
use Kip\View;

final class HomeController
{
    public function __construct(private View $view) {}

    public function index(): string
    {
        return $this->view->render('home/index', ['title' => 'My Blog']);
    }
}
