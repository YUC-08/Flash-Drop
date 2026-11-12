<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Scramble::afterOpenApiGenerated(function (OpenApi $openApi) {
            $openApi->secure(
                SecurityScheme::http('bearer')
            );

            foreach ($openApi->paths as $path) {
                if (str_contains($path->path, '/auth/token')) {
                    foreach ($path->operations as $operation) {
                        $operation->security = [];
                    }
                }

                if (str_contains($path->path, '/drops/{drop}/reserve')) {
                    $operation = $path->operations['post'] ?? null;
                    if ($operation) {
                        $hasHeader = collect($operation->parameters)->contains(fn ($p) => ($p->name ?? '') === 'Idempotency-Key');
                        if (! $hasHeader) {
                            $param = new Parameter('Idempotency-Key', 'header');
                            $param->required = true;
                            $param->description = 'Idempotency key in UUID format.';
                            $param->setSchema(Schema::fromType((new StringType)->format('uuid')));
                            $operation->parameters[] = $param;
                        }

                        $operation->responses = [
                            Response::make(201)->description('Reservation created successfully.'),
                            Response::make(403)->description('Drop not started or ended.'),
                            Response::make(409)->description('Conflict - already reserved or insufficient stock.'),
                            Response::make(422)->description('Validation error or idempotency key reused.'),
                            Response::make(503)->description('Service busy (lock timeout).'),
                        ];
                    }
                }

                if (str_contains($path->path, '/reservations/{reservation}/confirm')) {
                    $operation = $path->operations['post'] ?? null;
                    if ($operation) {
                        $operation->responses = [
                            Response::make(200)->description('Reservation confirmed successfully.'),
                            Response::make(403)->description('Forbidden - reservation belongs to another user.'),
                            Response::make(409)->description('Conflict - reservation is not pending.'),
                            Response::make(422)->description('Reservation expired.'),
                        ];
                    }
                }
            }
        });
    }
}
