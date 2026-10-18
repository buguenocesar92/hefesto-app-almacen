<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoDocumento;
use App\Enums\TipoDocumento;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documento de recepcion o despacho.
 *
 * Un documento es la INTENCION del usuario. El stock se mueve recien
 * cuando se confirma, y se mueve por movimientos.
 *
 * @property string $id
 * @property TipoDocumento $tipo
 * @property EstadoDocumento $estado
 * @property string $numero
 */
class Documento extends Model
{
    use HasUuids;

    protected $table = 'documentos';

    protected $fillable = [
        'tenant_id',
        'tipo',
        'numero',
        'estado',
        'fecha_documento',
        'referencia_externa',
        'notas',
        'usuario_creador_id',
        'confirmado_en',
        'anulado_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoDocumento::class,
            'estado' => EstadoDocumento::class,
            'fecha_documento' => 'date',
            'confirmado_en' => 'datetime',
            'anulado_en' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentoItem::class)->orderBy('orden');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoStock::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_creador_id');
    }
}