<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class BookingSlot extends Model
{
    protected string $table = 'booking_slots';
    protected bool $timestamps = false;
    protected array $fillable = ['id','variant_id','starts_at','ends_at','capacity','price_minor','currency'];
}
