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

namespace JP\Audit;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use JP\Audit\Contracts\Auditable;
use JP\Audit\Contracts\AuditDriver;
use JP\Audit\Drivers\Database;
use JP\Audit\Events\Audited;
use JP\Audit\Events\Auditing;
use JP\Audit\Exceptions\AuditingException;

class Auditor extends Manager implements Contracts\Auditor
{
    /**
     * {@inheritdoc}
     */
    public function getDefaultDriver()
    {
        return 'database';
    }

    /**
     * {@inheritdoc}
     */
    protected function createDriver($driver)
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException $exception) {
            if (class_exists($driver)) {
                return $this->app->make($driver);
            }

            throw $exception;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function auditDriver(Auditable $model): AuditDriver
    {
        $driver = $this->driver($model->getAuditDriver());

        if (!$driver instanceof AuditDriver) {
            throw new AuditingException('The driver must implement the AuditDriver contract');
        }

        return $driver;
    }

    /**
     * {@inheritdoc}
     */
    public function execute(Auditable $model)
    {
        if (!$model->readyForAuditing()) {
            return;
        }

        $driver = $this->auditDriver($model);

        if (!$this->fireAuditingEvent($model, $driver)) {
            return;
        }

        if ($audit = $driver->audit($model)) {
            $driver->prune($model);
        }

        $this->app->make('events')->fire(
            new Audited($model, $driver, $audit)
        );
    }

    /**
     * Create an instance of the Database audit driver.
     *
     * @return \JP\Audit\Drivers\Database
     */
    protected function createDatabaseDriver(): Database
    {
        return $this->app->make(Database::class);
    }

    /**
     * Fire the Auditing event.
     *
     * @param \JP\Audit\Contracts\Auditable   $model
     * @param \JP\Audit\Contracts\AuditDriver $driver
     *
     * @return bool
     */
    protected function fireAuditingEvent(Auditable $model, AuditDriver $driver): bool
    {
        return $this->app->make('events')->until(
            new Auditing($model, $driver)
        ) !== false;
    }
}
