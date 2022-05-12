<?php

namespace App\Http\Controllers;

use http\Env\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class ProductController extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return Product::all();
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
            'obj_file_path' => $path
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

        $headers = [
            'Content-Type' => 'application/pdf',
        ];

        $fileUrl = Storage::url('obj_files/'.$product['obj_file_path']);

        return response()->json([
            'product' => $product,
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

}
