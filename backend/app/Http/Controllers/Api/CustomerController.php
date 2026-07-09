<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\PosPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /** Search by phone/NTN/name/CNIC - used by the checkout "Attach customer" step. */
    public function index(Request $request)
    {
        $customers = Customer::query()
            ->where('is_active', true)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->string('search')->toString();
                $digits = preg_replace('/\D/', '', $term);
                $like = '%' . $term . '%';

                $q->where(function ($q2) use ($like, $digits) {
                    $q2->where('name', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like);

                    if ($digits !== '') {
                        $q2->orWhere('ntn', $digits)->orWhere('cnic', $digits);
                    }
                });
            })
            ->orderBy('name')
            ->limit($request->integer('limit', 20))
            ->get();

        return CustomerResource::collection($customers);
    }

    /** Quick-create during checkout - deliberately not permission-gated (any cashier can do this). */
    public function store(StoreCustomerRequest $request)
    {
        $customer = Customer::create($request->validated());

        return CustomerResource::make($customer->refresh())->response()->setStatusCode(201);
    }

    public function show(Customer $customer)
    {
        return CustomerResource::make($customer);
    }

    public function update(StoreCustomerRequest $request, Customer $customer)
    {
        if (! $request->user()->can(PosPermissions::CUSTOMER_MANAGE)) {
            throw new AuthorizationException();
        }

        $customer->update($request->validated());

        return CustomerResource::make($customer);
    }
}
