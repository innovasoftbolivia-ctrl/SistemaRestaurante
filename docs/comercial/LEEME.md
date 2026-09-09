# La propuesta y el presupuesto

Dos entregables comerciales, generados por script para que ninguna cifra se
escriba a mano dos veces:

| Script | Genera | Para qué |
|---|---|---|
| `propuesta.py` | `Propuesta-y-Presupuesto-Sistema-de-Ventas.docx` | Lo que se le entrega al cliente: qué hace el sistema, qué se entrega, qué no, y las dos opciones de precio. |
| `presupuesto_xlsx.py` | `Presupuesto_Sistema_de_Ventas.xlsx` | El modelo con fórmulas vivas, para uso interno: se mueve el tipo de cambio, las horas o la tarifa y se recalcula todo. |

`datos.py` tiene **todos los números en un solo lugar** y los otros dos lo leen.
Para cambiar un precio, se cambia ahí y se regeneran los dos documentos: así no
puede pasar que el Word diga una cosa y el Excel otra.

```bash
pip install python-docx openpyxl
cd docs/comercial
python propuesta.py
python presupuesto_xlsx.py
python verifica_xlsx.py     # comprueba que el libro y el Word digan lo mismo
```

Escriben en `despliegue-demo/`, que vive fuera del repositorio.

## De dónde salen los números

**La tarifa** es el arancel del CITI Santa Cruz, escalón de técnico superior
(Bs 120/hora), el mismo criterio que se usó en el presupuesto de Granja
Aranjuez. El tipo de cambio es Bs 7 por dólar: los precios se piensan en
dólares y se convierten, no al revés.

**Las horas** se estimaron por bloque contra el tamaño real del repositorio —23
controladores, 27 modelos, 12 servicios, 69 vistas, 275 pruebas, 33.695 líneas
propias—. Suman 865 h. Para volver a medir el repositorio:

```bash
find sistema-ventas/app -name '*.php' -exec cat {} + | wc -l
```

**El precio no es el valor de arancel**, y esa es la idea central de toda la
propuesta. A arancel el producto vale Bs 103.800, pero el sistema ya existe: el
cliente no paga el desarrollo, paga un producto terminado. Ese costo se reparte
entre todos los negocios que lo usen (`CLIENTES_AMORTIZACION`), y lo único que
se cobra entero cada vez es la puesta en marcha, que se gasta de nuevo con cada
cliente.

## Lo que hay que revisar antes de mandar

`verifica_xlsx.py` hace dos cosas que a mano se olvidan:

1. **Referencias rotas.** Una fórmula que apunta a una hoja mal escrita no falla
   al guardar: falla con `#¡REF!` cuando el cliente abre el archivo. El script
   recorre las 94 fórmulas y comprueba que cada referencia exista.
2. **Que el Word y el Excel digan lo mismo.** Rehace la cadena de cálculo en
   Python y la compara contra `datos.py`.

Dos trampas que ya mordieron:

- **Los nombres de hoja con tilde van entre comillas** en las fórmulas
  (`'Suscripción'!B15`). Sin comillas puede no resolverse.
- **Las tablas de Word se salen de la página en silencio.** El ancho útil es
  16,79 cm; los anchos de cada `tabla(...)` tienen que sumar 15,5 o menos. El
  ayudante `tabla()` en `comun.py` lo verifica con un `assert`.

## El PDF

Se saca abriendo el `.docx` con Word y «Guardar como → PDF». No hay paso
automático: en la máquina donde se hizo, la automatización COM de Word está
dañada (`TYPE_E_ELEMENTNOTFOUND`). Ver [`../manuales/LEEME.md`](../manuales/LEEME.md).
