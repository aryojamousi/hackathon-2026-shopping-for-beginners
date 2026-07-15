<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    #[Route('/products', name: 'app_products')]
    public function index(ArticleRepository $articleRepository): Response
    {
        return $this->render('product/index.html.twig', [
            'articles' => $articleRepository->findFeatured(12),
        ]);
    }
}
