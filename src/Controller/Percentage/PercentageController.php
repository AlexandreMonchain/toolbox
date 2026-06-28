<?php

namespace App\Controller\Percentage;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/percentage', name: 'percentage_')]
class PercentageController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('percentage/index.html.twig');
    }
}
