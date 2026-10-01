<?php // app/src/Nav.php
namespace App;

use Kip\Database;
use Kip\Session;
use Kip\Http\Request;

/**
 * Frame data every page needs: the title, whether the visitor is logged in,
 * whether they may open the admin panel, their CSRF token for the logout
 * form, the chosen theme ('' when the kip_theme cookie is unset or invalid,
 * so the layout renders auto), the request path, and the query string for
 * the theme switch's back target. Guests (a request with no cookies) stay
 * zero-query: touching the session would start one and set a cookie, making
 * every public page uncacheable for everyone. The theme read is cookie-only
 * and the is_admin lookup runs for logged-in visitors only; one indexed
 * column read, the same discipline the skeleton uses.
 */
final class Nav
{
    /**
     * Merge with `+` under the action's own data, e.g.
     * `Nav::frame(...) + ['posts' => $posts]`. PHP's `+` keeps the LEFT side
     * on key conflicts, so only NEW keys can be added this way: the login
     * page's `+ ['csrf' => ...]` does NOT override Nav's logged-out null,
     * and that is fine, because an unauthenticated POST is gated by the
     * kernel's same-origin proof, not by a session token. Anything that must
     * replace a frame value needs array_merge instead.
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
        $theme = $request->cookies['kip_theme'] ?? '';
        return [
            'title' => $title,
            'loggedIn' => $loggedIn,
            'isAdmin' => $isAdmin,
            'csrf' => $loggedIn ? $session->csrfToken() : null,
            'theme' => in_array($theme, ['light', 'dark'], true) ? $theme : '',
            'path' => $request->path,
            'query' => http_build_query($request->get),
        ];
    }
}
