<?php

namespace App\Repository;

use App\Entity\Room;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    public function save(Room $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Room $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find live rooms with eager loading to avoid N+1
     */
    public function findLiveRooms(int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'h', 'p')
            ->leftJoin('r.host', 'h')
            ->leftJoin('r.presets', 'p')
            ->andWhere('r.status = :status')
            ->setParameter('status', Room::STATUS_LIVE)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(Query::HYDRATE_OBJECT);
    }

    /**
     * Find rooms by host with eager loading
     */
    public function findByHost(User $host): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'h', 'p')
            ->leftJoin('r.host', 'h')
            ->leftJoin('r.presets', 'p')
            ->andWhere('r.host = :host')
            ->setParameter('host', $host)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult(Query::HYDRATE_OBJECT);
    }

    /**
     * Find upcoming rooms with eager loading
     */
    public function findUpcoming(int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'h', 'p')
            ->leftJoin('r.host', 'h')
            ->leftJoin('r.presets', 'p')
            ->andWhere('r.status = :status')
            ->andWhere('r.scheduledAt > :now')
            ->setParameter('status', Room::STATUS_SCHEDULED)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('r.scheduledAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(Query::HYDRATE_OBJECT);
    }

    /**
     * Find all rooms with eager loading (for list endpoint)
     */
    public function findAllWithEagerLoading(int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'h', 'p')
            ->leftJoin('r.host', 'h')
            ->leftJoin('r.presets', 'p')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(Query::HYDRATE_OBJECT);
    }

    /**
     * Find single room by ID with eager loading
     */
    public function findOneWithEagerLoading(string $id): ?Room
    {
        return $this->createQueryBuilder('r')
            ->select('r', 'h', 'p', 'part')
            ->leftJoin('r.host', 'h')
            ->leftJoin('r.presets', 'p')
            ->leftJoin('r.participants', 'part')
            ->andWhere('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult(Query::HYDRATE_OBJECT);
    }
}
