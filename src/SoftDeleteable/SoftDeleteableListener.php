<?php

namespace Gedmo\SoftDeleteable;

use Gedmo\Mapping\MappedEventSubscriber;
use Doctrine\Common\EventArgs;
use Doctrine\ODM\MongoDB\UnitOfWork as MongoDBUnitOfWork;

/**
 * SoftDeleteable listener
 *
 * @author Gustavo Falco <comfortablynumb84@gmail.com>
 * @author Gediminas Morkevicius <gediminas.morkevicius@gmail.com>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SoftDeleteableListener extends MappedEventSubscriber
{
    /**
     * Pre soft-delete event
     *
     * @var string
     */
    const PRE_SOFT_DELETE = "preSoftDelete";

    /**
     * Post soft-delete event
     *
     * @var string
     */
    const POST_SOFT_DELETE = "postSoftDelete";

    /**
     * Objects soft-deleted on flush.
     *
     * @var array
     */
    private $softDeletedObjects = array();

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            'loadClassMetadata',
            'onFlush',
            'postFlush',
        );
    }

    /**
     * If it's a SoftDeleteable object, update the "deletedAt" field
     * and skip the removal of the object
     *
     * @param EventArgs $args
     *
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        $evm = $om->getEventManager();

        //getScheduledDocumentDeletions
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            $config = $this->getConfiguration($om, $meta->name);

            if (isset($config['softDeleteable']) && $config['softDeleteable']) {
                $reflProp = $meta->getReflectionProperty($config['fieldName']);
                $oldValue = $reflProp->getValue($object);
                
                // Determine the field type in order to set the date to the respective value
                $mapping = $meta->getFieldMapping($config['fieldName']);
                if (isset($mapping['type']) && $mapping['type'] === 'integer') {
                    $date = time();
                } else if (isset($mapping['type']) && $mapping['type'] === 'zenddate') {
                    $date = new \Zend_Date();
                } else if (isset($mapping['type']) && in_array($mapping['type'], array('date_immutable', 'time_immutable', 'datetime_immutable', 'datetimetz_immutable'), true)) {
                    $date = new \DateTimeImmutable();
                } else {
                    $date = new \DateTime();
                }

                // Remove `$oldValue instanceof \DateTime` check when PHP version is bumped to >=5.5
                if (isset($config['hardDelete']) && $config['hardDelete'] && ($oldValue instanceof \DateTime || $oldValue instanceof \DateTimeInterface) && $oldValue <= $date) {
                    continue; // want to hard delete
                }

                $evm->dispatchEvent(
                    self::PRE_SOFT_DELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );


                $reflProp->setValue($object, $date);

                $om->persist($object);
                $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
                if ($uow instanceof MongoDBUnitOfWork && !method_exists($uow, 'scheduleExtraUpdate')) {
                    $ea->recomputeSingleObjectChangeSet($uow, $meta, $object);
                } else {
                    $uow->scheduleExtraUpdate($object, array(
                        $config['fieldName'] => array($oldValue, $date),
                    ));
                }

                $evm->dispatchEvent(
                    self::POST_SOFT_DELETE,
                    $ea->createLifecycleEventArgsInstance($object, $om)
                );

                $this->softDeletedObjects[] = $object;
            }
        }
    }

    /**
     * Detach soft-deleted objects from object manager.
     *
     * @param \Doctrine\Common\EventArgs $args
     *
     * @return void
     *
     * @throws \Gedmo\Exception\InvalidArgumentException
     */
    public function postFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        foreach ($this->softDeletedObjects as $index => $object) {
            $om->detach($object);
            unset($this->softDeletedObjects[$index]);
        }
    }

    /**
     * Maps additional metadata
     *
     * @param EventArgs $eventArgs
     *
     * @return void
     */
    public function loadClassMetadata(EventArgs $eventArgs)
    {
        $ea = $this->getEventAdapter($eventArgs);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $eventArgs->getClassMetadata());
    }

    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}
