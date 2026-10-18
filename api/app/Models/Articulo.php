<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Cantidad;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Articulo del almacen.
 *
 * El nombre del campo `sku` es deliberado: es lo que dice el proveedor en
 * la guia y lo que dice el cliente en el pedido. No se inventan codigos
 * internos que nadie mas reconoce.
 *
 * Las tres unidades son eleccion del negocio y se validan al guardar:
 * la base tiene que ser una unidad base, y compra y venta pueden ser
 * cualesquiera del catalogo.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sku
 * @property string $nombre
 * @property string $unidad_base_id
 * @property string $unidad_compra_id
 * @property string $unidad_venta_id
 */
class Articulo extends Model
{
    use HasUuids;

    protected $table = 'articulos';

    protected $fillable = [
        'tenant_id',
        'sku',
        'nombre',
        'descripcion',
        'unidad_base_id',
        'unidad_compra_id',
        'unidad_venta_id',
        'stock_minimo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'stock_minimo' => 'decimal:6',
            'activo' => 'boolean',
        ];
    }

    public function unidadBase(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_base_id');
    }

    public function unidadCompra(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_compra_id');
    }

    public function unidadVenta(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_venta_id');
    }

    public function saldos(): HasMany
    {
        return $this->hasMany(Saldo::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class);
    }

    /** Convierte una cantidad expresada en `unidad` a la unidad base. */
    public function aUnidadBase(float|int|string $cantidad, UnidadMedida $unidad): string
    {
        return Cantidad::normalizar((float) $cantidad / (float) $unidad->unidades_por_base);
    }

    /** Convierte una cantidad de la unidad base a `unidad`. */
    public function desdeUnidadBase(float|int|string $cantidadBase, UnidadMedida $unidad): string
    {
        return Cantidad::normalizar((float) $cantidadBase * (float) $unidad->unidades_por_base);
    }

    /**
     * Cuantas unidades de venta trae una unidad de compra.
     *
     * Es la pregunta que se hace todos los dias ("¿o sea que una caja son
     * seis?") y la respuesta sale de las unidades, no de un campo escrito
     * a mano que puede quedar viejo.
     */
    public function factorCompraVenta(): string
    {
        $compra = (float) $this->unidadCompra?->unidades_por_base ?? 1;
        $venta = (float) $this->unidadVenta?->unidades_por_base ?? 1;

        if ($compra === 0.0) {
            return '0.000000';
        }

        return Cantidad::normalizar($venta / $compra);
    }
}