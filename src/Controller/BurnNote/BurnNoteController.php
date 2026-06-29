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
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Target;

#[Route('/burn', name: 'burnnote_')]
class BurnNoteController extends AbstractController
{
    // $securityLogger est lié au canal Monolog "security" (déclaré dans monolog.yaml),
    // qui écrit toujours en prod — contrairement au handler "main" en fingers_crossed.
    public function __construct(private readonly LoggerInterface $securityLogger) {}

    /** Empêche toute mise en cache (navigateur, proxy) d'une réponse contenant un secret. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        return $response;
    }

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

        return $this->noStore($this->render('burnnote/created.html.twig', $data));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        EncryptionService $encryption,
        EntityManagerInterface $em,
        #[Target('burnNoteCreateLimiter')] RateLimiterFactoryInterface $createLimiter,
    ): Response {
        if ($limited = $this->checkRateLimit($request, $createLimiter)) {
            return $limited;
        }

        if (!$this->isCsrfTokenValid('burnnote_create', $request->request->get('_csrf_token'))) {
            $this->securityLogger->warning('burnnote.csrf_fail', ['action' => 'create', 'ip' => $request->getClientIp()]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $secret     = trim($request->request->get('secret', ''));
        $ttl        = (int) $request->request->get('ttl', 24);
        $maxViews   = (int) $request->request->get('max_views', 1);
        $passphrase = trim($request->request->get('passphrase', ''));

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

        if (strlen($passphrase) > 128) {
            return $this->render('burnnote/create.html.twig', [
                'error' => 'La passphrase ne peut pas dépasser 128 caractères.',
            ]);
        }

        $ttl = max(1, min(720, $ttl));

        // 101 = illimité (valeur sentinelle du slider) → stocké en NULL
        $unlimited = $maxViews >= 101;
        $maxViews  = $unlimited ? null : max(1, min(100, $maxViews));

        $encrypted = $encryption->encrypt($secret);

        $note = new BurnNote();
        $note->setPayload($encrypted['payload']);
        $note->setNonce($encrypted['nonce']);
        $note->setViewsRemaining($maxViews);
        $note->setExpiresAt(new \DateTimeImmutable("+{$ttl} hours"));

        if ($passphrase !== '') {
            $note->setPassphraseHash(password_hash($passphrase, PASSWORD_ARGON2ID));
        }

        $em->persist($note);
        $em->flush();

        $url = $this->generateUrl('burnnote_show', ['token' => $note->getToken()], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);

        $this->securityLogger->info('burnnote.created', [
            'ip'       => $request->getClientIp(),
            'ttl_h'    => $ttl,
            'maxViews' => $unlimited ? 'unlimited' : $maxViews,
            'token'    => substr($note->getToken(), 0, 8),
        ]);

        $request->getSession()->set('burnnote_created', [
            'url'          => $url,
            'maxViews'     => $note->getViewsRemaining(),
            'hasPassphrase' => $note->hasPassphrase(),
        ]);

        return $this->redirectToRoute('burnnote_created');
    }

    private function checkRateLimit(Request $request, RateLimiterFactoryInterface $factory): ?Response
    {
        $limiter = $factory->create($request->getClientIp());
        if (!$limiter->consume(1)->isAccepted()) {
            $this->securityLogger->warning('burnnote.rate_limit', [
                'ip'   => $request->getClientIp(),
                'path' => $request->getPathInfo(),
            ]);
            return new Response('Trop de requêtes. Réessayez dans une minute.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        return null;
    }

    #[Route('/{token}', name: 'show', methods: ['GET'])]
    public function show(string $token, Request $request, BurnNoteRepository $repository, EntityManagerInterface $em, #[Target('burnNoteLimiter')] RateLimiterFactoryInterface $burnnoteRateLimiter): Response
    {
        if ($limited = $this->checkRateLimit($request, $burnnoteRateLimiter)) {
            return $limited;
        }

        $note = $repository->findOneBy(['token' => $token]);

        if (!$note || !$note->isAccessible()) {
            if ($note) {
                $em->remove($note);
                $em->flush();
            }
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        if ($note->hasPassphrase()) {
            return $this->noStore($this->render('burnnote/unlock.html.twig', [
                'token' => $token,
            ]));
        }

        return $this->noStore($this->render('burnnote/preview.html.twig', [
            'token'    => $token,
            'maxViews' => $note->getViewsRemaining(),
            'expiresAt' => $note->getExpiresAt(),
        ]));
    }

    #[Route('/{token}/burn', name: 'burn', methods: ['POST'])]
    public function burn(
        string $token,
        Request $request,
        BurnNoteRepository $repository,
        EntityManagerInterface $em,
        #[Target('burnNoteLimiter')] RateLimiterFactoryInterface $burnnoteRateLimiter,
    ): Response {
        if ($limited = $this->checkRateLimit($request, $burnnoteRateLimiter)) {
            return $limited;
        }

        if (!$this->isCsrfTokenValid('burnnote_burn', $request->request->get('_csrf_token'))) {
            $this->securityLogger->warning('burnnote.csrf_fail', ['action' => 'burn', 'ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $note = $repository->findOneBy(['token' => $token]);

        if ($note && !$note->isExpired()) {
            $em->remove($note);
            $em->flush();
            $this->securityLogger->info('burnnote.burned', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
        }

        return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
    }

    #[Route('/{token}/unlock', name: 'unlock', methods: ['POST'])]
    public function unlock(
        string $token,
        Request $request,
        BurnNoteRepository $repository,
        EncryptionService $encryption,
        EntityManagerInterface $em,
        #[Target('burnNoteUnlockLimiter')] RateLimiterFactoryInterface $unlockLimiter,
    ): Response {
        if (!$this->isCsrfTokenValid('burnnote_unlock_' . $token, $request->request->get('_csrf_token'))) {
            $this->securityLogger->warning('burnnote.csrf_fail', ['action' => 'unlock', 'ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        // Rate limiter indexé sur le token — borne les tentatives même depuis plusieurs IPs
        $limiter = $unlockLimiter->create('bn_unlock_' . $token);
        if (!$limiter->consume(1)->isAccepted()) {
            $this->securityLogger->warning('burnnote.unlock_rate_limit', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            return $this->noStore($this->render('burnnote/unlock.html.twig', [
                'token' => $token,
                'error' => 'Trop de tentatives. Réessayez dans une minute.',
            ], new Response('', Response::HTTP_TOO_MANY_REQUESTS)));
        }

        $note = $repository->findOneBy(['token' => $token]);

        if (!$note || !$note->isAccessible()) {
            if ($note) { $em->remove($note); $em->flush(); }
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        if (!password_verify($request->request->get('passphrase', ''), $note->getPassphraseHash() ?? '')) {
            $this->securityLogger->warning('burnnote.unlock_failed', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            return $this->noStore($this->render('burnnote/unlock.html.twig', [
                'token' => $token,
                'error' => 'Passphrase incorrecte.',
            ]));
        }

        // Passphrase valide : consommer la vue et révéler
        $note = $repository->consumeView($token);
        if (!$note) {
            $this->securityLogger->warning('burnnote.reveal_expired', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        $secret = $encryption->decrypt($note->getPayload(), $note->getNonce());

        $this->securityLogger->info('burnnote.revealed', [
            'ip'             => $request->getClientIp(),
            'token'          => substr($token, 0, 8),
            'viewsRemaining' => $note->getViewsRemaining(),
            'via'            => 'unlock',
        ]);

        if ($note->getViewsRemaining() <= 0) {
            $em->remove($note);
            $em->flush();
        }

        return $this->noStore($this->render('burnnote/show.html.twig', [
            'secret'         => $secret,
            'viewsRemaining' => $note->getViewsRemaining(),
            'expiresAt'      => $note->getExpiresAt(),
            'token'          => $token,
        ]));
    }

    #[Route('/{token}', name: 'reveal', methods: ['POST'])]
    public function reveal(
        string $token,
        Request $request,
        BurnNoteRepository $repository,
        EncryptionService $encryption,
        EntityManagerInterface $em,
        #[Target('burnNoteLimiter')] RateLimiterFactoryInterface $burnnoteRateLimiter,
    ): Response {
        if ($limited = $this->checkRateLimit($request, $burnnoteRateLimiter)) {
            return $limited;
        }

        if (!$this->isCsrfTokenValid('burnnote_reveal', $request->request->get('_csrf_token'))) {
            $this->securityLogger->warning('burnnote.csrf_fail', ['action' => 'reveal', 'ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        // Note protégée par passphrase : passer par /unlock
        $rawNote = $repository->findOneBy(['token' => $token]);
        if ($rawNote && $rawNote->hasPassphrase()) {
            return $this->noStore($this->render('burnnote/unlock.html.twig', [
                'token' => $token,
            ]));
        }

        // UPDATE atomique — garantit qu'une seule requête concurrente obtient la vue
        $note = $repository->consumeView($token);

        if (!$note) {
            $expired = $repository->findOneBy(['token' => $token]);
            if ($expired) {
                $em->remove($expired);
                $em->flush();
            }
            $this->securityLogger->warning('burnnote.reveal_expired', ['ip' => $request->getClientIp(), 'token' => substr($token, 0, 8)]);
            return $this->render('burnnote/expired.html.twig', [], new Response('', Response::HTTP_GONE));
        }

        $secret = $encryption->decrypt($note->getPayload(), $note->getNonce());

        $this->securityLogger->info('burnnote.revealed', [
            'ip'             => $request->getClientIp(),
            'token'          => substr($token, 0, 8),
            'viewsRemaining' => $note->getViewsRemaining(),
        ]);

        if ($note->getViewsRemaining() <= 0) {
            $em->remove($note);
            $em->flush();
        }

        return $this->noStore($this->render('burnnote/show.html.twig', [
            'secret'         => $secret,
            'viewsRemaining' => $note->getViewsRemaining(),
            'expiresAt'      => $note->getExpiresAt(),
            'token'          => $token,
        ]));
    }
}
