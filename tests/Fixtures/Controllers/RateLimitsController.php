<?php // tests/Fixtures/Controllers/RateLimitsController.php
namespace Kip\Tests\Fixtures\Controllers;
use Kip\Database;
use Kip\Routing\Post;

/**
 * Cache-integration fixture for the rate limiter: a page that READS the
 * counter table (so its cache tag is exactly rate_limits), a page that reads
 * a content table, and the performance-contract upsert idiom that writes it.
 */
final class RateLimitsController
{
    public function __construct(private Database $db) {}

    /** Reads the counter table; the page's cache tag is rate_limits. */
    public function index(): string
    {
        return 'rows ' . (int) $this->db->one('SELECT COUNT(*) c FROM rate_limits')['c'];
    }

    /** Reads the content table the upsert writes. */
    public function things(): string
    {
        $row = $this->db->one('SELECT n FROM things WHERE id = 1');
        return 'n ' . (int) ($row['n'] ?? 0);
    }

    /** The upsert idiom the performance contract documents. */
    #[Post]
    public function store(): string
    {
        $this->db->query('INSERT INTO things (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = n + 1');
        return 'stored';
    }
}
