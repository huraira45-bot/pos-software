<?php

use App\Exceptions\Fiscal\InvoiceTotalsMismatchException;
use App\Exceptions\Sales\BuyerInfoRequiredException;
use App\Exceptions\Sales\InvalidCartException;
use App\Exceptions\Sales\InvalidReturnException;
use App\Exceptions\Sales\NonAtlConfirmationRequiredException;
use App\Exceptions\Sales\PaymentMismatchException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidCartException|PaymentMismatchException|BuyerInfoRequiredException|InvalidReturnException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });

        $exceptions->render(function (NonAtlConfirmationRequiredException $e, Request $request) {
            if ($request->expectsJson()) {
                // Distinct status + error_code so the frontend can show a
                // confirmation dialog specifically, not a generic error toast.
                return response()->json(['message' => $e->getMessage(), 'error_code' => 'non_atl_confirmation_required'], 409);
            }
        });

        $exceptions->render(function (InvoiceTotalsMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 403);
            }
        });
    })->create();
