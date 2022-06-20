<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class SalesController extends BaseController
{
    public function makeSale(Request $request) {
        $request->validate([
            "products" => "required|array|min:1",
            'products.*.id' => 'required|exists:products,id'
        ]);

        $buyerId = Auth::user()->getAuthIdentifier();

        if ($buyerId == $request['seller_id']) {
            // todo: return proper error
            return;
        }

        $sales = [];
        foreach ($request->input('products') as $item) {
            $product = Product::find($item['id']);

            $newSale = [
                'buyer_id' => $buyerId,
                'product_id' => $product['id'],
                'price' => $product['price'],
                'seller_id' => $product['user_id']
            ];

            $saleDb = Sale::create($newSale);
            array_push($sales, $saleDb);
        }
        return response()->json($sales);
    }
}
