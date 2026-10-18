<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\TipoMovimiento;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\Articulo;
use App\Models\Conteo;
use App\Models\Documento;
use App\Models\MovimientoStock;
use App\Models\MotivoMovimiento;
use App\Models\Saldo;
use App\Models\Ubicacion;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use App\Support\Cantidad;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * EL UNICO LUGAR DEL SISTEMA DONDE SE CAMBIA EL STOCK.
 *
 * La regla del almacen es "el stock nunca se edita: se mueve". Aqui esta
 * como se cumple, en tres capas:
 *
 *  1. Esta clase es la unica que guarda saldos con cantidades. Todo lo
 *     demas (documentos, reservas, picking, conteos) pide un ingreso, una
 *     salida o un traspaso.
 *  2. PostgreSQL lo exige: el gatillo `gatillo_saldos_solo_por_movimiento`
 *     rechaza cualquier escritura de `cantidad_fisica` o
 *     `cantidad_reservada` mientras la transaccion no tenga puesta la
 *     variable `app.movimiento_stock`. Un `psql` con acceso a la base
 *     tampoco puede saltarselo.
 *  3. Cada variacion deja una fila en `movimientos_stock` con su motivo.
 *     Sin motivo no hay movimiento, y sin movimiento no hay variacion.
 *
 * POR QUE "DISPONIBLE" NO SE TOCA NUNCA
 * -------------------------------------
 * `disponible` no se escribe: se deriva (`fisica - reservada`). Si fuera
 * una columna, cualquier movimiento lo dejaria viejo y el almacen
 * prometeria mercaderia comprometida.
 *
 * TRASPASO
 * --------
 * `traspasar()` escribe SIEMPRE dos movimientos con el mismo
 * `grupo_traspaso`: una salida del origen y una entrada en el destino, en
 * la misma transaccion. Si la segunda fallara, la primera tampoco queda.
 * Sumar al destino sin descontar el origen, el error tipico, no tiene
 * forma de ocurrir aca.
 *
 * CONTEO ABIERTO
 * --------------
 * Si la ubicacion esta dentro de un conteo abierto, el movimiento se
 * rechaza salvo que sea el propio ajuste de ese conteo. Sin esto, el
 * conteo compararia una foto contra un mundo que cambio mientras la
 * persona contaba, y la diferencia que apareceria no significaria nada.
 */
class RegistroDeMovimiento
{
    /**
     * Variable de PostgreSQL que abre el permiso de escritura sobre los
     * saldos. La pone esta clase y nadie mas.
     */
    public const VARIABLE_PERMISO = 'app.movimiento_stock';

    public function __construct(private readonly ContextoTenant $contexto) {}

    /**
     * Ingreso de mercaderia a una ubicacion.
     *
     * @param  float|int|string  $cantidadBase  Siempre en unidad base.
     */
    public function ingresar(
        Articulo $articulo,
        Ubicacion $ubicacion,
        float|int|string $cantidadBase,
        MotivoMovimiento $motivo,
        ?Documento $documento = null,
        ?string $referencia = null,
        ?Usuario $usuario = null,
        ?string $notas = null,
        ?Conteo $conteo = null,
    ): MovimientoStock {
        return $this->escribir(
            tipo: TipoMovimiento::INGRESO,
            articulo: $articulo,
            ubicacion: $ubicacion,
            cantidadConSigno: $cantidadBase,
            motivo: $motivo,
            documento: $documento,
            conteo: $conteo,
            referencia: $referencia,
            usuario: $usuario,
            notas: $notas,
        );
    }

    /**
     * Salida de mercaderia de una ubicacion.
     *
     * @param  float|int|string  $cantidadBase  Siempre en unidad base.
     * @param  bool  $consumiendoReserva  Cuando es `true` la salida puede
     *                                    comerse stock reservado. Solo lo
     *                                    usa el despacho de un pedido, que
     *                                    libera la reserva y sale en la
     *                                    misma operacion.
     */
    public function salir(
        Articulo $articulo,
        Ubicacion $ubicacion,
        float|int|string $cantidadBase,
        MotivoMovimiento $motivo,
        ?Documento $documento = null,
        ?string $referencia = null,
        ?Usuario $usuario = null,
        ?string $notas = null,
        ?Conteo $conteo = null,
        bool $consumiendoReserva = false,
    ): MovimientoStock {
        return $this->escribir(
            tipo: TipoMovimiento::SALIDA,
            articulo: $articulo,
            ubicacion: $ubicacion,
            cantidadConSigno: -1 * abs((float) $cantidadBase),
            motivo: $motivo,
            documento: $documento,
            conteo: $conteo,
            referencia: $referencia,
            usuario: $usuario,
            notas: $notas,
            consumeReservado: $consumiendoReserva,
        );
    }

    /**
     * Traspaso entre dos ubicaciones: un par de movimientos.
     *
     * @return array{salida: MovimientoStock, entrada: MovimientoStock}
     */
    public function traspasar(
        Articulo $articulo,
        Ubicacion $origen,
        Ubicacion $destino,
        float|int|string $cantidadBase,
        MotivoMovimiento $motivo,
        ?Documento $documento = null,
        ?string $referencia = null,
        ?Usuario $usuario = null,
        ?string $notas = null,
    ): array {
        if ($origen->id === $destino->id) {
            throw new ErrorDeAlmacen(
                'El origen y el destino del traspaso son la misma ubicacion.',
                'traspaso_mismo_origen'
            );
        }

        $cantidad = abs((float) $cantidadBase);

        if (! Cantidad::esPositivo($cantidad)) {
            throw new ErrorDeAlmacen(
                'La cantidad del traspaso tiene que ser mayor que cero.',
                'cantidad_invalida'
            );
        }

        $grupo = (string) Str::uuid();

        $par = $this->enTransaccion(function () use (
            $articulo,
            $origen,
            $destino,
            $cantidad,
            $motivo,
            $documento,
            $referencia,
            $usuario,
            $notas,
            $grupo,
        ): array {
            $salida = $this->escribir(
                tipo: TipoMovimiento::TRASPASO_SALIDA,
                articulo: $articulo,
                ubicacion: $origen,
                cantidadConSigno: -1 * $cantidad,
                motivo: $motivo,
                documento: $documento,
                conteo: null,
                referencia: $referencia,
                usuario: $usuario,
                notas: $notas,
                grupoTraspaso: $grupo,
            );

            $entrada = $this->escribir(
                tipo: TipoMovimiento::TRASPASO_ENTRADA,
                articulo: $articulo,
                ubicacion: $destino,
                cantidadConSigno: $cantidad,
                motivo: $motivo,
                documento: $documento,
                conteo: null,
                referencia: $referencia,
                usuario: $usuario,
                notas: $notas,
                grupoTraspaso: $grupo,
            );

            return ['salida' => $salida, 'entrada' => $entrada];
        });

        /** @var array{salida: MovimientoStock, entrada: MovimientoStock} $par */
        return $par;
    }

    /**
     * Ajuste que corrige una diferencia de conteo.
     *
     * El signo lo decide la cantidad: si falta mercaderia, la diferencia
     * es negativa y el ajuste es una salida; si sobro, es un ingreso.
     */
    public function ajustarPorConteo(
        Articulo $articulo,
        Ubicacion $ubicacion,
        float|int|string $diferencia,
        MotivoMovimiento $motivo,
        Conteo $conteo,
        ?Usuario $usuario = null,
        ?string $notas = null,
    ): MovimientoStock {
        $cantidad = abs((float) $diferencia);

        if (! Cantidad::esPositivo($cantidad)) {
            throw new ErrorDeAlmacen(
                'La diferencia de un conteo no puede ser cero.',
                'diferencia_invalida'
            );
        }

        return $this->escribir(
            tipo: TipoMovimiento::AJUSTE_CONteo,
            articulo: $articulo,
            ubicacion: $ubicacion,
            cantidadConSigno: $cantidad,
            motivo: $motivo,
            documento: null,
            conteo: $conteo,
            referencia: $conteo->nombre,
            usuario: $usuario,
            notas: $notas,
        );
    }

    /**
     * Compromete stock: sube `cantidad_reservada` de un saldo.
     *
     * Una reserva NO genera movimiento fisico: la mercaderia no se movio,
     * seologische comprometerla. Por eso cambia solo `cantidad_reservada`
     * y su historia vive en la tabla `reservas`, con su vencimiento.
     */
    public function reservar(Saldo $saldo, float|int|string $cantidadBase): Saldo
    {
        $cantidad = abs((float) $cantidadBase);

        if (! Cantidad::esPositivo($cantidad)) {
            throw new ErrorDeAlmacen(
                'La cantidad reservada tiene que ser mayor que cero.',
                'cantidad_invalida'
            );
        }

        return $this->enTransaccion(function () use ($saldo, $cantidad): Saldo {
            $bloqueado = Saldo::query()->lockForUpdate()->findOrFail($saldo->getKey());

            $nuevo = Cantidad::sumar($bloqueado->cantidad_reservada, $cantidad);

            // No se puede reservar mas de lo que hay en el rack. La base
            // tambien lo comprueba con un CHECK; esto da el mensaje util.
            if (Cantidad::comparar($nuevo, $bloqueado->cantidad_fisica) > 0) {
                throw new ErrorDeAlmacen(
                    sprintf(
                        'No se puede reservar %.6f en %s: hay %.6f en el rack.',
                        $cantidad,
                        $bloqueado->ubicacion?->codigo ?? 'la ubicacion',
                        $bloqueado->cantidad_fisica
                    ),
                    'reserva_excede_stock'
                );
            }

            $bloqueado->cantidad_reservada = $nuevo;
            $bloqueado->save();

            return $bloqueado;
        });
    }

    /**
     * Devuelve stock reservado a disponible: baja `cantidad_reservada`.
     *
     * No toca `cantidad_fisica`: la mercaderia sigue en el rack.
     */
    public function liberarReserva(Saldo $saldo, float|int|string $cantidadBase): Saldo
    {
        $cantidad = abs((float) $cantidadBase);

        return $this->enTransaccion(function () use ($saldo, $cantidad): Saldo {
            $bloqueado = Saldo::query()->lockForUpdate()->findOrFail($saldo->getKey());

            $nuevo = Cantidad::restar($bloqueado->cantidad_reservada, $cantidad);

            if (Cantidad::comparar($nuevo, '0') < 0) {
                throw new ErrorDeAlmacen(
                    'La reserva a liberar es mayor que lo reservado en el saldo.',
                    'reserva_inconsistente'
                );
            }

            $bloqueado->cantidad_reservada = $nuevo;
            $bloqueado->save();

            return $bloqueado;
        });
    }

    /**
     * Escritura real de un movimiento fisico.
     *
     * Privada a proposito: lo que se puede pedir desde afuera son las
     * operaciones con nombre (ingresar, salir, traspasar, ajustar). Asi no
     * se pueden inventar movimientos sin motivo.
     */
    private function escribir(
        TipoMovimiento $tipo,
        Articulo $articulo,
        Ubicacion $ubicacion,
        float|int|string $cantidadConSigno,
        MotivoMovimiento $motivo,
        ?Documento $documento,
        ?Conteo $conteo,
        ?string $referencia,
        ?Usuario $usuario,
        ?string $notas,
        ?string $grupoTraspaso = null,
        bool $consumeReservado = false,
    ): MovimientoStock {
        if (! Cantidad::esPositivo($cantidadConSigno)) {
            throw new ErrorDeAlmacen(
                'La cantidad del movimiento tiene que ser distinta de cero.',
                'cantidad_invalida'
            );
        }

        $tenantId = $this->tenantIdEnCurso();

        return $this->enTransaccion(function () use (
            $tipo,
            $articulo,
            $ubicacion,
            $cantidadConSigno,
            $motivo,
            $documento,
            $conteo,
            $referencia,
            $usuario,
            $notas,
            $grupoTraspaso,
            $consumeReservado,
            $tenantId,
        ): MovimientoStock {
            $this->verificarConteoAbierto($ubicacion, $conteo);

            $saldo = Saldo::query()
                ->where('tenant_id', $tenantId)
                ->where('articulo_id', $articulo->id)
                ->where('ubicacion_id', $ubicacion->id)
                ->lockForUpdate()
                ->first();

            $nuevaFisica = Cantidad::sumar(
                $saldo?->cantidad_fisica ?? '0',
                $cantidadConSigno
            );

            if (Cantidad::comparar($nuevaFisica, '0') < 0) {
                throw new ErrorDeAlmacen(
                    sprintf(
                        'No hay stock suficiente en %s: se pide %.6f y hay %.6f.',
                        $ubicacion->codigo,
                        abs((float) $cantidadConSigno),
                        $saldo?->cantidad_fisica ?? '0'
                    ),
                    'stock_insuficiente'
                );
            }

            // Salir de stock reservado solo tiene sentido cuando la misma
            // operacion esta consumiendo la reserva (un despacho). En
            // cualquier otro caso, mover mercaderia comprometida seria
            // romper la promesa hecha al pedido.
            if (! $consumeReservado) {
                $disponible = Cantidad::restar($nuevaFisica, $saldo?->cantidad_reservada ?? '0');

                if (Cantidad::comparar($disponible, '0') < 0) {
                    throw new ErrorDeAlmacen(
                        sprintf(
                            'En %s hay %.6f reservado: la salida dejaria la reserva sin mercaderia. '
                            . 'Cancele o libere la reserva antes de moverla.',
                            $ubicacion->codigo,
                            $saldo?->cantidad_reservada ?? '0'
                        ),
                        'salto_reservado'
                    );
                }
            }

            if ($saldo === null) {
                $saldo = Saldo::query()->create([
                    'tenant_id' => $tenantId,
                    'articulo_id' => $articulo->id,
                    'ubicacion_id' => $ubicacion->id,
                    'cantidad_fisica' => '0',
                    'cantidad_reservada' => '0',
                ]);
            }

            $saldo->cantidad_fisica = $nuevaFisica;
            $saldo->save();

            return MovimientoStock::query()->create([
                'tenant_id' => $tenantId,
                'tipo' => $tipo->value,
                'articulo_id' => $articulo->id,
                'ubicacion_id' => $ubicacion->id,
                'cantidad_base' => $cantidadConSigno,
                'motivo_id' => $motivo->id,
                'documento_id' => $documento?->id,
                'conteo_id' => $conteo?->id,
                'grupo_traspaso' => $grupoTraspaso,
                'referencia' => $referencia,
                'notas' => $notas,
                'usuario_id' => $usuario?->id,
            ]);
        });
    }

    /**
     * Abre la transaccion con el permiso de escritura sobre los saldos.
     *
     * El permiso de PostgreSQL tiene alcance de transaccion: al
     * confirmar o revertir, se descarta solo, igual que el contexto de
     * empresa. Por eso una transaccion que falla a mitad de un traspaso no
     * deja la puerta abierta.
     */
    private function enTransaccion(Closure $operacion): mixed
    {
        return DB::transaction(function () use ($operacion): mixed {
            DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE_PERMISO, '1']);

            Saldo::conPermisoDeMovimiento();

            try {
                return $operacion();
            } finally {
                Saldo::sinPermisoDeMovimiento();
            }
        });
    }

    /**
     * Empresa de la transaccion en curso.
     *
     * No se adivina ni se deduce del usuario: se lee la misma variable que
     * usa la RLS. Si no esta, la operacion no puede seguir, porque sin
     * contexto las politicas de aislamiento no tendrian contra que
     * filtrar.
     */
    private function tenantIdEnCurso(): string
    {
        $tenantId = $this->contexto->tenantIdEnConexion();

        if ($tenantId === null) {
            throw new ErrorDeAlmacen(
                'La operacion se ejecuto sin contexto de empresa. '
                . 'Envuela la llamada en App\Services\Tenancy\ContextoTenant::ejecutarAislado().',
                'sin_contexto_empresa'
            );
        }

        return $tenantId;
    }

    /**
     * Ningun movimiento entra a una ubicacion que esta en conteo abierto,
     * salvo el ajuste de ese mismo conteo.
     */
    private function verificarConteoAbierto(Ubicacion $ubicacion, ?Conteo $conteo): void
    {
        $conteoAbierto = \App\Models\ConteoItem::query()
            ->join('conteos', 'conteos.id', '=', 'conteo_items.conteo_id')
            ->where('conteo_items.ubicacion_id', $ubicacion->id)
            ->where('conteos.estado', \App\Enums\EstadoConteo::ABIERTO->value)
            ->first('conteo_items.conteo_id');

        if ($conteoAbierto === null) {
            return;
        }

        // El ajuste que genera el propio conteo si puede pasar: sin esta
        // excepcion, ningun conteo podria cerrarse nunca.
        if ($conteo !== null && $conteo->id === $conteoAbierto->conteo_id) {
            return;
        }

        throw new ErrorDeAlmacen(
            sprintf(
                'La ubicacion %s esta en conteo abierto. Termine el conteo antes de mover mercaderia.',
                $ubicacion->codigo
            ),
            'ubicacion_en_conteo'
        );
    }
}