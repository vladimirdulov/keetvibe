<?php

namespace App\Entity;

use App\Repository\RoomParticipantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RoomParticipantRepository::class)]
#[ORM\Table(name: 'room_participants')]
#[ORM\Index(columns: ['room_id', 'joined_at'], name: 'idx_participant_room_joined')]
class RoomParticipant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[Groups(['room:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class, inversedBy: 'participants')]
    #[ORM\JoinColumn(nullable: false)]
    private Room $room;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'participations')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['room:read'])]
    private User $user;

    #[ORM\Column(length: 20)]
    #[Groups(['room:read'])]
    private string $role = 'viewer';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    #[ORM\Column(nullable: true)]
    private bool $handRaised = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $handRaisedAt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isMuted = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $mutedUntil = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->joinedAt = new \DateTimeImmutable();
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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getJoinedAt(): ?\DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function setJoinedAt(?\DateTimeImmutable $joinedAt): static
    {
        $this->joinedAt = $joinedAt;
        return $this;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function setLeftAt(?\DateTimeImmutable $leftAt): static
    {
        $this->leftAt = $leftAt;
        return $this;
    }

    public function isHandRaised(): bool
    {
        return $this->handRaised;
    }

    public function setHandRaised(bool $handRaised): static
    {
        $this->handRaised = $handRaised;
        if ($handRaised) {
            $this->handRaisedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getHandRaisedAt(): ?\DateTimeImmutable
    {
        return $this->handRaisedAt;
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
}
