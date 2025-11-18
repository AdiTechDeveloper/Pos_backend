<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GstRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GstRateController extends Controller
{
    public function index()
    {
        $storeId = Auth::user()->store_id;
        $gstRates = GstRate::where('store_id', $storeId)->get();

        return response()->json([
            'status' => true,
            'gstRates' => $gstRates
        ], 200);
    }

    public function show($id)
    {
        $storeId = Auth::user()->store_id;
        $gstRate = GstRate::where('store_id', $storeId)->where('id', $id)->first();

        if (!$gstRate) {
            return response()->json([
                'status' => false,
                'message' => 'GST Rate not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'gstRate' => $gstRate
        ], 200);
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'rate' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $gstRate = GstRate::create([
                'store_id' => $storeId,
                'rate' => $request->rate,
                'description' => $request->description,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'GST Rate created successfully',
                'gstRate' => $gstRate
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while creating the GST Rate.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'rate' => 'required|string|max:255',
                'description' => 'nullable|string',
            ]);

            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $gstRate = GstRate::where('store_id', $storeId)->where('id', $id)->first();

            if (!$gstRate) {
                return response()->json([
                    'status' => false,
                    'message' => 'GST Rate not found'
                ], 404);
            }

            $gstRate->update([
                'rate' => $request->rate,
                'description' => $request->description,
                'updated_by' => Auth::id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'GST Rate updated successfully',
                'gstRate' => $gstRate
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating the GST Rate.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $storeId = Auth::user()->store_id;

            $gstRate = GstRate::where('store_id', $storeId)->where('id', $id)->first();

            if (!$gstRate) {
                return response()->json([
                    'status' => false,
                    'message' => 'GST Rate not found'
                ], 404);
            }

            $gstRate->delete();

            return response()->json([
                'status' => true,
                'message' => 'GST Rate deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while deleting the GST Rate.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function toggleActive(Request $request, $id)
    {
        try {
            $user = Auth::user();

            if (!in_array($user->role, ['admin', 'manager'])) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $gstRate = GstRate::find($id);

            if (! $gstRate) {
                return response()->json([
                    'status' => false,
                    'message' => 'GST Rate not found'
                ], 404);
            }

            if ($user->role === 'admin' && $user->store_id !== $gstRate->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to update GST Rate from another store'
                ], 403);
            }

            if ($user->role === 'manager' && $user->store_id !== $gstRate->store_id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You are not allowed to update GST Rate from another store'
                ], 403);
            }

            $gstRate->active = ! $gstRate->active;
            $gstRate->updated_by = $gstRate->id;
            $gstRate->save();

            return response()->json([
                'status' => true,
                'message' => 'GST Rate ' . ($gstRate->active ? 'activated' : 'deactivated') . ' successfully',
                'data' => $gstRate
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'An error occurred while updating GST Rate status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
