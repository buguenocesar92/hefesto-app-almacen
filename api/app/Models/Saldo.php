<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Saldo de un articulo en una ubicacion: LAS TRES CIFRAS DEL ALMACEN.
 *
 *     cantidad_fisica    lo que hay en el rack
 *     cantidad_reservada lo que ya esta comprometido a un pedido
 *     disponible         lo que se puede vender, DERIVADO de las dos
 *
 * `disponible` es un atributo calculado, no una columna. Si fuera una
 * columna, la desactualizaria alguien y el almacen prometeria mercaderia
 * que ya salio. Un solo campo de stock es un error de diseno, no una
 * simplificacion.
 *
 * Estas columnas NO SE PUEDEN ESCRIBIR directamente. PostgreSQL lo impide
 * con el gatillo `gatillo_saldos_solo_por_movimiento`, salvo que la
 * transaccion haya puesto `app.movimiento_stock = '1'`, que solo pone
 * `App\Services\Stock\RegistroDeMovimiento`. El metodo `guardar()` de este
 * modelo es una segunda barrera, para que un error en PHP de un mensaje
 * claro y no una excepcion de PostgreSQL.
 *
 * @property string $id
 * @property string $articulo_id
 * @property string $ubicacion_id
 * @property string $cantidad_fisica
 * @property string $cantidad_reservada
 * @property-read string $disponible
 *
 * No lleva filtro por `tenant_id` en PHP a proposito: el aislamiento lo
 * hace PostgreSQL (RLS). Un filtro de PHP seria una segunda verdad que
 * se puede olvidar; la RLS no se puede olvidar.
 */
class Saldo extends Model
{
    use HasUuids;

    protected $table = 'saldos';

    protected $fillable = [
        'tenant_id',
        'articulo_id',
        'ubicacion_id',
        'cantidad_fisica',
        'cantidad_reservada',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_fisica' => 'decimal:6',
            'cantidad_reservada' => 'decimal:6',
        ];
    }

    protected $appends = [
        'disponible',
    ];

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    /** Lo que se puede vender de este saldo: fisico menos lo reservado. */
    public function getDisponibleAttribute(): string
    {
        return Cantidad::restar($this->cantidad_fisica, $this->cantidad_reservada);
    }

    /**
     * Segunda barrera, en PHP, contra la escritura directa de cantidades.
     *
     * El unico que puede pasar es el registro de movimientos, que marca el
     * permiso con `conPermisoDeMovimiento()`.
     */
    protected static function booted(): void
    {
        static::updating(function (self $saldo): void {
            if (! $saldo->isDirty(['cantidad_fisica', 'cantidad_reservada'])) {
                return;
            }

            if (! self::hayPermisoDeMovimiento()) {
                throw new RuntimeException(
                    'Los saldos no se editan: se mueven. '
                    . 'Use App\Services\Stock\RegistroDeMovimiento para cambiar cantidades.'
                );
            }
        });

        static::creating(function (self $saldo): void {
            if (! self::hayPermisoDeMovimiento()) {
                throw new RuntimeException(
                    'Un saldo se crea a partir de un movimiento, no por separado. '
                    . 'Use App\Services\Stock\RegistroDeMovimiento.'
                );
            }
        });
    }

    /** Marca la transaccion en curso como autorizada a mover stock. */
    public static function conPermisoDeMovimiento(): void
    {
        static::$movimientoAutorizado = true;
    }

    /**
     * Quita el permiso al terminar la operacion.
     *
     * Sin esto, la bandera quedaria en true para el resto del proceso y
     * la barrera de PHP perderia sentido. El permiso real es el de la base
     * (alcance de transaccion), que ya se descarta solo.
     */
    public static function sinPermisoDeMovimiento(): void
    {
        static::$movimientoAutorizado = false;
    }

    public static function hayPermisoDeMovimiento(): bool
    {
        return static::$movimientoAutorizado === true;
    }

    /**
     * Bandera en memoria. Sirve para el error de PHP, no para el de la
     * base: la garantia real es el gatillo de PostgreSQL, que no depende
     * de que el codigo pase por aqui.
     */
    protected static bool $movimientoAutorizado = false;
}