<?php // app/src/Nav.php
namespace App;

use Kip\Database;
use Kip\Session;
use Kip\Http\Request;

/**
 * Frame data the top nav needs on every page: whether the visitor is logged
 * in, whether they may open the admin panel, and their CSRF token for the
 * logout form. Guests (a request with no cookies) stay zero-query: touching
 * the session would start one and set a cookie, making every public page
 * uncacheable for everyone. The is_admin lookup runs for logged-in visitors
 * only; one indexed column read, the same discipline the skeleton uses.
 */
final class Nav
{
    /**
     * Merge with `+` under the action's own data, e.g.
     * `Nav::frame(...) + ['posts' => $posts]`: the action wins on conflicts,
     * which is how the login page keeps its always-needed CSRF token.
     *
     * @return array<string, mixed>
     */
    public static function frame(Session $session, Request $request, ?Database $db, string $title): array
    {
        $loggedIn = $request->cookies !== [] && $session->get('user_id') !== null;
        $isAdmin = false;
        if ($loggedIn && $db !== null) {
            $isAdmin = (bool) ($db->one(
                'SELECT is_admin FROM users WHERE id = ?',
                [$session->get('user_id')]
            )['is_admin'] ?? false);
        }
        return [
            'title' => $title,
            'loggedIn' => $loggedIn,
            'isAdmin' => $isAdmin,
            'csrf' => $loggedIn ? $session->csrfToken() : null,
        ];
    }
}
