<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ReservationException extends Exception
{
    /**
     * Create a new reservation exception instance.
     *
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }

    /**
     * Render the exception into an HTTP response.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status, $this->headers);
    }
}
