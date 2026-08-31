<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;

class ProductCategoryController extends Controller
{
    public function index()
    {
        $categories = ProductCategory::all();
        return view('products.categories', compact('categories'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:product_categories,name',
            'code' => 'required|string|max:255|unique:product_categories,code',
        ]);

        ProductCategory::create($request->only('name', 'code'));

        return redirect()->route('product_categories.index')
            ->with('success', 'Category created successfully.');
    }

    public function update(Request $request, $id)
    {
        $productCategory = ProductCategory::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255|unique:product_categories,name,' . $productCategory->id,
            'code' => 'required|string|max:255|unique:product_categories,code,' . $productCategory->id,
        ]);

        $productCategory->update($request->only('name', 'code'));

        return redirect()->route('product_categories.index')
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(ProductCategory $productCategory)
    {
        $productCategory->delete();

        return redirect()->route('product_categories.index')
            ->with('success', 'Category deleted successfully.');
    }

    public function getSubcategories($categoryId)
    {
        try {
            $subcategories = ProductSubcategory::where('category_id', $categoryId)->get();
            return response()->json($subcategories);
        } catch (\Exception $e) {
            \Log::error('Error fetching subcategories: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch subcategories'], 500);
        }
    }

}
