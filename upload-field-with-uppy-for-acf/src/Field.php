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

use Diversen\Sendfile;
use FruganUFWUFACF\Middleware\Auth;
use FruganUFWUFACF\Middleware\UploadDir;
use FruganUFWUFACF\Middleware\UploadMetadata;
use Inpsyde\Wonolog\Configurator;
use TusPhp\Cache\AbstractCache;
use TusPhp\Events\TusEvent;
use TusPhp\Tus\Server;

if (!\defined('ABSPATH')) {
    exit;
}

/**
 * Class Field.
 *
 * @property string $title
 */
class Field extends \acf_field
{
    /**
     * Controls field type visibilty in REST requests.
     *
     * @var bool
     */
    public $show_in_rest = true;

    public ?Server $server = null;

    public array $paths = [];

    /**
     * Environment values relating to the theme or plugin.
     *
     * @var array plugin or theme context such as 'url' and 'version'
     */
    private array $env;

    /**
     * Constructor.
     */
    public function __construct()
    {
        /*
         * Field type reference used in PHP and JS code.
         *
         * No spaces. Underscores allowed.
         */
        $this->name = FRUGAN_UFWUFACF_NAME_UNDERSCORE;

        $this->title = __('Upload Field with Uppy for ACF', 'upload-field-with-uppy-for-acf');

        /*
         * Field type label.
         *
         * For public-facing UI. May contain spaces.
         */
        $this->label = __('Uppy', 'upload-field-with-uppy-for-acf');

        // The category the field appears within in the field type picker.
        $this->category = 'content'; // basic | content | choice | relational | jquery | layout | CUSTOM GROUP NAME

        /*
         * Field type Description.
         *
         * For field descriptions. May contain spaces.
         */
        $this->description = __('Upload Field with Uppy for ACF is a WordPress plugin that adds a new "Uppy" custom field to the list of fields of the Advanced Custom Fields plugin.', 'upload-field-with-uppy-for-acf');

        /*
         * Field type Doc URL.
         *
         * For linking to a documentation page. Displayed in the field picker modal.
         */
        $this->doc_url = 'https://github.com/frugan-dev/upload-field-with-uppy-for-acf';

        /*
         * Field type Tutorial URL.
         *
         * For linking to a tutorial resource. Displayed in the field picker modal.
         */
        $this->tutorial_url = 'https://github.com/frugan-dev/upload-field-with-uppy-for-acf';

        $this->set_server();

        $wp_upload_dir = wp_upload_dir();

        $this->env = [
            'version' => FRUGAN_UFWUFACF_VERSION,
            // @phpstan-ignore-next-line
            'slug' => \defined('FRUGAN_UFWUFACF_SLUG') && \is_string(FRUGAN_UFWUFACF_SLUG) && !empty(FRUGAN_UFWUFACF_SLUG) ? FRUGAN_UFWUFACF_SLUG : 'uppy',
            'url' => FRUGAN_UFWUFACF_URL,
            'path' => FRUGAN_UFWUFACF_PATH,
            'debug' => WP_DEBUG,
            'locale' => get_locale(),
            'tmp_path' => apply_filters($this->name.'/tmp_path', trailingslashit(sys_get_temp_dir()).trailingslashit(FRUGAN_UFWUFACF_NAME).get_current_user_id()),
            'symlink_url' => apply_filters($this->name.'/symlink_url', FRUGAN_UFWUFACF_URL.'symlink'),
            'symlink_path' => apply_filters($this->name.'/symlink_path', FRUGAN_UFWUFACF_PATH.'symlink'),
            'api_path' => home_url('/'.apply_filters($this->name.'/api_path', 'wp-tus')),
            'cache_ttl' => apply_filters($this->name.'/cache_ttl', $this->server->getCache()->getTtl()), // default: 86400
            'cache_busting' => \defined('FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED') && !empty(FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED) && !is_numeric(FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED) && filter_var(FRUGAN_UFWUFACF_CACHE_BUSTING_ENABLED, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? true : false,
        ];

        // Defaults for your custom user-facing settings for this field type.
        $this->defaults = [
            'dest_path' => apply_filters($this->name.'/dest_path', trailingslashit($wp_upload_dir['basedir']).$this->env['slug']),
            'max_file_size' => 10,
            // https://www.iana.org/assignments/media-types/media-types.xhtml
            'allowed_file_types' => null,
        ];

        /*
         * Strings used in JavaScript code.
         *
         * Allows JS strings to be translated in PHP and loaded in JS via:
         *
         * ```js
         * const errorMessage = acf._e("uppy", "error");
         * ```
         */
        $this->l10n = [
            'technical_problem' => __('There was a technical problem, please try again later.', 'upload-field-with-uppy-for-acf'),
        ];

        /*
         * Field type preview image.
         *
         * A preview image for the field type in the picker modal.
         */
        // $this->preview_image = $this->env['url'] . '/asset/img/preview-custom.png';

        $this->setup_server();
        $this->setup_rewites();

        parent::__construct();

        add_action('parse_request', [$this, 'parse_request'], 0);
        add_action('wp', [$this, 'wp']);
        add_action('before_delete_post', [$this, 'before_delete_post'], 10, 2);
        add_action('acf/save_post', [$this, 'save_post']);
    }

    public function set_server(): void
    {
        if ($this->server instanceof Server) {
            return;
        }

        $this->server = new Server(
            // Either redis, file or apcu. Leave empty for file based cache.
            // https://github.com/ankitpokhrel/tus-php/issues/408#issuecomment-1250229371
            // It is not advised to use FileStore in production. FileStore was initially designed for development purposes
            // and may not work properly in many cases. Please use redis or apcu cache in prod.
            apply_filters($this->name.'/cache', 'apcu')
        );
    }

    public function get_server()
    {
        return $this->server;
    }

    public function setup_server(): void
    {
        $this->server->setApiPath(
            // tus server endpoint.
            '/'.apply_filters($this->name.'/api_path', 'wp-tus')
        );

        // https://github.com/ankitpokhrel/tus-php/issues/102
        $cache = $this->server->getCache();

        if ($cache instanceof AbstractCache) {
            $cache->setTtl($this->env['cache_ttl']);
        }

        $this->server->middleware()->add(
            Auth::class,
            UploadMetadata::class,
            new UploadDir($this->env, $this->server)
        );

        $this->server->event()->addListener(
            'tus-server.upload.complete',
            function (TusEvent $tusEvent): void {
                $fileMeta = $tusEvent->getFile()->details();
                $fieldName = basename(\dirname($fileMeta['file_path']));

                $dirs = glob(trailingslashit($this->server->getUploadDir()).'*');

                if (false === $dirs) {
                    // translators: %s: path
                    throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), trailingslashit($this->server->getUploadDir()).'*')));
                }

                foreach ($dirs as $dir) {
                    if ($fileMeta['file_path'] === $dir) {
                        continue;
                    }

                    if (is_file($dir)) {
                        wp_delete_file($dir);
                    }
                }

                $requestKey = $tusEvent->getRequest()->key();

                $cacheable = $this->server->getCache();

                // getActualCacheKey() method is public only in FileStore
                // https://github.com/ankitpokhrel/tus-php/blob/v2.1.0/src/Cache/FileStore.php#L267
                if (!method_exists($cacheable, 'getActualCacheKey') || !\is_callable([$cacheable, 'getActualCacheKey'])) {
                    return;
                }

                // https://github.com/ankitpokhrel/tus-php/issues/102
                foreach ($cacheable->keys() as $cacheKey) {
                    if ($cacheable->getActualCacheKey($requestKey) === $cacheKey) {
                        continue;
                    }

                    if ($oldFileMeta = $cacheable->get($cacheKey)) {
                        if (preg_match('~'.preg_quote('/'.get_current_user_id().'/'.$fieldName.'/').'~', $oldFileMeta['file_path'])) {
                            $cacheable->delete($cacheKey);
                        }
                    }
                }
            }
        );
    }

    // https://github.com/ankitpokhrel/tus-php/wiki/WordPress-Integration
    public function setup_rewites(): void
    {
        global $wp;

        $wp->add_query_var('tus');
        $wp->add_query_var($this->name.'_action');
        $wp->add_query_var($this->name.'_pubkey');

        // add_rewrite_tag( '%tus%', '([^&]+)' );
        add_rewrite_rule('^'.apply_filters($this->name.'/api_path', 'wp-tus').'/?([^/]*)/?([^/]*)/?$', 'index.php?tus=$matches[1]', 'top');
        add_rewrite_rule('^'.apply_filters($this->name.'/base_path', $this->env['slug']).'/([^/]+)/([0-9]{1,})/([^/]+)/?$', 'index.php?'.$this->name.'_action=$matches[1]&page_id=$matches[2]&'.$this->name.'_pubkey=$matches[3]', 'top');
    }

    public function parse_request($wp): void
    {
        if (isset($wp->query_vars['tus'])) {
            $response = $this->server->serve();
            $response->send();

            exit;
        }

        if (empty($wp->query_vars[$this->name.'_action']) || empty($wp->query_vars['page_id']) || empty($wp->query_vars[$this->name.'_pubkey'])) {
            return;
        }

        $postId = (int) $wp->query_vars['page_id'];
        $postType = get_post_type($postId);

        switch ($wp->query_vars[$this->name.'_action']) {
            case 'download':
                $fieldsObj = get_field_objects($postId);

                if (!empty($fieldsObj)) {
                    $dest_files = $this->get_dest_files($fieldsObj, $postId);

                    if (!empty($dest_files)) {
                        foreach ($dest_files as $dest_file) {
                            $hash = apply_filters($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_hash', wp_hash($dest_file), $dest_file, $postId);

                            if (!empty($postType)) {
                                $hash = apply_filters($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_hash/type='.$postType, $hash, $dest_file, $postId);
                            }

                            if ($wp->query_vars[$this->name.'_pubkey'] === $hash) {
                                $found = true;

                                break;
                            }
                        }

                        if (!empty($found)) {
                            $allow = apply_filters($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_allow', true, $dest_file, $postId);

                            if (!empty($postType)) {
                                $allow = apply_filters($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_allow/type='.$postType, $allow, $dest_file, $postId);
                            }

                            if (!empty($allow)) {
                                require_once ABSPATH.'/wp-admin/includes/file.php';
                                WP_Filesystem();

                                global $wp_filesystem;

                                $i = 0;
                                $paths = glob(trailingslashit($this->env['symlink_path']).'*');

                                if (false === $paths) {
                                    // translators: %s: path
                                    throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), trailingslashit($this->env['symlink_path']).'*')));
                                }

                                foreach ($paths as $path) {
                                    if (is_dir($path)) {
                                        if (basename($path) === $wp->query_vars[$this->name.'_pubkey']) {
                                            continue;
                                        }

                                        // https://stackoverflow.com/a/34512584
                                        $stat = stat($path);

                                        if (false !== $stat) {
                                            $diff = ((time() - $stat['mtime']) / (60 * 60 * 24));

                                            if ($diff >= apply_filters($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_symlink_delete_days', 1)) {
                                                @$wp_filesystem->rmdir($path, true);
                                            }
                                        }

                                        ++$i;

                                        if ($i > apply_filters($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_symlink_delete_max', 10)) {
                                            break;
                                        }
                                    }
                                }

                                $symlink_path = trailingslashit($this->env['symlink_path']).trailingslashit($wp->query_vars[$this->name.'_pubkey']);

                                if (is_link($symlink_path)) {
                                    $symlink_path = readlink($symlink_path);
                                }

                                $symlinkFile = $symlink_path.basename($dest_file);

                                if (false === wp_mkdir_p($symlink_path)) {
                                    wp_die(
                                        esc_html(\sprintf(
                                            // translators: %1$s: symlink_path
                                            __('Error creating symlink_path "%1$s"', 'upload-field-with-uppy-for-acf'),
                                            $symlink_path
                                        )),
                                        500,
                                        ['back_link' => true]
                                    );
                                }

                                if (!is_link($symlinkFile)) {
                                    if (true !== @symlink($dest_file, $symlinkFile)) {
                                        @exec('ln -s '.escapeshellcmd($dest_file).' '.escapeshellcmd($symlinkFile), $out, $status);
                                    }
                                }

                                if (is_link($symlinkFile)) {
                                    wp_safe_redirect(trailingslashit($this->env['symlink_url']).trailingslashit($wp->query_vars[$this->name.'_pubkey']).basename($dest_file));

                                    exit;
                                }

                                // https://stackoverflow.com/a/1395173/3929620
                                // https://zinoui.com/blog/download-large-files-with-php
                                // https://github.com/diversen/http-send-file
                                // https://github.com/apfelbox/PHP-File-Download
                                try {
                                    (new Sendfile())->send($dest_file);

                                    exit;
                                } catch (\Exception $e) {
                                    wp_die(
                                        esc_html($e->getMessage()),
                                        500,
                                        ['back_link' => true]
                                    );
                                }
                            }
                        }
                    }
                }

                break;
        }

        do_action($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_fallback', $postId);
        do_action($this->name.'/'.$wp->query_vars[$this->name.'_action'].'_fallback/type='.$postType, $postId);
    }

    public function wp($wp): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        if (\defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        if (is_dir($this->env['tmp_path'])) {
            require_once ABSPATH.'/wp-admin/includes/file.php';
            WP_Filesystem();

            global $wp_filesystem;

            $paths = glob(trailingslashit($this->env['tmp_path']).'*');

            if (false === $paths) {
                // translators: %s: path
                throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), trailingslashit($this->env['tmp_path']).'*')));
            }

            foreach ($paths as $path) {
                if (is_dir($path)) {
                    @$wp_filesystem->rmdir($path, true);
                }
            }
        }

        // https://github.com/ankitpokhrel/tus-php/issues/102
        $cacheKeys = $this->server->getCache()->keys();
        // $this->server->getCache()->deleteAll( $cacheKeys );

        foreach ($cacheKeys as $cacheKey) {
            if (!($oldFileMeta = $this->server->getCache()->get($cacheKey))) {
                continue;
            }

            if (!preg_match('~^'.preg_quote(trailingslashit($this->env['tmp_path'])).'~', $oldFileMeta['file_path'])) {
                continue;
            }

            $this->server->getCache()->delete($cacheKey);
        }
    }

    /**
     * Settings to display when users configure a field of this type.
     *
     * These settings appear on the ACF “Edit Field Group” admin page when
     * setting up the field.
     *
     * @param array $field
     */
    public function render_field_settings($field): void
    {
        // Repeat for each setting you wish to display for this field type.
        acf_render_field_setting(
            $field,
            [
                'label' => __('Max file size', 'upload-field-with-uppy-for-acf'),
                'instructions' => implode(
                    '<br>'.PHP_EOL,
                    [
                        // translators: %1$s: max_file_size
                        \sprintf(__('Default: %1$s', 'upload-field-with-uppy-for-acf'), '<code>'.$this->defaults['max_file_size'].'</code>'),
                    ]
                ),
                'type' => 'number',
                'name' => 'max_file_size',
                'append' => 'MB',
                'min' => 0,
                'step' => 1,
            ]
        );

        acf_render_field_setting(
            $field,
            [
                'label' => __('Allowed file types', 'upload-field-with-uppy-for-acf'),
                'instructions' => implode(
                    '<br>'.PHP_EOL,
                    [
                        __('Wildcards mime types (e.g. image/*), exact mime types (e.g. image/jpeg), or file extensions (e.g. .jpg).', 'upload-field-with-uppy-for-acf'),
                        __('One value for each line.', 'upload-field-with-uppy-for-acf'),
                        // translators: %1$s: allowed_file_types
                        \sprintf(__('Default: %1$s', 'upload-field-with-uppy-for-acf'), '<code>'.$this->defaults['allowed_file_types'].'</code>'),
                    ]
                ),
                'type' => 'textarea',
                'name' => 'allowed_file_types',
            ]
        );

        acf_render_field_setting(
            $field,
            [
                'label' => __('Uploads path', 'upload-field-with-uppy-for-acf'),
                'instructions' => implode(
                    '<br>'.PHP_EOL,
                    [
                        __('Absolute path to the directory where to save all files.', 'upload-field-with-uppy-for-acf'),
                        __('It can also be outside the public directory.', 'upload-field-with-uppy-for-acf'),
                        // translators: %1$s: dest_path
                        \sprintf(__('Default: %1$s', 'upload-field-with-uppy-for-acf'), '<code>'.$this->defaults['dest_path'].'</code>'),
                    ]
                ),
                'type' => 'text',
                'name' => 'dest_path',
            ]
        );

        // To render field settings on other tabs in ACF 6.0+:
        // https://www.advancedcustomfields.com/resources/adding-custom-settings-fields/#moving-field-setting
    }

    /**
     * HTML content to show when a publisher edits the field on the edit screen.
     *
     * @param array $field the field settings and values
     */
    public function render_field($field): void
    {
        global $post;

        $dest_file = '';
        $hash = '';

        if (!empty($field['value'])) {
            $dest_path = !empty($field['dest_path']) ? trailingslashit($field['dest_path']) : apply_filters($this->name.'/dest_path/type='.$post->post_type, trailingslashit($this->defaults['dest_path']), $post->ID, $field);
            $dest_path .= trailingslashit($post->ID).trailingslashit(sanitize_file_name($field['key']));

            $dest_file = $dest_path.$field['value'];

            if (file_exists($dest_file)) {
                $found = true;

                $hash = apply_filters($this->name.'/download_hash', wp_hash($dest_file), $dest_file, $post->ID);
                $hash = apply_filters($this->name.'/download_hash/type='.$post->post_type, $hash, $dest_file, $post->ID);
            }
        }

        if (!empty($field['allowed_file_types'])) {
            $array = preg_split('/\r\n|[\r\n]/', $field['allowed_file_types']);

            if (false !== $array) {
                // http://stackoverflow.com/a/8321709
                $array = array_flip(array_flip($array));

                $field['allowed_file_types'] = wp_json_encode($array);
            }
        }
        ?>
		<input type="hidden" name="<?php echo esc_attr($field['name']); ?>" value="<?php echo esc_attr(!empty($found) ? $field['value'] : ''); ?>">
		<div class="UppyFileInput"
			data-fieldName="<?php echo esc_attr($field['name']); ?>"
			data-max_file_size="<?php echo esc_attr((string) ($field['max_file_size'] * 1024 * 1024)); ?>"
			data-allowed_file_types="<?php echo esc_attr($field['allowed_file_types']); ?>">
		</div>
		<div class="UppyStatusBar"></div>
		<div class="UppyInformer"></div>
		<div class="UppyResponse">
			<?php if (!empty($found)) { ?>
				<a data-field-name="<?php echo esc_attr($field['name']); ?>" class="UppyDelete" href="javascript:;">
					<span class="dashicons dashicons-trash"></span>
				</a>
				<a href="<?php echo esc_url(home_url('/'.apply_filters($this->name.'/base_path', $this->env['slug']).'/download/'.trailingslashit($post->ID).trailingslashit($hash))); ?>"><?php echo esc_html($field['value']); ?></a> (<?php echo esc_html(size_format((int) filesize($dest_file), 2)); ?>)
				<?php
			}
        ?>
		</div>
		<?php
    }

    /**
     * Enqueues CSS and JavaScript needed by HTML in the render_field() method.
     *
     * Callback for admin_enqueue_script.
     */
    public function input_admin_enqueue_scripts(): void
    {
        global $post;

        $version = $this->env['version'];
        $url = trailingslashit($this->env['url']);
        $path = trailingslashit($this->env['path']);
        $cache_busting = $this->env['cache_busting'];

        $ext = '.js';
        $files = glob($path.'asset/js'.(!empty(WP_DEBUG) ? '' : '/min').'/npm/*'.$ext);

        if (false === $files) {
            // translators: %s: path
            throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), $path.'asset/js'.(!empty(WP_DEBUG) ? '' : '/min').'/npm/*'.$ext)));
        }

        foreach ($files as $file) {
            $part = 'asset/js'.(!empty(WP_DEBUG) ? '' : '/min').'/npm/'.basename($file, $ext);

            wp_register_script(
                FRUGAN_UFWUFACF_NAME.'-npm-'.basename($file, $ext),
                $url.$part.($cache_busting ? '.'.filemtime($path.$part.$ext) : '').$ext,
                ['acf-input'],
                $version,
                [
                    'in_footer' => true,
                ]
            );
            wp_enqueue_script(FRUGAN_UFWUFACF_NAME.'-npm-'.basename($file, $ext));
        }

        $files = glob($path.'asset/js'.(!empty(WP_DEBUG) ? '' : '/min').'/*'.$ext);

        if (false === $files) {
            // translators: %s: path
            throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), $path.'asset/js'.(!empty(WP_DEBUG) ? '' : '/min').'/*'.$ext)));
        }

        foreach ($files as $file) {
            $part = 'asset/js'.(!empty(WP_DEBUG) ? '' : '/min').'/'.basename($file, $ext);

            wp_register_script(
                FRUGAN_UFWUFACF_NAME.'-'.basename($file, '.js'),
                $url.$part.($cache_busting ? '.'.filemtime($path.$part.$ext) : '').$ext,
                ['acf-input'],
                $version,
                [
                    'in_footer' => true,
                ]
            );

            if ('main' === basename($file, $ext)) {
                // $object_name is the name of the variable which will contain the data.
                // Note that this should be unique to both the script and to the plugin or theme.
                // Thus, the value here should be properly prefixed with the slug or another unique value,
                // to prevent conflicts. However, as this is a JavaScript object name, it cannot contain dashes.
                // Use underscores or camelCasing.
                wp_localize_script(FRUGAN_UFWUFACF_NAME.'-'.basename($file, $ext), $this->name, [
                    'env' => $this->env,
                ]);
            }

            wp_enqueue_script(FRUGAN_UFWUFACF_NAME.'-'.basename($file, $ext));
        }

        $files = [
            'asset/js/locales/@uppy/'.get_locale().'.min'.$ext,
        ];

        foreach ($files as $file) {
            if (file_exists($path.$file)) {
                $part = trailingslashit(\dirname($file)).basename($file, $ext);

                wp_register_script(
                    FRUGAN_UFWUFACF_NAME.'-'.basename(\dirname($file)).'-'.basename($file, $ext),
                    $url.$part.($cache_busting ? '.'.filemtime($path.$part.$ext) : '').$ext,
                    ['acf-input'],
                    $version,
                    [
                        'in_footer' => true,
                    ]
                );
                wp_enqueue_script(FRUGAN_UFWUFACF_NAME.'-'.basename(\dirname($file)).'-'.basename($file, $ext));

                if ('@uppy' === basename(\dirname($file))) {
                    wp_add_inline_script(FRUGAN_UFWUFACF_NAME.'-'.basename(\dirname($file)).'-'.basename($file, $ext), 'window.Uppy.locales = []', 'before');
                }
            }
        }

        $ext = '.css';
        $files = glob($path.'asset/css'.(!empty(WP_DEBUG) ? '' : '/min').'/npm/*'.$ext);

        if (false === $files) {
            // translators: %s: path
            throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), $path.'asset/css'.(!empty(WP_DEBUG) ? '' : '/min').'/npm/*'.$ext)));
        }

        foreach ($files as $file) {
            $part = 'asset/css'.(!empty(WP_DEBUG) ? '' : '/min').'/npm/'.basename($file, $ext);

            wp_register_style(
                FRUGAN_UFWUFACF_NAME.'-npm-'.basename($file, $ext),
                $url.$part.($cache_busting ? '.'.filemtime($path.$part.$ext) : '').$ext,
                ['acf-input'],
                $version
            );
            wp_enqueue_style(FRUGAN_UFWUFACF_NAME.'-npm-'.basename($file, $ext));
        }

        $files = glob($path.'asset/css'.(!empty(WP_DEBUG) ? '' : '/min').'/*'.$ext);

        if (false === $files) {
            // translators: %s: path
            throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), $path.'asset/css'.(!empty(WP_DEBUG) ? '' : '/min').'/*'.$ext)));
        }

        foreach ($files as $file) {
            $part = 'asset/css'.(!empty(WP_DEBUG) ? '' : '/min').'/'.basename($file, $ext);

            wp_register_style(
                FRUGAN_UFWUFACF_NAME.'-'.basename($file, '.css'),
                $url.$part.($cache_busting ? '.'.filemtime($path.$part.$ext) : '').$ext,
                ['acf-input'],
                $version
            );
            wp_enqueue_style(FRUGAN_UFWUFACF_NAME.'-'.basename($file, $ext));
        }
    }

    public function validate_value($valid, mixed $value, $field, $input)
    {
        $value = sanitize_file_name($value);

        $postId = (int) ($_POST['post_ID'] ?? $_POST['post_id']);

        $postType = get_post_type($postId);

        $tmp_path = trailingslashit($this->env['tmp_path']).trailingslashit(sanitize_file_name($input));

        $dest_path = !empty($field['dest_path']) ? trailingslashit($field['dest_path']) : apply_filters($this->name.'/dest_path/type='.$postType, trailingslashit($this->defaults['dest_path']), $postId, $field);
        $dest_path .= trailingslashit((string) $postId).trailingslashit(sanitize_file_name($field['key']));

        if (!empty($field['required']) && empty($value)) {
            $valid = false;
        } elseif (!empty($value) && !file_exists($tmp_path.$value) && !file_exists($dest_path.$value)) {
            // Basic usage
            $valid = false;

            // Advanced usage
            // $valid = __('File doesn\'t exists!', 'upload-field-with-uppy-for-acf');
        }

        if (true === $valid && !empty($value)) {
            $paths = [];

            $paths['tmp'] = file_exists($tmp_path.$value) ? $tmp_path.$value : false;

            if (!empty($paths['tmp'])) {
                $pathinfo = pathinfo($value);

                $counter = 0;

                while (file_exists($dest_path.$value)) {
                    $value = apply_filters(
                        $this->name.'/file_name_exists',
                        $pathinfo['filename'].
                        '-'.
                        ++$counter.
                        (isset($pathinfo['extension']) ? '.'.$pathinfo['extension'] : ''),
                        $dest_path,
                        $pathinfo,
                        $counter
                    );
                }
            }

            $paths['dest'] = $dest_path.apply_filters($this->name.'/file_name', $value, $dest_path);

            $this->paths[] = $paths;
        }

        return $valid;
    }

    public function update_value(mixed $value, mixed $post_id, $field)
    {
        // ACF saves drafts without validation!
        // https://support.advancedcustomfields.com/forums/topic/is-it-possible-to-apply-validation-to-draft-post/
        // https://github.com/AdvancedCustomFields/acf/blob/master/includes/forms/form-post.php#L311
        if (!empty($value) && empty($this->paths)) {
            $postTypes = array_merge(apply_filters($this->name.'/custom_post_types', []), ['post', 'page']);

            if (\in_array(get_post_type($post_id), $postTypes, true)) {
                $post = get_post($post_id);

                if ('draft' === $post->post_status) {
                    acf_validate_save_post();
                }
            }
        }

        if (!empty($value) && !empty($this->paths)) {
            $value = sanitize_file_name($value);

            $paths = array_shift($this->paths);

            if (!empty($paths['tmp'])) {
                if (basename($paths['tmp']) !== $value) {
                    wp_die(
                        esc_html(\sprintf(
                            // translators: %1$s: tmp_path, %2$s: file
                            __('Wrong tmp_path "%1$s" of file "%2$s"', 'upload-field-with-uppy-for-acf'),
                            $paths['tmp'],
                            $value
                        )),
                        500,
                        ['back_link' => true]
                    );
                }
            }

            if (!empty($paths['dest'])) {
                $dest_path = \dirname($paths['dest']);
                $value = basename($paths['dest']);

                if (false === wp_mkdir_p($dest_path)) {
                    wp_die(
                        esc_html(\sprintf(
                            // translators: %1$s: dest_path
                            __('Error creating dest_path "%1$s"', 'upload-field-with-uppy-for-acf'),
                            $dest_path
                        )),
                        500,
                        ['back_link' => true]
                    );
                }

                if (!empty($paths['tmp'])) {
                    // https://wordpress.stackexchange.com/a/370377/99214
                    if (!\function_exists('WP_Filesystem_Direct')) {
                        require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-base.php';

                        require_once ABSPATH.'wp-admin/includes/class-wp-filesystem-direct.php';
                    }

                    $wpFilesystemDirect = new \WP_Filesystem_Direct(null);

                    if (false === $wpFilesystemDirect->move($paths['tmp'], $paths['dest'])) {
                        wp_die(
                            esc_html(\sprintf(
                                // translators: %1$s: tmp_path, %2$s: dest_path
                                __('Error moving file from "%1$s" to "%2$s"', 'upload-field-with-uppy-for-acf'),
                                $paths['tmp'],
                                $paths['dest']
                            )),
                            500,
                            ['back_link' => true]
                        );
                    }
                }
            }
        }

        return $value;
    }

    public function before_delete_post(int $post_id, \WP_Post $wpPost): void
    {
        if ('acf-field-group' === $wpPost->post_type) {
            return;
        }

        if ('acf-field' === $wpPost->post_type) {
            $field = get_field_object($wpPost->post_name, $post_id);

            if (!empty($field)) {
                require_once ABSPATH.'/wp-admin/includes/file.php';
                WP_Filesystem();

                global $wp_filesystem;

                $args = [
                    'post_type' => 'any', // retrieves any type except revisions and types with ‘exclude_from_search’ set to true.
                    'meta_key' => '_'.$field['name'],
                    'meta_value' => $field['key'],
                    'nopaging' => true,
                ];

                $query = new \WP_Query($args);

                if ($query->have_posts()) {
                    while ($query->have_posts()) {
                        $query->the_post();

                        $dest_path = !empty($field['dest_path']) ? trailingslashit($field['dest_path']) : apply_filters($this->name.'/dest_path/type='.get_post_type(), trailingslashit($this->defaults['dest_path']), get_the_ID(), $field);
                        $dest_path .= trailingslashit((string) get_the_ID()).trailingslashit(sanitize_file_name($field['key']));

                        if (is_dir($dest_path)) {
                            @$wp_filesystem->rmdir($dest_path, true);
                        }
                    }
                }

                wp_reset_postdata();
            }
        } else {
            $fieldsObj = get_field_objects($post_id);

            if (!empty($fieldsObj)) {
                $dest_paths = $this->get_dest_paths($fieldsObj, $post_id, false);

                if (!empty($dest_paths)) {
                    require_once ABSPATH.'/wp-admin/includes/file.php';
                    WP_Filesystem();

                    global $wp_filesystem;

                    foreach ($dest_paths as $dest_path) {
                        if (is_dir($dest_path)) {
                            @$wp_filesystem->rmdir($dest_path, true);
                        }
                    }
                }
            }
        }
    }

    public function save_post($postId): void
    {
        $fieldsObj = get_field_objects($postId);

        if (!empty($fieldsObj)) {
            $dest_paths = $this->get_dest_paths($fieldsObj, $postId);

            if (!empty($dest_paths)) {
                $dest_files = $this->get_dest_files($fieldsObj, $postId);

                foreach ($dest_paths as $dest_path) {
                    if (is_dir($dest_path)) {
                        $paths = glob(trailingslashit($dest_path).'*');

                        if (false === $paths) {
                            // translators: %s: path
                            throw new \RuntimeException(esc_html(\sprintf(__('Error reading "%1$s"', 'upload-field-with-uppy-for-acf'), trailingslashit($dest_path).'*')));
                        }

                        foreach ($paths as $path) {
                            if (is_file($path)) {
                                if (\in_array($path, $dest_files, true)) {
                                    continue;
                                }

                                wp_delete_file($path);
                            }
                        }
                    }
                }
            }
        }
    }

    public static function activate(): void
    {
        // https://andrezrv.com/2014/08/12/efficiently-flush-rewrite-rules-plugin-activation/
        flush_rewrite_rules();
        delete_option('rewrite_rules');
    }

    public static function deactivate($network_deactivating = false): void
    {
        // https://andrezrv.com/2014/08/12/efficiently-flush-rewrite-rules-plugin-activation/
        flush_rewrite_rules();
        delete_option('rewrite_rules');
    }

    public function get_sub_values(array $values, string $fieldName): array
    {
        $returns = [];

        array_walk_recursive($values, static function ($value, $key) use ($fieldName, &$returns): void {
            if ($key === $fieldName && !\is_array($value)) {
                $returns[] = $value;
            }
        });

        return $returns;
    }

    public function get_dest_files(array $fieldsObj, int $postId, array $values = []): array
    {
        $returns = [];

        if (!empty($fieldsObj)) {
            $postType = get_post_type($postId);

            foreach ($fieldsObj as $fieldObj) {
                if ($fieldObj['type'] === $this->name) {
                    $dest_path = !empty($fieldObj['dest_path']) ? trailingslashit($fieldObj['dest_path']) : apply_filters($this->name.'/dest_path/type='.$postType, trailingslashit($this->defaults['dest_path']), $postId, $fieldObj);
                    $dest_path .= trailingslashit((string) $postId);
                    $dest_path .= trailingslashit(sanitize_file_name($fieldObj['key']));

                    if (!empty($fieldObj['value'])) {
                        $returns[] = $dest_path.$fieldObj['value'];
                    } elseif (!empty($values)) {
                        foreach ($this->get_sub_values($values, $fieldObj['name']) as $value) {
                            $returns[] = $dest_path.$value;
                        }
                    }
                } elseif (!empty($fieldObj['sub_fields'])) {
                    if (!empty($fieldObj['value'])) {
                        $values = $fieldObj['value'];
                    }

                    $returns = array_merge($returns, $this->get_dest_files($fieldObj['sub_fields'], $postId, $values));
                }
            }
        }

        return $returns;
    }

    public function get_dest_paths(array $fieldsObj, int $postId, bool $fullPath = true): array
    {
        $returns = [];

        if (!empty($fieldsObj)) {
            $postType = get_post_type($postId);

            foreach ($fieldsObj as $fieldObj) {
                if ($fieldObj['type'] === $this->name) {
                    $dest_path = !empty($fieldObj['dest_path']) ? trailingslashit($fieldObj['dest_path']) : apply_filters($this->name.'/dest_path/type='.$postType, trailingslashit($this->defaults['dest_path']), $postId, $fieldObj);
                    $dest_path .= trailingslashit((string) $postId);

                    if ($fullPath) {
                        $dest_path .= trailingslashit(sanitize_file_name($fieldObj['key']));
                    }

                    $returns[] = $dest_path;
                } elseif (!empty($fieldObj['sub_fields'])) {
                    $returns = array_merge($returns, $this->get_dest_paths($fieldObj['sub_fields'], $postId, $fullPath));
                }
            }
        }

        // http://stackoverflow.com/a/8321709
        return array_flip(array_flip($returns));
    }

    public function log($level, $message, array $context = []): void
    {
        if ($this->is_wonolog_active()) {
            do_action('wonolog.log.'.$level, $message, $context);
        } else {
            if ($message instanceof \Throwable) {
                $message = $message->getMessage();
            } elseif (is_wp_error($message)) {
                $context['wp_error_data'] = $message->get_error_data();
                $message = $message->get_error_message();
            }

            if (\is_array($message)) {
                $message = 'Message: '.wp_json_encode($message);
            }

            if (!empty($context)) {
                $message .= ' | Context: '.wp_json_encode($context);
            }

            error_log($message);
        }
    }

    public function is_wonolog_active()
    {
        return \function_exists('did_action') && class_exists(Configurator::class) && \defined(Configurator::class.'::ACTION_SETUP') && did_action(Configurator::ACTION_SETUP);
    }
}
