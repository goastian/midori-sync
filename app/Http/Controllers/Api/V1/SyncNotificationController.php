<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SyncNotificationTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncNotificationController extends Controller
{
    public function ticket(Request $request, SyncNotificationTickets $tickets): JsonResponse
    {
        return response()->json($tickets->issue($request->input('sync_session')))
            ->header('Cache-Control', 'no-store');
    }
}
