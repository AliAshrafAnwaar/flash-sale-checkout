<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'price', 'stock', 'version'];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'version' => 'integer',
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get available stock (physical stock minus active holds)
     */
    public function getAvailableStockAttribute(): int
    {
        $activeHolds = $this->holds()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->sum('quantity');

        return max(0, $this->stock - $activeHolds);
    }

    /**
     * Get available stock with Redis caching for read performance
     * Uses short TTL to balance freshness and performance under burst traffic
     * Falls back to direct query if cache is unavailable
     */
    public static function getAvailableStockCached(int $productId): int
    {
        $cacheKey = self::getStockCacheKey($productId);
        
        try {
            // Short TTL (5 seconds) - fast reads while staying accurate
            return Cache::remember($cacheKey, 5, function () use ($productId) {
                $product = self::find($productId);
                if (!$product) {
                    return 0;
                }
                
                Log::debug('Stock cache miss - computing available stock', [
                    'product_id' => $productId,
                ]);
                
                return $product->available_stock;
            });
        } catch (\Exception $e) {
            // Fallback to direct query if cache fails
            Log::warning('Cache unavailable, falling back to direct query', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            
            $product = self::find($productId);
            return $product ? $product->available_stock : 0;
        }
    }

    /**
     * Invalidate stock cache
     */
    public static function invalidateStockCache(int $productId): void
    {
        $cacheKey = self::getStockCacheKey($productId);
        
        try {
            Cache::forget($cacheKey);
            Log::debug('Stock cache invalidated', ['product_id' => $productId]);
        } catch (\Exception $e) {
            Log::warning('Failed to invalidate cache', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get cache key for product stock
     */
    private static function getStockCacheKey(int $productId): string
    {
        return "flash_sale:product:{$productId}:available_stock";
    }

    /**
     * Atomically decrement stock with optimistic locking
     * Returns true on success, false on conflict
     */
    public function decrementStockAtomic(int $quantity, int $expectedVersion): bool
    {
        $affected = DB::table('products')
            ->where('id', $this->id)
            ->where('version', $expectedVersion)
            ->where('stock', '>=', $quantity)
            ->update([
                'stock' => DB::raw("stock - {$quantity}"),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            self::invalidateStockCache($this->id);
            $this->refresh();
            return true;
        }

        return false;
    }

    /**
     * Atomically increment stock (for releasing holds/cancelled orders)
     */
    public function incrementStock(int $quantity): void
    {
        DB::table('products')
            ->where('id', $this->id)
            ->update([
                'stock' => DB::raw("stock + {$quantity}"),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);

        self::invalidateStockCache($this->id);
        $this->refresh();
    }
}
