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

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use JP\Audit\Contracts\IpAddressResolver;
use JP\Audit\Contracts\UrlResolver;
use JP\Audit\Contracts\UserAgentResolver;
use JP\Audit\Contracts\UserResolver;
use JP\Audit\Exceptions\AuditableTransitionException;
use JP\Audit\Exceptions\AuditingException;
use JP\Audit\Events\Auditing;
use Illuminate\Support\Facades\Event;
trait Auditable
{
    /**
     * Auditable attributes excluded from the Audit.
     *
     * @var array
     */
    protected $excludedAttributes = [];

    /**
     * Audit event name.
     *
     * @var string
     */
    protected $auditEvent;

    /**
     * Is auditing disabled?
     *
     * @var bool
     */
    public static $auditingDisabled = false;

    public $relatedFields=[];

    /**
     * Auditable boot logic.
     *
     * @return void
     */
    public static function bootAuditable()
    {
        if (static::isAuditingEnabled()) {
            static::observe(new AuditableObserver());
            Event::listen(Auditing::class, function ($event) {
                $temp1 = $event->model;
                //print_r('<pre>');

                //$event->model->attributes['conferenceUnits']=json_encode([2,3]);
                //$event->model->original['conferenceUnits']=json_encode([]);
                //print_r($event->model->attributes);
               //print_r($event->model);
                $relations = $temp1->getRelations();
                foreach($relations as $key1=>$relation){
                    $relationValues=[];
                    foreach($relation as $rel){
                        $relationValues[]=$rel->id;
                    }
                    $event->model->original[$key1]=json_encode($relationValues);
                }
                $event->model->load($event->model->myRelations);
                foreach($event->model->getRelations() as $key2=>$relation2){
                    //$event->model->load($key2);
                    $relationValues=[];
                    foreach($event->model->getRelation($key2) as $rel3){
                        $relationValues[]=$rel3->id;
                    }
                    $event->model->attributes[$key2]=json_encode($relationValues);
                }
                //if($event->model->auditEvent!="created") dd($event->model);

                return true;
                //dd($event->model);
            });
        }
    }

    /**
     * {@inheritdoc}
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(
            Config::get('audit.implementation', Models\Audit::class),
            'auditable'
        );
    }

    /**
     * Resolve the Auditable attributes to exclude from the Audit.
     *
     * @return void
     */
    protected function resolveAuditExclusions()
    {
        $this->excludedAttributes = $this->getAuditExclude();

        // When in strict mode, hidden and non visible attributes are excluded
        if ($this->getAuditStrict()) {
            // Hidden attributes
            $this->excludedAttributes = array_merge($this->excludedAttributes, $this->hidden);

            // Non visible attributes
            if ($this->visible) {
                $invisible = array_diff(array_keys($this->attributes), $this->visible);

                $this->excludedAttributes = array_merge($this->excludedAttributes, $invisible);
            }
        }

        // Exclude Timestamps
        if (!$this->getAuditTimestamps()) {
            array_push($this->excludedAttributes, static::CREATED_AT, static::UPDATED_AT);

            if (defined('static::DELETED_AT')) {
                $this->excludedAttributes[] = static::DELETED_AT;
            }
        }

        // Valid attributes are all those that made it out of the exclusion array
        $attributes = array_except($this->attributes, $this->excludedAttributes);

        foreach ($attributes as $attribute => $value) {
            // Apart from null, non scalar values will be excluded
            if (is_object($value) && !method_exists($value, '__toString') || is_array($value)) {
                $this->excludedAttributes[] = $attribute;
            }
        }
    }

    /**
     * Get the old/new attributes of a retrieved event.
     *
     * @return array
     */
    protected function getRetrievedEventAttributes(): array
    {
        // This is a read event with no attribute changes,
        // only metadata will be stored in the Audit

        return [
            [],
            [],
        ];
    }

    /**
     * Get the old/new attributes of a created event.
     *
     * @return array
     */
    protected function getCreatedEventAttributes(): array
    {
        $new = [];

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $new[$attribute] = $value;
            }
        }

        return [
            [],
            $new,
        ];
    }

    /**
     * Get the old/new attributes of an updated event.
     *
     * @return array
     */
    protected function getUpdatedEventAttributes(): array
    {
        $old = [];
        $new = [];
        //$this->load('conferenceUnits');
//dd($this,$this->getDirty());
        foreach ($this->getDirty() as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                //print_r("auditable: ".$attribute.' ');
                $old[$attribute] = array_get($this->original, $attribute);
                $new[$attribute] = array_get($this->attributes, $attribute);
            }
        }

        return [
            $old,
            $new,
        ];
    }

    /**
     * Get the old/new attributes of a deleted event.
     *
     * @return array
     */
    protected function getDeletedEventAttributes(): array
    {
        $old = [];

        foreach ($this->attributes as $attribute => $value) {
            if ($this->isAttributeAuditable($attribute)) {
                $old[$attribute] = $value;
            }
        }

        return [
            $old,
            [],
        ];
    }

    /**
     * Get the old/new attributes of a restored event.
     *
     * @return array
     */
    protected function getRestoredEventAttributes(): array
    {
        // A restored event is just a deleted event in reverse
        return array_reverse($this->getDeletedEventAttributes());
    }

    /**
     * {@inheritdoc}
     */
    public function readyForAuditing(): bool
    {
        if (static::$auditingDisabled) {
            return false;
        }

        return $this->isEventAuditable($this->auditEvent);
    }

    /**
     * {@inheritdoc}
     */
    public function toAudit(): array
    {
        if (!$this->readyForAuditing()) {
            throw new AuditingException('A valid audit event has not been set');
        }

        $attributeGetter = $this->resolveAttributeGetter($this->auditEvent);

        if (!method_exists($this, $attributeGetter)) {
            throw new AuditingException(sprintf(
                'Unable to handle "%s" event, %s() method missing',
                $this->auditEvent,
                $attributeGetter
            ));
        }

        $this->resolveAuditExclusions();

        list($old, $new) = call_user_func([$this, $attributeGetter]);
        $userForeignKey = Config::get('audit.user.foreign_key', 'user_id');

        $tags = implode(',', $this->generateTags());
            if(empty($old) && empty($new) && $this->auditEvent!=='restored') return [];
        return $this->transformAudit([
            'old_values'     => $old,
            'new_values'     => $new,
            'event'          => $this->auditEvent,
            'auditable_id'   => $this->getKey(),
            'auditable_type' => $this->getMorphClass(),
            $userForeignKey  => $this->resolveUser(),
            'url'            => $this->resolveUrl(),
            'ip_address'     => $this->resolveIpAddress(),
            'user_agent'     => $this->resolveUserAgent(),
            'tags'           => empty($tags) ? null : $tags,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function transformAudit(array $data): array
    {
        return $data;
    }

    /**
     * Resolve the User.
     *
     * @throws AuditingException
     *
     * @return mixed|null
     */
    protected function resolveUser()
    {
        $userResolver = Config::get('audit.resolver.user');

        if (is_subclass_of($userResolver, UserResolver::class)) {
            return call_user_func([$userResolver, 'resolve']);
        }

        throw new AuditingException('Invalid UserResolver implementation');
    }

    /**
     * Resolve the URL.
     *
     * @throws AuditingException
     *
     * @return string
     */
    protected function resolveUrl(): string
    {
        $urlResolver = Config::get('audit.resolver.url');

        if (is_subclass_of($urlResolver, UrlResolver::class)) {
            return call_user_func([$urlResolver, 'resolve']);
        }

        throw new AuditingException('Invalid UrlResolver implementation');
    }

    /**
     * Resolve the IP Address.
     *
     * @throws AuditingException
     *
     * @return string
     */
    protected function resolveIpAddress(): string
    {
        $ipAddressResolver = Config::get('audit.resolver.ip_address');

        if (is_subclass_of($ipAddressResolver, IpAddressResolver::class)) {
            return call_user_func([$ipAddressResolver, 'resolve']);
        }

        throw new AuditingException('Invalid IpAddressResolver implementation');
    }

    /**
     * Resolve the User Agent.
     *
     * @throws AuditingException
     *
     * @return string|null
     */
    protected function resolveUserAgent()
    {
        $userAgentResolver = Config::get('audit.resolver.user_agent');

        if (is_subclass_of($userAgentResolver, UserAgentResolver::class)) {
            return call_user_func([$userAgentResolver, 'resolve']);
        }

        throw new AuditingException('Invalid UserAgentResolver implementation');
    }

    /**
     * Determine if an attribute is eligible for auditing.
     *
     * @param string $attribute
     *
     * @return bool
     */
    protected function isAttributeAuditable(string $attribute): bool
    {
        // The attribute should not be audited

        if (in_array($attribute, $this->excludedAttributes)) {

            return false;
        }

        // The attribute is auditable when explicitly
        // listed or when the include array is empty
        $include = $this->getAuditInclude();

        return in_array($attribute, $include) || empty($include);
    }

    /**
     * Determine whether an event is auditable.
     *
     * @param string $event
     *
     * @return bool
     */
    protected function isEventAuditable($event): bool
    {
        return is_string($this->resolveAttributeGetter($event));
    }

    /**
     * Attribute getter method resolver.
     *
     * @param string $event
     *
     * @return string|null
     */
    protected function resolveAttributeGetter($event)
    {

        foreach ($this->getAuditEvents() as $key => $value) {
            $auditableEvent = is_int($key) ? $value : $key;

            $auditableEventRegex = sprintf('/%s/', preg_replace('/\*+/', '.*', $auditableEvent));

            if (preg_match($auditableEventRegex, $event)) {

                return is_int($key) ? sprintf('get%sEventAttributes', ucfirst($event)) : $value;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setAuditEvent(string $event): Contracts\Auditable
    {
        $this->auditEvent = $this->isEventAuditable($event) ? $event : null;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditEvent()
    {
        return $this->auditEvent;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditEvents(): array
    {
        return $this->auditEvents ?? Config::get('audit.events', [
            'created',
            'updated',
            'deleted',
            'restored',
        ]);
    }

    /**
     * Disable Auditing.
     *
     * @return void
     */
    public static function disableAuditing()
    {
        static::$auditingDisabled = true;
    }

    /**
     * Enable Auditing.
     *
     * @return void
     */
    public static function enableAuditing()
    {
        static::$auditingDisabled = false;
    }

    /**
     * Determine whether auditing is enabled.
     *
     * @return bool
     */
    public static function isAuditingEnabled(): bool
    {
        if (App::runningInConsole()) {
            return Config::get('audit.console', false);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditInclude(): array
    {
        return $this->auditInclude ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditExclude(): array
    {
        return $this->auditExclude ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditStrict(): bool
    {
        return $this->auditStrict ?? Config::get('audit.strict', false);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditTimestamps(): bool
    {
        return $this->auditTimestamps ?? Config::get('audit.timestamps', false);
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditDriver()
    {
        return $this->auditDriver ?? Config::get('audit.driver', 'database');
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditThreshold(): int
    {
        return $this->auditThreshold ?? Config::get('audit.threshold', 0);
    }

    /**
     * {@inheritdoc}
     */
    public function generateTags(): array
    {
        return [];
    }


    public function rollbackTo(int $version){
        $list = $this->audits()->where('id','>=',$version)->get()->reverse();

        $rollback=$this;

        foreach($list as $audit){//take new only in the last version
            $rollback = $this->transitionTo($audit,$version!=$audit->id);

        }
        return $rollback;
    }

    /**
     * {@inheritdoc}
     */
    public function transitionTo(Contracts\Audit $audit, bool $old = false): Contracts\Auditable
    {
        // The Audit must be for an Auditable model of this type
        if ($this->getMorphClass() !== $audit->auditable_type) {
            throw new AuditableTransitionException(sprintf(
                'Expected Auditable type %s, got %s instead',
                $this->getMorphClass(),
                $audit->auditable_type
            ));
        }

        // The Audit must be for this specific Auditable model
        if ($this->getKey() !== $audit->auditable_id) {
            throw new AuditableTransitionException(sprintf(
                'Expected Auditable id %s, got %s instead',
                $this->getKey(),
                $audit->auditable_id
            ));
        }

        // The attribute compatibility between the Audit and the Auditable model must be met
        $modified = $audit->getModified();
        $attributes = $this->getAttributes();
        if(isset($this->myRelations) && !empty($this->myRelations)){

            $this->load($this->myRelations);

            foreach($this->getRelations() as $key=>$relation){
                if(!isset($this->attributes[$key])){
                    $relationValues=[];

                    foreach($this->getRelation($key) as $rel){
                        $relationValues[]=$rel->id;
                    }
                    $attributes[$key]=json_encode($relationValues);
                    //if(!isset($this->attributes[$key]))
                    $this->setAttribute($key,$attributes[$key]);
                }

            }
        }

        if ($incompatibilities = array_diff_key($modified,$attributes )) {
            //dd($attributes,$modified,$this->getAttributes(),$incompatibilities);
            throw new AuditableTransitionException(sprintf(
                'Incompatibility between [%s:%s] and [%s:%s]',
                $this->getMorphClass(),
                $this->getKey(),
                get_class($audit),
                $audit->getKey()
            ), array_keys($incompatibilities));
        }

        $key = $old ? 'old' : 'new';

        foreach ($modified as $attribute => $value) {
            if (array_key_exists($key, $value)) {
                $this->setAttribute($attribute, $value[$key]);
            }
        }

        return $this;
    }
}
