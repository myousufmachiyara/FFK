<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SubHeadOfAccounts;
use App\Models\HeadOfAccounts;

class SubHeadOfAccController extends Controller
{
    public function index()
    {
        $subHeadOfAccounts = SubHeadOfAccounts::with('headOfAccount')->get();
        $HeadOfAccounts = HeadOfAccounts::get();

        return view('accounts.shoa', compact('subHeadOfAccounts', 'HeadOfAccounts')); // Return to the view
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'hoa_id' => 'required|exists:head_of_accounts,id',
            'name' => 'required|string|max:255',
        ]);

        SubHeadOfAccounts::create($validated);

        return redirect()->route('shoa.index')->with('success', 'Sub Head of Account created successfully.');
    }

    public function show(string $id)
    {
        $subHeadOfAccount = SubHeadOfAccounts::findOrFail($id);
        return view('shoa.show', compact('subHeadOfAccount')); // Return a detailed view
    }

    public function edit($id)
    {
        $subHeadOfAccount = SubHeadOfAccounts::findOrFail($id);
        return response()->json($subHeadOfAccount);
    }

    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'hoa_id' => 'required|exists:head_of_accounts,id',
            'name' => 'required|string|max:255',
        ]);

        $subHeadOfAccount = SubHeadOfAccounts::findOrFail($id);
        $subHeadOfAccount->update($validated);

        return redirect()->route('shoa.index')->with('success', 'Sub Head of Account updated successfully.');
    }

    public function destroy(string $id)
    {
        $subHeadOfAccount = SubHeadOfAccounts::findOrFail($id);
        $subHeadOfAccount->delete();

        return redirect()->route('shoa.index')->with('success', 'Sub Head of Account deleted successfully.');
    }

}
