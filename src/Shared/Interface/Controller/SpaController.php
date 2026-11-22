<?php

declare(strict_types=1);

namespace App\Shared\Interface\Controller;

use App\Shared\Application\Http\PublicOrigins;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SpaController extends AbstractController
{
    public function __construct(private readonly PublicOrigins $publicOrigins)
    {
    }

    #[Route('/', name: 'spa_index', methods: ['GET'], priority: -10)]
    #[Route('/{path}', name: 'spa_catchall', requirements: ['path' => '^(?!api).+'], methods: ['GET'], priority: -10)]
    public function index(Request $request): Response
    {
        return $this->render('spa.html.twig', [
            'api_url' => $this->publicOrigins->forRequest($request),
        ]);
    }
}
