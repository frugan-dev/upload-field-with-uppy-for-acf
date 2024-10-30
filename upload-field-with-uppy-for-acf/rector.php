<?php

declare(strict_types=1);

/*
 * This file is part of the WordPress plugin "Upload Field with Uppy for ACF".
 *
 * (ɔ) Frugan <dev@frugan.it>
 *
 * This source file is subject to the GNU GPLv3 or later license that is bundled
 * with this source code in the file LICENSE.
 */

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

// https://getrector.com/blog/5-common-mistakes-in-rector-config-and-how-to-avoid-them
return RectorConfig::configure()
    ->withPaths(
        [
            __DIR__.'/src',
            __DIR__.'/tests',
        ]
    )
    ->withRootFiles()
    ->withSets(
        [
            SetList::DEAD_CODE,
            // SetList::CODE_QUALITY,
            SetList::CODING_STYLE,
            SetList::TYPE_DECLARATION,
            SetList::PRIVATIZATION,
            SetList::NAMING,
            SetList::INSTANCEOF,
            SetList::EARLY_RETURN,
            // SetList::STRICT_BOOLEANS,
            LevelSetList::UP_TO_PHP_80,
        ]
    )
;
