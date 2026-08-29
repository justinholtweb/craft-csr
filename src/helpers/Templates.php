<?php

declare(strict_types=1);

namespace justinholtweb\csr\helpers;

use Craft;
use craft\web\View;

/**
 * Rendering CSR's own front-end templates, with the site allowed to overrule them.
 *
 * CSR ships a working knowledge base and support form so that the plugin does something the day it
 * is installed. A site that wants its own markup copies the file it cares about into
 * `templates/csr/…` and edits it there — no overrides file, no configuration, and no need to
 * replace all of them to change one.
 *
 * The mechanism is the mode: a template that exists in the *site's* templates renders in site
 * mode, and everything else falls back to the plugin's copy in control panel mode.
 */
abstract class Templates
{
    /** @param array<string, mixed> $variables */
    public static function render(string $template, array $variables = []): string
    {
        $view = Craft::$app->getView();

        return $view->renderTemplate($template, $variables, self::modeFor($template));
    }

    public static function modeFor(string $template): string
    {
        $view = Craft::$app->getView();

        return $view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)
            ? View::TEMPLATE_MODE_SITE
            : View::TEMPLATE_MODE_CP;
    }

    /** Whether the site has taken over this template. Used by the settings screen to say so. */
    public static function isOverridden(string $template): bool
    {
        return Craft::$app->getView()->doesTemplateExist($template, View::TEMPLATE_MODE_SITE);
    }
}
