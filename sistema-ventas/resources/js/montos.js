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

/** Suma importes sin arrastrar error de coma flotante. */
export function sumar(importes) {
    return importes.reduce((total, importe) => total + centavos(importe), 0) / 100;
}

window.montos = { centavos, importeLinea, impuestoDe, sumar };
