/**
 * Importes de dinero en el navegador, calculados igual que MySQL.
 *
 * La base guarda precios con 2 decimales y cantidades con 3, y redondea con
 * `ROUND` sobre DECIMAL: aritmética exacta, la mitad hacia arriba. En coma
 * flotante, en cambio, 1.45 × 1.5 da 2.1749999… y el mostrador mostraba 2.17
 * donde la venta quedaba en 2.18: el pago dividido se rechazaba y el QR salía
 * por un centavo menos. Pasaba en cerca del 3 % de las combinaciones de precio
 * y peso.
 *
 * Por eso aquí todo se lleva a enteros —diezmilésimas de precio, milésimas de
 * cantidad— antes de multiplicar, y se redondea con división entera.
 */

/** Centavos enteros de un importe que ya viene con 2 decimales. */
export function centavos(importe) {
    return Math.round(Number(importe || 0) * 100);
}

/** ROUND(cantidad × precio, 2), exacto. */
export function importeLinea(precio, cantidad) {
    const p = Math.round(Number(precio || 0) * 10000);   // diezmilésimas
    const q = Math.round(Number(cantidad || 0) * 1000);  // milésimas

    return Math.floor((p * q + 50000) / 100000) / 100;
}

/** ROUND(importe × tasa, 2), exacto, con la tasa de 4 decimales de la base. */
export function impuestoDe(importe, tasa) {
    const c = centavos(importe);
    const t = Math.round(Number(tasa || 0) * 10000);

    return Math.floor((c * t + 5000) / 10000) / 100;
}

/**
 * El impuesto que lleva adentro un importe con impuesto incluido:
 * ROUND(importe × tasa / (1 + tasa), 2), exacto, igual que la columna
 * generada `venta_detalle.impuesto_linea` y Config::impuestoDentroDe.
 */
export function impuestoIncluido(importe, tasa) {
    const c = centavos(importe);
    const t = Math.round(Number(tasa || 0) * 10000);
    const d = 10000 + t;

    return Math.floor((2 * c * t + d) / (2 * d)) / 100;
}

/**
 * El impuesto que queda después del descuento de cabecera:
 * ROUND(bruto × (base − descuento) / base, 2), exacto, igual que
 * sp_recalcular_venta y ReglasEnPhp::recalcularVenta.
 */
export function impuestoConDescuento(bruto, base, descuento) {
    const b = centavos(base);
    if (b <= 0) return 0;

    const numerador = centavos(bruto) * (b - centavos(descuento));

    return Math.floor((2 * numerador + b) / (2 * b)) / 100;
}

/**
 * Lo que paga el cliente por una línea: la columna generada
 * `venta_detalle.total_linea`. Con el impuesto encima es el importe más SU
 * impuesto redondeado; con el impuesto incluido, el importe tal cual.
 *
 * El carrito mostraba precio de estante × cantidad, y el precio de estante ya
 * viene redondeado por unidad: con el IVA encima y precios como 1,17, las
 * líneas sumaban hasta tres centavos distinto del total cobrado.
 */
export function totalLinea(precio, cantidad, afecto, tasa, incluido) {
    const importe = importeLinea(precio, cantidad);

    if (!afecto || incluido) return importe;

    return (centavos(importe) + centavos(impuestoDe(importe, tasa))) / 100;
}

/** Suma importes sin arrastrar error de coma flotante. */
export function sumar(importes) {
    return importes.reduce((total, importe) => total + centavos(importe), 0) / 100;
}

window.montos = { centavos, importeLinea, totalLinea, impuestoDe, impuestoIncluido, impuestoConDescuento, sumar };
