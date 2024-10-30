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

use PhpCsFixer\Config;
use PhpCsFixer\ConfigurationException\InvalidConfigurationException;
use PhpCsFixer\Finder;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet;

$header = <<<'EOF'
    This file is part of the WordPress plugin "Upload Field with Uppy for ACF".

    (ɔ) Frugan <dev@frugan.it>

    This source file is subject to the GNU GPLv3 or later license that is bundled
    with this source code in the file LICENSE.
    EOF;

// exclude will work only for directories, so if you need to exclude file, try notPath
$finder = Finder::create()
    ->in([__DIR__])
    ->exclude(['patch', 'symlink', 'vendor'])
    ->append([__DIR__.'/.php-cs-fixer.dist.php'])
;

$config = (new Config())
    ->setCacheFile(sys_get_temp_dir().'/.php_cs.cache')
    ->setRiskyAllowed(true)
    ->setRules([
        // https://mlocati.github.io/php-cs-fixer-configurator
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@PHP80Migration' => true,
        '@PHP80Migration:risky' => true,
        'header_comment' => ['header' => $header],
    ])
    // ->registerCustomFixers( [
    //     new WeDevs\Fixer\SpaceInsideParenthesisFixer(),
    //     new WeDevs\Fixer\BlankLineAfterClassOpeningFixer(),
    //  ] )
    // ->setRules( WeDevs\Fixer\Fixer::rules() )
    ->setFinder($finder)
;

// special handling of fabbot.io service if it's using too old PHP CS Fixer version
if (false !== getenv('FABBOT_IO')) {
    try {
        // @phpstan-ignore-next-line
        FixerFactory::create()
            ->registerBuiltInFixers()
            ->registerCustomFixers($config->getCustomFixers())
            // @phpstan-ignore-next-line
            ->useRuleSet(new RuleSet($config->getRules()))
        ;
    } catch (InvalidConfigurationException $e) {
        $config->setRules([]);
    } catch (UnexpectedValueException $e) {
        $config->setRules([]);
    } catch (InvalidArgumentException $e) {
        $config->setRules([]);
    }
}

return $config;
