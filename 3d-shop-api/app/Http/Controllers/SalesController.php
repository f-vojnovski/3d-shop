<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SalesController extends BaseController
{
    public function makeSale(Request $request) {
        $request->validate([
            "products" => "required|array|min:1",
            'products.*.id' => 'required|exists:products,id'
        ]);

        $buyerId = Auth::user()->getAuthIdentifier();

        DB::beginTransaction();

        $sales = [];
        foreach ($request->input('products') as $item) {
            $product = Product::find($item['id']);

            // check if user buying product from themselves
            //        if ($buyerId == $request['seller_id']) {
            //            return;
            //        }

            $newSale = [
                'buyer_id' => $buyerId,
                'product_id' => $product['id'],
                'price' => $product['price'],
            ];

            $existingSale = Sale::where('buyer_id', $newSale['buyer_id'])
                ->where('product_id', $newSale['product_id'])
                ->first();

            if ($existingSale != null) {
                // todo: return proper error
                DB::rollBack();
                return;
            }

            $saleDb = Sale::create($newSale);
            array_push($sales, $saleDb);
        }

        DB::commit();

        return response()->json($sales);
    }

    public function getSalesForAuthenticatedUser() {
        $userId = Auth::user()->getAuthIdentifier();

        $sales = DB::table('sales')
            ->join('products', 'sales.product_id', '=', 'products.id')
            ->join('users', 'sales.buyer_id', '=', 'users.id')
            ->where('user_id', $userId)
            ->select('sales.id as id',
                'sales.buyer_id as buyer_id',
                'users.name as buyer_name',
                'products.id as product_id',
                'products.name as product_name',
                'sales.price as price')
            ->get();

        return $sales;
    }
}
