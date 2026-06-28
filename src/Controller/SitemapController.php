<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    /** Pages publiques indexables : route => priorité. Les pages à secret (token) en sont exclues. */
    private const PAGES = [
        'app_home'        => '1.0',
        'burnnote_index'  => '0.9',
        'droptext_index'  => '0.9',
        'csr_index'       => '0.8',
        'subnet_index'    => '0.8',
        'base64_index'    => '0.7',
        'diff_index'      => '0.7',
        'qrcode_index'    => '0.7',
        'secret_index'    => '0.7',
        'cron_index'      => '0.7',
        'cmdgen_index'    => '0.7',
        'converter_index' => '0.7',
        'percentage_index'=> '0.6',
        'keyboard_index'  => '0.6',
        'incident_index'  => '0.6',
    ];

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [];
        foreach (self::PAGES as $route => $priority) {
            $urls[] = [
                'loc'        => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'monthly',
                'priority'   => $priority,
            ];
        }

        $response = new Response(
            $this->renderView('sitemap/sitemap.xml.twig', ['urls' => $urls]),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml'],
        );
        $response->setSharedMaxAge(86400); // cache 24h

        return $response;
    }
}
