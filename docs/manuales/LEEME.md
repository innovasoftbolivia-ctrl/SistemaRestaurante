# Los manuales del cliente

Dos documentos Word, cada uno generado por su script con `python-docx`:

| Script | Genera | Para qué |
|---|---|---|
| `manual-de-uso.py` | `Manual-Sistema-de-Ventas.docx` | Que el cliente **pruebe** el sistema: ejercicios paso a paso, con qué comprobar en cada uno. |
| `manual-de-funcionamiento.py` | `Manual-Funcionamiento.docx` | Referencia de **cómo funciona**: módulos, estados, fórmulas y reglas. |

```bash
pip install python-docx
python docs/manuales/manual-de-uso.py
python docs/manuales/manual-de-funcionamiento.py
```

Escriben directo en `despliegue-demo/`, que vive fuera del repositorio: la
ruta está al final de cada script.

## Dos cosas que ya salieron mal

**Las tablas se salen de la página en silencio.** El ancho útil es de 16,79 cm
(A4 menos los márgenes). Word no avisa: recorta y ya. Los anchos de cada
`tabla(...)` tienen que sumar 15,5 cm o menos.

**Word ignora los `\n` dentro de un párrafo.** No los junta ni los muestra:
simplemente desaparecen y las dos frases quedan pegadas. Si hacen falta dos
líneas, son dos llamadas a `p(...)`.

## El PDF

Se saca abriendo el `.docx` con Word y «Guardar como → PDF». No hay paso
automático: en la máquina donde se hicieron, la automatización COM de Word
está dañada (`TYPE_E_ELEMENTNOTFOUND`). Si generas el PDF, comprueba que sea
del mismo día que el `.docx` — un PDF viejo junto a un Word nuevo es peor que
no tener PDF.
