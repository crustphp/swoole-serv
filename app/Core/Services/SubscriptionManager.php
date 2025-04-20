<?php

namespace App\Core\Services;

use Bootstrap\SwooleTableFactory;

class SubscriptionManager
{
    protected $subscriptionTable = null;
    protected $swooleTableName = 'topic_subscribers';

    // $topicFds and $fdTopics Properties are used for Worker Managing their own subscription
    private static $topicFds = [];
    private static $fdTopics = [];

    private $server = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->subscriptionTable = SwooleTableFactory::getTable($this->swooleTableName);

        // Set the Worker ID
        $this->server = $GLOBALS['global_server'] ?? null;
    }

    /**
     * Subscribe an FD to topic.
     *
     * @param int $fd The FD of the subscriber.
     * @param string $topic The name of the topic the FD is subscribing to.
     * @param int $worker_id The worker ID of the FD.
     * @return mixed 1 in-case of success, -1 if FD is already subscribed to topic and false in-case of Failure
     */
    public function subscribe(int $fd, string $topic, int $worker_id): mixed
    {
        $key = $this->generateKey($fd, $topic);

        // Check if the FD is already subscribed
        if ($this->subscriptionTable->exists($key)) {
            return -1;
        }

        // Subscribe the FD to Table
        $subscribed = $this->subscriptionTable->set($key, [
            'topic' => $topic,
            'fd' => $fd,
            'worker_id' => $worker_id,
        ]);

        if ($subscribed) {
            // Subscribe the FD to Worker
            //- Map the Topics to FD
            self::$fdTopics[$fd][$topic] = $topic;

            //- Map the FDs to Topics
            self::$topicFds[$topic][$fd] = &self::$fdTopics[$fd];

            return 1;
        }

        // Return false in-case of failure
        return false;
    }

    /**
     * Unsubscribe an FD from a specific topic.
     *
     * @param int $fd The FD of the subscriber.
     * @param string $topic The name of the topic the FD is unsubscribing from.
     * @return bool 
     */
    public function unsubscribe(int $fd, string $topic): bool
    {
        $key = $this->generateKey($fd, $topic);

        // Check if the key exists in the table
        if (!$this->subscriptionTable->exists($key)) {
            return false;
        }

        // Un-subscribe the FD from Worker
        unset(self::$topicFds[$topic][$fd]);
        unset(self::$fdTopics[$fd][$topic]);

        // If the topic has no more subscribers, remove it entirely
        if (empty(self::$topicFds[$topic])) {
            unset(self::$topicFds[$topic]);
        }

        // If the FD has no more topic subscriptions, remove it entirely
        if (empty(self::$fdTopics[$fd])) {
            unset(self::$fdTopics[$fd]);
        }

        // Unsubscribe from Swoole Table
        return $this->subscriptionTable->del($key);
    }

    /**
     * Get all the data of Topic Subscription Table
     *
     * @return mixed
     */
    public function getAllSubscriptionData(): mixed
    {
        return SwooleTableFactory::getSwooleTableData($this->swooleTableName);
    }

    /**
     * Get the FDs subscribed to specified Topic, optionally filter by Worker ID
     *
     * @param string $topic The name of the topic.
     * @param bool $fdsOnly If true, returns only an array of FDs; if false, returns all data of the row.
     * @param int|null $worker_id Optional worker ID to filter the FDs for provided worker
     * @return array 
     */
    public function getFdsOfTopic(string $topic, bool $fdsOnly = true, ?int $worker_id = null): array
    {
        // Prefer to get the data from the worker scope
        if ($fdsOnly && is_null($worker_id)) {
            return isset(self::$topicFds[$topic]) ? array_keys(self::$topicFds[$topic]) : [];
        }

        $subscribedData = [];
        $subscriptionSwooleTable = $this->subscriptionTable->getSwooleTable();

        foreach ($subscriptionSwooleTable as $row) {
            if ($row['topic'] === $topic && ($worker_id === null || $row['worker_id'] === $worker_id)) {
                if ($fdsOnly) {
                    $subscribedData[] = $row['fd'];
                } else {
                    $subscribedData[] = [
                        'topic' => $row['topic'],
                        'fd' => $row['fd'],
                        'worker_id' => $row['worker_id'],
                    ];
                }
            }
        }

        return $subscribedData;
    }

    /**
     * Get all topics that a specific FD has subscribed to.
     *
     * @param int $fd The file descriptor (FD).
     * @param bool $fdsOnly If true, returns only an array of FDs; if false, returns all data of the row.
     * @return array
     */
    public function getTopicsOfFD(int $fd, bool $fdsOnly = true): array
    {
        // Prefer to get the data from the worker scope
        if ($fdsOnly) {
            return isset(self::$fdTopics[$fd]) ? array_keys(self::$fdTopics[$fd]) : [];
        }

        $subscribedTopics = [];
        $subscriptionSwooleTable = $this->subscriptionTable->getSwooleTable();

        foreach ($subscriptionSwooleTable as $row) {
            if ($row['fd'] === $fd) {
                $subscribedTopics[] = [
                    'topic' => $row['topic'],
                    'fd' => $row['fd'],
                    'worker_id' => $row['worker_id'],
                ];
            }
        }

        return $subscribedTopics;
    }


    /**
     * Manage subscriptions (Subscribe/Unsubscribe) for an FD.
     *
     * @param int $fd The FD of the client.
     * @param array $subscribeTopics Topics to subscribe to.
     * @param array $unsubscribeTopics Topics to unsubscribe from.
     * @param int $worker_id The worker ID of the FD.
     * @return array Returns an associative array with results of subscriptions and unsubscriptions.
     */
    public function manageSubscriptions(int $fd, array $subscribeTopics, array $unsubscribeTopics, int $worker_id): array
    {
        $subscriptionResults = [
            'subscribed' => [],
            'already_subscribed' => [],
            'unsubscribed' => [],
            'errors' => [],
        ];

        // Handle subscriptions
        foreach ($subscribeTopics as $topic) {
            $subscribed = $this->subscribe($fd, $topic, $worker_id);
            if ($subscribed == -1) {
                $subscriptionResults['already_subscribed'][] = $topic;
            } else if ($subscribed) {
                $subscriptionResults['subscribed'][] = $topic;
            } else {
                $subscriptionResults['errors'][] = "Failed subscribed to topic: $topic";
            }
        }

        // Handle unsubscriptions
        foreach ($unsubscribeTopics as $topic) {
            if ($this->unsubscribe($fd, $topic)) {
                $subscriptionResults['unsubscribed'][] = $topic;
            } else {
                $subscriptionResults['errors'][] = "Failed to un-subscribed from topic: $topic";
            }
        }

        return $subscriptionResults;
    }

    /**
     * Remove all subscriptions for a specific FD.
     *
     * @param int $fd The FD to remove subscriptions for.
     * @return bool 
     */
    public function removeSubscriptionsForFD(int $fd): bool
    {
        $removed = false;

        // Remove from Swoole Table
        $fdTopics = $this->getTopicsOfFD($fd);
        foreach ($fdTopics as $topic) {
            $removed = $this->subscriptionTable->del($this->generateKey($fd, $topic));
        }

        // Remove the subscriptions from the Worker Scope.
        if (isset(self::$fdTopics[$fd])) {
            foreach (self::$fdTopics[$fd] as $topic) {
                unset(self::$topicFds[$topic][$fd]);

                // If no FDs are left in this topic, remove the topic entirely
                if (empty(self::$topicFds[$topic])) {
                    unset(self::$topicFds[$topic]);
                }
            }

            // Remove all topic references for this FD
            unset(self::$fdTopics[$fd]);
        }

        return $removed;
    }

    /**
     * Check if an FD is subscribed to a specific topic.
     *
     * @param int $fd The FD of the subscriber.
     * @param string $topic The name of the topic to check subscription for.
     * @return bool 
     */
    public function isSubscribed(int $fd, string $topic): bool
    {
        // Prefer to get the data from worker scope
        return isset(self::$fdTopics[$fd][$topic]);

        // Old Code to Fetch from Table
        // $key = $this->generateKey($fd, $topic);
        // return $this->subscriptionTable->exists($key);
    }

    /**
     * This function creates the Key of record for Subscription's Swoole Table
     *
     * @param  int $fd
     * @param  string $topic
     * @return string
     */
    public function generateKey(int $fd, string $topic): string
    {
        return "{$topic}:{$fd}";
    }

    /**
     * This function returns the data of subscription manager stored in Worker Scope
     *
     * @return mixed
     */
    public function getWorkerScopeData()
    {
        $topicFds = [];

        foreach (self::$topicFds as $topic => $fdTopics) {
            $topicFds[$topic] = array_keys(self::$topicFds[$topic]);
        }

        return [
            'worker_id' => $this->server->worker_id,
            'topic_fds' => $topicFds,
            'topic_fds_real' => self::$topicFds,
            'fds_topics' => self::$fdTopics,
        ];
    }

    /**
     * This function restores the worker scope data from the swoole table
     *
     * @return void
     */
    public function restoreSubscriptionsFromTable(): void
    {
        // Reset the Worker Scope Data
        self::$topicFds = [];
        self::$fdTopics = [];

        // Backup the data from the Swoole Table
        $subscriptionsSwooleTable = $this->subscriptionTable->getSwooleTable();

        foreach ($subscriptionsSwooleTable as $row) {
            if ($row['worker_id'] == $this->server->worker_id) {
                self::$topicFds[$row['topic']][$row['fd']] = $row['fd'];
                self::$fdTopics[$row['fd']][$row['topic']] = $row['topic'];
            }
        }
    }
}
