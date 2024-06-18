<?php

declare(strict_types=1);

/*
 * This file is part of the ACF Uppy Field WordPress plugin.
 *
 * (ɔ) Frugan <dev@frugan.it>
 *
 * This source file is subject to the GNU GPLv3 or later license that is bundled
 * with this source code in the file LICENSE.
 */

use AcfUppy\AcfUppy;

/*
 * Plugin Name: Advanced Custom Fields: Uppy
 * Plugin URI: https://github.com/frugan-dev/acf-uppy
 * Description: Uppy Field for Advanced Custom Fields
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Author: Frugan
 * Author URI: https://about.me/frugan
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
}

define('ACF_UPPY_VERSION', '0.3.0');
define('ACF_UPPY_NAME', dirname(plugin_basename(__FILE__)));
define('ACF_UPPY_NAME_UNDERSCORE', str_replace('-', '_', ACF_UPPY_NAME));
define('ACF_UPPY_FIELD_TYPE', 'uppy');

$AcfUppy = new AcfUppy();

register_activation_hook(__FILE__, [$AcfUppy, 'activate']);
register_deactivation_hook(__FILE__, [$AcfUppy, 'deactivate']);
