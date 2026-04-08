<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClickHouseService
{
    private string $url;
    private string $user;
    private string $password;
    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        string $clickhouseUrl,
        string $clickhouseUser,
        string $clickhousePassword
    ) {
        $this->url = rtrim($clickhouseUrl, '/');
        $this->user = $clickhouseUser;
        $this->password = $clickhousePassword;
        $this->httpClient = $httpClient;
    }

    public function query(string $sql, array $params = []): array
    {
        $query = $this->buildQuery($sql, $params);
        
        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'query' => [
                    'query' => $query,
                    'default_format' => 'JSON',
                ],
                'auth' => [$this->user, $this->password],
                'timeout' => 30,
            ]);

            $data = json_decode($response->getContent(), true);
            
            return $data['data'] ?? [];
        } catch (\Throwable $e) {
            throw new \RuntimeException('ClickHouse query failed: ' . $e->getMessage());
        }
    }

    public function insert(string $table, array $columns, array $values): bool
    {
        $columnsStr = implode(', ', $columns);
        $valuesStr = array_map(function ($row) {
            return '(' . implode(', ', array_map([$this, 'escapeValue'], $row)) . ')';
        }, $values);
        
        $sql = "INSERT INTO {$table} ({$columnsStr}) VALUES " . implode(', ', $valuesStr);
        
        try {
            $this->httpClient->request('POST', $this->url, [
                'query' => ['query' => $sql],
                'auth' => [$this->user, $this->password],
                'timeout' => 10,
            ]);
            
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function insertEvent(
        string $eventType,
        string $roomId,
        string $userId,
        string $userName,
        array $properties = []
    ): bool {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        
        $propertiesJson = json_encode($properties, JSON_THROW_ON_ERROR);
        
        // Use parameterized query to prevent SQL injection
        return $this->insert('keetvibe_analytics.room_events', [
            'event_type', 'room_id', 'user_id', 'user_name', 
            'properties', 'occurred_at'
        ], [[
            $this->escapeValue($eventType),
            $this->escapeValue($roomId),
            $this->escapeValue($userId),
            $this->escapeValue($userName),
            $this->escapeValue($propertiesJson),
            $this->escapeValue($now),
        ]]);
    }

    public function insertChatMessage(
        string $roomId,
        string $userId,
        string $userName,
        string $content,
        ?string $replyToId = null
    ): bool {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $contentLength = strlen($content);
        $isReply = $replyToId ? '1' : '0';
        
        // Use parameterized query to prevent SQL injection
        return $this->insert('keetvibe_analytics.chat_messages', [
            'room_id', 'user_id', 'user_name', 'content',
            'message_length', 'is_reply', 'replied_to_message_id', 'occurred_at'
        ], [[
            $this->escapeValue($roomId),
            $this->escapeValue($userId),
            $this->escapeValue($userName),
            $this->escapeValue($content),
            $this->escapeValue((string)$contentLength),
            $this->escapeValue($isReply),
            $replyToId ? $this->escapeValue($replyToId) : 'NULL',
            $this->escapeValue($now),
        ]]);
    }

    public function recordSlideView(string $roomId, int $slideIndex, string $hostId): bool
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        
        return $this->insert('keetvibe_analytics.slide_views', [
            'room_id', 'slide_index', 'host_id', 'occurred_at'
        ], [[
            $this->escapeValue($roomId),
            $this->escapeValue((string)$slideIndex),
            $this->escapeValue($hostId),
            $this->escapeValue($now),
        ]]);
    }

    public function recordRecording(
        string $roomId,
        string $hostId,
        string $fileUrl,
        int $fileSizeBytes,
        int $durationSeconds,
        \DateTime $startedAt,
        \DateTime $endedAt
    ): bool {
        $recordingId = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        $createdAt = (new \DateTime())->format('Y-m-d H:i:s');
        $startedAtStr = $startedAt->format('Y-m-d H:i:s');
        $endedAtStr = $endedAt->format('Y-m-d H:i:s');
        
        return $this->insert('keetvibe_analytics.recordings', [
            'recording_id', 'room_id', 'host_id', 'file_url',
            'file_size_bytes', 'duration_seconds', 'started_at', 'ended_at', 'created_at'
        ], [[
            $this->escapeValue($recordingId),
            $this->escapeValue($roomId),
            $this->escapeValue($hostId),
            $this->escapeValue($fileUrl),
            $this->escapeValue((string)$fileSizeBytes),
            $this->escapeValue((string)$durationSeconds),
            $this->escapeValue($startedAtStr),
            $this->escapeValue($endedAtStr),
            $this->escapeValue($createdAt),
        ]]);
    }

    public function getRoomStats(string $roomId, \DateTime $from, \DateTime $to): array
    {
        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');
        
        // Validate room ID to prevent SQL injection
        $safeRoomId = $this->escapeString($roomId);
        
        // Use parameterized WHERE clause
        $sql = "SELECT 
            count() as total_events,
            uniqExact(user_id) as unique_viewers,
            sumIf(1, event_type = 'viewer_joined') as total_joins,
            sumIf(1, event_type = 'viewer_left') as total_leaves,
            sumIf(1, event_type = 'chat_message') as total_messages,
            sumIf(1, event_type = 'hand_raised') as total_hand_raises
        FROM keetvibe_analytics.room_events 
        WHERE room_id = {$this->escapeValue($safeRoomId)}
        AND occurred_at BETWEEN {$this->escapeValue($fromStr)} AND {$this->escapeValue($toStr)}";
        
        $result = $this->query($sql);
        return $result[0] ?? [];
    }

    public function getHourlyStats(string $roomId, \DateTime $date): array
    {
        $dateStr = $date->format('Y-m-d');
        
        // Validate room ID to prevent SQL injection
        $safeRoomId = $this->escapeString($roomId);
        
        $sql = "SELECT 
            toStartOfHour(occurred_at) as hour,
            count() as events,
            uniqExact(user_id) as viewers
        FROM keetvibe_analytics.room_events 
        WHERE room_id = {$this->escapeValue($safeRoomId)}
        AND toDate(occurred_at) = {$this->escapeValue($dateStr)}
        GROUP BY hour
        ORDER BY hour";
        
        return $this->query($sql);
    }

    public function getSlideAnalytics(string $roomId): array
    {
        // Validate room ID to prevent SQL injection
        $safeRoomId = $this->escapeString($roomId);
        
        $sql = "SELECT 
            slide_index,
            sum(total_views) as views,
            avg(avg_time_on_slide_seconds) as avg_time
        FROM keetvibe_analytics.slide_views 
        WHERE room_id = {$this->escapeValue($safeRoomId)}
        GROUP BY slide_index
        ORDER BY slide_index";
        
        return $this->query($sql);
    }

    public function getTopRooms(int $limit = 10, string $period = 'day'): array
    {
        // Validate limit to prevent abuse
        $safeLimit = max(1, min((int)$limit, 1000));
        
        $dateCondition = match($period) {
            'hour' => "AND occurred_at >= now() - INTERVAL 1 HOUR",
            'day' => "AND occurred_at >= now() - INTERVAL 1 DAY",
            'week' => "AND occurred_at >= now() - INTERVAL 7 DAY",
            'month' => "AND occurred_at >= now() - INTERVAL 30 DAY",
            default => '',
        };
        
        $sql = "SELECT 
            room_id,
            uniqExact(user_id) as unique_viewers,
            count() as total_events,
            sumIf(1, event_type = 'chat_message') as messages
        FROM keetvibe_analytics.room_events 
        WHERE 1=1 {$dateCondition}
        GROUP BY room_id
        ORDER BY unique_viewers DESC
        LIMIT {$safeLimit}";
        
        return $this->query($sql);
    }

    public function healthCheck(): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->url, [
                'auth' => [$this->user, $this->password],
                'timeout' => 5,
            ]);
            
            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function buildQuery(string $sql, array $params): string
    {
        foreach ($params as $key => $value) {
            $sql = str_replace(":{$key}", $this->escapeString($value), $sql);
        }
        return $sql;
    }

    private function escapeValue(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_null($value)) {
            return 'NULL';
        }
        return "'" . $this->escapeString($value) . "'";
    }

    private function escapeString(string $value): string
    {
        // Use proper escaping for ClickHouse
        // ClickHouse uses backslash for escaping, but single quotes need doubled escaping
        return str_replace(
            ['\\', "'"],
            ['\\\\', "''"],
            $value
        );
    }
}