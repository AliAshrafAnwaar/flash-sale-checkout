<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    /**
     * Get product details with accurate available stock
     * Uses caching for performance under burst traffic
     */
    public function show(int $id): JsonResponse
    {
        $product = Product::find($id);
        
        if (!$product) {
            return response()->json([
                'error' => 'Product not found',
            ], 404);
        }
        
        // Use cached available stock for performance
        $availableStock = Product::getAvailableStockCached($id);
        
        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => $product->price,
            'available_stock' => $availableStock,
            'updated_at' => $product->updated_at,
        ]);
    }
}
