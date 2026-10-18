/**
 * Tipos que cruzan la frontera entre la API y la interfaz.
 *
 * LAS TRES CIFRAS DEL STOCK
 * -------------------------
 * `Stock` tiene tres campos y NO uno. `disponible` viene derivado del
 * servidor (`fisica - reservada`), nunca se calcula en el navegador: si el
 * navegador hiciera la resta, alguna pantalla se olvidaria del reservado y
 * prometeria mercaderia que ya salio.
 */

export interface Stock {
  /** Lo que hay en el rack. */
  fisico: string;
  /** Lo comprometido a pedidos. */
  reservado: string;
  /** Lo que se puede vender: derivado en el servidor. */
  disponible: string;
}

export interface Usuario {
  id: string;
  nombre: string;
  email: string;
  rol: 'auditor' | 'preparador' | 'jefe_almacen' | 'administrador';
  empresa: string | null;
  puede_anular: boolean;
  puede_cerrar_conteos: boolean;
  puede_administrar: boolean;
}

export interface Articulo {
  id: string;
  sku: string;
  nombre: string;
  descripcion: string | null;
  activo: boolean;
  unidad_base: string | null;
  unidad_compra: string | null;
  unidad_venta: string | null;
  /** Cuantas unidades de venta trae una unidad de compra. */
  factor_compra_venta: string;
  stock: Stock;
  /** La misma cifra disponible, expresada en unidad de venta. */
  en_venta: string;
  stock_minimo: string | null;
}

export interface Ubicacion {
  id: string;
  codigo: string;
  codigo_barras: string;
  pasillo: string;
  rack: string;
  nivel: number;
  posicion: number;
  etiqueta: string;
  descripcion: string | null;
  zona: string | null;
  zona_id: string | null;
  activo: boolean;
}

export interface UbicacionConStock {
  saldo_id: string;
  ubicacion: Ubicacion | null;
  codigo: string | null;
  codigo_barras: string | null;
  etiqueta: string | null;
  zona: string | null;
  fisico: string;
  reservado: string;
  disponible: string;
}

export interface ParadaDeRuta {
  /** Id de la parada en `oleada_items`: lo usa la API para marcarla. */
  id: string;
  secuencia: number;
  estado: string;
  cantidad_base: string;
  articulo: {
    id: string | null;
    sku: string | null;
    nombre: string | null;
  };
  ubicacion: {
    id: string;
    codigo: string;
    codigo_barras: string;
    etiqueta: string;
  } | null;
  pedido: {
    numero: string | null;
    prioridad: number | null;
  };
}

export interface Oleada {
  id: string;
  nombre: string;
  fecha: string | null;
  estado: string;
  zona: string | null;
  paradas: ParadaDeRuta[];
  por_zona: Record<string, number>;
  items_count?: number;
}

export interface Conteo {
  id: string;
  nombre: string;
  zona: string | null;
  estado: string;
  congelado: boolean;
  abierto_en: string | null;
  cerrado_en: string | null;
  items: ConteoItem[];
  resumen: {
    paradas: number;
    contadas: number;
    diferencias: number;
  };
}

export interface ConteoItem {
  id: string;
  articulo: { id: string; sku: string | null; nombre: string | null };
  ubicacion: { id: string; codigo: string | null; etiqueta: string | null };
  cantidad_sistema: string;
  cantidad_contada: string | null;
  diferencia: string | null;
  motivo_id: string | null;
  justificacion: string | null;
}

export interface Pagina<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface ErrorDeAlmacen {
  mensaje: string;
  codigo: string;
}