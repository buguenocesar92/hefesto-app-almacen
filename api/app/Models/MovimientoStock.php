<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoMovimiento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de stock: una linea del libro que explica cada numero.
 *
 * Es de SOLO AGREGADO. PostgreSQL tiene un gatillo que prohibe el UPDATE y
 * el DELETE sobre esta tabla, y este modelo declara `const UPDATED_AT =
 * null` para que Eloquent tampoco intente actualizarla nunca.
 *
 * `cantidad_base` va con signo: positiva suma, negativa resta. Por eso el
 * saldo de una ubicacion es la suma de los movimientos de esa ubicacion, y
 * `almacen:conciliar` puede recomputarlo y comparar contra lo que dice la
 * tabla de saldos.
 *
 * @property string $id
 * @property TipoMovimiento $tipo
 * @property string $articulo_id
 * @property string $ubicacion_id
 * @property string $cantidad_base
 * @property string|null $grupo_traspaso
 * @property string $creado_en
 */
class MovimientoStock extends Model
{
    use HasUuids;

    /** Sin `updated_at`: esta tabla no se actualiza jamas. */
    public const UPDATED_AT = null;

    protected $table = 'movimientos_stock';

    protected $fillable = [
        'tenant_id',
        'tipo',
        'articulo_id',
        'ubicacion_id',
        'cantidad_base',
        'motivo_id',
        'documento_id',
        'conteo_id',
        'grupo_traspaso',
        'referencia',
        'notas',
        'usuario_id',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMovimiento::class,
            'cantidad_base' => 'decimal:6',
            'creado_en' => 'datetime',
        ];
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoMovimiento::class, 'motivo_id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    /** El par del traspaso: el movimiento del otro extremo. */
    public function contraparte(): ?self
    {
        if ($this->grupo_traspaso === null) {
            return null;
        }

        return self::query()
            ->where('grupo_traspaso', $this->grupo_traspaso)
            ->whereKeyNot($this->getKey())
            ->first();
    }
}