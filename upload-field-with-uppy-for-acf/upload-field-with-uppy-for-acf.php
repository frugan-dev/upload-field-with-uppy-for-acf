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

use FruganUFWUFACF\Bootstrap;

/*
 * Plugin Name: Upload Field with Uppy for ACF
 * Plugin URI: https://github.com/frugan-dev/upload-field-with-uppy-for-acf
 * Description: Upload Field with Uppy for ACF is a WordPress plugin that adds a new "Uppy" custom field to the list of fields of the Advanced Custom Fields plugin.
 * Version: 3.0.0
 * Requires PHP: 8.0
 * Author: Frugan
 * Author URI: https://frugan.it
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Donate link: https://buymeacoff.ee/frugan
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__.'/vendor/autoload.php')) {
    require __DIR__.'/vendor/autoload.php';
}

define('FRUGAN_UFWUFACF_VERSION', '3.0.0');
define('FRUGAN_UFWUFACF_BASENAME', plugin_basename(__FILE__));
define('FRUGAN_UFWUFACF_NAME', dirname(FRUGAN_UFWUFACF_BASENAME));
define('FRUGAN_UFWUFACF_NAME_UNDERSCORE', str_replace('-', '_', FRUGAN_UFWUFACF_NAME));
define('FRUGAN_UFWUFACF_URL', plugin_dir_url(__FILE__));
define('FRUGAN_UFWUFACF_PATH', plugin_dir_path(__FILE__));

Bootstrap::get_instance();
