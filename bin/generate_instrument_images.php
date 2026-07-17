<?php

/**
 * Generate one image per instrument category for the "Find your perfect
 * instrument" picker bubbles, and save them as local assets under
 * public/images/instruments/. Re-runnable (overwrites existing files).
 *
 *   php bin/generate_instrument_images.php
 *
 * Uses a text-to-image service (pollinations.ai) via PHP's HTTP stack.
 */

$targetDir = __DIR__.'/../public/images/instruments';
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Could not create $targetDir\n");
    exit(1);
}

/** slug => image subject */
$instruments = [
    'electric-guitar' => 'electric guitar',
    'acoustic-guitar' => 'acoustic guitar',
    'drums' => 'drum kit',
    'piano' => 'grand piano',
    'singer' => 'vocalist singing into a studio microphone',
    'dj' => 'DJ mixing on turntables with headphones',
    'producer' => 'music producer at a studio mixing console',
    'violin' => 'violin',
    'trumpet' => 'trumpet',
    'marimba' => 'marimba percussion instrument',
];

$style = ', studio product photo, centered, plain light grey background, high detail';

foreach ($instruments as $slug => $subject) {
    $prompt = rawurlencode($subject.$style);
    $url = "https://image.pollinations.ai/prompt/$prompt?width=512&height=512&nologo=true";
    $ctx = stream_context_create(['http' => ['timeout' => 120, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);

    echo "Generating $slug ... ";
    $data = @file_get_contents($url, false, $ctx);
    if (false === $data || \strlen($data) < 1000 || "\xff\xd8" !== substr($data, 0, 2)) {
        echo "FAILED\n";
        continue;
    }
    file_put_contents("$targetDir/$slug.jpg", $data);
    echo strlen($data)." bytes\n";
}

echo "Done. Images in $targetDir\n";
