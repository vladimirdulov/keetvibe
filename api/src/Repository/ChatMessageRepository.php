<?php

namespace App\Repository;

use App\Entity\ChatMessage;
use App\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    public function save(ChatMessage $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findRecentMessages(Room $room, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->select('m', 'u')
            ->leftJoin('m.user', 'u')
            ->andWhere('m.room = :room')
            ->andWhere('m.isDeleted = :deleted')
            ->setParameter('room', $room)
            ->setParameter('deleted', false)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
