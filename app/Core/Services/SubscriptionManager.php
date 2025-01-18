<?php

namespace App\Core\Services;

use Bootstrap\SwooleTableFactory;

use Crust\SwooleDb\Selector\TableSelector;
use Crust\SwooleDb\Selector\Enum\ConditionElementType;
use Crust\SwooleDb\Selector\Enum\ConditionOperator;
use Crust\SwooleDb\Selector\Bean\ConditionElement;
use Crust\SwooleDb\Selector\Bean\Condition;

class SubscriptionManager
{
    protected $subscriptionTable = null;
    protected $swooleTableName = 'topic_subscribers';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->subscriptionTable = SwooleTableFactory::getTable($this->swooleTableName);
    }

    /**
     * Subscribe an FD to topic.
     *
     * @param int $fd The FD of the subscriber.
     * @param string $topic The name of the topic the FD is subscribing to.
     * @param int $worker_id The worker ID of the FD.
     * @return mixed 1 in-case of success, -1 if FD is already subscribed to topic and false in-case of Failure
     */
    public function subscribe(int $fd, mixed $topic, int $worker_id): mixed
    {
        $key = $this->generateKey($fd, $topic);

        // Check if the FD is already subscribed
        if ($this->subscriptionTable->exists($key)) {
            return -1;
        }

        // Subscribe the FD
        $subscribed = $this->subscriptionTable->set($key, [
            'topic' => $topic,
            'fd' => $fd,
            'worker_id' => $worker_id,
        ]);

        if ($subscribed) {
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

        return $this->subscriptionTable->del($key);
    }

    /**
     * Get all the data of Topic Subscription Table
     *
     * @return mixed
     */
    public function getAllSubsciptionData(): mixed
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
        $subscribedTopics = [];
        $subscriptionSwooleTable = $this->subscriptionTable->getSwooleTable();

        foreach ($subscriptionSwooleTable as $row) {
            if ($row['fd'] === $fd) {
                if ($fdsOnly) {
                    $subscribedTopics[] = $row['topic'];
                } else {
                    $subscribedTopics[] = [
                        'topic' => $row['topic'],
                        'fd' => $row['fd'],
                        'worker_id' => $row['worker_id'],
                    ];
                }
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

        // First Check if FD is Subscribed to any Topic Before Fetching and looping all data.
        $selector = new TableSelector($this->swooleTableName);

        $selector->where()
            ->firstCondition(new Condition(
                new ConditionElement(ConditionElementType::var, 'fd', $this->swooleTableName),
                ConditionOperator::equal,
                new ConditionElement(ConditionElementType::const, $fd)
            ));

        $fdRecords = $selector->execute();
        
        // Here we will load the fds into the server
        foreach ($fdRecords as $record) {
            $fd = $record[$this->swooleTableName]->getValue('fd');
            $topic = $record[$this->swooleTableName]->getValue('topic');
            
            $removed = $this->subscriptionTable->del($this->generateKey($fd, $topic));
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
        $key = $this->generateKey($fd, $topic);
        return $this->subscriptionTable->exists($key);
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
}
