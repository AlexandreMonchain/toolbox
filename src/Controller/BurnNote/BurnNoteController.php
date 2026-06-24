<?php

namespace App\Controller\BurnNote;

use App\Entity\BurnNote;
use App\Repository\BurnNoteRepository;
use App\Service\BurnNote\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/burn', name: 'burnnote_')]
class BurnNoteController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('burnnote/create.html.twig');
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        EncryptionService $encryption,
        EntityManagerInterface $em,
    ): Response {
        $secret   = trim($request->request->get('secret', ''));
        $ttl      = (int) $request->request->get('ttl', 24);
        $maxViews = (int) $request->request->get('max_views', 1);

        if ($secret === '') {
            return $this->render('burnnote/create.html.twig', [
                'error' => 'Le secret ne peut pas être vide.',
            ]);
        }

        $ttl = max(1, min(720, $ttl));

        // 101 = illimité (valeur sentinelle du slider)
        $unlimited = $maxViews >= 101;
        $maxViews  = $unlimited ? PHP_INT_MAX : max(1, min(100, $maxViews));

        $encrypted = $encryption->encrypt($secret);

        $note = new BurnNote();
        $note->setPayload($encrypted['payload']);
        $note->setNonce($encrypted['nonce']);
        $note->setViewsRemaining($maxViews);
        $note->setExpiresAt(new \DateTimeImmutable("+{$ttl} hours"));

        $em->persist($note);
        $em->flush();

        return $this->render('burnnote/created.html.twig', [
            'url' => $this->generateUrl('burnnote_show', ['token' => $note->getToken()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/{token}', name: 'show', methods: ['GET'])]
    public function show(
        string $token,
        BurnNoteRepository $repository,
        EncryptionService $encryption,
        EntityManagerInterface $em,
    ): Response {
        $note = $repository->findOneBy(['token' => $token]);

        if (!$note || !$note->isAccessible()) {
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        $secret = $encryption->decrypt($note->getPayload(), $note->getNonce());

        $note->setViewsRemaining($note->getViewsRemaining() - 1);

        if ($note->getViewsRemaining() <= 0) {
            $note->burn();
        }

        $em->flush();

        return $this->render('burnnote/show.html.twig', [
            'secret'         => $secret,
            'viewsRemaining' => $note->getViewsRemaining(),
        ]);
    }
}
