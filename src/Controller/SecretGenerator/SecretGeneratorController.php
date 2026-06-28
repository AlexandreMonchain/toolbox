<?php

namespace App\Controller\SecretGenerator;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/secret', name: 'secret_')]
class SecretGeneratorController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('secret_generator/index.html.twig');
    }
}
