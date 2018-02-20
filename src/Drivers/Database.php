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

namespace JP\Audit\Drivers;

use Illuminate\Support\Facades\Config;
use JP\Audit\Contracts\Audit;
use JP\Audit\Contracts\Auditable;
use JP\Audit\Contracts\AuditDriver;

class Database implements AuditDriver
{
    /**
     * {@inheritdoc}
     */
    public function audit(Auditable $model): Audit
    {
        $implementation = Config::get('audit.implementation', \JP\Audit\Models\Audit::class);

        return call_user_func([$implementation, 'create'], $model->toAudit());
    }

    /**
     * {@inheritdoc}
     */
    public function prune(Auditable $model): bool
    {
        if (($threshold = $model->getAuditThreshold()) > 0) {
            $forRemoval = $model->audits()
                ->latest()
                ->get()
                ->slice($threshold)
                ->pluck('id');

            if ($forRemoval->isNotEmpty()) {
                return $model->audits()
                    ->whereIn('id', $forRemoval)
                    ->delete() > 0;
            }
        }

        return false;
    }
}
