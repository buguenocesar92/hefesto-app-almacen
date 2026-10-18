<?php

declare(strict_types=1);

namespace App\Services\Unidades;

use App\Exceptions\ErrorDeAlmacen;
use App\Models\Articulo;
use App\Models\UnidadMedida;
use App\Support\Cantidad;

/**
 * Conversion entre unidades. El unico lugar donde se divide por el factor.
 *
 * La regla del almacen: nunca se guarda stock en cajas ni en pallets.
 * Siempre en unidad base. Convertir es una operacion explicita, con la
 * unidad a la vista, y no una division escondida en un formulario.
 *
 * Que el error clasico del almacen (confundir caja con unidad) no vuelva
 * no depende de que la gente se acuerde: depende de que no exista la
 * forma de escribir "12" sin decir en que unidad. Ver docs/02.
 */
class ConversorDeUnidades
{
    /**
     * Cantidad expresada en `$unidad`, convertida a la unidad base del
     * articulo.
     */
    public function aBase(Articulo $articulo, float|int|string $cantidad, UnidadMedida $unidad): string
    {
        $this->verificarUnidad($unidad, 'unidad de la cantidad');

        $factor = (float) $unidad->unidades_por_base;

        if ($factor <= 0.0) {
            throw new ErrorDeAlmacen(
                sprintf('La unidad %s tiene un factor de conversion invalido.', $unidad->codigo),
                'unidad_invalida'
            );
        }

        $base = (float) $cantidad / $factor;

        if ($base < 0.0) {
            throw new ErrorDeAlmacen(
                'La cantidad no puede ser negativa.',
                'cantidad_invalida'
            );
        }

        return Cantidad::normalizar($base);
    }

    /**
     * Cantidad expresada en unidad base, convertida a `$unidad`.
     */
    public function desdeBase(Articulo $articulo, float|int|string $cantidadBase, UnidadMedida $unidad): string
    {
        $this->verificarUnidad($unidad, 'unidad de destino');

        return Cantidad::normalizar((float) $cantidadBase * (float) $unidad->unidades_por_base);
    }

    /**
     * Verifica que un articulo tenga unidades coherentes.
     *
     * La unidad base tiene que ser una unidad base de verdad (factor 1).
     * Si no lo fuera, el stock quedaria guardado en una caja y la
     * conversion de toda la historia seria distinta cada vez que se toque
     * la unidad.
     *
     * @throws ErrorDeAlmacen si las unidades no son coherentes.
     */
    public function verificarArticulo(Articulo $articulo): void
    {
        $base = $articulo->unidadBase;

        if ($base === null) {
            throw new ErrorDeAlmacen(
                'El articulo no tiene unidad base.',
                'articulo_sin_unidad_base'
            );
        }

        if (! $base->esBase()) {
            throw new ErrorDeAlmacen(
                sprintf(
                    'La unidad base del articulo %s es %s, que no es una unidad base. '
                    . 'El stock tiene que guardarse en unidades, no en cajas.',
                    $articulo->sku,
                    $base->codigo
                ),
                'unidad_base_no_base'
            );
        }

        foreach ([$articulo->unidadCompra, $articulo->unidadVenta] as $unidad) {
            $this->verificarUnidad($unidad, 'unidad del articulo');
        }
    }

    /**
     * Texto para mostrar al operador, sin que tenga que hacer la cuenta.
     *
     * "36 unidades (3 cajas de 12)" es la forma en que el almacen piensa;
     * "36" solo, obliga a la division mental.
     */
    public function describenEnVenta(Articulo $articulo, float|int|string $cantidadBase): string
    {
        $venta = $articulo->unidadVenta;

        if ($venta === null) {
            return Cantidad::normalizar($cantidadBase) . ' und';
        }

        $cantidad = $this->desdeBase($articulo, $cantidadBase, $venta);

        return sprintf('%s %s', $cantidad, $venta->nombre);
    }

    private function verificarUnidad(?UnidadMedida $unidad, string $contexto): void
    {
        if ($unidad === null) {
            throw new ErrorDeAlmacen(
                sprintf('Falta la %s del articulo.', $contexto),
                'unidad_faltante'
            );
        }
    }
}