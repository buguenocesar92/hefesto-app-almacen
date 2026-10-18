<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoConteo;
use App\Enums\EstadoOleada;
use App\Enums\RolOperador;
use App\Enums\SentidoMovimiento;
use App\Models\Articulo;
use App\Models\Conteo;
use App\Models\MotivoMovimiento;
use App\Models\Oleada;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Tenant;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Zona;
use App\Services\Conteos\ServicioDeConteos;
use App\Services\Picking\ArmadorDeRuta;
use App\Services\Reservas\GestorDeReservas;
use App\Services\Stock\RegistroDeMovimiento;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Un almacen de ejemplo, completo y coherente.
 *
 * Esto es lo que hay que mirar para entender el sistema en una sentada:
 *
 *  - UNA empresa, con cuatro personas y cuatro roles distintos.
 *  - TRES zonas, con pasillos y racks de verdad.
 *  - CUATRO unidades con su factor: pieza, caja de 12, caja de 24 y
 *    pallet de 48. El articulo de ejes se COMPRA en cajas de 12 y se
 *    VENDE en piezas: ese es el error clasico del almacen, resuelto en el
 *    modelo y no con un campo de texto.
 *  - STOCK cargado por MOVIMIENTOS de recepcion, nunca editando saldos:
 *    si este seeder Couldiera escribir el saldo, la garantia del sistema
 *    seria mentira.
 *  - UN pedido reservado y una oleada ARMADA, para que se vea la ruta
 *    ordenada en el momento de abrirlo.
 *  - UN conteo con diferencia justificada, con su movimiento de ajuste y
 *    su explicacion escrita.
 */
class AlmacenEjemploSeeder extends Seeder
{
    public function run(ContextoTenant $contexto, RegistroDeMovimiento $movimientos, GestorDeReservas $gestorReservas, ArmadorDeRuta $armador, ServicioDeConteos $conteos): void
    {
        // `tenants` no tiene RLS: se puede leer sin contexto. Es la unica
        // tabla en esa situacion.
        $existente = Tenant::query()->where('slug', 'bodega-demo.hefestostudio.cl')->first();

        if ($existente !== null) {
            $this->command?->warn('El almacen de ejemplo ya existe. No se vuelve a sembrar.');

            return;
        }

        $tenant = Tenant::query()->create([
            'slug' => 'bodega-demo.hefestostudio.cl',
            'nombre' => 'Bodega de prueba Quilicura',
            'razon_social' => 'Hefesto Studio Demo Ltda.',
            'activo' => true,
        ]);

        // Todo lo que sigue es dato de negocio y va con contexto: sin el,
        // la RLS no deja escribir ni una fila.
        $contexto->ejecutarAislado($tenant->id, function () use ($tenant, $movimientos, $gestorReservas, $armador, $conteos): void {
            $personas = $this->crearPersonas($tenant);
            $zonas = $this->crearZonas($tenant);
            $motivos = $this->crearMotivos($tenant);
            $unidades = $this->crearUnidades($tenant);
            $articulos = $this->crearArticulos($tenant, $unidades);
            $ubicaciones = $this->crearUbicaciones($tenant, $zonas);

            $this->cargarStockInicial($movimientos, $articulos, $ubicaciones, $motivos, $personas['jefe']);

            $pedido = $this->crearPedidoReservado($tenant, $articulos, $unidades, $personas['preparador']);

            $gestorReservas->reservar($pedido, Carbon::now()->addHours(24), $personas['preparador']);

            $this->armarOleada($tenant, $zonas['A'], $armador, $personas['jefe']);

            $this->dejarConteoConDiferencia($conteos, $tenant, $zonas['B'], $articulos, $ubicaciones, $motivos, $personas['jefe']);

            $this->command?->info(sprintf(
                'Almacen de ejemplo listo. Empresa %s. Usuario para entrar: jefe@%s',
                $tenant->slug,
                $tenant->slug
            ));
        });
    }

    /** @return array{administrador: Usuario, jefe: Usuario, preparador: Usuario, auditor: Usuario} */
    private function crearPersonas(Tenant $tenant): array
    {
        return [
            'administrador' => Usuario::factory()->administrador()->de($tenant)->create([
                'email' => 'admin@' . $tenant->slug,
                'nombre' => 'Ana Perez',
            ]),
            'jefe' => Usuario::factory()->jefe()->de($tenant)->create([
                'email' => 'jefe@' . $tenant->slug,
                'nombre' => 'Luis Rojas',
            ]),
            'preparador' => Usuario::factory()->de($tenant)->create([
                'email' => 'preparador@' . $tenant->slug,
                'nombre' => 'Carla Munoz',
                'rol' => RolOperador::PREPARADOR,
            ]),
            'auditor' => Usuario::factory()->auditor()->de($tenant)->create([
                'email' => 'auditor@' . $tenant->slug,
                'nombre' => 'Diego Fuentes',
            ]),
        ];
    }

    /** @return array<string, Zona> */
    private function crearZonas(Tenant $tenant): array
    {
        return [
            'A' => Zona::query()->create([
                'tenant_id' => $tenant->id,
                'codigo' => 'A',
                'nombre' => 'Pasillos 1 a 3',
                'descripcion' => 'Reposicion rapida: lo que mas se pide.',
                'pasillo_desde' => '1',
                'pasillo_hasta' => '3',
            ]),
            'B' => Zona::query()->create([
                'tenant_id' => $tenant->id,
                'codigo' => 'B',
                'nombre' => 'Pasillos 4 a 6',
                'descripcion' => 'Reposicion lenta y mercaderia pesada.',
                'pasillo_desde' => '4',
                'pasillo_hasta' => '6',
            ]),
            'C' => Zona::query()->create([
                'tenant_id' => $tenant->id,
                'codigo' => 'C',
                'nombre' => 'Recepcion y despacho',
                'descripcion' => 'Banca de recepcion y mesa de empaque. No se guarda mercaderia de forma permanente.',
            ]),
        ];
    }

    /** @return array<string, MotivoMovimiento> */
    private function crearMotivos(Tenant $tenant): array
    {
        $definiciones = [
            ['RECEPCION', 'Recepcion de compra', SentidoMovimiento::INGRESO, false],
            ['DESPACHO', 'Despacho por venta', SentidoMovimiento::SALIDA, false],
            ['TRASPASO', 'Traspaso entre ubicaciones', SentidoMovimiento::AJUSTE, false],
            ['MERMA', 'Merma por rotura o vencimiento', SentidoMovimiento::AJUSTE, true],
            ['AJUSTE_SISTEMA', 'Correccion administrativa', SentidoMovimiento::AJUSTE, true],
            ['DEVOLUCION', 'Devolucion de cliente', SentidoMovimiento::INGRESO, false],
            ['MUESTRA', 'Muestra o corte', SentidoMovimiento::SALIDA, true],
        ];

        $motivos = [];

        foreach ($definiciones as [$codigo, $nombre, $sentido, $exige]) {
            $motivos[$codigo] = MotivoMovimiento::query()->create([
                'tenant_id' => $tenant->id,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'sentido' => $sentido,
                'exige_justificacion' => $exige,
            ]);
        }

        return $motivos;
    }

    /** @return array<string, UnidadMedida> */
    private function crearUnidades(Tenant $tenant): array
    {
        $definiciones = [
            // El factor de conversion vive en la UNIDAD, no en el
            // articulo. Por eso dos articulos que se venden en cajas de 12
            // y de 24 usan dos unidades distintas.
            'UND' => ['Unidad', '1'],
            'CAJA12' => ['Caja de 12', '12'],
            'CAJA24' => ['Caja de 24', '24'],
            'PALLET48' => ['Pallet de 48', '48'],
        ];

        $unidades = [];

        foreach ($definiciones as $codigo => [$nombre, $factor]) {
            $unidades[$codigo] = UnidadMedida::query()->create([
                'tenant_id' => $tenant->id,
                'codigo' => $codigo,
                'nombre' => $nombre,
                'unidades_por_base' => $factor,
            ]);
        }

        return $unidades;
    }

    /**
     * @param  array<string, UnidadMedida>  $unidades
     * @return array<string, Articulo>
     */
    private function crearArticulos(Tenant $tenant, array $unidades): array
    {
        $articulos = [];

        $articulos['eje'] = Articulo::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'EJE-6204',
            'nombre' => 'Eje de acero 6204',
            'descripcion' => 'Se compra en cajas de 12 y se vende por pieza. Es el ejemplo del error clasico del almacen.',
            'unidad_base_id' => $unidades['UND']->id,
            'unidad_compra_id' => $unidades['CAJA12']->id,
            'unidad_venta_id' => $unidades['UND']->id,
            'stock_minimo' => '240',
        ]);

        $articulos['manual'] = Articulo::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'MAN-REF',
            'nombre' => 'Manual de mantenimiento',
            'descripcion' => 'Se compra y se vende en cajas de 24.',
            'unidad_base_id' => $unidades['UND']->id,
            'unidad_compra_id' => $unidades['CAJA24']->id,
            'unidad_venta_id' => $unidades['CAJA24']->id,
            'stock_minimo' => '120',
        ]);

        $articulos['filtro'] = Articulo::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'FIL-ANF',
            'nombre' => 'Filtro de aire ANF-9',
            'descripcion' => 'Viene en pallet de 48 y se vende por pieza.',
            'unidad_base_id' => $unidades['UND']->id,
            'unidad_compra_id' => $unidades['PALLET48']->id,
            'unidad_venta_id' => $unidades['UND']->id,
        ]);

        $articulos['correa'] = Articulo::query()->create([
            'tenant_id' => $tenant->id,
            'sku' => 'COR-8M',
            'nombre' => 'Correa de 8 mm',
            'unidad_base_id' => $unidades['UND']->id,
            'unidad_compra_id' => $unidades['UND']->id,
            'unidad_venta_id' => $unidades['UND']->id,
        ]);

        return $articulos;
    }

    /**
     * @param  array<string, Zona>  $zonas
     * @return array<string, array<int, Ubicacion>>
     */
    private function crearUbicaciones(Tenant $tenant, array $zonas): array
    {
        $creadas = [];

        // Pasillo 1 a 3, zona A: racks 01 a 06, tres niveles.
        $creadas['A'] = [];

        foreach (['1' => 'A', '2' => 'A', '3' => 'A', '4' => 'B', '5' => 'B', '6' => 'B'] as $pasillo => $zona) {
            for ($rack = 1; $rack <= 6; $rack++) {
                for ($nivel = 1; $nivel <= 3; $nivel++) {
                    $rackTexto = str_pad((string) $rack, 2, '0', STR_PAD_LEFT);
                    $codigo = sprintf('%s-%s-%02d-01', $pasillo, $rackTexto, $nivel);

                    $creadas[$zona][] = Ubicacion::query()->create([
                        'tenant_id' => $tenant->id,
                        'zona_id' => $zonas[$zona]->id,
                        'codigo' => $codigo,
                        'pasillo' => $pasillo,
                        'rack' => $rackTexto,
                        'nivel' => $nivel,
                        'posicion' => 1,
                        'codigo_barras' => 'ALM-' . $codigo,
                    ]);
                }
            }
        }

        return $creadas;
    }

    /**
     * Carga el stock con MOVIMIENTOS DE RECEPCION, no escribiendo saldos.
     *
     * Si este metodo pudiera escribir saldos, el gatillo de PostgreSQL
     * lo impediria: eso es exactamente lo que se quiere comprobar.
     *
     * @param  array<string, Articulo>  $articulos
     * @param  array<string, array<int, Ubicacion>>  $ubicaciones
     * @param  array<string, MotivoMovimiento>  $motivos
     */
    private function cargarStockInicial(
        RegistroDeMovimiento $movimientos,
        array $articulos,
        array $ubicaciones,
        array $motivos,
        Usuario $usuario,
    ): void {
        $motivo = $motivos['RECEPCION'];

        // 480 ejes: 40 cajas de 12, repartidas en los pasillos 1 y 2.
        foreach (array_slice($ubicaciones['A'], 0, 4) as $indice => $ubicacion) {
            $movimientos->ingresar(
                articulo: $articulos['eje'],
                ubicacion: $ubicacion,
                cantidadBase: 120,
                motivo: $motivo,
                referencia: 'OC-1001',
                usuario: $usuario,
                notas: 'Recepcion de compra: 10 cajas de 12 por pasillo.',
            );
        }

        // 96 manuales: 4 cajas de 24, en el pasillo 3.
        foreach (array_slice($ubicaciones['A'], 8, 2) as $ubicacion) {
            $movimientos->ingresar(
                articulo: $articulos['manual'],
                ubicacion: $ubicacion,
                cantidadBase: 48,
                motivo: $motivo,
                referencia: 'OC-1001',
                usuario: $usuario,
            );
        }

        // 96 filtros: 2 pallets de 48, en el pasillo 5 (zona B).
        foreach (array_slice($ubicaciones['B'], 0, 2) as $ubicacion) {
            $movimientos->ingresar(
                articulo: $articulos['filtro'],
                ubicacion: $ubicacion,
                cantidadBase: 48,
                motivo: $motivo,
                referencia: 'OC-1002',
                usuario: $usuario,
            );
        }

        // 30 correas en el pasillo 6, para que el pasillo tenga algo y la
        // ruta no quede con un tramo vacio.
        $movimientos->ingresar(
            articulo: $articulos['correa'],
            ubicacion: $ubicaciones['B'][6],
            cantidadBase: 30,
            motivo: $motivo,
            referencia: 'OC-1002',
            usuario: $usuario,
        );
    }

    /**
     * @param  array<string, Articulo>  $articulos
     * @param  array<string, UnidadMedida>  $unidades
     */
    private function crearPedidoReservado(Tenant $tenant, array $articulos, array $unidades, Usuario $preparador): Pedido
    {
        $pedido = Pedido::query()->create([
            'tenant_id' => $tenant->id,
            'numero' => 'PED-1001',
            'referencia_externa' => 'OV-55231',
            'destinatario' => 'Taller mecanico Silva',
            'prioridad' => 2,
            'fecha_entrega' => Carbon::now()->addDay()->format('Y-m-d'),
            'notas' => 'Cliente pide despacho antes de las 16:00.',
        ]);

        $lineas = [
            // 72 ejes en cajas de 12 (6 cajas), pero se anota como caja
            // porque el cliente las pide asi. La conversion pasa por
            // unidad base: 6 x 12 = 72 piezas.
            ['eje', '6', $unidades['CAJA12'], 'Cajas de 12 ejes para el taller.'],
            ['manual', '2', $unidades['CAJA24'], 'Dos cajas de manuales.'],
            ['correa', '10', $unidades['UND'], 'Diez correas sueltas.'],
        ];

        foreach ($lineas as [$clave, $cantidad, $unidad, $nota]) {
            $articulo = $articulos[$clave];

            PedidoItem::query()->create([
                'tenant_id' => $tenant->id,
                'pedido_id' => $pedido->id,
                'articulo_id' => $articulo->id,
                'cantidad' => $cantidad,
                'unidad_id' => $unidad->id,
                'cantidad_base' => (float) $cantidad * (float) $unidad->unidades_por_base,
            ]);
        }

        return $pedido;
    }

    private function armarOleada(Tenant $tenant, Zona $zona, ArmadorDeRuta $armador, Usuario $jefe): Oleada
    {
        $oleada = Oleada::query()->create([
            'tenant_id' => $tenant->id,
            'zona_id' => $zona->id,
            'nombre' => 'Oleada manana - zona A',
            'fecha' => Carbon::now()->addDay()->format('Y-m-d'),
            'estado' => EstadoOleada::ABIERTA,
        ]);

        $armador->armar($oleada, $zona, $jefe);

        return $oleada;
    }

    /**
     * Deja un conteo con diferencia, ya justificado y corregido.
     *
     * Sirve para mostrar el caso completo: foto congelada, diferencia,
     * motivo, explicacion escrita y movimiento de ajuste. Si alguien
     * pregunta "que pasa cuando el numero no cuadra", esta fila es la
     * respuesta.
     *
     * @param  array<string, Articulo>  $articulos
     * @param  array<string, array<int, Ubicacion>>  $ubicaciones
     * @param  array<string, MotivoMovimiento>  $motivos
     */
    private function dejarConteoConDiferencia(
        ServicioDeConteos $conteos,
        Tenant $tenant,
        Zona $zona,
        array $articulos,
        array $ubicaciones,
        array $motivos,
        Usuario $jefe,
    ): void {
        $conteo = $conteos->abrir($zona, 'Conteo mensual zona B', $jefe);

        $items = $conteo->items()->orderBy('ubicacion_id')->get();

        // Todo cuadra salvo la primera fila: faltan 3 correas.
        foreach ($items as $indice => $item) {
            $faltan = $indice === 0 ? 3 : 0;

            $conteos->registrar($conteo, $item, (float) $item->cantidad_sistema - $faltan, $jefe);

            if ($faltan === 0) {
                continue;
            }

            $item->motivo_movimiento_id = $motivos['MERMA']->id;
            $item->justificacion = 'Se encontraron 3 unidades menos de las que el sistema registra. '
                . 'Segun el operador del pasillo, se rompieron al destapar el pallet de la semana pasada '
                . 'y el carton quedo humedo en el piso. Se propone merma.';
            $item->save();
        }

        $conteos->cerrar($conteo, $jefe);

        $this->command?->info(sprintf(
            'Conteo de ejemplo cerrado con diferencia: %s (%s).',
            $conteo->nombre,
            $conteo->estado->value === EstadoConteo::CERRADO->value ? 'cerrado' : 'abierto'
        ));
    }
}