<?php
/**
 * This file is part of the Laravel Auditing package.
 *
 * @author     Antério Vieira <anteriovieira@gmail.com>
 * @author     Quetzy Garcia  <quetzyg@altek.org>
 * @author     Raphael França <raphaelfrancabsb@gmail.com>
 * @copyright  2015-2018
 *
 * For the full copyright and license information,
 * please view the LICENSE.md file that was distributed
 * with this source code.
 */

namespace JP\Audit\Resolvers;

use Illuminate\Support\Facades\Request;

class UserAgentResolver implements \JP\Audit\Contracts\UserAgentResolver
{
    /**
     * {@inheritdoc}
     */
    public static function resolve()
    {
        return Request::userAgent();
    }
}
