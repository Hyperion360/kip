<?php // app/src/Controllers/ThemeController.php
namespace App\Controllers;

use Kip\Http\Request;
use Kip\Http\Response;
use Kip\Redirects;
use Kip\Routing\Post;

/**
 * Public appearance switcher: POST /theme from the footer form on every page.
 * Sets (or clears) the kip_theme cookie, then redirects back where the
 * visitor was.
 *
 * Cross-site protection is the kernel's, not this class's: every non-GET
 * request passes the CSRF gate, and for this unauthenticated lane that means
 * the same-origin proof (Sec-Fetch-Site / Origin / Referer against the Host),
 * so a forged cross-site POST is 403 before we run. The endpoint itself
 * writes one whitelisted cookie and nothing else (no session write, no
 * database, no server state); a guest POST still starts the lazy session,
 * because the kernel's gate reads the session token store first.
 */
final class ThemeController
{
    /**
     * Where the footer form may send the visitor back: the blog's GET pages
     * only. POST-only endpoints (store/update/attempt/logout) and anything
     * off-app fall back to /, so a crafted back value can never replay a
     * POST or leave the site. Query strings are preserved for paginated lists.
     */
    private const BACK_ALLOWED = '#^/(?:$|posts(?:$|/show/\d+$|/create$|/edit/\d+$)|auth/login$)#';

    public function __construct(private Request $request) {}

    #[Post]
    public function index(): Response
    {
        $value = $this->request->postStr('theme');
        if (!in_array($value, ['auto', 'light', 'dark'], true)) $value = 'auto';
        $back = $this->request->postStr('back');
        $path = explode('?', $back, 2)[0];
        // The route whitelist is this app's own; the generic checks (leading
        // slash, CR/LF, backslash, dot segments) are Redirects::safeReturn's,
        // shared with the admin panel's theme endpoint.
        if (preg_match(self::BACK_ALLOWED, $path) !== 1) {
            $back = '/';
        } else {
            $back = Redirects::safeReturn($back, '/');
        }
        // Path=/ on purpose: the theme applies to every public page, unlike
        // the admin panel's Path=/admin scope. Trade-off: a visitor who picked
        // light/dark sends a cookie on every request, so the page cache marks
        // them BYPASS; auto (the default) deletes the cookie and the next
        // visit is cacheable again.
        $cookie = $value === 'auto'
            ? 'kip_theme=; Path=/; Max-Age=0; SameSite=Lax'
            : 'kip_theme=' . $value . '; Path=/; Max-Age=31536000; SameSite=Lax';
        return new Response('', 302, ['Location' => $back, 'Set-Cookie' => $cookie]);
    }
}
