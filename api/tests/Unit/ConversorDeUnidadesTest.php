<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ErrorDeAlmacen;
use App\Models\Articulo;
use App\Models\UnidadMedida;
use App\Services\Unidades\ConversorDeUnidades;
use Tests\TestCase;

/**
 * Conversion de unidades: el error clasico del almacen.
 *
 * "Se recibieron 6 cajas" no es lo mismo que "se recibieron 6 unidades".
 * Si el sistema no distingue las dos cosas, un pedido de 12 ejes se
 * despacha como 12 cajas y el almacen pierde 132 piezas sin darse cuenta.
 *
 * Estas pruebas son unitarias a proposito: no necesitan base de datos
 * porque la conversion es aritmetica, no estado. Corren en cualquier
 * parte, incluso sin PostgreSQL.
 */
class ConversorDeUnidadesTest extends TestCase
{
    private ConversorDeUnidades $conversor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversor = new ConversorDeUnidades();
    }

    public function test_una_caja_de_doce_doce_doce_unidades(): void
    {
        $base = $this->unidad('UND', '1');
        $caja = $this->unidad('CAJA-12', '12');
        $articulo = $this->articulo($base, $caja, $base);

        $this->assertSame(
            '72.000000',
            $this->conversor->aBase($articulo, 6, $caja),
            'Seis cajas de doce tienen que ser setenta y dos unidades.'
        );
    }

    public function test_la_unidad_base_no_se_convierte(): void
    {
        $base = $this->unidad('UND', '1');
        $articulo = $this->articulo($base, $base, $base);

        $this->assertSame('12.000000', $this->conversor->aBase($articulo, 12, $base));
    }

    public function test_un_pallet_de_cuarenta_y_ocho(): void
    {
        $base = $this->unidad('UND', '1');
        $pallet = $this->unidad('PALLET-48', '48');
        $articulo = $this->articulo($base, $pallet, $base);

        $this->assertSame('144.000000', $this->conversor->aBase($articulo, 3, $pallet));
    }

    public function test_la_conversion_de_vuelta_devuelve_la_caja(): void
    {
        $base = $this->unidad('UND', '1');
        $caja = $this->unidad('CAJA-12', '12');
        $articulo = $this->articulo($base, $caja, $base);

        $this->assertSame(
            '6.000000',
            $this->conversor->desdeBase($articulo, 72, $caja),
            'Setenta y dos unidades son seis cajas de doce.'
        );
    }

    public function test_una_caja_media_no_se_trunca_sin_querer(): void
    {
        $base = $this->unidad('UND', '1');
        $caja = $this->unidad('CAJA-12', '12');
        $articulo = $this->articulo($base, $caja, $base);

        // Media caja son seis unidades. El sistema tiene que poder
        // representarlo: en la practica es un pallet abierto.
        $this->assertSame('6.000000', $this->conversor->aBase($articulo, 0.5, $caja));
    }

    public function test_una_cantidad_negativa_se_rechaza(): void
    {
        $base = $this->unidad('UND', '1');
        $caja = $this->unidad('CAJA-12', '12');
        $articulo = $this->articulo($base, $caja, $base);

        $this->expectException(ErrorDeAlmacen::class);

        $this->conversor->aBase($articulo, -1, $caja);
    }

    public function test_un_articulo_no_puede_guardar_stock_en_cajas(): void
    {
        $caja = $this->unidad('CAJA-12', '12');
        $otra = $this->unidad('CAJA-24', '24');
        $articulo = $this->articulo($caja, $caja, $otra);

        // Si la unidad base fuera una caja, todo el stock de este
        // articulo quedaria guardado en cajas y la conversion de la
        // historia seria distinta cada vez que se toque la unidad.
        $this->expectException(ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/no es una unidad base/i');

        $this->conversor->verificarArticulo($articulo);
    }

    public function test_el_texto_para_el_operario_no_le obliga_a_dividir(): void
    {
        $base = $this->unidad('UND', '1');
        $caja = $this->unidad('CAJA-12', '12');
        $articulo = $this->articulo($base, $caja, $base);

        $this->assertSame(
            '6.000000 Caja de 12',
            $this->conversor->describenEnVenta($articulo, 72),
        );
    }

    private function unidad(string $codigo, string $factor): UnidadMedida
    {
        $unidad = new UnidadMedida();
        $unidad->codigo = $codigo;
        $unidad->nombre = $codigo;
        $unidad->unidades_por_base = $factor;

        return $unidad;
    }

    /**
     * Articulo de mentira, solo con las tres unidades.
     *
     * `setRelation` marca la relacion como ya cargada, asi que Eloquent no
     * va a la base a buscarla. Por eso esta prueba es unitaria de verdad y
     * no necesita PostgreSQL.
     */
    private function articulo(UnidadMedida $base, UnidadMedida $compra, UnidadMedida $venta): Articulo
    {
        $articulo = new Articulo();
        $articulo->sku = 'ART-PRUEBA';
        $articulo->nombre = 'Articulo de prueba';

        $articulo->setRelation('unidadBase', $base);
        $articulo->setRelation('unidadCompra', $compra);
        $articulo->setRelation('unidadVenta', $venta);

        return $articulo;
    }
}