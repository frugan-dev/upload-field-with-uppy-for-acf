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

namespace FruganUFWUFACF;

if (!\defined('ABSPATH')) {
    exit;
}

class Bootstrap
{
    private static ?self $instance = null;

    protected function __construct()
    {
        add_action('muplugins_loaded', [$this, 'muplugins_loaded']);
        add_action('plugins_loaded', [$this, 'plugins_loaded']);
        add_action('init', [$this, 'init'], 0);
        add_action('admin_init', [$this, 'admin_init'], 999);
        add_action('deactivated_plugin', [$this, 'deactivated_plugin']);

        if (!$this->is_mu_plugin()) {
            delete_option(FRUGAN_UFWUFACF_NAME.'__activated');

            register_activation_hook(FRUGAN_UFWUFACF_BASENAME, static fn (): array => [Field::class, 'activate']);
            register_deactivation_hook(FRUGAN_UFWUFACF_BASENAME, static fn (): array => [Field::class, 'deactivate']);
        }
    }

    public static function get_instance(): self
    {
        if (!self::$instance instanceof self) {
            // @phpstan-ignore-next-line
            self::$instance = new static();
        }

        return self::$instance;
    }

    public function muplugins_loaded(): void
    {
        if ($this->is_mu_plugin()) {
            load_muplugin_textdomain(
                FRUGAN_UFWUFACF_NAME,
                trailingslashit(FRUGAN_UFWUFACF_NAME).'lang'
            );
        }
    }

    public function plugins_loaded(): void
    {
        if (!$this->is_mu_plugin()) {
            load_plugin_textdomain(
                FRUGAN_UFWUFACF_NAME,
                false,
                trailingslashit(FRUGAN_UFWUFACF_NAME).'lang'
            );
        }
    }

    public function init(): void
    {
        if (!class_exists('acf') || !\function_exists('acf_register_field_type')) {
            return;
        }

        if ($this->is_mu_plugin() && !get_option(FRUGAN_UFWUFACF_NAME.'__activated')) {
            Field::activate();
            update_option(FRUGAN_UFWUFACF_NAME.'__activated', true);
        }

        acf_register_field_type(Field::class);
    }

    public function admin_init(): void
    {
        if (!class_exists('acf')) {
            $this->deactivate();

            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }

            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-error">';
                echo '<h3>'.esc_html__('Upload Field with Uppy for ACF', 'upload-field-with-uppy-for-acf').'</h3>';
                // translators: %s: plugin name
                echo '<p><strong>'.esc_html(\sprintf(__('%1$s plugin is required.', 'upload-field-with-uppy-for-acf'), __('Advanced Custom Fields', 'upload-field-with-uppy-for-acf'))).'</strong></p>';
                echo '</div>';
            });
        }
    }

    public function deactivated_plugin(string $plugin): void
    {
        if (\in_array($plugin, ['advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php'], true)) {
            $this->deactivate();
        }
    }

    private function deactivate(): void
    {
        deactivate_plugins(FRUGAN_UFWUFACF_BASENAME);
    }

    private function is_mu_plugin(): bool
    {
        return \defined('WPMU_PLUGIN_DIR') && str_contains(FRUGAN_UFWUFACF_PATH, WPMU_PLUGIN_DIR);
    }
}
