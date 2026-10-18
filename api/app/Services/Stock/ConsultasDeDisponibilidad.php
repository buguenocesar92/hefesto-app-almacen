<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\Articulo;
use App\Models\Saldo;
use App\Models\Ubicacion;
use App\Support\Cantidad;
use Illuminate\Support\Collection;

/**
 * Consultas de stock: las tres cifras, siempre separadas.
 *
 * Este servicio SOLO lee. Existe para que ninguna pantalla tenga que
 * escribir la resta `fisica - reservada` a mano: si cada pantalla la
 * calcula por su cuenta, alguna va a olvidar el reservado y va a
 * prometer mercaderia comprometida. Es exactamente el error que este
 * modulo existe para evitar.
 *
 * `disponible()` es la unica forma de responder "cuanto puedo
 * comprometer"; `fisico()` responde "cuanto hay en el rack", que es la
 * pregunta del conteo.
 */
class ConsultasDeDisponibilidad
{
    /**
     * Cifras de un articulo, agregadas sobre todas sus ubicaciones.
     *
     * @return array{fisico: string, reservado: string, disponible: string}
     */
    public function cifrasDeArticulo(Articulo $articulo): array
    {
        $totales = Saldo::query()
            ->where('articulo_id', $articulo->id)
            ->selectRaw('COALESCE(SUM(cantidad_fisica), 0) AS fisico, COALESCE(SUM(cantidad_reservada), 0) AS reservado')
            ->first();

        $fisico = (string) ($totales->fisico ?? '0');
        $reservado = (string) ($totales->reservado ?? '0');

        return [
            'fisico' => Cantidad::normalizar($fisico),
            'reservado' => Cantidad::normalizar($reservado),
            'disponible' => Cantidad::normalizar((float) $fisico - (float) $reservado),
        ];
    }

    /**
     * Cifras de un articulo en una ubicacion.
     *
     * @return array{fisico: string, reservado: string, disponible: string}
     */
    public function cifrasDeSaldo(Saldo $saldo): array
    {
        return [
            'fisico' => Cantidad::normalizar($saldo->cantidad_fisica),
            'reservado' => Cantidad::normalizar($saldo->cantidad_reservada),
            'disponible' => $saldo->disponible,
        ];
    }

    /**
     * DONDE ESTA: el servicio con la consulta que el almacenenocia mas.
     *
     * Devuelve todas las ubicaciones con stock del articulo, ordenadas en
     * el mismo orden en que se recorreria una ruta de picking, para que
     * quien pregunta vea tambien el camino, no solo el destino.
     *
     * @return Collection<int, object>
     */
    public function dondeEsta(Articulo $articulo, bool $soloConDisponible = false): Collection
    {
        return Saldo::query()
            ->with(['ubicacion.zona'])
            ->where('articulo_id', $articulo->id)
            ->when(
                $soloConDisponible,
                fn ($consulta) => $consulta->whereRaw('cantidad_fisica - cantidad_reservada > 0')
            )
            ->join('ubicaciones', 'ubicaciones.id', '=', 'saldos.ubicacion_id')
            ->orderBy('ubicaciones.pasillo')
            ->orderBy('ubicaciones.rack')
            ->orderBy('ubicaciones.nivel')
            ->orderBy('ubicaciones.posicion')
            ->get([
                'saldos.id',
                'saldos.cantidad_fisica',
                'saldos.cantidad_reservada',
                'saldos.ubicacion_id',
            ])
            ->map(function (Saldo $saldo): object {
                $saldo->load('ubicacion');

                return (object) [
                    'saldo_id' => $saldo->id,
                    'ubicacion' => $saldo->ubicacion,
                    'codigo' => $saldo->ubicacion?->codigo,
                    'codigo_barras' => $saldo->ubicacion?->codigo_barras,
                    'etiqueta' => $saldo->ubicacion?->etiqueta(),
                    'zona' => $saldo->ubicacion?->zona?->codigo,
                    'fisico' => Cantidad::normalizar($saldo->cantidad_fisica),
                    'reservado' => Cantidad::normalizar($saldo->cantidad_reservada),
                    'disponible' => $saldo->disponible,
                ];
            });
    }

    /**
     * Saldos con disponible, en el orden en que se puede tomar mercaderia
     * para un pedido: primero lo que esta en el pasillo de entrada, para
     * no dejar un pallet abierto al fondo.
     */
    public function saldosDisponibles(Articulo $articulo, ?string $zonaId = null): Collection
    {
        return Saldo::query()
            ->join('ubicaciones', 'ubicaciones.id', '=', 'saldos.ubicacion_id')
            ->where('saldos.articulo_id', $articulo->id)
            ->whereRaw('saldos.cantidad_fisica - saldos.cantidad_reservada > 0')
            ->when($zonaId !== null, fn ($consulta) => $consulta->where('ubicaciones.zona_id', $zonaId))
            ->orderBy('ubicaciones.pasillo')
            ->orderBy('ubicaciones.rack')
            ->orderBy('ubicaciones.nivel')
            ->orderBy('ubicaciones.posicion')
            ->select('saldos.*')
            ->get();
    }

    /** Articulos bajo el minimo declarado. Alerta de reposicion. */
    public function articulosBajoMinimo(): Collection
    {
        return Articulo::query()
            ->whereNotNull('stock_minimo')
            ->where('activo', true)
            ->get()
            ->filter(function (Articulo $articulo): bool {
                $cifras = $this->cifrasDeArticulo($articulo);

                return Cantidad::comparar($cifras['disponible'], $articulo->stock_minimo) < 0;
            })
            ->values();
    }

    /** Ubicaciones de un mismo pasillo, para pintar el mapa en la interfaz. */
    public function pasillo(string $pasillo): Collection
    {
        return Ubicacion::query()
            ->where('pasillo', $pasillo)
            ->orderBy('rack')
            ->orderBy('nivel')
            ->orderBy('posicion')
            ->get();
    }
}