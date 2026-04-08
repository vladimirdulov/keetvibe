<?php

namespace App\Controller;

use App\DTO\CreateRoomRequest;
use App\Entity\Room;
use App\Entity\RoomParticipant;
use App\Entity\User;
use App\Repository\RoomParticipantRepository;
use App\Repository\RoomRepository;
use App\Service\WebSocketNotifier;
use App\Trait\UuidValidatorTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/rooms')]
class RoomController extends AbstractController
{
    use UuidValidatorTrait;

    public function __construct(
        private readonly RoomRepository $roomRepository,
        private readonly RoomParticipantRepository $participantRepository,
        private readonly ValidatorInterface $validator,
        private readonly WebSocketNotifier $wsNotifier,
    ) {}

    #[Route('', name: 'api_rooms_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $limit = min((int) $request->query->get('limit', 20), 100);

        if ($status === 'live') {
            $rooms = $this->roomRepository->findLiveRooms($limit);
        } elseif ($status === 'upcoming') {
            $rooms = $this->roomRepository->findUpcoming($limit);
        } else {
            $rooms = $this->roomRepository->findAllWithEagerLoading($limit);
        }

        return $this->json([
            'data' => array_map(fn(Room $r) => $this->serializeRoom($r), $rooms),
        ]);
    }

    #[Route('', name: 'api_rooms_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        
        $dto = new CreateRoomRequest();
        $dto->title = $data['title'] ?? '';
        $dto->description = $data['description'] ?? null;
        $dto->scheduledAt = isset($data['scheduled_at']) 
            ? new \DateTimeImmutable($data['scheduled_at']) 
            : null;

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json(['errors' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        $room = new Room();
        $room->setTitle($dto->title);
        $room->setDescription($dto->description);
        $room->setScheduledAt($dto->scheduledAt);
        $room->setHost($user);
        $room->setStatus($dto->scheduledAt ? Room::STATUS_SCHEDULED : Room::STATUS_DRAFT);

        $this->roomRepository->save($room, true);

        return $this->json([
            'data' => $this->serializeRoom($room),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_rooms_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'data' => $this->serializeRoom($room),
        ]);
    }

    #[Route('/{id}/start', name: 'api_rooms_start', methods: ['POST'])]
    public function start(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $this->requireHostAccess($room, $user, 'start');

        try {
            $room->start();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->roomRepository->save($room, true);

        $this->wsNotifier->notifyRoomStarted($room);

        return $this->json([
            'data' => $this->serializeRoom($room),
        ]);
    }

    #[Route('/{id}/end', name: 'api_rooms_end', methods: ['POST'])]
    public function end(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $this->requireHostAccess($room, $user, 'end');

        try {
            $room->end();
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->roomRepository->save($room, true);

        $this->wsNotifier->notifyRoomEnded($room);

        return $this->json([
            'data' => $this->serializeRoom($room),
        ]);
    }

    #[Route('/{id}/slide', name: 'api_rooms_slide', methods: ['POST'])]
    public function updateSlide(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $this->requireHostAccess($room, $user, 'change slides in');

        $data = json_decode($request->getContent(), true);
        $slide = (int) ($data['slide'] ?? 0);

        $room->setCurrentSlide($slide);
        $this->roomRepository->save($room, true);

        $this->wsNotifier->notifySlideChange($room, $slide);

        return $this->json([
            'data' => ['slide' => $slide],
        ]);
    }

    #[Route('/my', name: 'api_rooms_my', methods: ['GET'])]
    public function myRooms(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $rooms = $this->roomRepository->findByHost($user);

        return $this->json([
            'data' => array_map(fn(Room $r) => $this->serializeRoom($r), $rooms),
        ]);
    }

    /**
     * Check if user has access to the room (host or participant)
     */
    private function checkRoomAccess(Room $room, User $user): ?string
    {
        // Check if user is host
        if ($room->getHost()->getId()->toString() === $user->getId()->toString()) {
            return 'host';
        }

        // Check if user is a participant
        $participant = $this->participantRepository->findActiveParticipant($room, $user);
        if ($participant) {
            return $participant->getRole();
        }

        return null;
    }

    /**
     * Check if user has host-only access (start, end, slide control)
     */
    private function requireHostAccess(Room $room, User $user, string $action): void
    {
        if ($room->getHost()->getId()->toString() !== $user->getId()->toString()) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                "Only host can {$action} the room"
            );
        }
    }

    private function serializeRoom(Room $room, ?string $userRole = null): array
    {
        return [
            'id' => $room->getId(),
            'title' => $room->getTitle(),
            'description' => $room->getDescription(),
            'status' => $room->getStatus(),
            'host' => [
                'id' => $room->getHost()->getId(),
                'name' => $room->getHost()->getName(),
            ],
            'viewer_count' => $room->getViewerCount(),
            'current_slide' => $room->getCurrentSlide(),
            'scheduled_at' => $room->getScheduledAt()?->format(\DateTimeInterface::ATOM),
            'started_at' => $room->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'ended_at' => $room->getEndedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $room->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'user_role' => $userRole, // 'host', 'viewer', 'speaker', or null
            'presets' => array_map(fn($p) => [
                'id' => $p->getId(),
                'type' => $p->getType(),
                'url' => $p->getUrl(),
                'name' => $p->getName(),
                'position' => $p->getPosition(),
            ], $room->getPresets()->toArray()),
        ];
    }

    #[Route('/{id}/join', name: 'api_rooms_join', methods: ['POST'])]
    public function join(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if already a participant
        $existingParticipant = $this->participantRepository->findActiveParticipant($room, $user);
        if ($existingParticipant) {
            return $this->json([
                'data' => $this->serializeRoom($room, $existingParticipant->getRole()),
            ]);
        }

        // Create new participant
        $participant = new RoomParticipant();
        $participant->setRoom($room);
        $participant->setUser($user);
        $participant->setRole('viewer');
        $this->participantRepository->save($participant, true);

        // Notify via WebSocket
        $this->wsNotifier->notifyViewerJoined($room, $user->getId()->toString());

        return $this->json([
            'data' => $this->serializeRoom($room, 'viewer'),
        ]);
    }

    #[Route('/{id}/leave', name: 'api_rooms_leave', methods: ['POST'])]
    public function leave(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        // Check if user is a participant
        $participant = $this->participantRepository->findActiveParticipant($room, $user);
        
        if ($participant) {
            $participant->setLeftAt(new \DateTimeImmutable());
            $this->participantRepository->save($participant, true);
            
            // Notify via WebSocket
            $this->wsNotifier->notifyViewerLeft($room, $user->getId()->toString());
        }

        return $this->json(['data' => ['left' => true]]);
    }
}
