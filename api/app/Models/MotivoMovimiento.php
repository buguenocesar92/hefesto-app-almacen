<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SentidoMovimiento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Motivo de movimiento: el POR QUE de una variacion.
 *
 * Sin motivo, una diferencia de inventario no se puede investigar: se ve
 * que el numero no cuadra, pero no que paso. Los motivos marcados con
 * `exige_justificacion` son los que obligan a escribir la explicacion.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property SentidoMovimiento $sentido
 * @property bool $exige_justificacion
 */
class MotivoMovimiento extends Model
{
    use HasUuids;

    protected $table = 'motivos_movimiento';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'sentido',
        'exige_justificacion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'sentido' => SentidoMovimiento::class,
            'exige_justificacion' => 'boolean',
            'activo' => 'boolean',
        ];
    }
}