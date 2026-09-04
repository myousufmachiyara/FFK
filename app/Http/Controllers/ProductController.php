<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Attribute;
use App\Models\ProductCategory;
use App\Models\ProductSubcategory;
use App\Models\MeasurementUnit;
use App\Models\ProductVariation;
use App\Models\ProductPart;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorPNG;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Maatwebsite\Excel\Facades\Excel;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('category', 'subcategory', 'variations')->get();
        return view('products.index', compact('products'));
    }

    public function create()
    {
        $categories = ProductCategory::all();
        $attributes = Attribute::with('values')->get();
        $units = MeasurementUnit::all();
        return view('products.create', compact('categories', 'attributes', 'units'));
    }

    // ─────────────────────────────────────────────────────────────
    // Auto-generate a barcode value if the user didn't provide one.
    // Uses the variation's SKU as-is — Code128 (what BarcodeGeneratorPNG
    // defaults to) encodes arbitrary alphanumeric text fine, so the SKU
    // itself is a perfectly valid, human-readable barcode value. If you
    // need strictly numeric EAN/UPC-style codes instead, replace this
    // with a sequence generator.
    // ─────────────────────────────────────────────────────────────
    private function resolveBarcode(?string $provided, ?string $sku): ?string
    {
        $value = trim((string) $provided);
        if ($value !== '') {
            return $value;
        }
        return $sku ? trim($sku) : null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:products,name',
            'category_id' => 'required|exists:product_categories,id',
            'subcategory_id' => 'nullable|exists:product_subcategories,id',
            'sku' => 'required|string|unique:products,sku',
            'description' => 'nullable|string',
            'measurement_unit' => 'required|exists:measurement_units,id',
            'selling_price' => 'nullable|numeric',
            'opening_stock' => 'required|numeric',
            'is_active' => 'boolean',
            'variations.*.barcode' => 'nullable|string|max:64|unique:product_variations,barcode',
        ]);

        DB::beginTransaction();

        try {
            $productData = $request->only([
                'name', 'category_id', 'subcategory_id', 'sku', 'description',
                'measurement_unit', 'opening_stock', 'selling_price', 'is_active'
            ]);

            $product = Product::create($productData);
            Log::info('[Product Store] Product created', ['product_id' => $product->id, 'data' => $productData]);

            if ($request->has('variations')) {
                foreach ($request->variations as $variationData) {
                    $sku = $variationData['sku'] ?? null;

                    $variation = $product->variations()->create([
                        'sku'            => $sku,
                        'barcode'        => $this->resolveBarcode($variationData['barcode'] ?? null, $sku),
                        'stock_quantity' => $variationData['stock_quantity'] ?? 0,
                        'selling_price'  => $variationData['selling_price'] ?? 0,
                    ]);
                    Log::info('[Product Store] Variation created', ['variation_id' => $variation->id, 'product_id' => $product->id, 'data' => $variationData]);

                    if (!empty($variationData['attributes'])) {
                        $variation->attributeValues()->sync($variationData['attributes']);
                        Log::info('[Product Store] Variation attributes synced', [
                            'variation_id' => $variation->id,
                            'attributes' => $variationData['attributes']
                        ]);
                    }
                }
            }

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product created successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Product Store] Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => $request->all()
            ]);
            return back()->withInput()->with('error', 'Product creation failed. Check logs for details.');
        }
    }

    public function show(Product $product)
    {
        return redirect()->route('products.index');
    }

    public function details(Request $request)
    {
        $product = Product::findOrFail($request->id);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'code' => $product->item_code ?? '',
            'unit' => $product->unit ?? '',
            'price' => $product->price ?? 0,
        ]);
    }

    public function edit($id)
    {
        $product = Product::with([
            'images',
            'variations.attributeValues',
        ])->findOrFail($id);

        $categories = ProductCategory::all();
        $subcategories = ProductSubcategory::all();
        $attributes = Attribute::with('values')->get();
        $units = MeasurementUnit::all();

        $attributeValues = collect();
        foreach ($attributes as $attribute) {
            foreach ($attribute->values as $val) {
                $val->attribute = $attribute;
                $attributeValues->push($val);
            }
        }

        return view('products.edit', compact(
            'product',
            'categories',
            'subcategories',
            'attributes',
            'attributeValues',
            'units'
        ));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'variations.*.barcode'     => 'nullable|string|max:64|unique:product_variations,barcode,' . implode(',', array_column($request->variations ?? [], 'id')),
            'new_variations.*.barcode' => 'nullable|string|max:64|unique:product_variations,barcode',
        ]);

        DB::beginTransaction();

        try {
            $product = Product::findOrFail($id);

            $product->update($request->only([
                'name', 'category_id', 'subcategory_id', 'sku', 'measurement_unit', 'opening_stock', 'description', 'selling_price', 'is_active'
            ]));

            $handledVariationIds = [];

            if (is_array($request->variations)) {
                foreach ($request->variations as $variationData) {
                    $variation = ProductVariation::findOrFail($variationData['id']);
                    $variation->update([
                        'sku'            => $variationData['sku'],
                        'barcode'        => $this->resolveBarcode($variationData['barcode'] ?? $variation->barcode, $variationData['sku']),
                        'stock_quantity' => $variationData['stock_quantity'] ?? 0,
                        'selling_price'  => $variationData['selling_price'] ?? 0,
                    ]);

                    if (!empty($variationData['attributes'])) {
                        $variation->attributeValues()->sync($variationData['attributes']);
                    }

                    $handledVariationIds[] = $variation->id;
                }
            }

            if (is_array($request->new_variations)) {
                foreach ($request->new_variations as $newVar) {
                    $sku = $newVar['sku'];

                    $variation = $product->variations()->create([
                        'sku'            => $sku,
                        'barcode'        => $this->resolveBarcode($newVar['barcode'] ?? null, $sku),
                        'stock_quantity' => $newVar['stock_quantity'] ?? 0,
                        'selling_price'  => $newVar['selling_price'] ?? 0,
                    ]);

                    if (!empty($newVar['attributes'])) {
                        $variation->attributeValues()->sync($newVar['attributes']);
                    }

                    $handledVariationIds[] = $variation->id;
                }
            }

            if ($request->filled('removed_variations')) {
                ProductVariation::whereIn('id', $request->removed_variations)->delete();
            }

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product updated successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[Product Update] Failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Product update failed. Try again.');
        }
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }

    // ─────────────────────────────────────────────────────────────
    // FIX (#9, #10): this endpoint previously returned only id/sku/unit
    // per variation — Sale Invoice's variation dropdown reads
    // stock_quantity and selling_price from this exact response, so
    // both showed as 0/blank no matter what was actually in the
    // database. Now returns everything the frontend needs.
    // ─────────────────────────────────────────────────────────────
    public function getVariations($productId)
    {
        $product = Product::with(['variations', 'measurementUnit'])->find($productId);

        if (!$product) {
            return response()->json([
                'success'   => false,
                'variation' => [],
            ]);
        }

        $unitId = optional($product->measurementUnit)->id;

        $variations = $product->variations->map(function ($v) use ($unitId) {
            return [
                'id'             => $v->id,
                'sku'            => $v->sku,
                'barcode'        => $v->barcode,
                'unit'           => $unitId,
                'stock_quantity' => (float) $v->stock_quantity,
                'selling_price'  => $v->selling_price !== null ? (float) $v->selling_price : null,
            ];
        });

        return response()->json([
            'success'   => true,
            'variation' => $variations,

            'product'   => [
                'id'                 => $product->id,
                'name'               => $product->name,
                'manufacturing_cost' => $product->manufacturing_cost,
                'unit'               => $unitId,
                'selling_price'      => $product->selling_price,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // NEW: generate a scannable barcode PNG for a variation. The
    // BarcodeGeneratorPNG import was already sitting unused in this
    // file — this is the endpoint it was presumably meant for.
    // Use e.g. <img src="/products/variations/{id}/barcode"> on a
    // label/print view.
    // ─────────────────────────────────────────────────────────────
    public function variationBarcode($variationId)
    {
        $variation = ProductVariation::findOrFail($variationId);

        if (!$variation->barcode) {
            abort(404, 'This variation has no barcode value set.');
        }

        $generator = new BarcodeGeneratorPNG();
        $png = $generator->getBarcode($variation->barcode, $generator::TYPE_CODE_128);

        return Response::make($png, 200, [
            'Content-Type' => 'image/png',
        ]);
    }

    public function getLocationStock(Request $request) {
        $v_id = $request->variation_id;
        $l_id = $request->location_id;

        $purchased = DB::table('purchase_invoice_items')->where(['variation_id' => $v_id, 'location_id' => $l_id])->sum('quantity');
        $sold = DB::table('sale_invoice_items')->where(['variation_id' => $v_id, 'location_id' => $l_id])->sum('quantity');

        $t_in = DB::table('stock_transfer_details')
            ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
            ->where(['variation_id' => $v_id, 'to_location_id' => $l_id])
            ->sum('quantity');

        $t_out = DB::table('stock_transfer_details')
            ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
            ->where(['variation_id' => $v_id, 'from_location_id' => $l_id])
            ->sum('quantity');

        return response()->json(['stock' => ($purchased + $t_in) - ($sold + $t_out)]);
    }
}