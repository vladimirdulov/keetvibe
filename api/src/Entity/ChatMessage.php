<?php

namespace App\Entity;

use App\Repository\ChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Table(name: 'chat_messages')]
#[ORM\Index(columns: ['room_id', 'created_at'], name: 'idx_chat_room_created')]
class ChatMessage
{
    public const REPORT_REASON_SPAM = 'spam';
    public const REPORT_REASON_ABUSE = 'abuse';
    public const REPORT_REASON_OTHER = 'other';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['room:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Room $room;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['room:read'])]
    private User $user;

    #[ORM\Column(type: 'text')]
    #[Groups(['room:read'])]
    private string $content;

    #[ORM\Column(nullable: true)]
    private ?Uuid $replyToId = null;

    #[ORM\Column(nullable: true)]
    private ?Uuid $senderParticipantId = null;

    #[ORM\Column]
    private bool $isDeleted = false;

    #[ORM\Column(nullable: true)]
    private ?bool $isMuted = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $mutedUntil = null;

    #[ORM\Column(nullable: true)]
    private ?string $reportedBy = null;

    #[ORM\Column(nullable: true)]
    private ?string $reportReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reportProcessedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function setRoom(Room $room): static
    {
        $this->room = $room;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function getReplyToId(): ?Uuid
    {
        return $this->replyToId;
    }

    public function setReplyToId(?Uuid $replyToId): static
    {
        $this->replyToId = $replyToId;
        return $this;
    }

    public function getSenderParticipantId(): ?Uuid
    {
        return $this->senderParticipantId;
    }

    public function setSenderParticipantId(?Uuid $senderParticipantId): static
    {
        $this->senderParticipantId = $senderParticipantId;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(bool $isDeleted): static
    {
        $this->isDeleted = $isDeleted;
        return $this;
    }

    public function isMuted(): ?bool
    {
        return $this->isMuted;
    }

    public function isUserMuted(): bool
    {
        return $this->isMuted === true && $this->mutedUntil !== null && $this->mutedUntil > new \DateTimeImmutable();
    }

    public function mute(?\DateTimeImmutable $until = null): static
    {
        $this->isMuted = true;
        $this->mutedUntil = $until;
        return $this;
    }

    public function unmute(): static
    {
        $this->isMuted = false;
        $this->mutedUntil = null;
        return $this;
    }

    public function getMutedUntil(): ?\DateTimeImmutable
    {
        return $this->mutedUntil;
    }

    public function report(string $reason, string $reportedByUserId): static
    {
        $this->reportedBy = $reportedByUserId;
        $this->reportReason = $reason;
        $this->reportProcessedAt = null;
        return $this;
    }

    public function getReportedBy(): ?string
    {
        return $this->reportedBy;
    }

    public function getReportReason(): ?string
    {
        return $this->reportReason;
    }

    public function isReported(): bool
    {
        return $this->reportedBy !== null;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
