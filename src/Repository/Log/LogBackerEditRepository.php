<?php

namespace App\Repository\Log;

use App\Entity\Log\LogBackerEdit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LogBackerEdit>
 *
 * @method LogBackerEdit|null find($id, $lockMode = null, $lockVersion = null)
 * @method LogBackerEdit|null findOneBy(array $criteria, array $orderBy = null)
 * @method LogBackerEdit[]    findAll()
 * @method LogBackerEdit[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class LogBackerEditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LogBackerEdit::class);
    }

    //    /**
    //     * @return LogBackerEdit[] Returns an array of LogBackerEdit objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('l.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?LogBackerEdit
    //    {
    //        return $this->createQueryBuilder('l')
    //            ->andWhere('l.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
