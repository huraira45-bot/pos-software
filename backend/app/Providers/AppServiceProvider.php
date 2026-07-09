<?php

namespace App\Providers;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every other controller in this API returns response()->json($array)
        // directly (no "data" wrapper) - without this, JsonResource-based
        // endpoints (SaleController, ProductController, ReturnController)
        // would be the only ones nesting the payload under "data", an
        // inconsistency clients would have to special-case per endpoint.
        JsonResource::withoutWrapping();
    }
}
