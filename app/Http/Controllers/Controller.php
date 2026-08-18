<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 12's skeleton controller is deliberately bare. AuthorizesRequests is
 * added here because every API controller in this project gates access with
 * policies (AT-007), and authorizeResource() lives in that trait.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
