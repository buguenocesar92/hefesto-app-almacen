<?php

declare(strict_types=1);

namespace App\Services\Documentos;

use App\Enums\EstadoDocumento;
use App\Enums\TipoDocumento;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\Documento;
use App\Models\DocumentoItem;
use App\Models\MotivoMovimiento;
use App\Models\Usuario;
use App\Services\Stock\RegistroDeMovimiento;
use App\Support\Cantidad;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Recepcion y despacho: documentos que mueven stock al CONFIRMARSE.
 *
 * Un documento en borrador no toca el stock. Esa separacion importa: la
 * recepcion se digitawhile la mercaderia sigue bajando del camion, y no
 * puede estar messing con el rack que todavia no existe en el sistema.
 *
 * AL CONFIRMAR
 *   entrada_compra -> un ingreso por linea, en la ubicacion de destino
 *   salida_venta   -> una salida por linea, de la ubicacion de origen
 *   traspaso       -> un par de movimientos por linea (ver
 *                     RegistroDeMovimiento::traspasar)
 *
 * AL ANULAR UN DOCUMENTO YA CONFIRMADO
 *   No se borra nada: se escriben movimientos de sentido contrario. Un
 *   documento anulado sigue estando en la historia, y el stock que movio
 *   queda devuelto con su motivo.
 */
class ServicioDeDocumentos
{
    public function __construct(private readonly RegistroDeMovimiento $movimientos) {}

    /**
     * Confirma un documento y ejecuta los movimientos de sus lineas.
     *
     * @return Collection<int, \App\Models\MovimientoStock>
     */
    public function confirmar(Documento $documento, ?Usuario $usuario = null): Collection
    {
        if (! $documento->estado->esEditable()) {
            throw new ErrorDeAlmacen(
                sprintf('El documento %s esta %s y no se puede confirmar.', $documento->numero, $documento->estado->value),
                'documento_no_confirmable'
            );
        }

        $items = $documento->items()->with(['articulo', 'origen', 'destino', 'unidad'])->get();

        if ($items->isEmpty()) {
            throw new ErrorDeAlmacen(
                sprintf('El documento %s no tiene lineas.', $documento->numero),
                'documento_sin_items'
            );
        }

        $movimientos = collect();

        foreach ($items as $item) {
            $this->verificarItem($documento->tipo, $item);
        }

        foreach ($items as $item) {
            $movimientos->push($this->movimientoDeConfirmacion($documento, $item, $usuario));
        }

        $documento->estado = EstadoDocumento::CONFIRMADO;
        $documento->confirmado_en = Carbon::now();
        $documento->save();

        return $movimientos;
    }

    /**
     * Anula un documento confirmado devolviendo el stock que movio.
     *
     * Un traspaso NO se anula: se revierte con el traspaso de vuelta, para
     * que la historia muestre el camino completo en vez de un
     * "deshacer". Para una entrada o una salida, la anulacion es el
     * movimiento contrario, con su motivo.
     *
     * @return Collection<int, \App\Models\MovimientoStock>
     */
    public function anular(
        Documento $documento,
        MotivoMovimiento $motivo,
        ?Usuario $usuario = null,
        ?string $motivoTexto = null,
    ): Collection {
        if ($documento->estado !== EstadoDocumento::CONFIRMADO) {
            throw new ErrorDeAlmacen(
                sprintf('Solo se puede anular un documento confirmado; %s esta %s.', $documento->numero, $documento->estado->value),
                'documento_no_anulable'
            );
        }

        if ($documento->tipo === TipoDocumento::TRASPASO) {
            throw new ErrorDeAlmacen(
                sprintf(
                    'El traspaso %s no se anula: se revierte con un traspaso de vuelta, '
                    . 'para que la historia muestre el camino completo.',
                    $documento->numero
                ),
                'traspaso_no_anulable'
            );
        }

        $notas = $motivoTexto ?? ('Anulacion del documento ' . $documento->numero);

        $movimientos = collect();

        foreach ($documento->items()->with(['articulo', 'origen', 'destino'])->get() as $item) {
            // La anulacion es el movimiento contrario: lo que entro sale,
            // lo que salio vuelve.
            $movimiento = $documento->tipo === TipoDocumento::ENTRADA_COMPRA
                ? $this->movimientos->salir(
                    articulo: $item->articulo,
                    ubicacion: $item->destino,
                    cantidadBase: $item->cantidad_base,
                    motivo: $motivo,
                    documento: $documento,
                    referencia: $documento->numero,
                    usuario: $usuario,
                    notas: $notas,
                )
                : $this->movimientos->ingresar(
                    articulo: $item->articulo,
                    ubicacion: $item->origen,
                    cantidadBase: $item->cantidad_base,
                    motivo: $motivo,
                    documento: $documento,
                    referencia: $documento->numero,
                    usuario: $usuario,
                    notas: $notas,
                );

            $movimientos->push($movimiento);
        }

        $documento->estado = EstadoDocumento::ANULADO;
        $documento->anulado_en = Carbon::now();
        $documento->save();

        return $movimientos;
    }

    /**
     * Revisa que la linea tenga sentido para el tipo de documento.
     *
     * La base no puede expresar estas reglas con restricciones simples
     * (dependen de otra fila), asi que se validan aca, antes de mover
     * una sola unidad.
     */
    private function verificarItem(TipoDocumento $tipo, DocumentoItem $item): void
    {
        if (! Cantidad::esPositivo($item->cantidad_base)) {
            throw new ErrorDeAlmacen(
                'Una linea de documento no puede tener cantidad cero.',
                'cantidad_invalida'
            );
        }

        match ($tipo) {
            TipoDocumento::ENTRADA_COMPRA => $this->verificarIngreso($item),
            TipoDocumento::SALIDA_VENTA => $this->verificarSalida($item),
            TipoDocumento::TRASPASO => $this->verificarTraspaso($item),
        };
    }

    private function verificarIngreso(DocumentoItem $item): void
    {
        if ($item->ubicacion_destino_id === null) {
            throw new ErrorDeAlmacen(
                'Una entrada de compra necesita la ubicacion donde se guarda.',
                'ingreso_sin_destino'
            );
        }

        if ($item->ubicacion_origen_id !== null) {
            throw new ErrorDeAlmacen(
                'Una entrada de compra no tiene ubicacion de origen.',
                'ingreso_con_origen'
            );
        }
    }

    private function verificarSalida(DocumentoItem $item): void
    {
        if ($item->ubicacion_origen_id === null) {
            throw new ErrorDeAlmacen(
                'Una salida por venta necesita la ubicacion de donde sale.',
                'salida_sin_origen'
            );
        }

        if ($item->ubicacion_destino_id !== null) {
            throw new ErrorDeAlmacen(
                'Una salida por venta no tiene ubicacion de destino.',
                'salida_con_destino'
            );
        }
    }

    private function verificarTraspaso(DocumentoItem $item): void
    {
        if ($item->ubicacion_origen_id === null || $item->ubicacion_destino_id === null) {
            throw new ErrorDeAlmacen(
                'Un traspaso necesita las dos ubicaciones: de donde sale y donde queda.',
                'traspaso_incompleto'
            );
        }

        if ($item->ubicacion_origen_id === $item->ubicacion_destino_id) {
            throw new ErrorDeAlmacen(
                'El origen y el destino del traspaso son la misma ubicacion.',
                'traspaso_mismo_origen'
            );
        }
    }

    /**
     * Traduce una linea al movimiento que corresponde.
     */
    private function movimientoDeConfirmacion(
        Documento $documento,
        DocumentoItem $item,
        ?Usuario $usuario,
    ): \App\Models\MovimientoStock {
        $motivo = $this->motivoPorDefecto($documento->tipo);

        return match ($documento->tipo) {
            TipoDocumento::ENTRADA_COMPRA => $this->movimientos->ingresar(
                articulo: $item->articulo,
                ubicacion: $item->destino,
                cantidadBase: $item->cantidad_base,
                motivo: $motivo,
                documento: $documento,
                referencia: $documento->numero,
                usuario: $usuario,
            ),

            TipoDocumento::SALIDA_VENTA => $this->movimientos->salir(
                articulo: $item->articulo,
                ubicacion: $item->origen,
                cantidadBase: $item->cantidad_base,
                motivo: $motivo,
                documento: $documento,
                referencia: $documento->numero,
                usuario: $usuario,
            ),

            TipoDocumento::TRASPASO => $this->movimientos->traspasar(
                articulo: $item->articulo,
                origen: $item->origen,
                destino: $item->destino,
                cantidadBase: $item->cantidad_base,
                motivo: $motivo,
                documento: $documento,
                referencia: $documento->numero,
                usuario: $usuario,
            )['salida'],
        };
    }

    /**
     * Motivo por defecto segun el tipo de documento.
     *
     * La empresa puede crear sus propios motivos (codigo `RECEPCION`,
     * `DESPACHO`, `TRASPASO`). Si no existen, se registra el movimiento
     * con el motivo mas cercano posible; el codigo del movimiento deja
     * constancia de que fue un valor por defecto.
     */
    private function motivoPorDefecto(TipoDocumento $tipo): MotivoMovimiento
    {
        $codigo = match ($tipo) {
            TipoDocumento::ENTRADA_COMPRA => 'RECEPCION',
            TipoDocumento::SALIDA_VENTA => 'DESPACHO',
            TipoDocumento::TRASPASO => 'TRASPASO',
        };

        $motivo = MotivoMovimiento::query()
            ->where('codigo', $codigo)
            ->where('activo', true)
            ->first();

        if ($motivo !== null) {
            return $motivo;
        }

        throw new ErrorDeAlmacen(
            sprintf(
                'No existe el motivo de movimiento %s. '
                . 'Cree los motivos del almacen antes de confirmar documentos.',
                $codigo
            ),
            'motivo_faltante'
        );
    }
}