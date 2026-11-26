<?php

declare(strict_types=1);

namespace App\Models;

use Siro\Core\Model;

final class BookingReservation extends Model
{
    protected string $table = 'booking_reservations';
    protected bool $timestamps = false;
    protected array $fillable = ['id','variant_id','slot_start','order_id','qty','state','created_at'];
}
