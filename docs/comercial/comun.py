# -*- coding: utf-8 -*-
"""Estilos y ayudantes para los documentos Word de la propuesta."""

import datos as D
from docx import Document
from docx.shared import Pt, Cm, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.oxml.ns import qn
from docx.oxml import OxmlElement

AZUL = RGBColor(0x1D, 0x4E, 0xD8)
GRIS = RGBColor(0x55, 0x5B, 0x66)
NEGRO = RGBColor(0x1F, 0x24, 0x2C)

# El ancho útil de la hoja es de 16,79 cm. Word no avisa cuando una tabla se
# pasa: la recorta y ya. Los anchos de cada tabla tienen que sumar 15,5 o menos.
ANCHO_UTIL = 15.5

doc = Document()

normal = doc.styles['Normal']
normal.font.name = 'Calibri'
normal.font.size = Pt(10.5)
normal.font.color.rgb = NEGRO
normal.paragraph_format.space_after = Pt(6)
normal.paragraph_format.line_spacing = 1.15
normal._element.rPr.rFonts.set(qn('w:eastAsia'), 'Calibri')

for _nivel, _tam in ((1, 18), (2, 13.5), (3, 11.5)):
    _est = doc.styles['Heading %d' % _nivel]
    _est.font.name = 'Calibri'
    _est.font.size = Pt(_tam)
    _est.font.bold = True
    _est.font.color.rgb = AZUL if _nivel < 3 else NEGRO
    _est.paragraph_format.space_before = Pt(16 if _nivel == 1 else 12)
    _est.paragraph_format.space_after = Pt(6)

for _sec in doc.sections:
    _sec.top_margin = Cm(2.2)
    _sec.bottom_margin = Cm(2.2)
    _sec.left_margin = Cm(2.4)
    _sec.right_margin = Cm(2.4)


def sombrear(celda, hexcolor):
    tc = celda._tc.get_or_add_tcPr()
    s = OxmlElement('w:shd')
    s.set(qn('w:val'), 'clear')
    s.set(qn('w:fill'), hexcolor)
    tc.append(s)


def p(texto='', negrita=False, cursiva=False, tam=None, color=None, espacio=None, centro=False):
    par = doc.add_paragraph()
    run = par.add_run(texto)
    run.bold = negrita
    run.italic = cursiva
    if tam:
        run.font.size = Pt(tam)
    if color:
        run.font.color.rgb = color
    if espacio is not None:
        par.paragraph_format.space_after = Pt(espacio)
    if centro:
        par.alignment = WD_ALIGN_PARAGRAPH.CENTER
    return par


def vineta(texto, negrita_inicial=None):
    par = doc.add_paragraph(style='List Bullet')
    if negrita_inicial:
        par.add_run(negrita_inicial).bold = True
    par.add_run(texto)
    return par


def tabla(cabeceras, filas, anchos=None, destacar=(), derecha=()):
    assert anchos is None or abs(sum(anchos) - ANCHO_UTIL) < 0.6, \
        'los anchos suman %.1f cm y la tabla se saldria de la pagina' % sum(anchos)
    t = doc.add_table(rows=1, cols=len(cabeceras))
    t.style = 'Table Grid'
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    enc = t.rows[0].cells
    for i, texto in enumerate(cabeceras):
        enc[i].text = ''
        run = enc[i].paragraphs[0].add_run(texto)
        run.bold = True
        run.font.size = Pt(9.5)
        run.font.color.rgb = RGBColor(0xFF, 0xFF, 0xFF)
        sombrear(enc[i], '1D4ED8')
    for f, fila in enumerate(filas):
        celdas = t.add_row().cells
        for i, texto in enumerate(fila):
            celdas[i].text = ''
            par = celdas[i].paragraphs[0]
            par.paragraph_format.space_after = Pt(2)
            if i in derecha:
                par.alignment = WD_ALIGN_PARAGRAPH.RIGHT
            run = par.add_run(str(texto))
            run.font.size = Pt(9.5)
            if (i == 0 and len(fila) > 1) or f in destacar:
                run.bold = True
        relleno = 'DBEAFE' if f in destacar else ('F1F5F9' if f % 2 == 1 else None)
        if relleno:
            for c in celdas:
                sombrear(c, relleno)
    if anchos:
        for fila in t.rows:
            for i, ancho in enumerate(anchos):
                fila.cells[i].width = Cm(ancho)
    doc.add_paragraph().paragraph_format.space_after = Pt(4)
    return t


def aviso(titulo, texto, color='FEF3C7'):
    t = doc.add_table(rows=1, cols=1)
    t.style = 'Table Grid'
    celda = t.rows[0].cells[0]
    celda.width = Cm(ANCHO_UTIL)
    celda.text = ''
    par = celda.paragraphs[0]
    r1 = par.add_run(titulo + '  ')
    r1.bold = True
    r1.font.size = Pt(9.5)
    r2 = par.add_run(texto)
    r2.font.size = Pt(9.5)
    sombrear(celda, color)
    doc.add_paragraph().paragraph_format.space_after = Pt(4)


def precio(usd, sufijo=''):
    """El numero grande de una opcion."""
    par = doc.add_paragraph()
    par.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = par.add_run(D.m(usd) + sufijo)
    r.bold = True
    r.font.size = Pt(26)
    r.font.color.rgb = AZUL
    par.paragraph_format.space_after = Pt(0)
    par2 = doc.add_paragraph()
    par2.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r2 = par2.add_run(D.b(D.bs(usd)) + sufijo)
    r2.font.size = Pt(13)
    r2.font.color.rgb = GRIS
    par2.paragraph_format.space_after = Pt(10)


def salto():
    doc.add_paragraph().add_run().add_break(WD_BREAK.PAGE)


def sin(prefijo, texto):
    """Quita 'US$ ' o 'Bs ' para las columnas que ya lo dicen en la cabecera."""
    return texto.replace(prefijo, '')
