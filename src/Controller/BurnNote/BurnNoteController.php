<?php

namespace App\Controller\BurnNote;

use App\Entity\BurnNote;
use App\Repository\BurnNoteRepository;
use App\Service\BurnNote\EncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[Route('/burn', name: 'burnnote_')]
class BurnNoteController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('burnnote/create.html.twig');
    }

    #[Route('/created', name: 'created', methods: ['GET'])]
    public function created(Request $request): Response
    {
        $data = $request->getSession()->get('burnnote_created');

        if (!$data) {
            return $this->redirectToRoute('burnnote_index');
        }

        $request->getSession()->remove('burnnote_created');

        return $this->render('burnnote/created.html.twig', $data);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        EncryptionService $encryption,
        EntityManagerInterface $em,
        #[Target('burnNoteCreateLimiter')] RateLimiterFactory $createLimiter,
    ): Response {
        if ($limited = $this->checkRateLimit($request, $createLimiter)) {
            return $limited;
        }

        if (!$this->isCsrfTokenValid('burnnote_create', $request->request->get('_csrf_token'))) {
            $this->logger->warning('burnnote.csrf_fail', ['action' => 'create', 'ip' => $request->getClientIp()]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $secret   = trim($request->request->get('secret', ''));
        $ttl      = (int) $request->request->get('ttl', 24);
        $maxViews = (int) $request->request->get('max_views', 1);

        if ($secret === '') {
            return $this->render('burnnote/create.html.twig', [
                'error' => 'Le secret ne peut pas être vide.',
            ]);
        }

        if (mb_strlen($secret) > 30000) {
            return $this->render('burnnote/create.html.twig', [
                'error' => 'Le secret ne peut pas dépasser 30 000 caractères.',
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

        $url = $this->generateUrl('burnnote_show', ['token' => $note->getToken()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        $this->logger->info('burnnote.created', [
            'ip'       => $request->getClientIp(),
            'ttl_h'    => $ttl,
            'maxViews' => $unlimited ? 'unlimited' : $maxViews,
            'token'    => substr($note->getToken(), 0, 8),
        ]);

        $request->getSession()->set('burnnote_created', [
            'url'      => $url,
            'maxViews' => $note->getViewsRemaining(),
        ]);

        return $this->redirectToRoute('burnnote_created');
    }

    private function checkRateLimit(Request $request, RateLimiterFactory $factory): ?Response
    {
        $limiter = $factory->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->logger->warning('burnnote.rate_limit', [
                'ip'   => $request->getClientIp(),
                'path' => $request->getPathInfo(),
            ]);
            return new Response('Trop de requêtes. Réessayez dans une minute.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        return null;
    }

    #[Route('/{token}', name: 'show', methods: ['GET'])]
    public function show(string $token, Request $request, BurnNoteRepository $repository, #[Target('burnNoteLimiter')] RateLimiterFactory $burnnoteRateLimiter): Response
    {
        if ($limited = $this->checkRateLimit($request, $burnnoteRateLimiter)) {
            return $limited;
        }

        $note = $repository->findOneBy(['token' => $token]);

        if (!$note || !$note->isAccessible()) {
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        return $this->render('burnnote/preview.html.twig', [
            'token'    => $token,
            'maxViews' => $note->getViewsRemaining(),
            'expiresAt' => $note->getExpiresAt(),
        ]);
    }

    #[Route('/{token}/burn', name: 'burn', methods: ['POST'])]
    public function burn(
        string $token,
        Request $request,
        BurnNoteRepository $repository,
        EntityManagerInterface $em,
        #[Target('burnNoteLimiter')] RateLimiterFactory $burnnoteRateLimiter,
    ): Response {
        if ($limited = $this->checkRateLimit($request, $burnnoteRateLimiter)) {
            return $limited;
        }

        if (!$this->isCsrfTokenValid('burnnote_burn', $request->request->get('_csrf_token'))) {
            $this->logger->warning('burnnote.csrf_fail', ['action' => 'burn', 'ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $note = $repository->findOneBy(['token' => $token]);

        if ($note && !$note->isExpired()) {
            $note->burn();
            $em->flush();
            $this->logger->info('burnnote.burned', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
        }

        return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
    }

    #[Route('/{token}', name: 'reveal', methods: ['POST'])]
    public function reveal(
        string $token,
        Request $request,
        BurnNoteRepository $repository,
        EncryptionService $encryption,
        EntityManagerInterface $em,
        #[Target('burnNoteLimiter')] RateLimiterFactory $burnnoteRateLimiter,
    ): Response {
        if ($limited = $this->checkRateLimit($request, $burnnoteRateLimiter)) {
            return $limited;
        }

        if (!$this->isCsrfTokenValid('burnnote_reveal', $request->request->get('_csrf_token'))) {
            $this->logger->warning('burnnote.csrf_fail', ['action' => 'reveal', 'ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        // UPDATE atomique — garantit qu'une seule requête concurrente obtient la vue
        $note = $repository->consumeView($token);

        if (!$note) {
            $this->logger->warning('burnnote.reveal_expired', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        $secret = $encryption->decrypt($note->getPayload(), $note->getNonce());

        $this->logger->info('burnnote.revealed', [
            'ip'             => $request->getClientIp(),
            'token'          => substr($token, 0, 8),
            'viewsRemaining' => $note->getViewsRemaining(),
        ]);

        if ($note->getViewsRemaining() <= 0) {
            $note->burn();
            $em->flush();
        }

        return $this->render('burnnote/show.html.twig', [
            'secret'         => $secret,
            'viewsRemaining' => $note->getViewsRemaining(),
            'token'          => $token,
        ]);
    }
}
