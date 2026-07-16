<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductCardController extends AbstractController
{
    #[Route('/product-card', name: 'app_product_card', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('product_card/index.html.twig');
    }
}
