<?php

namespace App\Core\Services;

use Bootstrap\SwooleTableFactory;

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
     * @return bool 
     */
    public function subscribe(int $fd, mixed $topic, int $worker_id): bool
    {
        $key = "{$topic}:{$fd}";

        // Check if the FD is already subscribed
        if (!$this->subscriptionTable->exists($key)) {
            $this->subscriptionTable->set($key, [
                'topic' => $topic,
                'fd' => $fd,
                'worker_id' => $worker_id,
            ]);

            return true;
        }

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
        $key = "{$topic}:{$fd}";

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
        return SwooleTableFactory::getTableData($this->swooleTableName);
    }

    /**
     * Get the FDs subscribed to specified Topic, optionally filter by Worker ID
     *
     * @param string $topic The name of the topic.
     * @param bool $fdsOnly If true, returns only an array of FDs; if false, returns all data of the row.
     * @param int|null $worker_id Optional worker ID to filter the FDs for provided worker
     * @return array 
     */
    public function getSubscribersForTopic(string $topic, bool $fdsOnly = false, ?int $worker_id = null): array
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
            'unsubscribed' => [],
            'errors' => [],
        ];

        // Handle subscriptions
        foreach ($subscribeTopics as $topic) {
            if ($this->subscribe($fd, $topic, $worker_id)) {
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
        $subscriptionSwooleTable = $this->subscriptionTable->getSwooleTable();

        foreach ($subscriptionSwooleTable as $key => $row) {
            if ($row['fd'] === $fd) {
                $subscriptionSwooleTable->del($key);
                $removed = true;
            }
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
        $key = "{$topic}:{$fd}";
        return $this->subscriptionTable->exists($key);
    }
}
