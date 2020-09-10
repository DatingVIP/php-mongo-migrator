<?php

namespace Sokil\Mongo\Migrator;

use Sokil\Mongo\Client;
use Sokil\Mongo\Collection;
use Sokil\Mongo\Migrator\Event\Factory\EventFactory;
use Sokil\Mongo\Migrator\Event\Factory\EventFactoryInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Sokil\Mongo\Event\Manager\EventManagerInterface;

/**
 * Migration management
 */
class Manager
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Collection
     */
    private $logCollection;

    /**
     * @var array
     */
    private $appliedRevisions = array();

    /**
     * @var \Sokil\Mongo\Event\Manager\EventManagerInterface|NULL
     */
    private $eventManager;

    /**
     * @var \Sokil\Mongo\Migrator\Event\Factory\EventFactoryInterface
     */
    private $eventFactory;

    public function __construct(
        Config $config,
        EventManagerInterface $eventManger = null,
        EventFactoryInterface $eventFactory = null
    ) {
        $this->config = $config;

        $this->eventManager = $eventManger;

        $this->eventFactory = $eventFactory ?: new EventFactory();
    }


    /**
     * @param string $environment
     *
     * @return Client
     */
    private function getClient($environment)
    {
        if (empty($this->client[$environment])) {
            $this->client[$environment] = new Client(
                $this->config->getDsn($environment),
                $this->config->getConnectOptions($environment),
                $this->eventManager
            );

            $this->client[$environment]->useDatabase($this->config->getDefaultDatabaseName($environment));
        }


        return $this->client[$environment];
    }

    /**
     * @return string
     */
    public function getMigrationsDir()
    {
        $migrationsDir = $this->config->getMigrationsDir();

        return $migrationsDir;
    }

    /**
     * Creates directory for migrations
     */
    public function createMigrationsDir()
    {
        // create migrations dir
        $migrationsDirectory = $this->getMigrationsDir();

        if (!file_exists($migrationsDirectory)) {
            if (!mkdir($migrationsDirectory, 0755, true)) {
                throw new \Exception('Can\'t create migrations directory ' . $migrationsDirectory);
            }
        }
    }

    /**
     * @param int|null $limit If specified, get only last revisions
     *
     * @return Revision[]
     */
    public function getAvailableRevisions($limit = null)
    {
        if ($limit !==null && !is_integer($limit)) {
            throw new \InvalidArgumentException('Limit must be integer');
        }

        $list = [];

        foreach (new \DirectoryIterator($this->getMigrationsDir()) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            list($id, $className) = explode('_', $file->getBasename('.php'));

            $revision = new Revision();
            $revision
                ->setId($id)
                ->setName($className)
                ->setFilename($file->getFilename());

            $list[$id] = $revision;

            ksort($list);
        }

        if ($limit !== null) {
            $list = array_slice($list, -$limit);
        }

        return $list;
    }

    /**
     * @param string $environment
     *
     * @return Collection
     *
     * @throws \Sokil\Mongo\Exception
     */
    protected function getLogCollection($environment)
    {
        if ($this->logCollection) {
            return $this->logCollection;
        }

        $databaseName = $this->config->getLogDatabaseName($environment);
        $collectionName = $this->config->getLogCollectionName($environment);

        $this->logCollection = $this
            ->getClient($environment)
            ->getDatabase($databaseName)
            ->getCollection($collectionName);

        return $this->logCollection;
    }

    /**
     * @param string $revision
     * @param string $environment
     *
     * @return self
     *
     * @throws \Sokil\Mongo\Exception
     * @throws \Sokil\Mongo\Exception\WriteException
     */
    protected function logUp($revision, $environment)
    {
        $this
            ->getLogCollection($environment)
            ->createDocument(array(
                'revision'  => $revision,
                'date'      => new \MongoDate,
            ))
            ->save();

        return $this;
    }

    /**
     * @param string $revision
     * @param string $environment
     *
     * @return self
     *
     * @throws \Sokil\Mongo\Exception
     */
    protected function logDown($revision, $environment)
    {
        $collection = $this->getLogCollection($environment);
        $collection->batchDelete($collection->expression()->where('revision', $revision));

        return $this;
    }

    /**
     * @param string $environment
     *
     * @return array
     *
     * @throws \Sokil\Mongo\Exception
     */
    public function getAppliedRevisions($environment)
    {
        if (isset($this->appliedRevisions[$environment])) {
            return $this->appliedRevisions[$environment];
        }

        $documents = array_values(
            $this
                ->getLogCollection($environment)
                ->find()
                ->sort(array('revision' => 1))
                ->map(function ($document) {
                    return $document->revision;
                })
        );

        if (!$documents) {
            return array();
        }

        $this->appliedRevisions[$environment] = $documents;

        return $this->appliedRevisions[$environment];
    }

    /**
     * @param string $revision
     * @param string $environment
     *
     * @return bool
     *
     * @throws \Sokil\Mongo\Exception
     */
    public function isRevisionApplied($revision, $environment)
    {
        return in_array($revision, $this->getAppliedRevisions($environment));
    }

    /**
     * @param string $environment
     *
     * @return string
     *
     * @throws \Sokil\Mongo\Exception
     */
    protected function getLatestAppliedRevisionId($environment)
    {
        $revisions = $this->getAppliedRevisions($environment);
        return end($revisions);
    }

    /**
     * @param string $targetRevision
     * @param string $environment
     * @param string $direction
     *
     * @throws \Sokil\Mongo\Exception
     * @throws \Sokil\Mongo\Exception\WriteException
     */
    protected function executeMigration($targetRevision, $environment, $direction)
    {
        $this->triggerEvent($this->eventFactory->createStartEvent());

        // get last applied migration
        $latestRevisionId = $this->getLatestAppliedRevisionId($environment);

        // get list of migrations
        $availableRevisions = $this->getAvailableRevisions();

        // execute
        if ($direction === 1) {
            $this->triggerEvent($this->eventFactory->createBeforeMigrateEvent());

            ksort($availableRevisions);

            foreach ($availableRevisions as $revision) {
                if ($revision->getId() <= $latestRevisionId) {
                    continue;
                }

                $event = $this->eventFactory->createBeforeMigrateRevisionEvent();
                $event->setRevision($revision);
                $this->triggerEvent($event);

                $revisionPath = $this->getMigrationsDir() . '/' . $revision->getFilename();
                require_once $revisionPath;

                $className = $revision->getName();

                $migration = new $className(
                    $this->getClient($environment)
                );

                $migration->setEnvironment($environment);

                $migration->up();

                $this->logUp($revision->getId(), $environment);

                $event = $this->eventFactory->createMigrateRevisionEvent();
                $event->setRevision($revision);
                $this->triggerEvent($event);

                if ($targetRevision && in_array($targetRevision, array($revision->getId(), $revision->getName()))) {
                    break;
                }
            }

            $this->triggerEvent($this->eventFactory->createMigrateEvent());
        } else {
            $this->triggerEvent($this->eventFactory->createBeforeRollbackEvent());

            // check if nothing to revert
            if (!$latestRevisionId) {
                return;
            }

            krsort($availableRevisions);

            foreach ($availableRevisions as $revision) {
                if ($revision->getId() > $latestRevisionId) {
                    continue;
                }

                if ($targetRevision && in_array($targetRevision, array($revision->getId(), $revision->getName()))) {
                    break;
                }

                $event = $this->eventFactory->createBeforeRollbackRevisionEvent();
                $event->setRevision($revision);
                $this->triggerEvent($event);

                $revisionPath = $this->getMigrationsDir() . '/' . $revision->getFilename();
                require_once $revisionPath;

                $className = $revision->getName();

                $migration = new $className($this->getClient($environment));
                $migration->setEnvironment($environment);
                $migration->down();

                $this->logDown($revision->getId(), $environment);

                $event = $this->eventFactory->createRollbackRevisionEvent();
                $event->setRevision($revision);
                $this->triggerEvent($event);

                if (!$targetRevision) {
                    break;
                }
            }

            $this->triggerEvent($this->eventFactory->createRollbackEvent());
        }

        $this->triggerEvent($this->eventFactory->createStopEvent());

        // clear cached applied revisions
        unset($this->appliedRevisions[$environment]);
    }

    /**
     * @param string $revision
     * @param string $environment
     *
     * @return self
     *
     * @throws \Sokil\Mongo\Exception
     * @throws \Sokil\Mongo\Exception\WriteException
     */
    public function migrate($revision, $environment)
    {
        $this->executeMigration($revision, $environment, 1);

        return $this;
    }

    /**
     * @param string $revision
     * @param string $environment
     *
     * @return self
     *
     * @throws \Sokil\Mongo\Exception
     * @throws \Sokil\Mongo\Exception\WriteException
     */
    public function rollback($revision, $environment)
    {
        $this->executeMigration($revision, $environment, -1);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onStart($listener)
    {
        $this->attachEvent('start', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onBeforeMigrate($listener)
    {
        $this->attachEvent('before_migrate', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onBeforeMigrateRevision($listener)
    {
        $this->attachEvent('before_migrate_revision', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onMigrateRevision($listener)
    {
        $this->attachEvent('migrate_revision', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onMigrate($listener)
    {
        $this->attachEvent('migrate', $listener);

        return $this;
    }

    public function onBeforeRollback($listener)
    {
        $this->attachEvent('before_rollback', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onBeforeRollbackRevision($listener)
    {
        $this->attachEvent('before_rollback_revision', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onRollbackRevision($listener)
    {
        $this->attachEvent('rollback_revision', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onRollback($listener)
    {
        $this->attachEvent('rollback', $listener);

        return $this;
    }

    /**
     * @param callable $listener
     *
     * @return self
     */
    public function onStop($listener)
    {
        $this->attachEvent('stop', $listener);

        return $this;
    }

    /**
     * @return string
     */
    public function getDefaultEnvironment()
    {
        return $this->config->getDefaultEnvironment();
    }

    private function addListener()
    {
        if ($this->eventManager === null) {
            return $this;
        }
    }

    /**
     * Manually trigger defined events
     * @return \Psr\EventDispatcher\StoppableEventInterface
     */
    public function triggerEvent(StoppableEventInterface $event)
    {
        if ($this->eventManager === null) {
            return $event;
        }

        return $this->eventManager->dispatch($event);
    }

    /**
     * Attach event handler
     * @param string $event event name
     * @param callable|array|string $handler event handler
     */
    public function attachEvent($event, $handler, $priority = 0)
    {
        if ($this->eventManager !== null) {
            $this->eventManager->addListener($event, $handler, $priority);
        }
    }
}
