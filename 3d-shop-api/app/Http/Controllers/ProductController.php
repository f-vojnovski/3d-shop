<?php

namespace App\Http\Controllers;

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
        return Product::where('unlisted', false)->orderBy("id")->paginate(16);
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
            'description' => 'nullable|max:1000',
            'price' => 'required|numeric|min:0|max:999999.99',
            'objModel' => 'nullable|file|max:51200',
            'gltfModel' => 'nullable|file|max:51200',
            'thumbnail' => 'required|file|image|max:5120',
        ]);

        $objModel = $request->file('objModel');
        $objUrl = null;

        if ($objModel != null) {
            $objModelName = uniqid() . '.' . $objModel->getClientOriginalExtension();

            $objModelPath = Storage::disk('public')->putFileAs(
                'obj_files', $objModel, $objModelName
            );

            $objUrl = Storage::disk('public')->url($objModelPath);
        }

        $gltfModel = $request->file('gltfModel');
        $gltfUrl = null;

        if ($gltfModel != null) {
            $gltfModelName = uniqid() . '.' . $gltfModel->getClientOriginalExtension();

            $gltfModelPath = Storage::disk('public')->putFileAs(
                'gltf_files', $gltfModel, $gltfModelName
            );

            $gltfUrl = Storage::disk('public')->url($gltfModelPath);
        }

        if ($gltfModel == null && $objModel == null) {
            abort(403, 'Must provide model!');
        }

        $thumbnail = $request->file('thumbnail');
        $thumbnailName = uniqid().'.'.$thumbnail->getClientOriginalExtension();

        // Disk named explicitly: the default local disk root is app/private.
        $thumbnailPath = Storage::disk('public')->putFileAs(
            'thumbnails', $thumbnail, $thumbnailName
        );

        $thumbnailUrl = Storage::disk('public')->url($thumbnailPath);

        $newProduct = [
            'name' => $request->input('name'),
            'price_cents' => (int) round($request->input('price') * 100),
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
        $product = Product::findOrFail($id);

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
        $product = Product::findOrFail($id);

        if ($product->user_id != Auth::user()->getAuthIdentifier()) {
            abort(403, 'You are not the owner of this product!');
        }

        $fields = $request->validate([
            'name' => 'sometimes|required|max:255',
            'description' => 'sometimes|nullable|max:1000',
            'price' => 'sometimes|required|numeric|min:0|max:999999.99',
            'unlisted' => 'sometimes|boolean',
        ]);

        if (array_key_exists('price', $fields)) {
            $fields['price_cents'] = (int) round($fields['price'] * 100);
            unset($fields['price']);
        }

        $product->update($fields);

        return $product;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\positive-int
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
        return Product::where('unlisted', false)
            ->where('name', 'like', '%'.$name.'%')
            ->get();
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
        return Product::where('unlisted', false)
            ->orderBy("id")
            ->where('user_id', $userId)
            ->paginate(16);
    }

    public function getPurchasedProductsForUser() {
        $userId = Auth::user()->getAuthIdentifier();
        return DB::table('products')
            ->join('sales', 'sales.product_id', '=', 'products.id')
            ->where('sales.buyer_id', $userId)
            ->select('products.id as id',
                'products.name as name',
                'products.price_cents as price_cents',
                'products.description as description',
                'products.obj_file_path as obj_file_path',
                'products.gltf_file_path as gltf_file_path',
                'products.thumbnail_path as thumbnail_path',
                'products.user_id as user_id')
            ->paginate(16);
    }
}
