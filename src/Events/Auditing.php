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

namespace JP\Audit\Events;

use JP\Audit\Contracts\Auditable;
use JP\Audit\Contracts\AuditDriver;

class Auditing
{
    /**
     * The Auditable model.
     *
     * @var \JP\Audit\Contracts\Auditable
     */
    public $model;

    /**
     * Audit driver.
     *
     * @var \JP\Audit\Contracts\AuditDriver
     */
    public $driver;

    /**
     * Create a new Auditing event instance.
     *
     * @param \JP\Audit\Contracts\Auditable   $model
     * @param \JP\Audit\Contracts\AuditDriver $driver
     */
    public function __construct(Auditable $model, AuditDriver $driver)
    {
        $this->model = $model;
        $this->driver = $driver;
    }
}
