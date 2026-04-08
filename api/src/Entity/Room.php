<?php

namespace App\Entity;

use App\Repository\RoomRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'rooms')]
#[ORM\Index(columns: ['status'], name: 'idx_room_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_room_created')]
#[ORM\HasLifecycleCallbacks]
class Room
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LIVE = 'live';
    public const STATUS_ENDED = 'ended';

    // Valid status transitions
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_SCHEDULED, self::STATUS_LIVE],
        self::STATUS_SCHEDULED => [self::STATUS_DRAFT, self::STATUS_LIVE],
        self::STATUS_LIVE => [self::STATUS_ENDED],
        self::STATUS_ENDED => [], // Terminal state
    ];

    private ?string $pendingStatus = null;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['room:read', 'room:list'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['room:read', 'room:list'])]
    private string $title;

    #[ORM\Column(nullable: true)]
    #[Groups(['room:read'])]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'rooms')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['room:read'])]
    private User $host;

    #[ORM\Column(length: 20)]
    #[Groups(['room:read', 'room:list'])]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(nullable: true)]
    #[Groups(['room:read'])]
    private ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['room:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['room:read'])]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?int $viewerCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $peakViewers = 0;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $streamKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $currentSlide = 0;

    #[ORM\OneToMany(mappedBy: 'room', targetEntity: RoomPreset::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['room:read'])]
    private Collection $presets;

    #[ORM\OneToMany(mappedBy: 'room', targetEntity: RoomParticipant::class, cascade: ['persist', 'remove'])]
    private Collection $participants;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->presets = new ArrayCollection();
        $this->participants = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->generateStreamKey();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getHost(): User
    {
        return $this->host;
    }

    public function setHost(User $host): static
    {
        $this->host = $host;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Check if transition to new status is allowed
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    /**
     * Get allowed status transitions from current status
     */
    public function getAllowedTransitions(): array
    {
        return self::ALLOWED_TRANSITIONS[$this->status] ?? [];
    }

    /**
     * Set status with validation
     * @throws \InvalidArgumentException if transition is not allowed
     */
    public function setStatus(string $status): static
    {
        // Allow if no current status (new room)
        if ($this->status === '' || $this->status === null) {
            $this->status = $status;
            return $this;
        }

        // Validate transition
        if (!$this->canTransitionTo($status)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid status transition from "%s" to "%s". Allowed: %s',
                    $this->status,
                    $status,
                    implode(', ', $this->getAllowedTransitions()) ?: 'none'
                )
            );
        }

        $this->status = $status;
        return $this;
    }

    /**
     * Start the room (transition to LIVE)
     * @throws \InvalidArgumentException if cannot start
     */
    public function start(): static
    {
        if (!$this->canTransitionTo(self::STATUS_LIVE)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot start room from "%s" status', $this->status)
            );
        }
        
        $this->status = self::STATUS_LIVE;
        $this->startedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * End the room (transition to ENDED)
     * @throws \InvalidArgumentException if cannot end
     */
    public function end(): static
    {
        if (!$this->canTransitionTo(self::STATUS_ENDED)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot end room from "%s" status', $this->status)
            );
        }
        
        $this->status = self::STATUS_ENDED;
        $this->endedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getViewerCount(): int
    {
        return $this->viewerCount ?? 0;
    }

    public function setViewerCount(int $viewerCount): static
    {
        $this->viewerCount = $viewerCount;
        return $this;
    }

    public function getPeakViewers(): int
    {
        return $this->peakViewers ?? 0;
    }

    public function setPeakViewers(int $peakViewers): static
    {
        $this->peakViewers = $peakViewers;
        return $this;
    }

    public function getStreamKey(): ?string
    {
        return $this->streamKey;
    }

    public function generateStreamKey(): void
    {
        $this->streamKey = bin2hex(random_bytes(32));
    }

    public function getCurrentSlide(): int
    {
        return $this->currentSlide ?? 0;
    }

    public function setCurrentSlide(int $currentSlide): static
    {
        $this->currentSlide = $currentSlide;
        return $this;
    }

    public function getPresets(): Collection
    {
        return $this->presets;
    }

    public function addPreset(RoomPreset $preset): static
    {
        if (!$this->presets->contains($preset)) {
            $this->presets->add($preset);
            $preset->setRoom($this);
        }
        return $this;
    }

    public function removePreset(RoomPreset $preset): static
    {
        if ($this->presets->removeElement($preset)) {
            if ($preset->getRoom() === $this) {
                $preset->setRoom(null);
            }
        }
        return $this;
    }

    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    public function isLive(): bool
    {
        return $this->status === self::STATUS_LIVE;
    }
}
