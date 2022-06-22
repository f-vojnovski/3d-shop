<?php

namespace App\Http\Controllers;

use http\Env\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class ProductController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return Product::orderBy("id")->paginate(16);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|max:255',
            'price' => 'required',
            'model' => 'required|file'
        ]);

        $tdmodel = $request->file('model');
        $tdmodelname = uniqid().'.'.$tdmodel->getClientOriginalExtension();

        $path = Storage::putFileAs(
            'public/obj_files', $tdmodel, $tdmodelname
        );

        $newProduct = [
            'name' => $request->input('name'),
            'price' => $request->input('price'),
            'description'=> $request->input('description'),
            'obj_file_path' => $path,
            'user_id' => Auth::user()->getAuthIdentifier()
        ];

        return Product::create($newProduct);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        return Product::find($id);
    }

    /**
     * Download a 3d model if one is found in database
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProductModelUrl($id)
    {
        $product = Product::find($id);

        $fileUrl = Storage::url($product['obj_file_path']);

        return response()->json([
            'fileUrl' => $fileUrl
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);
        $product->update($request->all());
        return $product;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        return Product::destroy($id);
    }


    /**
     * Search for a name
     * @param string name
     * @return \Illuminate\Http\Response
     */
    public function search($name) {
        return Product::where('name', 'like', '%'.$name.'%')->get();
    }

    /**
     * Get products for current user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function getCurrentUserProducts() {
        $userId = Auth::user()->getAuthIdentifier();
        return Product::orderBy("id")->where('user_id', $userId)->paginate(16);
    }

    /**
     *
     *
     * @param  int  $userId
     * @return \Illuminate\Http\Response
     */
    public function getProductsForUser($userId) {
        return Product::orderBy("id")->where('user_id', $userId)->paginate(16);
    }

    public function getPurchasedProductsForUser() {
        $userId = Auth::user()->getAuthIdentifier();
        return DB::table('products')
            ->join('sales', 'sales.product_id', '=', 'products.id')
            ->where('sales.buyer_id', $userId)
            ->select('products.id as id',
                'products.name as name',
                'products.price as price',
                'products.description as description',
                'products.obj_file_path as obj_file_path',
                'products.user_id as user_id')
            ->paginate(16);
    }
}
