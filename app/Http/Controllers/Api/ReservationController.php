<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReserveDropRequest;
use App\Models\Drop;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(
        protected ReservationService $reservationService
    ) {}

    /**
     * Reserve stock for a flash drop.
     *
     * Creates an idempotent pending reservation for the specified flash drop.
     *
     * @response 201 array{data: array{uuid: string, drop_uuid: string, quantity: int, status: string, expires_at: string}}
     * @response 403 array{message: string, code: string}
     * @response 409 array{message: string, code: string}
     * @response 422 array{message: string, code: string}
     * @response 503 array{message: string, code: string}
     */
    public function reserve(ReserveDropRequest $request, Drop $drop): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $quantity = (int) $request->validated('quantity');

        $result = $this->reservationService->reserve($user, $drop, $quantity, $idempotencyKey);

        $headers = [];
        if ($result['replayed']) {
            $headers['Idempotent-Replayed'] = 'true';
        }

        return response()->json($result['body'], $result['status'], $headers);
    }

    /**
     * Confirm a pending reservation.
     *
     * Confirms a previously created pending reservation before it expires.
     *
     * @response 200 array{data: array{uuid: string, drop_uuid: string, quantity: int, status: string, expires_at: string}}
     * @response 403 array{message: string, code: string}
     * @response 404 array{message: string, code: string}
     * @response 409 array{message: string, code: string}
     * @response 422 array{message: string, code: string}
     */
    public function confirm(Request $request, Reservation $reservation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $confirmed = $this->reservationService->confirm($user, $reservation->uuid);

        return response()->json([
            'data' => [
                'uuid' => $confirmed->uuid,
                'drop_uuid' => $confirmed->drop_uuid,
                'quantity' => $confirmed->quantity,
                'status' => $confirmed->status->value,
                'expires_at' => $confirmed->expires_at->toIso8601String(),
            ],
        ], 200);
    }
}
