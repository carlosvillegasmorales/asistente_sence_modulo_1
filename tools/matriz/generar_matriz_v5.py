"""Genera la matriz RU-RS-EARS v5 (Excel y Word) y el SPEC v5.1 desde una sola fuente de datos.

Entradas:
  - docs/Spec_Asistente_IA_para_IDE.md (v5): ID, categoría, fuente, RS, EARS, patrón y marca de cambio.
  - Matriz v4 (xlsx): redacción RU de los 50 requisitos sin cambios.
  - datos_v5.py: RU de los 60 nuevos o modificados y estado de implementación de los 110.

Salidas (en Documentos/Documentos de requerimientos/):
  - Matriz_RU_RS_EARS_Asistente_IA_Modulo1_v5.xlsx / .docx  (el PDF se exporta desde Word)
  - y el SPEC v5.1 en docs/ (se reescribe en el mismo archivo, con el estado de cada requisito).

Uso:  python generar_matriz_v5.py
Requiere: pip install openpyxl python-docx
"""

import re
import sys
from collections import Counter, OrderedDict
from pathlib import Path

import openpyxl
from docx import Document
from docx.enum.table import WD_TABLE_ALIGNMENT
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Pt, RGBColor, Cm
from openpyxl.styles import Alignment, Border, Font, PatternFill, Side
from openpyxl.utils import get_column_letter

sys.stdout.reconfigure(encoding="utf-8")
AQUI = Path(__file__).resolve().parent
sys.path.insert(0, str(AQUI))
import datos_v5 as D  # noqa: E402

REPO = AQUI.parents[1]
SPEC = REPO / "docs" / "Spec_Asistente_IA_para_IDE.md"
DOCS = REPO.parent / "Documentos" / "Documentos de requerimientos"
# Copia versionada de la matriz v4: de ahi sale la redaccion RU de los 50
# requisitos que v5 no cambio. Vive en el repo para que el generador no dependa
# de un archivo suelto de la carpeta de entrega.
V4 = AQUI / "fuente_ru_v4.xlsx"
OUT_XLSX = DOCS / "Matriz_RU_RS_EARS_Asistente_IA_Modulo1_v5.xlsx"
OUT_DOCX = DOCS / "Matriz_RU_RS_EARS_Asistente_IA_Modulo1_v5.docx"
VERSION_PLUGIN = "M1-v1.9.4"
FECHA = "29-09-2026"
AZUL = "0B4582"

CATEGORIAS = OrderedDict([
    ("0.", "Arquitectura general"), ("1.", "Reglas del asistente durante el diagnóstico"),
    ("2.", "Modo A · Tutor de aprendizaje"), ("3.", "Modo B · Coach de desarrollo"),
    ("4.", "Modo C · Evaluador formativo"), ("5.", "Motor de apoyos adaptativos (transversal)"),
    ("6.", "Gobernanza y límites"), ("7.", "Protección y uso responsable de IA"),
    ("8.", "Consentimiento informado"), ("9.", "Cálculo y datos del diagnóstico (insumo del asistente)"),
    ("10.", "Requisitos no funcionales"), ("11.", "Trazabilidad y registros (Anexo G)"),
    ("12.", "Base de conocimiento, experiencia usuaria y validación"),
])


# ------------------------------------------------------------------ extracción
def extraer_spec(texto):
    reqs, seccion = [], ""
    for bloque in re.split(r"\n(?=## |#### `)", texto):
        m = re.match(r"## (.+)", bloque)
        if m:
            seccion = m.group(1).strip()
            continue
        m = re.match(r"#### `([A-Z0-9-]+)` — patrón EARS: \*\*([^*]+)\*\*(?: `\[(NUEVO|MODIFICADO) v5\]`)?", bloque)
        if not m:
            continue

        def campo(etiqueta):
            mm = re.search(r"- \*\*" + etiqueta + r":\*\* (.+?)(?=\n- \*\*|\n\n```|\n```|\n\n\||\Z)", bloque, re.S)
            return re.sub(r"\s+", " ", mm.group(1)).strip() if mm else ""
        cat = next((v for k, v in CATEGORIAS.items() if seccion.startswith(k)), seccion)
        reqs.append({"id": m.group(1), "patron": m.group(2).strip(), "cambio": m.group(3) or "Sin cambios",
                     "categoria": cat, "fuente": campo("Fuente"), "rs": campo(r"RS \(para implementar\)"),
                     "ears": campo("EARS"), "nota": campo("Nota(?: v5)?")})
    return reqs


def ru_v4():
    wb = openpyxl.load_workbook(V4, read_only=True)
    ws = wb["Matriz RU-RS-EARS"]
    return {r[0]: r[3] for r in ws.iter_rows(min_row=2, values_only=True) if r[0]}


def limpiar(t):
    """Markdown ligero a texto plano para las celdas."""
    t = re.sub(r"`([^`]+)`", r"\1", t or "")
    t = re.sub(r"\*\*([^*]+)\*\*", r"\1", t)
    return t.replace("~~", "")


# ------------------------------------------------------------------ Excel
def generar_xlsx(reqs):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Matriz RU-RS-EARS v5"
    cols = [("ID", 18), ("Categoría", 24), ("Fuente (documento · sección)", 34), ("Requerimiento de Usuario (RU)", 55),
            ("Requerimiento de Sistema (RS)", 70), ("Patrón EARS", 12), ("Requerimiento EARS", 60),
            ("Cambio v5", 13), ("Estado de implementación", 22), ("Versión del plugin", 13),
            ("Evidencia de verificación", 55), ("Notas", 45)]
    fino = Side(style="thin", color="BFC7D5")
    borde = Border(left=fino, right=fino, top=fino, bottom=fino)
    for i, (nombre, ancho) in enumerate(cols, 1):
        c = ws.cell(row=1, column=i, value=nombre)
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor=AZUL)
        c.alignment = Alignment(wrap_text=True, vertical="center")
        c.border = borde
        ws.column_dimensions[get_column_letter(i)].width = ancho
    colores = {D.VER: "E3F1E6", D.IMP: "E6EEF8", D.PAR: "FFF4D6", D.BLQ: "F8E1E1"}
    for fila, r in enumerate(reqs, 2):
        valores = [r["id"], r["categoria"], limpiar(r["fuente"]), limpiar(r["ru"]), limpiar(r["rs"]), r["patron"],
                   limpiar(r["ears"]), r["cambio"], r["estado"], r["version"], limpiar(r["evidencia"]), limpiar(r["nota"])]
        for i, v in enumerate(valores, 1):
            c = ws.cell(row=fila, column=i, value=v)
            c.alignment = Alignment(wrap_text=True, vertical="top")
            c.border = borde
        ws.cell(row=fila, column=9).fill = PatternFill("solid", fgColor=colores[r["estado"]])
        if r["cambio"] != "Sin cambios":
            ws.cell(row=fila, column=8).font = Font(bold=True, color=AZUL)
    ws.freeze_panes = "B2"
    ws.auto_filter.ref = f"A1:{get_column_letter(len(cols))}{len(reqs) + 1}"

    # Resumen.
    rs = wb.create_sheet("Resumen de cumplimiento")
    rs.column_dimensions["A"].width = 52
    for c in "BCDEF":
        rs.column_dimensions[c].width = 16
    rs["A1"] = f"Matriz RU → RS → EARS v5 — Asistente IA, Módulo 1 · estado al {FECHA} ({VERSION_PLUGIN})"
    rs["A1"].font = Font(bold=True, size=13, color=AZUL)
    encab = ["Categoría", D.VER, D.IMP, D.PAR, D.BLQ, "Total"]
    for i, h in enumerate(encab, 1):
        c = rs.cell(row=3, column=i, value=h)
        c.font = Font(bold=True, color="FFFFFF")
        c.fill = PatternFill("solid", fgColor=AZUL)
        c.alignment = Alignment(wrap_text=True)
    fila = 4
    for cat in CATEGORIAS.values():
        sub = [r for r in reqs if r["categoria"] == cat]
        cnt = Counter(r["estado"] for r in sub)
        for i, v in enumerate([cat, cnt[D.VER], cnt[D.IMP], cnt[D.PAR], cnt[D.BLQ], len(sub)], 1):
            rs.cell(row=fila, column=i, value=v)
        fila += 1
    tot = Counter(r["estado"] for r in reqs)
    for i, v in enumerate(["Total", tot[D.VER], tot[D.IMP], tot[D.PAR], tot[D.BLQ], len(reqs)], 1):
        c = rs.cell(row=fila, column=i, value=v)
        c.font = Font(bold=True)
    fila += 2
    guia = [
        "Estados:",
        f"{D.VER}: cumple y lo prueba un caso de la prueba automática del flujo completo o un verificador del plugin (cli/verificar_*.php).",
        f"{D.IMP}: está implementado en el código o en el prompt operativo, pero no hay un caso de prueba que lo verifique de forma específica.",
        f"{D.PAR}: se cumple una parte; la columna Evidencia dice qué falta.",
        f"{D.BLQ}: el comportamiento está construido, pero falta un insumo o una definición del cliente (ver sección 13 del SPEC).",
        "",
        "Fuentes de verdad: el RS y el EARS vienen del SPEC v5 (docs/Spec_Asistente_IA_para_IDE.md); la evidencia, de "
        "docs/RESULTADOS_PRUEBAS_AUTOMATICAS.md y tools/plan_pruebas/*.json. Los casos F-/A-/B-/C-/I-/P- son los del guion "
        "docs/PLAN_PRUEBAS_FLUJO_COMPLETO.md, trazados a los casos CP- del Anexo H.",
        "Cambio v5: 'NUEVO' = requisito agregado en v5 a partir del PDP (Doc 5) y la Unidad 1 (Doc 6.0, Anexos A–H); "
        "'MODIFICADO' = requisito de la v4 ajustado en v5.",
    ]
    for linea in guia:
        c = rs.cell(row=fila, column=1, value=linea)
        c.alignment = Alignment(wrap_text=True, vertical="top")
        rs.merge_cells(start_row=fila, start_column=1, end_row=fila, end_column=6)
        rs.row_dimensions[fila].height = 30 if len(linea) > 90 else 16
        fila += 1
    wb.save(OUT_XLSX)


# ------------------------------------------------------------------ Word
def sombrear(celda, color):
    tcpr = celda._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:val"), "clear")
    shd.set(qn("w:color"), "auto")
    shd.set(qn("w:fill"), color)
    tcpr.append(shd)


def generar_docx(reqs):
    doc = Document()
    estilo = doc.styles["Normal"]
    estilo.font.name = "Calibri"
    estilo.font.size = Pt(10)
    for s in doc.sections:
        s.left_margin = s.right_margin = Cm(2)
        s.top_margin = s.bottom_margin = Cm(2)

    def titulo(texto, nivel=1):
        h = doc.add_heading(texto, level=nivel)
        for run in h.runs:
            run.font.color.rgb = RGBColor.from_string(AZUL)
        return h

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r = p.add_run("MATRIZ DE TRAZABILIDAD")
    r.bold = True
    r.font.size = Pt(18)
    r.font.color.rgb = RGBColor.from_string(AZUL)
    for linea in ["Requerimiento de Usuario → Requerimiento de Sistema → EARS",
                  "Asistente de Inteligencia Artificial — Módulo 1",
                  "Sistema de Autoaprendizaje Adaptativo para Personas Facilitadoras",
                  f"Versión 5 · {FECHA} · estado de implementación: plugin {VERSION_PLUGIN}"]:
        q = doc.add_paragraph(linea)
        q.alignment = WD_ALIGN_PARAGRAPH.CENTER

    titulo("Cómo usar este documento")
    doc.add_paragraph(
        "Consolida los 111 requisitos del asistente de IA (modos Tutor, Coach y Evaluador formativo), extraídos de los "
        "documentos de Interfases Consultores: (0) Arquitectura del Módulo 1, (1) Bienvenida, Perfilamiento y "
        "Consentimiento, (2) Diagnóstico Autoaplicado, (3) Matriz Individual de Brechas, (4) Matriz de Diseño del "
        "Itinerario Base, (5) Plan Personal de Desarrollo y (6.0 y Anexos A–H) Unidad 1. Incluye además requisitos no "
        "funcionales recomendados (NFR), marcados como tales.")
    for linea in ["RU: la necesidad en lenguaje de negocio o de usuario.",
                  "RS: la traducción técnica de esa necesidad.",
                  "EARS: la forma verificable del RS (Ubicuo, Evento, Estado, Donde, No deseado).",
                  "Estado de implementación: si el plugin lo cumple, con qué versión y con qué evidencia."]:
        doc.add_paragraph(linea, style="List Bullet")

    titulo("Historial de cambios")
    for linea in [
        "v2 — Fórmula del puntaje compuesto de orientación (RU-CALC-001).",
        "v3 — Matriz Individual (Doc 3) y Matriz de Diseño del Itinerario (Doc 4): motor de la matriz del Coach y "
        "motor de apoyos adaptativos; corrección de la regla de derivación (RU-GEN-DERIV-001).",
        "v4 — Requisitos no funcionales recomendados: seguridad ante inyección, contención de alcance, límites de uso "
        "y arquitectura multi-proveedor.",
        "v5 — Se incorporan el PDP (Doc 5) y la Unidad 1 (Doc 6.0 y Anexos A–H): 18 requisitos modificados y 42 "
        "nuevos (Tutor, Coach, trazabilidad, privacidad, base de conocimiento, experiencia usuaria y validación). "
        "Se agrega el estado de implementación de cada requisito, verificado con la prueba automática del flujo "
        f"completo sobre el plugin {VERSION_PLUGIN}. La fuente 'Doc 5' de la v4 pasa a 'Doc 4' (numeración de la "
        "carpeta Documentos Cliente)."]:
        doc.add_paragraph(linea, style="List Bullet")

    titulo("Resumen de cumplimiento")
    cats = list(CATEGORIAS.values())
    t = doc.add_table(rows=1, cols=6)
    t.style = "Table Grid"
    t.alignment = WD_TABLE_ALIGNMENT.CENTER
    for i, h in enumerate(["Categoría", "Verificado", "Implementado", "Parcial", "Bloqueado", "Total"]):
        c = t.rows[0].cells[i]
        c.text = h
        c.paragraphs[0].runs[0].bold = True
        c.paragraphs[0].runs[0].font.color.rgb = RGBColor(255, 255, 255)
        sombrear(c, AZUL)
    for cat in cats + ["Total"]:
        sub = reqs if cat == "Total" else [r for r in reqs if r["categoria"] == cat]
        cnt = Counter(r["estado"] for r in sub)
        row = t.add_row().cells
        for i, v in enumerate([cat, cnt[D.VER], cnt[D.IMP], cnt[D.PAR], cnt[D.BLQ], len(sub)]):
            row[i].text = str(v)
            if cat == "Total":
                row[i].paragraphs[0].runs[0].bold = True
    doc.add_paragraph()
    doc.add_paragraph(
        f"{D.VER}: cumple y lo prueba un caso de prueba o un verificador del plugin. {D.IMP}: implementado sin caso de "
        f"prueba específico. {D.PAR}: se cumple una parte (la evidencia dice qué falta). {D.BLQ}: construido, pero "
        "depende de un insumo del cliente (ver la sección 13 del SPEC v5).")

    colores = {D.VER: "E3F1E6", D.IMP: "E6EEF8", D.PAR: "FFF4D6", D.BLQ: "F8E1E1"}
    for cat in cats:
        sub = [r for r in reqs if r["categoria"] == cat]
        if not sub:
            continue
        doc.add_page_break()
        titulo(cat)
        for r in sub:
            h = doc.add_paragraph()
            run = h.add_run(f"{r['id']}")
            run.bold = True
            run.font.size = Pt(11)
            run.font.color.rgb = RGBColor.from_string(AZUL)
            h.add_run(f"   ·  Patrón EARS: {r['patron']}" + (f"   ·  {r['cambio']} en v5" if r["cambio"] != "Sin cambios" else ""))
            tabla = doc.add_table(rows=0, cols=2)
            tabla.style = "Table Grid"
            filas = [("Fuente", r["fuente"]), ("RU (Usuario)", r["ru"]), ("RS (Sistema)", r["rs"]), ("EARS", r["ears"]),
                     ("Estado", f"{r['estado']} · {r['version']}"), ("Evidencia", r["evidencia"])]
            if r["nota"]:
                filas.append(("Notas", r["nota"]))
            for etiqueta, valor in filas:
                celdas = tabla.add_row().cells
                celdas[0].text = etiqueta
                celdas[0].paragraphs[0].runs[0].bold = True
                celdas[0].width = Cm(3.2)
                celdas[1].text = limpiar(valor)
                celdas[1].width = Cm(13.8)
                sombrear(celdas[0], "EEF2F7")
                if etiqueta == "Estado":
                    sombrear(celdas[1], colores[r["estado"]])
            doc.add_paragraph()
    doc.save(OUT_DOCX)


# ------------------------------------------------------------------ SPEC v5.1
def actualizar_spec(texto, reqs):
    por_id = {r["id"]: r for r in reqs}
    # Una linea de estado despues del encabezado de cada ficha (reemplaza la anterior si ya existe).
    texto = re.sub(r"\n+- \*\*Estado \(M1-v[\d.]+\):\*\* [^\n]*", "", texto)
    texto = re.sub(r"(#### `[A-Z0-9-]+` — patrón EARS: [^\n]*)\n{3,}", r"\1\n\n", texto)

    def insertar(m):
        r = por_id[m.group(2)]
        return (f"{m.group(0)}\n\n- **Estado ({VERSION_PLUGIN}):** {r['estado']} · desde {r['version']} · "
                f"Evidencia: {r['evidencia']}")
    texto = re.sub(r"(#### `([A-Z0-9-]+)` — patrón EARS: \*\*[^*]+\*\*(?: `\[(?:NUEVO|MODIFICADO) v5\]`)?)",
                   insertar, texto)
    # RU antes del RS en cada ficha, para que el SPEC tenga la cadena completa RU -> RS -> EARS por si solo.
    texto = re.sub(r"\n- \*\*RU \(necesidad de usuario\):\*\* [^\n]*", "", texto)
    partes = re.split(r"(?=\n#### `)", texto)
    for i, parte in enumerate(partes):
        m = re.match(r"\n#### `([A-Z0-9-]+)`", parte)
        if m and m.group(1) in por_id:
            partes[i] = parte.replace("\n- **RS (para implementar):**",
                                      f"\n- **RU (necesidad de usuario):** {por_id[m.group(1)]['ru']}"
                                      "\n- **RS (para implementar):**", 1)
    texto = "".join(partes)
    texto = texto.replace(
        "- Cada requisito trae su **ID** (referenciable en commits, código o tests: `// implements RU-TUT-007`).",
        "- Cada requisito trae su **ID** (referenciable en commits, código o tests: `// implements RU-TUT-007`).\n"
        "- Cada requisito trae la cadena completa: **RU** (la necesidad, en lenguaje de usuario) → **RS** (qué debe "
        "hacer el sistema; es lo que se implementa) → **EARS** (la forma verificable) → **Gherkin** (el caso de "
        "prueba). Si hay conflicto, manda el EARS.")
    texto = re.sub(r"(- Cada requisito trae la cadena completa:[^\n]*\n)(?:- Cada requisito trae la cadena completa:[^\n]*\n)+",
                   r"\1", texto)
    texto = texto.replace("# Especificación técnica del asistente de IA — Módulo 1 (v5)",
                          "# Especificación técnica del asistente de IA — Módulo 1 (v5.1)")
    tot = Counter(r["estado"] for r in reqs)
    aviso = (f"> **v5.1 ({FECHA}):** cada requisito indica su **estado de implementación** en el plugin {VERSION_PLUGIN}, "
             f"la versión desde la que se cumple y la evidencia que lo verifica. Resumen: {tot[D.VER]} verificados · "
             f"{tot[D.IMP]} implementados · {tot[D.PAR]} parciales · {tot[D.BLQ]} bloqueados por insumo del cliente. "
             "Ver la sección 15. La matriz Word/PDF/Excel v5 (`Matriz_RU_RS_EARS_Asistente_IA_Modulo1_v5`) contiene lo "
             "mismo más la redacción RU de cada requisito, y se genera con `tools/matriz/generar_matriz_v5.py`.")
    texto = re.sub(r"> v5 \(septiembre 2026\):[^\n]*", aviso, texto, count=1)
    texto = re.sub(r"\n---\n\n## 15\. Estado de implementación.*\Z", "", texto, flags=re.S).rstrip() + "\n"
    filas = "\n".join(f"| {cat} | " + " | ".join(str(Counter(r['estado'] for r in reqs if r['categoria'] == cat)[e])
                                                 for e in (D.VER, D.IMP, D.PAR, D.BLQ))
                      + f" | {sum(1 for r in reqs if r['categoria'] == cat)} |" for cat in CATEGORIAS.values())
    pendientes = "\n".join(f"| `{r['id']}` | {r['estado']} | {limpiar(r['evidencia'])} |"
                           for r in reqs if r["estado"] in (D.PAR, D.BLQ))
    texto += f"""
---

## 15. Estado de implementación ({VERSION_PLUGIN}, {FECHA})

Verificado con la prueba automática del flujo completo (`docs/RESULTADOS_PRUEBAS_AUTOMATICAS.md`) y los verificadores del plugin (`cli/verificar_*.php`, 507 casos y 614 claves de idioma, 0 fallos).

| Categoría | {D.VER} | {D.IMP} | {D.PAR} | {D.BLQ} | Total |
|---|---|---|---|---|---|
{filas}
| **Total** | **{tot[D.VER]}** | **{tot[D.IMP]}** | **{tot[D.PAR]}** | **{tot[D.BLQ]}** | **{len(reqs)}** |

### Requisitos parciales o bloqueados

| Requisito | Estado | Qué falta |
|---|---|---|
{pendientes}

### Decisiones de implementación (desvíos declarados)

- **12 estados de meta (RU-ADAPT-006):** se implementan como **capa derivada** (`pdp::goal_state_v5`) de lo que ya se guarda (decisión de la persona, plan validado, último apoyo y propuestas pendientes), sin migrar datos ni cambiar la lógica de decisión.
- **Subdimensiones D1.1 y D1.2:** el instrumento del plugin trabaja por dimensión (D1). Los criterios de verificación de ambas subdimensiones del Anexo F se aplican juntos sobre D1.
- **Evento CLOSE del Tutor (RU-TRZ-003):** lo avisa el widget con `navigator.sendBeacon` al cerrarse o al salir de la página; el servidor lo registra solo si hubo consultas después del último cierre. OPEN se deriva de la primera consulta después de 30 minutos sin actividad.
- **Catálogo de eventos (RU-TRZ-003/004):** un solo evento de Moodle (`trace_recorded`) con el código del Anexo G, más la tabla `local_chatbot_trace` para los indicadores.
- **Patrones del quiz (RU-TRZ-005):** se escucha `attempt_graded` y no `attempt_submitted`, porque en Moodle 5 el envío y la calificación van separados.
- **Regla P01/P07:** se usa "y/o" (Anexo G) y no "y" (Anexo D), pendiente P-06.
- **Base de conocimiento del quiz (RU-KB-001):** solo lleva el criterio de cada pregunta. Con las alternativas en contexto, el Tutor las parafraseaba y dejaba la correcta por descarte.
- **Datos de terceros (RU-PRIV-008):** RUT, teléfonos y correos se detectan antes de llamar al modelo; los nombres propios dependen del juicio del modelo.
"""
    return texto


# ------------------------------------------------------------------ principal
def main():
    spec = SPEC.read_text(encoding="utf-8")
    reqs = extraer_spec(spec)
    assert len(reqs) == 111, len(reqs)
    v4 = ru_v4()
    for r in reqs:
        r["ru"] = D.RU.get(r["id"]) or v4.get(r["id"]) or ""
        if not r["ru"]:
            raise SystemExit(f"Sin RU: {r['id']}")
        r["estado"], r["version"], r["evidencia"] = D.EST[r["id"]]
    generar_xlsx(reqs)
    generar_docx(reqs)
    SPEC.write_text(actualizar_spec(spec, reqs), encoding="utf-8")
    print(f"OK: {len(reqs)} requisitos · {Counter(r['estado'] for r in reqs)}")
    print(" ", OUT_XLSX)
    print(" ", OUT_DOCX)
    print(" ", SPEC, "(v5.1)")


if __name__ == "__main__":
    main()
