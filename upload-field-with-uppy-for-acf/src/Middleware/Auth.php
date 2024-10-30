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

class Auth implements TusMiddleware
{
    /**
     * @throws \Exception
     */
    public function handle(Request $request, Response $response): void
    {
        if (!is_user_logged_in()) {
            throw new \Exception(esc_html__('Unauthorized', 'upload-field-with-uppy-for-acf'));
        }
    }
}
