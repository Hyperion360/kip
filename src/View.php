<?php // src/View.php

declare(strict_types=1);
namespace Kip;

use Kip\Routing\Router;

final class View
{
    private ?string $layout = null;

    public function __construct(
        private string $dir,
        private string $featuresDir = '',
        private string $overrideDir = ''
    ) {}

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public function layout(string $name): void
    {
        $this->layout = $name;
    }

    /**
     * Render a template, optionally wrapped by a layout chosen via $this->layout().
     * Data keys beginning with "__kip_" are reserved and will be ignored by extract().
     *
     * @param array<string, mixed> $__kip_data
     */
    public function render(string $__kip_template, array $__kip_data = []): string
    {
        // Reentrancy: a template rendering a partial via $this->render() must not
        // clobber the outer template's layout selection (review T5-fix).
        $__kip_outer = $this->layout;
        $this->layout = null;
        $__kip_ob = ob_get_level();
        try {
            // Resolution order (the skins seam): overrideDir first, then the app
            // root, then the feature root. The override dir winning is the
            // deliberate skinning choice and diverges from controller resolution
            // on purpose (there the app's plain namespace wins, so code and
            // template can never flip independently); here they may, but only
            // through an explicit skin directory the consumer configures. A
            // miss falls through unchanged. Feature folders (ch. 3) keep their
            // shape under it: after the app root misses, a template whose first
            // segment names a feature resolves at
            // {featuresDir}/{Studly}/views/{rest}.php, studly-cased by the
            // Router's own rule (Router::studly, the single implementation).
            // Layouts resolve override-first too; see the layout pass below.
            $__kip_feature = null;
            if ($this->featuresDir !== '' && str_contains($__kip_template, '/')) {
                $__kip_head = strstr($__kip_template, '/', true);
                $__kip_studly = Router::studly($__kip_head);
                $__kip_rest = substr($__kip_template, strlen($__kip_head) + 1);
                $__kip_feature = $this->featuresDir . "/{$__kip_studly}/views/{$__kip_rest}.php";
            }
            $__kip_candidates = [];
            if ($this->overrideDir !== '') {
                $__kip_candidates[] = $this->overrideDir . "/{$__kip_template}.php";
            }
            $__kip_candidates[] = $this->dir . "/{$__kip_template}.php";
            if ($__kip_feature !== null) {
                $__kip_candidates[] = $__kip_feature;
            }
            $__kip_file = null;
            foreach ($__kip_candidates as $__kip_candidate) {
                if (is_file($__kip_candidate)) { $__kip_file = $__kip_candidate; break; }
            }
            if ($__kip_file === null) {
                // No override configured: the two historical message shapes stay
                // byte-identical (pinned by tests). With one, every root searched
                // is listed in resolution order.
                throw new TemplateNotFoundException($this->overrideDir === '' && $__kip_feature === null
                    ? "Template \"{$__kip_template}\" not found in {$this->dir} (looked for {$__kip_template}.php)"
                    : ($this->overrideDir === ''
                        ? "Template \"{$__kip_template}\" not found (looked for {$this->dir}/{$__kip_template}.php and {$__kip_feature})"
                        : "Template \"{$__kip_template}\" not found (looked for " . implode(' and ', $__kip_candidates) . ')'));
            }
            extract($__kip_data, EXTR_SKIP);
            ob_start();
            require $__kip_file;
            $__kip_content = ob_get_clean();
            if ($this->layout !== null) {
                $__kip_wrap = $this->layout;
                $this->layout = null;
                // Guard the layout path too, and EXTR_SKIP so no data key (e.g. 'wrap')
                // can clobber locals and hijack the require path (review T5-fix).
                // Layouts resolve override-first as well: BOTH the guard and the
                // require below carry the override arm, because arming only one
                // means an override layout either 404s (armed require, unarmed
                // guard) or loads unvalidated (the reverse).
                $__kip_layout = null;
                if ($this->overrideDir !== '' && is_file($this->overrideDir . "/{$__kip_wrap}.php")) {
                    $__kip_layout = $this->overrideDir . "/{$__kip_wrap}.php";
                } elseif (is_file($this->dir . "/{$__kip_wrap}.php")) {
                    $__kip_layout = $this->dir . "/{$__kip_wrap}.php";
                }
                if ($__kip_layout === null) {
                    throw new TemplateNotFoundException($this->overrideDir === ''
                        ? "Layout \"{$__kip_wrap}\" not found in {$this->dir} (looked for {$__kip_wrap}.php)"
                        : "Layout \"{$__kip_wrap}\" not found (looked for {$this->overrideDir}/{$__kip_wrap}.php and {$this->dir}/{$__kip_wrap}.php)");
                }
                extract($__kip_data, EXTR_SKIP);
                $content = (string) $__kip_content; // UNCONDITIONAL assignment (T3 fix-round): a caller data key named
                // 'content' leaks through the FIRST extract into a plain $content local, and the old
                // ??= guard was dead code in both directions. Only a hard overwrite here guarantees
                // the layout renders the actual body, never caller data.
                ob_start();
                require $__kip_layout;
                $__kip_content = ob_get_clean();
            }
            return (string) $__kip_content; // ob_get_clean() is string|false
        } finally {
            $this->layout = $__kip_outer;
            while (ob_get_level() > $__kip_ob) {
                ob_end_clean(); // a throwing template must not leak an open buffer
            }
        }
    }
}
