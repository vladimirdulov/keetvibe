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
        return [
            'id' => $message->getId(),
            'content' => $this->sanitizer->escape($message->getContent()),
            'user' => [
                'id' => $message->getUser()->getId(),
                'name' => $this->sanitizer->escape($message->getUser()->getName()),
            ],
            'reply_to_id' => $message->getReplyToId(),
            'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
