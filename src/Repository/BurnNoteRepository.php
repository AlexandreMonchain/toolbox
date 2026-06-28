<?php

namespace App\Repository;

use App\Entity\BurnNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BurnNote>
 */
class BurnNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BurnNote::class);
    }

    /**
     * Décrémente atomiquement views_remaining et retourne la note si la vue a été accordée.
     * Retourne null si la note n'existe pas, est expirée, ou a déjà été consommée par une requête concurrente.
     */
    public function consumeView(string $token): ?BurnNote
    {
        $conn = $this->getEntityManager()->getConnection();

        $affected = $conn->executeStatement(
            'UPDATE burn_note
             SET views_remaining = views_remaining - 1
             WHERE token = :token
               AND expired = false
               AND views_remaining > 0
               AND expires_at > NOW()',
            ['token' => $token]
        );

        if ($affected === 0) {
            return null;
        }

        // Recharge l'entité avec la valeur à jour
        $this->getEntityManager()->clear();
        return $this->findOneBy(['token' => $token]);
    }
}
