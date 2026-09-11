<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesController extends BaseController
{
    public function getSalesForAuthenticatedUser() {
        $userId = Auth::user()->getAuthIdentifier();

        $sales = DB::table('sales')
            ->join('products', 'sales.product_id', '=', 'products.id')
            ->join('users', 'sales.buyer_id', '=', 'users.id')
            ->where('products.user_id', $userId)
            ->select('sales.id as id',
                'sales.buyer_id as buyer_id',
                'users.name as buyer_name',
                'products.id as product_id',
                'products.name as product_name',
                'sales.price_cents as price_cents')
            ->orderByDesc('sales.id')
            ->get();

        return $sales;
    }
}
