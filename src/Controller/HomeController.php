<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        $categories = [
            ['icon' => '🎸', 'label' => 'Guitars & Basses'],
            ['icon' => '🥁', 'label' => 'Drums & Percussion'],
            ['icon' => '🎹', 'label' => 'Keyboards'],
            ['icon' => '🎛️', 'label' => 'Studio & Recording'],
            ['icon' => '💿', 'label' => 'Software'],
            ['icon' => '🔊', 'label' => 'PA & Sound'],
            ['icon' => '💡', 'label' => 'Lighting & Stage'],
            ['icon' => '🎧', 'label' => 'DJ Equipment'],
            ['icon' => '🎤', 'label' => 'Microphones'],
        ];

        return $this->render('home/index.html.twig', [
            'userName' => 'Aryo',
            'categories' => $categories,
        ]);
    }
}
