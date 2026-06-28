<?php

namespace App\Controller\KeyboardTester;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/keyboard', name: 'keyboard_')]
class KeyboardTesterController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('keyboard_tester/index.html.twig');
    }
}
