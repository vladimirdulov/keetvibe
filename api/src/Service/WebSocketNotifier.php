<?php

namespace App\Service;

use App\Entity\Room;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WebSocketNotifier
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function notifyRoomStarted(Room $room): void
    {
        $this->sendEvent('room_started', $room, [
            'started_at' => $room->getStartedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function notifyRoomEnded(Room $room): void
    {
        $this->sendEvent('room_ended', $room, [
            'ended_at' => $room->getEndedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    public function notifySlideChange(Room $room, int $slide): void
    {
        $this->sendEvent('slide_change', $room, [
            'slide' => $slide,
        ]);
    }

    public function notifyViewerJoined(Room $room, string $userId): void
    {
        $this->sendEvent('viewer_joined', $room, [
            'user_id' => $userId,
        ]);
    }

    public function notifyViewerLeft(Room $room, string $userId): void
    {
        $this->sendEvent('viewer_left', $room, [
            'user_id' => $userId,
        ]);
    }

    public function notifyHandRaised(Room $room, string $userId, string $userName): void
    {
        $this->sendEvent('hand_raised', $room, [
            'user_id' => $userId,
            'user_name' => $userName,
        ]);
    }

    private function sendEvent(string $type, Room $room, array $payload = []): void
    {
        try {
            $this->httpClient->request('POST', $this->apiUrl . '/internal/notify', [
                'json' => [
                    'type' => $type,
                    'room_id' => $room->getId()->toRfc4122(),
                    'payload' => $payload,
                ],
                'timeout' => 1,
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('WebSocket notification failed', [
                'type' => $type,
                'room_id' => $room->getId()->toRfc4122(),
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
        }
    }
}
