<?php

declare(strict_types=1);

namespace justinholtweb\csr\web\assets\kb;

use craft\web\AssetBundle;

/**
 * The front-end runtime: live search, the rating control, and the deflection panel.
 *
 * No build step and no dependencies. Everything here is **progressive enhancement, meant
 * literally**: nothing in `csr.js` may create content, only enhance what the server already
 * rendered. The search box submits, the rating buttons are real form submissions, and the support
 * form works with JavaScript switched off — the script makes the search live, the votes silent and
 * the suggestions appear as you type.
 *
 * The stylesheet is deliberately small and namespaced under `.csr`. A knowledge base has to look
 * like the site it is part of, and a plugin that ships a design system is a plugin whose pages
 * look like a plugin.
 */
class KbAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [];

    public $js = ['csr.js'];

    public $css = ['csr.css'];
}
