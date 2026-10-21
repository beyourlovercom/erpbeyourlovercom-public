<?php

namespace App\Modules\Kol\Infrastructure\Models;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KolCollaborationProduct extends Model
{
    protected $table = 'kol_collaboration_products';
    protected $fillable = ['collaboration_id', 'product_id', 'sku_snapshot', 'product_name_snapshot', 'quantity'];
    protected $casts = ['quantity' => 'integer'];

    public function collaboration(): BelongsTo { return $this->belongsTo(KolCollaboration::class, 'collaboration_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class, 'product_id'); }
}
