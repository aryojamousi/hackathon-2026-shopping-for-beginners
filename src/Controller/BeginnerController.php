<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BeginnerController extends AbstractController
{
    #[Route('/beginner', name: 'app_beginner')]
    public function index(): Response
    {
        // Fixed beginner recommendations — always show these three products.
        $articles = [
            [
                'artid' => 215724,
                'artnr' => '315480',
                'brand' => 'Harley Benton',
                'name' => 'TE-20 SB Standard Series Set 3',
                'price' => 209.00,
                'manufacturer' => null,
                'image' => 'https://fast-images.static-thomann.de/pics/bdb/_31/315480/12705047_800.jpg',
                'badge' => 'BEST MATCH',
                'highlight' => true,
                'rating' => 5,
                'reviews' => 7722,
                'stockLabel' => 'In stock',
                'stockStatus' => 'in',
                'whyName' => 'Harley Benton TE-20 SB Standard Series Set 3',
                'whyText' => 'A timeless guitar design seen on countless punk and rock stages. This set comes with the most powerful amp of the three — nice to have if there’s room to turn it up. 👉 Best pick for: punk and classic rock with a classic look.',
            ],
            [
                'artid' => 380600,
                'artnr' => '480181',
                'brand' => 'Harley Benton',
                'name' => 'ST-20HSS Standard SBK Set',
                'price' => 229.00,
                'manufacturer' => null,
                'image' => 'https://fast-images.static-thomann.de/pics/bdb/_48/480181/14693146_800.jpg',
                'badge' => 'COMPLETE SET',
                'highlight' => false,
                'rating' => 5,
                'reviews' => 126,
                'stockLabel' => 'In stock',
                'stockStatus' => 'in',
                'whyName' => 'Harley Benton ST-20HSS Standard SBK Bundle',
                'whyText' => 'Handles rock and punk well, but also pop, blues, and everything in between — great if your teen’s music taste is still changing. It’s also the most affordable set, so it’s perfect for trying a new hobby without a big commitment. 👉 Best pick for: keeping options open at the lowest price.',
            ],
            [
                'artid' => 437716,
                'artnr' => '537058',
                'brand' => 'Harley Benton',
                'name' => 'S-620 TB Rock Series Bundle',
                'price' => 249.00,
                'manufacturer' => null,
                'image' => 'https://fast-images.static-thomann.de/pics/bdb/_53/537058/16967260_800.jpg',
                'badge' => 'ROOM TO GROW',
                'highlight' => false,
                'rating' => 5,
                'reviews' => 429,
                'stockLabel' => 'In stock',
                'stockStatus' => 'in',
                'whyName' => 'Harley Benton S-620 TB Rock Series Bundle',
                'whyText' => 'Built exactly for the music your teen loves. Its pickups (the parts that “hear” the strings) are made for heavy, distorted rock and metal sounds. If they dream of playing like their favorite band, this is the one. 👉 Best pick for: rock & metal, all in.',
            ],
        ];

        // Placeholder accessories for the slider — real data to be provided later.
        $accessories = [
            ['name' => 'Millenium GS-2001 E', 'price' => 9.90, 'image' => null],
            ['name' => 'Harley Benton GW-Lock', 'price' => 5.90, 'image' => null],
            ['name' => 'Ernie Ball 2221 Regular Slinky', 'price' => 5.90, 'image' => null],
            ['name' => 'Daddario EXL110', 'price' => 7.90, 'image' => null],
            ['name' => 'the sssnake IPP1030', 'price' => 3.50, 'image' => null],
            ['name' => 'Fender Deluxe Cable 3m', 'price' => 24.90, 'image' => null],
            ['name' => 'Rockbag RB 20606 B Gig Bag', 'price' => 22.90, 'image' => null],
            ['name' => 'Vox amPlug 2 AC30', 'price' => 44.00, 'image' => null],
            ['name' => 'Thomann Guitar Stand', 'price' => 8.90, 'image' => null],
            ['name' => 'Dunlop Picks Variety Pack', 'price' => 4.50, 'image' => null],
        ];

        // Useful content links shown below the slider.
        $usefulContent = [
            [
                'type' => 'Video',
                'title' => 'Electric guitars — the big beginner guide',
                'description' => 'Everything about body shapes, pickups and amps, explained without jargon.',
                'url' => '#',
            ],
            [
                'type' => 'Blog post',
                'title' => 'The best electric guitars for beginners',
                'description' => 'Our current favourites for starting out — tested and compared by the Thomann team.',
                'url' => '#',
            ],
            [
                'type' => 'Online guide',
                'title' => 'Guitar amps for home practice',
                'description' => 'Which amp size and features make sense for practising at home.',
                'url' => '#',
            ],
            [
                'type' => 'Blog post',
                'title' => 'Your first 10 songs: easy riffs to start with',
                'description' => 'Motivating first songs that only need a few chords and simple riffs.',
                'url' => '#',
            ],
        ];

        return $this->render('beginner/index.html.twig', [
            'articles' => $articles,
            'accessories' => $accessories,
            'usefulContent' => $usefulContent,
        ]);
    }
}
