<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with('variants')
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . mb_strtolower($request->string('search')) . '%';
                $q->where(fn ($q2) => $q2->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(item_code) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', [$term]));
            })
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        // refresh() so the response reflects Postgres column defaults (is_active,
        // reorder_level) for any field the request omitted - the in-memory model
        // create() returns only has what was explicitly assigned, not what the
        // database actually applied.
        $product = Product::create($request->validated())->refresh();

        return ProductResource::make($product)->response()->setStatusCode(201);
    }

    public function show(Product $product)
    {
        return ProductResource::make($product->load('variants'));
    }

    public function update(StoreProductRequest $request, Product $product)
    {
        $product->update($request->validated());

        return ProductResource::make($product);
    }
}
