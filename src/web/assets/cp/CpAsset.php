<?php

declare(strict_types=1);

namespace justinholtweb\csr\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

/**
 * The control panel's layout rules: spacing, the ticket conversation, report tiles and charts.
 *
 * Registered for every page template under `csr/` and by the desk widget. Colours, borders and
 * radii are Craft's own variables, so the screens follow the control panel rather than a copy of
 * it — which is what the templates' old inline styles could not do.
 */
class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [CraftCpAsset::class];

    public $css = ['csr-cp.css'];
}
