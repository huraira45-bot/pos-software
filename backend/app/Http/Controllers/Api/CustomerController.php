<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Customer;
use App\Models\Invoice;
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
                $like = '%' . mb_strtolower($term) . '%';

                $q->where(function ($q2) use ($like, $digits) {
                    $q2->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(contact_person) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(strn) LIKE ?', [$like]);

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
        $customer->load([
            'invoices' => fn ($query) => $query
                ->with('items')
                ->where('invoice_type', Invoice::TYPE_NEW)
                ->orderByDesc('sold_at')
                ->limit(10),
        ]);
        $customer->setAttribute('sales_summary', $this->salesSummary($customer));

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

    public function sales(Request $request, Customer $customer)
    {
        $invoices = $customer->invoices()
            ->with('items')
            ->where('invoice_type', Invoice::TYPE_NEW)
            ->when($request->date('from'), fn ($q, $v) => $q->where('sold_at', '>=', $v))
            ->when($request->date('to'), fn ($q, $v) => $q->where('sold_at', '<=', $v))
            ->orderByDesc('sold_at')
            ->paginate($request->integer('per_page', 25));

        return InvoiceResource::collection($invoices);
    }

    private function salesSummary(Customer $customer): array
    {
        $query = $customer->invoices()->where('invoice_type', Invoice::TYPE_NEW);
        $salesCount = (clone $query)->count();
        $totalSalesAmount = (clone $query)->sum('total_bill_amount');
        $totalTaxCharged = (clone $query)->sum('total_tax_charged');
        $lastSaleAt = (clone $query)->max('sold_at');
        $openingBalance = (float) ($customer->opening_balance ?? 0);
        $creditLimit = (float) ($customer->credit_limit ?? 0);

        return [
            'sales_count' => $salesCount,
            'total_sales_amount' => number_format((float) $totalSalesAmount, 2, '.', ''),
            'total_tax_charged' => number_format((float) $totalTaxCharged, 2, '.', ''),
            'last_sale_at' => $lastSaleAt ? (string) $lastSaleAt : null,
            'opening_balance' => number_format($openingBalance, 2, '.', ''),
            'account_balance' => number_format($openingBalance, 2, '.', ''),
            'available_credit' => number_format(max(0, $creditLimit - $openingBalance), 2, '.', ''),
        ];
    }
}
