<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FindGuitarController extends AbstractController
{
    #[Route('/find-guitar', name: 'app_find_guitar', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('find_guitar/index.html.twig');
    }
}
