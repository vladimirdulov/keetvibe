<?php

namespace App\Controller;

use App\DTO\ChatMessageRequest;
use App\Entity\ChatMessage;
use App\Entity\Room;
use App\Entity\User;
use App\Message\ChatMessageEvent;
use App\Repository\ChatMessageRepository;
use App\Repository\RoomRepository;
use App\Service\SanitizerService;
use App\Trait\UuidValidatorTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/rooms/{roomId}/chat')]
class ChatController extends AbstractController
{
    use UuidValidatorTrait;

    public function __construct(
        private readonly RoomRepository $roomRepository,
        private readonly ChatMessageRepository $chatRepository,
        private readonly ValidatorInterface $validator,
        private readonly MessageBusInterface $messageBus,
        private readonly SanitizerService $sanitizer,
    ) {}

    #[Route('', name: 'api_chat_list', methods: ['GET'])]
    public function list(string $roomId, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($roomId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $limit = min((int) $request->query->get('limit', 100), 500);
        $messages = $this->chatRepository->findRecentMessages($room, $limit);

        return $this->json([
            'data' => array_map(fn(ChatMessage $m) => $this->serializeMessage($m), $messages),
        ]);
    }

    #[Route('', name: 'api_chat_send', methods: ['POST'])]
    public function send(Request $request, string $roomId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $uuid = Uuid::fromString($roomId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($uuid);
        
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        
        $dto = new ChatMessageRequest();
        $dto->content = $data['content'] ?? '';
        $dto->replyToId = $data['reply_to_id'] ?? null;

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->json(['errors' => (string) $errors], Response::HTTP_BAD_REQUEST);
        }

        $message = new ChatMessage();
        $message->setRoom($room);
        $message->setUser($user);
        $message->setContent($dto->content);
        
        if ($dto->replyToId) {
            try {
                $message->setReplyToId(Uuid::fromString($dto->replyToId));
            } catch (\InvalidArgumentException) {
                return $this->json([
                    'error' => 'Invalid reply_to_id format. Must be a valid UUID.'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        $this->chatRepository->save($message, true);

        $this->messageBus->dispatch(new ChatMessageEvent(
            $roomId,
            $user->getId()->toRfc4122(),
            $user->getName(),
            $message->getContent(),
            $message->getId()->toRfc4122()
        ));

        return $this->json([
            'data' => $this->serializeMessage($message),
        ], Response::HTTP_CREATED);
    }

    private function serializeMessage(ChatMessage $message): array
    {
        // Don't show deleted content
        if ($message->isDeleted()) {
            return [
                'id' => $message->getId(),
                'content' => '[deleted]',
                'user' => [
                    'id' => $message->getUser()->getId(),
                    'name' => '[deleted]',
                ],
                'reply_to_id' => $message->getReplyToId(),
                'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'is_deleted' => true,
            ];
        }

        return [
            'id' => $message->getId(),
            'content' => $this->sanitizer->escape($message->getContent()),
            'user' => [
                'id' => $message->getUser()->getId(),
                'name' => $this->sanitizer->escape($message->getUser()->getName()),
            ],
            'reply_to_id' => $message->getReplyToId(),
            'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'is_deleted' => false,
        ];
    }

    /**
     * Delete a message (host or message author can delete)
     */
    #[Route('/{messageId}', name: 'api_chat_delete', methods: ['DELETE'])]
    public function delete(string $roomId, string $messageId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $roomUuid = Uuid::fromString($roomId);
            $messageUuid = Uuid::fromString($messageId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid ID format'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($roomUuid);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->chatRepository->find($messageUuid);
        if (!$message || $message->getRoom()->getId() !== $roomUuid) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        // Check permission: host or message author
        $isHost = $room->getHost()->getId()->toString() === $user->getId()->toString();
        $isAuthor = $message->getUser()->getId()->toString() === $user->getId()->toString();

        if (!$isHost && !$isAuthor) {
            return $this->json(['error' => 'Permission denied'], Response::HTTP_FORBIDDEN);
        }

        $message->setIsDeleted(true);
        $this->chatRepository->save($message, true);

        return $this->json(['data' => ['deleted' => true]]);
    }

    /**
     * Report a message
     */
    #[Route('/{messageId}/report', name: 'api_chat_report', methods: ['POST'])]
    public function report(Request $request, string $roomId, string $messageId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $roomUuid = Uuid::fromString($roomId);
            $messageUuid = Uuid::fromString($messageId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid ID format'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($roomUuid);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        $message = $this->chatRepository->find($messageUuid);
        if (!$message || $message->getRoom()->getId() !== $roomUuid) {
            return $this->json(['error' => 'Message not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? '';

        // Validate reason
        $validReasons = [ChatMessage::REPORT_REASON_SPAM, ChatMessage::REPORT_REASON_ABUSE, ChatMessage::REPORT_REASON_OTHER];
        if (!in_array($reason, $validReasons, true)) {
            return $this->json(['error' => 'Invalid report reason'], Response::HTTP_BAD_REQUEST);
        }

        // Can't report own message
        if ($message->getUser()->getId()->toString() === $user->getId()->toString()) {
            return $this->json(['error' => 'Cannot report your own message'], Response::HTTP_BAD_REQUEST);
        }

        $message->report($reason, $user->getId()->toString());
        $this->chatRepository->save($message, true);

        return $this->json(['data' => ['reported' => true]]);
    }

    /**
     * Mute a user in the room (host only)
     */
    #[Route('/mute/{participantId}', name: 'api_chat_mute', methods: ['POST'])]
    public function mute(Request $request, string $roomId, string $participantId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $roomUuid = Uuid::fromString($roomId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID format'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($roomUuid);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        // Only host can mute
        if ($room->getHost()->getId()->toString() !== $user->getId()->toString()) {
            return $this->json(['error' => 'Only host can mute users'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        $duration = (int) ($data['duration_minutes'] ?? 60); // Default 60 minutes
        $duration = max(1, min($duration, 10080)); // 1 min to 7 days

        $mutedUntil = (new \DateTimeImmutable())->modify("+{$duration} minutes");

        // Find the participant and mute them
        $participants = $room->getParticipants();
        $found = false;
        foreach ($participants as $participant) {
            if ($participant->getId()->toString() === $participantId) {
                $participant->mute($mutedUntil);
                $this->roomRepository->save($room, true);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->json(['error' => 'Participant not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'data' => [
                'muted' => true,
                'until' => $mutedUntil->format(\DateTimeInterface::ATOM),
            ]
        ]);
    }

    /**
     * Unmute a user in the room (host only)
     */
    #[Route('/unmute/{participantId}', name: 'api_chat_unmute', methods: ['POST'])]
    public function unmute(string $roomId, string $participantId): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        try {
            $roomUuid = Uuid::fromString($roomId);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid room ID format'], Response::HTTP_BAD_REQUEST);
        }

        $room = $this->roomRepository->find($roomUuid);
        if (!$room) {
            return $this->json(['error' => 'Room not found'], Response::HTTP_NOT_FOUND);
        }

        // Only host can unmute
        if ($room->getHost()->getId()->toString() !== $user->getId()->toString()) {
            return $this->json(['error' => 'Only host can unmute users'], Response::HTTP_FORBIDDEN);
        }

        // Find the participant and unmute them
        $participants = $room->getParticipants();
        $found = false;
        foreach ($participants as $participant) {
            if ($participant->getId()->toString() === $participantId) {
                $participant->unmute();
                $this->roomRepository->save($room, true);
                $found = true;
                break;
            }
        }

        if (!$found) {
            return $this->json(['error' => 'Participant not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['data' => ['muted' => false]]);
    }
}
