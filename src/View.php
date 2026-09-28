<?php // src/View.php

declare(strict_types=1);
namespace Kip;

use Kip\Routing\Router;

final class View
{
    private ?string $layout = null;

    public function __construct(private string $dir, private string $featuresDir = '') {}

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
            // Feature folders (ch. 3): after the app root misses, a template whose first
            // segment names a feature resolves at {featuresDir}/{Studly}/views/{rest}.php,
            // studly-cased by the Router's own rule (Router::studly, the single
            // implementation). The app root wins, mirroring the Router's
            // plain-namespaces-first precedence, so code and template can never flip
            // independently. Layouts stay app-root-only: the shared kernel owns them.
            $__kip_feature = null;
            if ($this->featuresDir !== '' && str_contains($__kip_template, '/')) {
                $__kip_head = strstr($__kip_template, '/', true);
                $__kip_studly = Router::studly($__kip_head);
                $__kip_rest = substr($__kip_template, strlen($__kip_head) + 1);
                $__kip_feature = $this->featuresDir . "/{$__kip_studly}/views/{$__kip_rest}.php";
            }
            $__kip_file = $this->dir . "/{$__kip_template}.php";
            if (!is_file($__kip_file)) {
                $__kip_file = $__kip_feature !== null && is_file($__kip_feature) ? $__kip_feature : null;
            }
            if ($__kip_file === null) {
                throw new TemplateNotFoundException($__kip_feature === null
                    ? "Template \"{$__kip_template}\" not found in {$this->dir} (looked for {$__kip_template}.php)"
                    : "Template \"{$__kip_template}\" not found (looked for {$this->dir}/{$__kip_template}.php and {$__kip_feature})");
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
                if (!is_file($this->dir . "/{$__kip_wrap}.php")) {
                    throw new TemplateNotFoundException(
                        "Layout \"{$__kip_wrap}\" not found in {$this->dir} (looked for {$__kip_wrap}.php)"
                    );
                }
                extract($__kip_data, EXTR_SKIP);
                $content = (string) $__kip_content; // UNCONDITIONAL assignment (T3 fix-round): a caller data key named
                // 'content' leaks through the FIRST extract into a plain $content local, and the old
                // ??= guard was dead code in both directions. Only a hard overwrite here guarantees
                // the layout renders the actual body, never caller data.
                ob_start();
                require $this->dir . "/{$__kip_wrap}.php";
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
