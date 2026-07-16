<?php

namespace App\Controller;

use App\Repository\GuideRepository;
use App\Repository\LearnArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GuideController extends AbstractController
{
    #[Route('/guides', name: 'app_guides')]
    public function index(
        GuideRepository $guides,
        LearnArticleRepository $articles,
    ): Response {
        return $this->render('guide/index.html.twig', [
            'guidesByCategory' => $guides->findAllGroupedByCategory(),
            'articleCounts' => $articles->countByCategory(),
            'latestArticles' => $articles->findLatest(12),
        ]);
    }

    #[Route('/guides/{category}', name: 'app_guides_category')]
    public function category(
        string $category,
        GuideRepository $guides,
        LearnArticleRepository $articles,
    ): Response {
        return $this->render('guide/category.html.twig', [
            'category' => $category,
            'guides' => $guides->findByCategory($category),
            'articles' => $articles->findByCategory($category),
        ]);
    }
}
