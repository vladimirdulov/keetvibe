<?php

namespace App\Controller;

use App\Repository\RoomRepository;
use App\Service\ClickHouseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Route('/api/analytics')]
class AnalyticsController extends AbstractController
{
    public function __construct(
        private readonly ClickHouseService $clickHouse,
        private readonly RoomRepository $roomRepository,
        private readonly TokenStorageInterface $tokenStorage
    ) {}

    private function getCurrentUser(): ?object
    {
        $token = $this->tokenStorage->getToken();
        return $token?->getUser();
    }

    private function getUserId(): ?string
    {
        $user = $this->getCurrentUser();
        if (method_exists($user, 'getId')) {
            return $user->getId();
        }
        return null;
    }

    private function checkRoomAccess(string $roomId): void
    {
        $room = $this->roomRepository->find($roomId);
        
        if (!$room) {
            throw new AccessDeniedException('Room not found');
        }

        $userId = $this->getUserId();
        $isHost = $room->getHost()->getId()->toString() === $userId;
        
        // Host can access analytics, or check if user is a participant
        if (!$isHost) {
            $participant = $room->getParticipants()->filter(
                fn($p) => $p->getUser()->getId()->toString() === $userId
            );
            if ($participant->isEmpty()) {
                throw new AccessDeniedException('You do not have access to this room analytics');
            }
        }
    }

    #[Route('/rooms/{roomId}/stats', name: 'api_analytics_room_stats', methods: ['GET'])]
    public function roomStats(string $roomId, Request $request): JsonResponse
    {
        $this->checkRoomAccess($roomId);

        $from = $request->query->get('from');
        $to = $request->query->get('to');

        $fromDate = $from ? new \DateTime($from) : new \DateTime('-7 days');
        $toDate = $to ? new \DateTime($to) : new \DateTime();

        $stats = $this->clickHouse->getRoomStats($roomId, $fromDate, $toDate);

        return $this->json(['data' => $stats]);
    }

    #[Route('/rooms/{roomId}/hourly', name: 'api_analytics_hourly', methods: ['GET'])]
    public function hourlyStats(string $roomId, Request $request): JsonResponse
    {
        $this->checkRoomAccess($roomId);

        $date = $request->query->get('date', 'today');
        $dateObj = $date === 'today' ? new \DateTime() : new \DateTime($date);

        $stats = $this->clickHouse->getHourlyStats($roomId, $dateObj);

        return $this->json(['data' => $stats]);
    }

    #[Route('/rooms/{roomId}/slides', name: 'api_analytics_slides', methods: ['GET'])]
    public function slideAnalytics(string $roomId): JsonResponse
    {
        $this->checkRoomAccess($roomId);

        $stats = $this->clickHouse->getSlideAnalytics($roomId);

        return $this->json(['data' => $stats]);
    }

    #[Route('/rooms/top', name: 'api_analytics_top_rooms', methods: ['GET'])]
    public function topRooms(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 10);
        $period = $request->query->get('period', 'day');

        $rooms = $this->clickHouse->getTopRooms($limit, $period);

        return $this->json(['data' => $rooms]);
    }

    #[Route('/health', name: 'api_analytics_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $healthy = $this->clickHouse->healthCheck();

        return $this->json([
            'status' => $healthy ? 'ok' : 'error',
            'service' => 'clickhouse'
        ], $healthy ? 200 : 503);
    }
}
