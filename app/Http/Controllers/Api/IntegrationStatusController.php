<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class IntegrationStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'available']);
    }
}
