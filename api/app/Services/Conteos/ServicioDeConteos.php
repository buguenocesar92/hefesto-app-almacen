<?php

declare(strict_types=1);

namespace App\Services\Conteos;

use App\Enums\EstadoConteo;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\Articulo;
use App\Models\Conteo;
use App\Models\ConteoItem;
use App\Models\Saldo;
use App\Models\Ubicacion;
use App\Models\Usuario;
use App\Models\Zona;
use App\Services\Stock\RegistroDeMovimiento;
use App\Support\Cantidad;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Inventario fisico: conteo ciclico por zona.
 *
 * EL PROBLEMA QUE ATAQUE
 * ----------------------
 * Las diferencias de inventario no se ven porque nadie mira. Cuando se
 * miran ya son grandes y nadie sabe cuando quedaron asi. Este servicio no
 * evita que falte mercaderia: hace que la diferencia aparezca con fecha,
 * con lugar y con un nombre de quien lafirmo.
 *
 * POR QUE LA ZONA SE CONGELA
 * -------------------------
 * Al abrir el conteo se toma una foto de los saldos de la zona
 * (`cantidad_sistema`) y se bloquea el movimiento en esas ubicaciones
 * mientras el conteo esta abierto. Sin ese bloqueo, el conteo compararia
 * una foto contra un mundo que cambio mientras la persona contaba: la
 * diferencia apareceria sola y nadie podria decir si fue merma, error de
 * conteo o movimiento real.
 *
 * POR QUE LA DIFERENCIA SE JUSTIFICA
 * ---------------------------------
 * Un numero que no cuadra sin explicacion es un numero que nadie va a
 * querer mirar. Al cerrar, cada diferencia pide un motivo y una
 * explicacion escrita, y la correccion se hace con un movimiento, nunca
 * escribiendo sobre el saldo.
 */
class ServicioDeConteos
{
    public function __construct(private readonly RegistroDeMovimiento $movimientos) {}

    /**
     * Abre un conteo de una zona y congela la foto de los saldos.
     */
    public function abrir(Zona $zona, string $nombre, ?Usuario $usuario = null): Conteo
    {
        $abierto = Conteo::query()
            ->where('zona_id', $zona->id)
            ->where('estado', EstadoConteo::ABIERTO->value)
            ->exists();

        if ($abierto) {
            throw new ErrorDeAlmacen(
                sprintf('La zona %s ya tiene un conteo abierto.', $zona->codigo),
                'zona_con_conteo_abierto'
            );
        }

        $conteo = Conteo::query()->create([
            'tenant_id' => $zona->tenant_id,
            'zona_id' => $zona->id,
            'nombre' => $nombre,
            'estado' => EstadoConteo::ABIERTO,
            'abierto_por' => $usuario?->id,
            'abierto_en' => Carbon::now(),
        ]);

        $saldos = Saldo::query()
            ->join('ubicaciones', 'ubicaciones.id', '=', 'saldos.ubicacion_id')
            ->where('ubicaciones.zona_id', $zona->id)
            ->where('saldos.cantidad_fisica', '>', 0)
            ->select('saldos.*')
            ->get();

        foreach ($saldos as $saldo) {
            ConteoItem::query()->create([
                'tenant_id' => $zona->tenant_id,
                'conteo_id' => $conteo->id,
                'articulo_id' => $saldo->articulo_id,
                'ubicacion_id' => $saldo->ubicacion_id,
                'cantidad_sistema' => $saldo->cantidad_fisica,
            ]);
        }

        return $conteo->fresh(['items']);
    }

    /**
     * Registra lo que contó la persona en una parada del conteo.
     */
    public function registrar(Conteo $conteo, ConteoItem $item, float|int|string $cantidad, ?Usuario $usuario = null): ConteoItem
    {
        if (! $conteo->abierto()) {
            throw new ErrorDeAlmacen(
                sprintf('El conteo %s ya no esta abierto.', $conteo->nombre),
                'conteo_cerrado'
            );
        }

        if (Cantidad::comparar($cantidad, '0') < 0) {
            throw new ErrorDeAlmacen(
                'La cantidad contada no puede ser negativa.',
                'cantidad_invalida'
            );
        }

        $item->cantidad_contada = Cantidad::normalizar($cantidad);
        $item->contado_por = $usuario?->id;
        $item->contado_en = Carbon::now();
        $item->save();

        return $item;
    }

    /**
     * Cierra el conteo: calcula diferencias y las corrige con movimientos.
     *
     * @return array{conteo: Conteo, ajustes: Collection<int, ConteoItem>}
     */
    public function cerrar(Conteo $conteo, ?Usuario $usuario = null): array
    {
        if (! $conteo->abierto()) {
            throw new ErrorDeAlmacen(
                sprintf('El conteo %s ya no esta abierto.', $conteo->nombre),
                'conteo_cerrado'
            );
        }

        $items = $conteo->items()->with(['articulo', 'ubicacion', 'motivo'])->get();

        $sinContar = $items->first(fn (ConteoItem $item): bool => ! $item->contado());

        if ($sinContar !== null) {
            throw new ErrorDeAlmacen(
                sprintf(
                    'Falta contar el articulo %s en la ubicacion %s. '
                    . 'Un conteo se cierra completo o no se cierra.',
                    $sinContar->articulo?->sku ?? 'desconocido',
                    $sinContar->ubicacion?->codigo ?? 'desconocida'
                ),
                'conteo_incompleto'
            );
        }

        $ajustes = collect();

        foreach ($items as $item) {
            $diferencia = Cantidad::restar($item->cantidad_contada, $item->cantidad_sistema);

            $item->diferencia = $diferencia;

            if (Cantidad::esCero($diferencia)) {
                $item->save();
                continue;
            }

            // Una diferencia sin justificacion es una diferencia que nadie
            // va a querer mirar la proxima vez.
            if ($item->motivo_movimiento_id === null || blank($item->justificacion)) {
                throw new ErrorDeAlmacen(
                    sprintf(
                        'La diferencia de %s en %s necesita un motivo y una explicacion escrita.',
                        $item->articulo?->sku ?? 'un articulo',
                        $item->ubicacion?->codigo ?? 'una ubicacion'
                    ),
                    'diferencia_sin_justificar'
                );
            }

            $movimiento = $this->movimientos->ajustarPorConteo(
                articulo: $item->articulo,
                ubicacion: $item->ubicacion,
                diferencia: $diferencia,
                motivo: $item->motivo,
                conteo: $conteo,
                usuario: $usuario,
                notas: $item->justificacion,
            );

            $item->movimiento_id = $movimiento->id;
            $item->save();

            $ajustes->push($item);
        }

        $conteo->estado = EstadoConteo::CERRADO;
        $conteo->cerrado_por = $usuario?->id;
        $conteo->cerrado_en = Carbon::now();
        $conteo->save();

        return ['conteo' => $conteo->fresh(['items']), 'ajustes' => $ajustes];
    }

    /**
     * Cancela un conteo abierto y devuelve la zona al movimiento normal.
     *
     * Se usa cuando el conteo quedo a medias y conviene empezar de nuevo:
     * las cantidades contadas hasta ahora no corrigen nada.
     */
    public function cancelar(Conteo $conteo, ?Usuario $usuario = null): Conteo
    {
        if (! $conteo->abierto()) {
            throw new ErrorDeAlmacen(
                sprintf('El conteo %s no esta abierto.', $conteo->nombre),
                'conteo_no_abierto'
            );
        }

        $conteo->estado = EstadoConteo::CANCELADO;
        $conteo->cerrado_por = $usuario?->id;
        $conteo->cerrado_en = Carbon::now();
        $conteo->save();

        return $conteo;
    }

    /**
     * Zonas cuya hace mas tiempo que no se cuentan.
     *
     * El conteo ciclico sirve si hay rotacion. Sin esto, cada uno cuenta
     * la zona que le queda mas a mano y las otras se pudren.
     *
     * @return Collection<int, Zona>
     */
    public function zonasParaContar(): Collection
    {
        return Zona::query()
            ->where('activo', true)
            ->leftJoin('conteos', function ($consulta): void {
                $consulta->on('conteos.zona_id', '=', 'zonas.id')
                    ->where('conteos.estado', EstadoConteo::CERRADO->value);
            })
            ->select('zonas.*', 'MAX(conteos.cerrado_en) AS ultimo_conteo')
            ->groupBy('zonas.id')
            ->orderByRaw('ultimo_conteo ASC NULLS FIRST')
            ->get();
    }

    /** Ubicaciones de la zona que aun no tienen saldo: hay que contarlas igual. */
    public function ubicacionesVacias(Zona $zona): Collection
    {
        return Ubicacion::query()
            ->where('zona_id', $zona->id)
            ->whereDoesntHave('saldos', function ($consulta): void {
                $consulta->where('cantidad_fisica', '>', 0);
            })
            ->orderBy('pasillo')
            ->get();
    }

    /** Articulo del conteo, para las consultas de la interfaz. */
    public function articuloDe(ConteoItem $item): ?Articulo
    {
        return $item->articulo;
    }
}