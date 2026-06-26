<?php

namespace App\Repository;

use App\Entity\DropText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DropText>
 */
class DropTextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DropText::class);
    }

    public function save(DropText $note): void
    {
        $em = $this->getEntityManager();
        $em->persist($note);
        $em->flush();
    }

    /**
     * Lookup read-only — ne consomme pas de lecture.
     * Utilisé pour vérifier l'existence et la passphrase avant d'incrémenter.
     */
    public function findActiveByToken(string $token): ?DropText
    {
        return $this->createQueryBuilder('d')
            ->where('d.token = :token')
            ->andWhere('d.expiresAt > :now')
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Incrémente atomiquement read_count et retourne la note si la lecture est accordée.
     * Si max_reads est atteint après l'incrément, la note est supprimée (burn).
     * Retourne null si la note n'existe pas, est expirée ou a déjà atteint son max de lectures.
     */
    public function consumeRead(string $token): ?DropText
    {
        $conn = $this->getEntityManager()->getConnection();

        $affected = $conn->executeStatement(
            'UPDATE drop_text
             SET read_count = read_count + 1
             WHERE token = :token
               AND expires_at > NOW()
               AND (max_reads IS NULL OR read_count < max_reads)',
            ['token' => $token]
        );

        if ($affected === 0) {
            return null;
        }

        $this->getEntityManager()->clear();
        $note = $this->findOneBy(['token' => $token]);

        if ($note === null) {
            return null;
        }

        // Burn : supprime la note si le quota de lectures vient d'être atteint
        if ($note->getMaxReads() !== null && $note->getReadCount() >= $note->getMaxReads()) {
            $this->getEntityManager()->remove($note);
            $this->getEntityManager()->flush();
        }

        return $note;
    }

    /**
     * Supprime une note par son token (burn manuel). Retourne true si une ligne a été supprimée.
     */
    public function burnByToken(string $token): bool
    {
        $deleted = $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM drop_text WHERE token = :token',
            ['token' => $token]
        );
        return $deleted > 0;
    }

    /**
     * Supprime les notes expirées. Appelé par le cron app:droptext:purge.
     */
    public function deleteExpired(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM drop_text WHERE expires_at < NOW()'
        );
    }
}
