<?php

declare(strict_types=1);

namespace App\Controllers;

use Arix\HttpFoundation\Response;

class HomeController
{
    public function show(): Response
    {

        return new Response('Arix test application');
    }
}
