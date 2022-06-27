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
        $request->validate([
            'name' => 'required|max:255',
            'price' => 'required',
            'objModel' => 'file',
            'gltfModel' => 'file',
            'thumbnail' => 'file|required',
        ]);

        $objModel = $request->file('objModel');
        $objUrl = null;

        if ($objModel != null) {
            $objModelName = uniqid() . '.' . $objModel->getClientOriginalExtension();

            $objModelPath = Storage::putFileAs(
                'public/obj_files', $objModel, $objModelName
            );

            $objUrl = Storage::url($objModelPath);
        }

        $gltfModel = $request->file('gltfModel');
        $gltfUrl = null;

        if ($gltfModel != null) {
            $gltfModelName = uniqid() . '.' . $gltfModel->getClientOriginalExtension();

            $gltfModelPath = Storage::putFileAs(
                'public/gltf_files', $gltfModel, $gltfModelName
            );

            $gltfUrl = Storage::url($gltfModelPath);
        }

        $thumbnail = $request->file('thumbnail');
        $thumbnailName = uniqid().'.'.$thumbnail->getClientOriginalExtension();

        $thumbnailPath = Storage::putFileAs(
            'public/thumbnails', $thumbnail, $thumbnailName
        );

        $thumbnailUrl = Storage::url($thumbnailPath);

        $newProduct = [
            'name' => $request->input('name'),
            'price' => $request->input('price'),
            'description'=> $request->input('description'),
            'obj_file_path' => $objUrl,
            'gltf_file_path' => $gltfUrl,
            'thumbnail_path' => $thumbnailUrl,
            'user_id' => Auth::user()->getAuthIdentifier()
        ];

        return Product::create($newProduct);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $product = Product::find($id);

        if (!Auth::check()) {
            return response()->json($product);
        }

        $userId = Auth::user()->getAuthIdentifier();
        $productStatus = 'not-purchased';

        if ($product['user_id'] == $userId) {
            $productStatus = 'owner';
        }
        else {
            $sale = DB::table('sales')
                ->where('product_id', $product['id'])
                ->where('buyer_id', $userId)
                ->first();
            if ($sale) {
                $productStatus = 'purchased';
            }
        }

        $product->product_status = $productStatus;

        return response()->json($product);
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
