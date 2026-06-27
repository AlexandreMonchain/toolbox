<?php

namespace App\Controller\DropText;

use App\Entity\DropText;
use App\Repository\DropTextRepository;
use App\Service\DropText\EncryptionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/droptext', name: 'droptext_')]
class DropTextController extends AbstractController
{
    public function __construct(
        private readonly EncryptionService  $encryption,
        private readonly DropTextRepository $repository,
        #[Target('securityLogger')] private readonly LoggerInterface $securityLogger,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('droptext/index.html.twig', $this->formVars());
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(
        Request $request,
        #[Target('droptextCreateLimiter')] RateLimiterFactory $limiter,
    ): Response {
        if (!$this->isCsrfTokenValid('droptext_create', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $limit = $limiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return $this->renderError('Trop de créations. Réessayez dans quelques minutes.', 429);
        }

        $content    = $request->request->get('content', '');
        $language   = $request->request->get('language', 'plaintext');
        $passphrase = $request->request->get('passphrase', '');
        $ttlSecs    = (int) $request->request->get('ttl', 86400);
        $maxReads   = (int) $request->request->get('max_reads', 0);

        if (trim($content) === '') {
            return $this->renderError('Le contenu ne peut pas être vide.');
        }
        if (strlen($content) > DropText::MAX_CONTENT_BYTES) {
            return $this->renderError('Le contenu dépasse la limite de 512 Ko.');
        }
        if (strlen($passphrase) > DropText::MAX_PASSPHRASE_LEN) {
            return $this->renderError('La passphrase dépasse 128 caractères.');
        }
        if (!array_key_exists($language, DropText::LANGUAGES)) {
            $language = 'plaintext';
        }
        if (!array_key_exists($ttlSecs, DropText::TTL_OPTIONS)) {
            $ttlSecs = 86400;
        }
        if (!array_key_exists($maxReads, DropText::MAX_READS_OPTIONS)) {
            $maxReads = 0;
        }

        $encrypted = $this->encryption->encrypt($content);

        $note = new DropText();
        $note->setPayload($encrypted['payload']);
        $note->setNonce($encrypted['nonce']);
        $note->setLanguage($language);
        $note->setExpiresAt(new \DateTimeImmutable(sprintf('+%d seconds', $ttlSecs)));
        $note->setMaxReads($maxReads > 0 ? $maxReads : null);

        if ($passphrase !== '') {
            $note->setPassphraseHash(password_hash($passphrase, PASSWORD_ARGON2ID));
        }

        $this->repository->save($note);

        $response = $this->render('droptext/created.html.twig', ['note' => $note]);
        $this->noStore($response);
        return $response;
    }

    #[Route('/{token}', name: 'show', methods: ['GET'], requirements: ['token' => '[0-9a-f]{64}'])]
    public function show(string $token): Response
    {
        $note = $this->repository->findActiveByToken($token);
        if ($note === null) {
            return $this->render('droptext/gone.html.twig', [], new Response(status: 404));
        }

        if ($note->hasPassphrase()) {
            return $this->render('droptext/unlock.html.twig', ['token' => $token]);
        }

        // Pas de consommation sur un GET : les aperçus de lien (Slack, Teams,
        // Outlook Safe Links…) ne doivent pas brûler la note. La lecture réelle
        // passe par reveal() en POST.
        return $this->render('droptext/reveal.html.twig', ['note' => $note, 'token' => $token]);
    }

    #[Route('/{token}/reveal', name: 'reveal', methods: ['POST'], requirements: ['token' => '[0-9a-f]{64}'])]
    public function reveal(string $token, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('droptext_reveal_' . $token, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $note = $this->repository->findActiveByToken($token);
        if ($note === null) {
            return $this->render('droptext/gone.html.twig', [], new Response(status: 404));
        }
        // Une note protégée passe toujours par unlock(), jamais par reveal direct.
        if ($note->hasPassphrase()) {
            return $this->render('droptext/unlock.html.twig', ['token' => $token]);
        }

        return $this->doShow($token);
    }

    #[Route('/{token}/unlock', name: 'unlock', methods: ['POST'], requirements: ['token' => '[0-9a-f]{64}'])]
    public function unlock(
        string $token,
        Request $request,
        #[Target('droptextUnlockLimiter')] RateLimiterFactory $limiter,
    ): Response {
        if (!$this->isCsrfTokenValid('droptext_unlock_' . $token, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        // Limiteur indexé sur le token : borne les tentatives de passphrase
        // PAR NOTE (pas seulement par IP, contournable derrière Cloudflare/NAT).
        $limit = $limiter->create('dt_unlock_' . $token)->consume();
        if (!$limit->isAccepted()) {
            return $this->render('droptext/unlock.html.twig', [
                'token' => $token,
                'error' => 'Trop de tentatives. Réessayez dans une minute.',
            ], new Response(status: 429));
        }

        $note = $this->repository->findActiveByToken($token);
        if ($note === null) {
            return $this->render('droptext/gone.html.twig', [], new Response(status: 404));
        }

        $passphrase = $request->request->get('passphrase', '');

        if (!$note->hasPassphrase() || !password_verify($passphrase, $note->getPassphraseHash())) {
            $this->securityLogger->warning('DropText: passphrase incorrecte', [
                'token' => substr($token, 0, 8),
                'ip'    => $request->getClientIp(),
            ]);
            return $this->render('droptext/unlock.html.twig', [
                'token' => $token,
                'error' => 'Passphrase incorrecte.',
            ]);
        }

        return $this->doShow($token);
    }

    #[Route('/{token}/burn', name: 'burn', methods: ['POST'], requirements: ['token' => '[0-9a-f]{64}'])]
    public function burn(
        string $token,
        Request $request,
        #[Target('droptextBurnLimiter')] RateLimiterFactory $limiter,
    ): Response {
        if (!$this->isCsrfTokenValid('droptext_burn_' . $token, $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $limit = $limiter->create($request->getClientIp())->consume();
        if (!$limit->isAccepted()) {
            return new Response('Trop de requêtes. Réessayez dans une minute.', 429);
        }

        $burned = $this->repository->burnByToken($token);

        if ($burned) {
            $this->securityLogger->info('DropText: note brûlée manuellement', [
                'token' => substr($token, 0, 8),
                'ip'    => $request->getClientIp(),
            ]);
        }

        return $this->render('droptext/gone.html.twig', ['manually_burned' => true]);
    }

    private function doShow(string $token): Response
    {
        $note = $this->repository->consumeRead($token);
        if ($note === null) {
            return $this->render('droptext/gone.html.twig', [], new Response(status: 404));
        }

        $content  = $this->encryption->decrypt($note->getPayload(), $note->getNonce());
        $response = $this->render('droptext/show.html.twig', [
            'note'    => $note,
            'content' => $content,
        ]);
        $this->noStore($response);
        return $response;
    }

    private function renderError(string $error, int $status = 422): Response
    {
        return $this->render('droptext/index.html.twig', [
            ...$this->formVars(),
            'error' => $error,
        ], new Response(status: $status));
    }

    private function formVars(): array
    {
        return [
            'languages'         => DropText::LANGUAGES,
            'ttl_options'       => DropText::TTL_OPTIONS,
            'max_reads_options' => DropText::MAX_READS_OPTIONS,
        ];
    }

    private function noStore(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
    }
}
