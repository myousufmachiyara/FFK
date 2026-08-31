<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Attribute;
use App\Models\AttributeValue;

class AttributeController extends Controller
{
    public function index()
    {
        $attributes = Attribute::with('values')->get();
        return view('products.attributes', compact('attributes'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:attributes,slug',
            'values' => 'required|string' // comma-separated string
        ]);

        $attribute = Attribute::create($request->only('name', 'slug'));

        // Split comma-separated values and insert into AttributeValue
        $values = array_map('trim', explode(',', $request->input('values')));
        foreach ($values as $val) {
            if (!empty($val)) {
                AttributeValue::create([
                    'attribute_id' => $attribute->id,
                    'value' => $val,
                ]);
            }
        }

        return redirect()->route('attributes.index')->with('success', 'Attribute created successfully.');
    }

    public function update(Request $request, $id)
    {
        // Find the attribute or fail
        $attribute = Attribute::findOrFail($id);

        // Validate input
        $request->validate([
            'name'   => 'required|string|max:255',
            'slug'   => 'required|string|max:255|unique:attributes,slug,' . $attribute->id,
            'values' => 'required|string' // comma-separated
        ]);

        // Update attribute fields
        $attribute->update($request->only('name', 'slug'));

        // Parse and normalize incoming values
        $incomingValues = collect(explode(',', $request->input('values')))
            ->map(fn($val) => trim($val))
            ->filter() // removes empty strings
            ->unique()
            ->values();

        // Existing values
        $existing = $attribute->values;

        // Create values not in existing
        foreach ($incomingValues as $value) {
            if (!$existing->contains(fn($v) => strtolower($v->value) === strtolower($value))) {
                $attribute->values()->create(['value' => $value]);
            }
        }

        // Delete values that were removed
        foreach ($existing as $val) {
            if (!$incomingValues->contains(fn($v) => strtolower($v) === strtolower($val->value))) {
                $val->delete();
            }
        }

        return redirect()->route('attributes.index')->with('success', 'Attribute updated successfully.');
    }

    public function destroy(Attribute $attribute)
    {
        $attribute->values()->delete(); // Delete related values first
        $attribute->delete();

        return redirect()->route('attributes.index')->with('success', 'Attribute deleted successfully.');
    }
}
