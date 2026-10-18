<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CatalogosController;
use App\Http\Controllers\ArticuloController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConteoController;
use App\Http\Controllers\DocumentoController;
use App\Http\Controllers\OleadaController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\SaludController;
use App\Http\Controllers\UbicacionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API del almacen
|--------------------------------------------------------------------------
|
| Las rutas viven en el grupo `web` de Laravel a proposito: el almacen se
| opera desde una interfaz servida por Nuxt, que actua como servidor
| intermedio (BFF). Asi la cookie de sesion viaja en la misma origen y el
| token CSRF se reenvia como cabecera `X-XSRF-TOKEN`. Ver docs/07.
|
| Lo que es publico: el acceso, el callback y la salud. Nada mas. No hay
| endpoints de datos de negocio sin sesion.
|
*/

// --- Publico ---------------------------------------------------------------

Route::get('/api/v1/salud', SaludController::class)->name('salud');

Route::get('/api/v1/acceso', [AuthController::class, 'entrar'])->name('acceso');
Route::get('/api/v1/acceso/callback', [AuthController::class, 'callback'])->name('acceso.callback');

// --- Con sesion -----------------------------------------------------------

Route::middleware('auth')->group(function (): void {

    Route::post('/api/v1/salir', [AuthController::class, 'salir'])->name('salir');
    Route::get('/api/v1/yo', [AuthController::class, 'yo'])->name('yo');
    Route::get('/api/v1/yo/diagnostico', [AuthController::class, 'diagnostico'])->name('yo.diagnostico');

    // --- Articulos y stock ------------------------------------------------

    Route::get('/api/v1/articulos', [ArticuloController::class, 'index'])->name('articulos.index');
    Route::post('/api/v1/articulos', [ArticuloController::class, 'store'])->name('articulos.store');
    Route::get('/api/v1/articulos/bajo-minimo', [ArticuloController::class, 'bajoMinimo'])
        ->name('articulos.bajo-minimo');

    Route::get('/api/v1/articulos/{articulo}', [ArticuloController::class, 'show'])->name('articulos.show');
    Route::put('/api/v1/articulos/{articulo}', [ArticuloController::class, 'update'])->name('articulos.update');
    Route::post('/api/v1/articulos/{articulo}/desactivar', [ArticuloController::class, 'desactivar'])
        ->name('articulos.desactivar');

    // La pregunta del operario: "donde esta".
    Route::get('/api/v1/articulos/{articulo}/ubicaciones', [ArticuloController::class, 'dondeEsta'])
        ->name('articulos.ubicaciones');
    Route::get('/api/v1/articulos/{articulo}/movimientos', [ArticuloController::class, 'movimientos'])
        ->name('articulos.movimientos');

    // --- Ubicaciones ------------------------------------------------------

    Route::get('/api/v1/ubicaciones', [UbicacionController::class, 'index'])->name('ubicaciones.index');
    Route::post('/api/v1/ubicaciones', [UbicacionController::class, 'store'])->name('ubicaciones.store');
    Route::get('/api/v1/ubicaciones/codigo-barras/{codigo}', [UbicacionController::class, 'porCodigoDeBarras'])
        ->name('ubicaciones.codigo-barras');
    Route::get('/api/v1/ubicaciones/pasillo/{nombre}', [UbicacionController::class, 'pasillo'])
        ->name('ubicaciones.pasillo');

    Route::get('/api/v1/ubicaciones/{ubicacion}', [UbicacionController::class, 'show'])->name('ubicaciones.show');
    Route::put('/api/v1/ubicaciones/{ubicacion}', [UbicacionController::class, 'update'])->name('ubicaciones.update');
    Route::post('/api/v1/ubicaciones/{ubicacion}/desactivar', [UbicacionController::class, 'desactivar'])
        ->name('ubicaciones.desactivar');

    // --- Recepcion y despacho --------------------------------------------

    Route::get('/api/v1/documentos', [DocumentoController::class, 'index'])->name('documentos.index');
    Route::post('/api/v1/documentos', [DocumentoController::class, 'store'])->name('documentos.store');
    Route::get('/api/v1/documentos/ubicaciones/{tipo}', [DocumentoController::class, 'ubicacionesDisponibles'])
        ->name('documentos.ubicaciones');

    Route::get('/api/v1/documentos/{documento}', [DocumentoController::class, 'show'])->name('documentos.show');
    Route::post('/api/v1/documentos/{documento}/confirmar', [DocumentoController::class, 'confirmar'])
        ->name('documentos.confirmar');
    Route::post('/api/v1/documentos/{documento}/anular', [DocumentoController::class, 'anular'])
        ->name('documentos.anular');

    // --- Pedidos ----------------------------------------------------------

    Route::get('/api/v1/pedidos', [PedidoController::class, 'index'])->name('pedidos.index');
    Route::post('/api/v1/pedidos', [PedidoController::class, 'store'])->name('pedidos.store');

    Route::get('/api/v1/pedidos/{pedido}', [PedidoController::class, 'show'])->name('pedidos.show');
    Route::post('/api/v1/pedidos/{pedido}/reservar', [PedidoController::class, 'reservar'])
        ->name('pedidos.reservar');
    Route::post('/api/v1/pedidos/{pedido}/liberar', [PedidoController::class, 'liberar'])
        ->name('pedidos.liberar');
    Route::post('/api/v1/pedidos/{pedido}/despachar', [PedidoController::class, 'despachar'])
        ->name('pedidos.despachar');

    // --- Oleadas de picking ----------------------------------------------

    Route::get('/api/v1/oleadas', [OleadaController::class, 'index'])->name('oleadas.index');
    Route::post('/api/v1/oleadas', [OleadaController::class, 'store'])->name('oleadas.store');

    Route::get('/api/v1/oleadas/{oleada}', [OleadaController::class, 'show'])->name('oleadas.show');
    Route::post('/api/v1/oleadas/{oleada}/armar', [OleadaController::class, 'armar'])->name('oleadas.armar');
    Route::get('/api/v1/oleadas/{oleada}/ruta', [OleadaController::class, 'ruta'])->name('oleadas.ruta');
    Route::post('/api/v1/oleadas/{oleada}/completar', [OleadaController::class, 'completar'])
        ->name('oleadas.completar');
    Route::post('/api/v1/oleadas/{oleada}/paradas/{parada}/recoger', [OleadaController::class, 'recoger'])
        ->name('oleadas.recoger');

    // --- Inventario fisico ------------------------------------------------

    Route::get('/api/v1/conteos', [ConteoController::class, 'index'])->name('conteos.index');
    Route::post('/api/v1/conteos', [ConteoController::class, 'store'])->name('conteos.store');
    Route::get('/api/v1/conteos/pendientes', [ConteoController::class, 'pendientes'])->name('conteos.pendientes');

    Route::get('/api/v1/conteos/{conteo}', [ConteoController::class, 'show'])->name('conteos.show');
    Route::post('/api/v1/conteos/{conteo}/cerrar', [ConteoController::class, 'cerrar'])->name('conteos.cerrar');
    Route::post('/api/v1/conteos/{conteo}/cancelar', [ConteoController::class, 'cancelar'])
        ->name('conteos.cancelar');

    Route::post('/api/v1/conteos/{conteo}/items/{item}', [ConteoController::class, 'registrar'])
        ->name('conteos.registrar');
    Route::post('/api/v1/conteos/{conteo}/items/{item}/justificar', [ConteoController::class, 'justificar'])
        ->name('conteos.justificar');

    // --- Administracion ---------------------------------------------------

    Route::get('/api/v1/admin/zonas', [CatalogosController::class, 'zonas'])->name('admin.zonas');
    Route::post('/api/v1/admin/zonas', [CatalogosController::class, 'guardarZona'])->name('admin.zonas.guardar');

    Route::get('/api/v1/admin/motivos', [CatalogosController::class, 'motivos'])->name('admin.motivos');
    Route::post('/api/v1/admin/motivos', [CatalogosController::class, 'guardarMotivo'])->name('admin.motivos.guardar');

    Route::get('/api/v1/admin/unidades', [CatalogosController::class, 'unidades'])->name('admin.unidades');
    Route::post('/api/v1/admin/unidades', [CatalogosController::class, 'guardarUnidad'])->name('admin.unidades.guardar');

    Route::get('/api/v1/admin/usuarios', [CatalogosController::class, 'usuarios'])->name('admin.usuarios');
    Route::post('/api/v1/admin/usuarios/{usuario}/rol', [CatalogosController::class, 'cambiarRol'])
        ->name('admin.usuarios.rol');
});