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

namespace JP\Audit\Contracts;

interface AuditDriver
{
    /**
     * Perform an audit.
     *
     * @param \JP\Audit\Contracts\Auditable $model
     *
     * @return \JP\Audit\Contracts\Audit | boolean
     */
    public function audit(Auditable $model);

    /**
     * Remove older audits that go over the threshold.
     *
     * @param \JP\Audit\Contracts\Auditable $model
     *
     * @return bool
     */
    public function prune(Auditable $model): bool;
}
