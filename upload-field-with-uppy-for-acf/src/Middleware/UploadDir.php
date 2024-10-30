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

namespace FruganUFWUFACF\Middleware;

use TusPhp\Middleware\TusMiddleware;
use TusPhp\Request;
use TusPhp\Response;
use TusPhp\Tus\Server;

class UploadDir implements TusMiddleware
{
    /**
     * @var array{version:string,fieldType:string,url:string,path:string,dest_path:string,tmp_path:string,symlink_url:string,symlink_path:string,cache_ttl:string}
     */
    public $settings;

    /**
     * @var Server
     */
    public $server;

    /**
     * @param array{version:string,fieldType:string,url:string,path:string,dest_path:string,tmp_path:string,symlink_url:string,symlink_path:string,cache_ttl:string} $settings
     */
    public function __construct(
        array $settings,
        Server $server
    ) {
        $this->settings = $settings;
        $this->server = $server;
    }

    /**
     * @throws \Exception
     */
    public function handle(Request $request, Response $response): void
    {
        if ('GET' !== $request->method()) {
            $fieldName = $request->header('Field-Name');

            if (null === $fieldName) {
                throw new \Exception(esc_html__('Wrong headers', 'upload-field-with-uppy-for-acf'));
            }

            $fieldName = sanitize_file_name($fieldName);

            $this->server->setUploadDir(trailingslashit($this->settings['tmp_path']).$fieldName);

            if (!is_dir($this->server->getUploadDir())) {
                wp_mkdir_p($this->server->getUploadDir());

                // https://github.com/ankitpokhrel/tus-php/issues/102
                /*
                $cacheKeys = $this->server->getCache()->keys();
                //$this->server->getCache()->deleteAll( $cacheKeys );

                foreach( $cacheKeys as $cacheKey ) {

                    if ( $oldFileMeta = $this->server->getCache()->get($cacheKey) ) {

                        if( preg_match('~'.preg_quote( '/'.get_current_user_id().'/'.$fieldName.'/' ).'~', $oldFileMeta['file_path'] ) ) {
                            $this->server->getCache()->delete( $cacheKey );
                        }
                    }
                }*/
            }
        }
    }
}
