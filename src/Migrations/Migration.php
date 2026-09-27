<?php // src/Migrations/Migration.php

declare(strict_types=1);
namespace Kip\Migrations;
use Kip\Database;

abstract class Migration
{
    abstract public function up(Database $db): void;
    abstract public function down(Database $db): void;
}
