"""Construye paquete_u1.json a partir de los documentos de Interfases.

Lee la carpeta `Documentos Cliente/` y genera todo lo que el cargador PHP necesita para
montar el curso de prueba de la Unidad 1:

  - paginas:     HTML de los anexos A, B y C para Moodle (solo contenido para la persona
                 facilitadora: se corta antes de "Configuracion sugerida en Moodle").
  - kb:          los mismos recursos en texto, segmentados por apartado con su codigo
                 (RU-TUT-009), el Anexo D SANEADO sin claves (RU-KB-001), y las fuentes
                 prioritarias externas autorizadas de RU-TUT-005: Manual EDECOMP (Fuente 7),
                 Guía de Planes Formativos SENCE-ChileValora (Fuente 8) y MCTP (Fuente 9).
  - quiz:        las 10 preguntas del Anexo D con clave y retroalimentacion por alternativa,
                 que solo viajan al banco de preguntas de Moodle.
  - diagnostico: el instrumento real del Doc 2 (D1 a D4, 32 declarativos, S1 a S6).

Uso:  python construir_paquete.py  [ruta_salida]
Requiere: pip install mammoth pypdf
"""
import json
import re
import sys
import zipfile
from html import unescape
from pathlib import Path

import mammoth
import pypdf

REPO = Path(__file__).resolve().parents[2]
DOCS = REPO / "Documentos Cliente"
U1 = DOCS / "6. Unidad 1, Ruta Formativa - Interfases"
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).with_name("paquete_u1.json")


def docx_text_lines(path):
    """Parrafos del documento como lineas de texto, sin campos de indice."""
    xml = zipfile.ZipFile(path).read("word/document.xml").decode("utf8")
    xml = re.sub(r"</w:p>", "\n", xml)
    text = unescape(re.sub(r"<[^>]+>", "", xml))
    return [l.strip() for l in text.split("\n") if l.strip() and "PAGEREF" not in l]


def recurso_html(path, cortar_en=("Configuración sugerida en Moodle",)):
    html = mammoth.convert_to_html(path.open("rb")).value
    html = re.sub(r"<img[^>]*>", "", html)
    start = html.find("<h1>")
    end = len(html)
    for marca in cortar_en:
        i = html.find(f"<h1>{marca}")
        if i != -1:
            end = min(end, i)
    return html[start:end]


def html_a_texto_kb(html, codigo):
    """Texto plano con cada titulo marcado con el codigo del recurso, para que el Tutor
    pueda citar 'Revisa el apartado 3 del Documento de Lectura'."""
    t = html
    t = re.sub(r"<h1>(.*?)</h1>", lambda m: f"\n\n## [{codigo}] {m.group(1)}\n", t)
    t = re.sub(r"<h2>(.*?)</h2>", lambda m: f"\n### [{codigo}] {m.group(1)}\n", t)
    t = re.sub(r"<h3>(.*?)</h3>", lambda m: f"\n#### {m.group(1)}\n", t)
    t = re.sub(r"</(p|li|tr)>", "\n", t)
    t = re.sub(r"</t[dh]>", " | ", t)
    t = re.sub(r"<li>", "- ", t)
    t = unescape(re.sub(r"<[^>]+>", "", t))
    t = re.sub(r"[ \t]+", " ", t)
    t = re.sub(r"\n\s*\n\s*\n+", "\n\n", t)
    return t.strip()


def parsear_quiz(lines):
    preguntas = []
    i = 0
    while i < len(lines):
        m = re.match(r"^Pregunta (\d+)\. (.+)$", lines[i])
        if not m:
            i += 1
            continue
        num, titulo = int(m.group(1)), m.group(2)
        meta = lines[i + 1]
        codigo = re.search(r"Código: (U1-QF-01-P\d+)", meta).group(1)
        criterio = re.search(r"Criterio principal: ([^·]+)", meta).group(1).strip()
        dimension = re.search(r"Dimensión diagnóstica: ([^·]+)", meta).group(1).strip()
        j = i + 2
        bloque = []
        while not lines[j].startswith("SOLO CONFIGURACIÓN MOODLE"):
            bloque.append(lines[j])
            j += 1
        correcta = re.search(r"Alternativa correcta: ([A-D])", lines[j]).group(1)
        opciones = bloque[-4:]
        enunciado = "\n".join(bloque[:-4])
        # Alternativa / Retroalimentacion / A / texto / B / texto ...
        k = j + 1
        assert lines[k] == "Alternativa" and lines[k + 1] == "Retroalimentación", (num, lines[k:k + 2])
        k += 2
        feedback = {}
        for letra in "ABCD":
            assert lines[k] == letra, (num, letra, lines[k])
            feedback[letra] = lines[k + 1]
            k += 2
        preguntas.append({
            "numero": num, "codigo": codigo, "titulo": titulo, "criterio": criterio,
            "dimension": dimension, "enunciado": enunciado, "opciones": opciones,
            "correcta": "ABCD".index(correcta),
            "retroalimentacion": [feedback[l] for l in "ABCD"],
        })
        i = k
    assert len(preguntas) == 10, f"se esperaban 10 preguntas, hay {len(preguntas)}"
    return preguntas


def quiz_kb_saneado(preguntas, lines):
    """Anexo D para la base de conocimiento: SIN clave y SIN retroalimentacion por
    alternativa (la retroalimentacion delata la correcta). Solo lo que la persona ve en
    el enunciado mas el criterio que trabaja cada pregunta."""
    # Tampoco van las alternativas: en la prueba automática del 21-09-2026, con las alternativas
    # en contexto el Tutor no dijo la letra, pero las parafraseó y dejó la correcta como la única
    # que sobrevivía a sus preguntas (CP-IA-T-07/08). Sin alternativas solo puede trabajar el criterio.
    out = ["## [U1-QF-01] Quiz formativo de alta discriminación de la Unidad 1 (criterios por pregunta)",
           "Este documento describe QUÉ criterio trabaja cada pregunta. No contiene las alternativas, las claves"
           " ni la retroalimentación por alternativa. No intentes reconstruir ni evaluar alternativas: orienta"
           " con el criterio y remite a la lectura, el caso y el ejemplo (RU-TUT-007).", ""]
    for p in preguntas:
        out.append(f"### [{p['codigo']}] Pregunta {p['numero']}. {p['titulo']}")
        out.append(f"Criterio que trabaja: {p['criterio']} · Dimensión: {p['dimension']}")
        out.append("")
    texto = "\n".join(out)
    assert "Alternativa correcta" not in texto and "Correcta." not in texto
    return texto


def parsear_diagnostico(lines):
    dims = {
        "D1": {"name": "D1. Fundamentos y diseño de actividades con enfoque de competencias", "items": [], "sit": []},
        "D2": {"name": "D2. Estrategias didácticas para el aprendizaje activo", "items": [], "sit": []},
        "D3": {"name": "D3. Evaluación de aprendizajes con enfoque de competencias", "items": [], "sit": []},
        "D4": {"name": "D4. Reflexión sobre la práctica y desarrollo profesional", "items": [], "sit": []},
    }
    for idx, l in enumerate(lines):
        m = re.match(r"^(D[1-4])\.(\d+)$", l)
        if m and idx + 1 < len(lines) and len(lines[idx + 1]) > 40:
            codigo = l
            if codigo not in [x["codigo"] for x in dims[m.group(1)]["items"]]:
                dims[m.group(1)]["items"].append({"codigo": codigo, "enunciado": lines[idx + 1]})
    sit_dim = {"S1": "D1", "S2": "D1", "S3": "D2", "S4": "D3", "S5": "D3", "S6": "D4"}
    for idx, l in enumerate(lines):
        m = re.match(r"^(S[1-6])\. (.+)$", l)
        if not m or lines[idx + 1] != "Situación":
            continue
        enunciado = lines[idx + 2]
        opciones = [re.sub(r"^[A-D]\.\s*", "", lines[idx + 3 + n]) for n in range(4)]
        clave = re.search(r"Clave: ([A-D])", lines[idx + 7]).group(1)
        dims[sit_dim[m.group(1)]]["sit"].append({
            "codigo": m.group(1), "titulo": m.group(2), "enunciado": enunciado,
            "opciones": opciones, "correcta": "ABCD".index(clave)})
    esperado = {"D1": 8, "D2": 6, "D3": 11, "D4": 7}
    for d, n in esperado.items():
        assert len(dims[d]["items"]) == n, (d, len(dims[d]["items"]))
    assert sum(len(d["sit"]) for d in dims.values()) == 6
    return dims


# Criterios formativos de verificacion (RU-COA-013). D1 viene del Anexo F (D1.1 + D1.2);
# D2 a D4 del Doc 2 §8, mientras no lleguen los anexos de las Unidades 2 a 4.
CRITERIOS = {
    "D1": [
        "D1.1 Distingue el desempeño de referencia del aprendizaje esperado de la actividad",
        "D1.1 Expresa una capacidad observable y adecuada al nivel de entrada",
        "D1.1 Identifica condiciones relevantes y no meramente decorativas",
        "D1.1 Propone una evidencia vinculada con la capacidad que se busca observar",
        "D1.1 Distingue la evidencia del instrumento usado para recogerla o valorarla",
        "D1.1 Explica relaciones entre componentes y evita equivalencias mecánicas",
        "D1.2 Se relaciona con un aprendizaje esperado o capacidad observable",
        "D1.2 Presenta una situación auténtica, simulada o contextualizada pertinente al sector o servicio",
        "D1.2 Considera condiciones de participantes, recursos, tiempo, modalidad, seguridad, riesgos o restricciones",
        "D1.2 Solicita una acción de análisis, decisión, ejecución, producción o justificación",
        "D1.2 Define una evidencia de avance coherente con la capacidad trabajada",
        "D1.2 Incorpora progresión entre apoyo inicial y una aplicación con mayor autonomía o complejidad",
        "D1.2 Es viable dentro de las condiciones descritas",
        "D1.2 Distingue el diseño inicial de las decisiones que se profundizarán en estrategias y evaluación",
    ],
    "D2": [
        "Selecciona una estrategia y justifica su pertinencia para un aprendizaje esperado",
        "Considera las características de las personas participantes y las condiciones reales del grupo",
        "Presenta una matriz de decisión metodológica coherente",
    ],
    "D3": [
        "Alinea aprendizaje esperado, evidencia, criterio e instrumento en una matriz simple",
        "Distingue evidencia de instrumento",
        "Reformula una retroalimentación de modo específico y accionable y define un ajuste de facilitación",
    ],
    "D4": [
        "Registra una evidencia de su práctica y la analiza",
        "Formula una meta de desarrollo con acciones y evidencia de avance verificables",
        "Relaciona la reflexión con decisiones concretas de mejora",
    ],
}


def extraer_edecomp(pdf_path):
    """Extrae el referente metodológico del Manual EDECOMP (Fuente 7 en RU-TUT-005).
    Prioriza el Módulo Cero (ejes de planeación, diseño curricular basado en competencias,
    traducción formativa, ciclo de aprendizaje) y las síntesis/criterios clave de didáctica y evaluación."""
    reader = pypdf.PdfReader(pdf_path)
    partes = [
        "## [Manual EDECOMP] Manual de Estrategias Didácticas y de Evaluación para el Desarrollo de Competencias",
        "Referente metodológico oficial SENCE - CChC - Interfases Consultores (Fuente 7 en RU-TUT-005).",
        "",
        "### [Manual EDECOMP] Módulo Cero: Ejes para la planeación de la formación basada en competencias"
    ]

    def limpiar_texto(t):
        lines = []
        for l in t.split("\n"):
            l = l.strip()
            if not l:
                continue
            if re.match(r"^\d+\s+Manual de estrategias", l):
                continue
            if re.match(r"^\d+\s+MÓDULO\s+\d+", l):
                continue
            if re.match(r"^MÓDULO\s+\d+\s+Estrategias", l):
                continue
            lines.append(l)
        return "\n".join(lines)

    # Introducción y Módulo Cero (páginas 5 a 53)
    for p in range(4, 53):
        t = limpiar_texto(reader.pages[p].extract_text() or "")
        if t:
            partes.append(t)

    # Módulo Uno: Criterios de técnicas didácticas y síntesis (pp. 56-72 y 97)
    partes.append("\n### [Manual EDECOMP] Módulo Uno: Estrategias didácticas centradas en el aprendizaje (Criterios y Síntesis)")
    for p in range(55, min(72, len(reader.pages))):
        t = limpiar_texto(reader.pages[p].extract_text() or "")
        if t:
            partes.append(t)
    if len(reader.pages) > 96:
        partes.append(limpiar_texto(reader.pages[96].extract_text() or ""))

    # Módulo Dos: Evaluación y realimentación (pp. 100-117 y 141)
    partes.append("\n### [Manual EDECOMP] Módulo Dos: Estrategias de evaluación y realimentación (Fundamentos y Síntesis)")
    for p in range(99, min(117, len(reader.pages))):
        t = limpiar_texto(reader.pages[p].extract_text() or "")
        if t:
            partes.append(t)
    if len(reader.pages) > 140:
        partes.append(limpiar_texto(reader.pages[140].extract_text() or ""))

    res = "\n\n".join(partes)
    res = re.sub(r"[ \t]+", " ", res)
    res = re.sub(r"\n\s*\n\s*\n+", "\n\n", res)
    return res.strip()


def extraer_guia_planes(pdf_path):
    """Extrae la Guía de Elaboración de Planes Formativos SENCE-ChileValora (Fuente 8 en RU-TUT-005).
    Contiene la metodología de traducción de perfiles ocupacionales y competencias en módulos formativos."""
    reader = pypdf.PdfReader(pdf_path)
    partes = [
        "## [Guía Planes Formativos] Guía de Elaboración de Planes Formativos SENCE–ChileValora",
        "Metodología oficial de traducción de perfiles ocupacionales y competencias en módulos formativos (Fuente 8 en RU-TUT-005).",
        ""
    ]
    for p in reader.pages:
        t = p.extract_text() or ""
        lines = [l.strip() for l in t.split("\n") if l.strip() and not re.match(r"^\d+$", l.strip())]
        if lines:
            partes.append("\n".join(lines))
    res = "\n\n".join(partes)
    res = re.sub(r"[ \t]+", " ", res)
    res = re.sub(r"\n\s*\n\s*\n+", "\n\n", res)
    return res.strip()


def extraer_mctp(pdf_path):
    """Extrae el Marco de Cualificaciones Técnico-Profesional (Fuente 9 en RU-TUT-005).
    Referente sectorial y formativo del Ministerio de Educación y CORFO."""
    reader = pypdf.PdfReader(pdf_path)
    partes = [
        "## [MCTP] Marco de Cualificaciones Técnico-Profesional",
        "Referente sectorial y formativo del Ministerio de Educación y CORFO (Fuente 9 en RU-TUT-005).",
        ""
    ]
    for p in reader.pages:
        t = p.extract_text() or ""
        lines = [l.strip() for l in t.split("\n") if l.strip() and "MARCO DE CUALIFICACIONES TÉCNICO-PROFESIONAL" not in l and not re.match(r"^\d+\s*/", l) and not re.match(r"^/\s*\d+", l)]
        if lines:
            partes.append("\n".join(lines))
    res = "\n\n".join(partes)
    res = re.sub(r"[ \t]+", " ", res)
    res = re.sub(r"\n\s*\n\s*\n+", "\n\n", res)
    return res.strip()


def main():
    a = next(U1.glob("6.1.*.docx"))
    b = next(U1.glob("6.2.*.docx"))
    c = next(U1.glob("6.3.*.docx"))
    d = next(U1.glob("6.4.*.docx"))
    diag = next(DOCS.glob("2. *.docx"))

    paginas = {
        "U1-LF-01": {"nombre": "Lectura: Formación con enfoque en competencias y diseño de actividades de aprendizaje",
                     "html": recurso_html(a)},
        "U1-CA-01": {"nombre": "Caso de análisis: inicio seguro y coordinado de una operación de preparación de pedidos",
                     "html": recurso_html(b)},
        "U1-ER-01": {"nombre": "Ejemplo de resolución comentado: inicio seguro y coordinado de una operación de preparación de pedidos",
                     "html": recurso_html(c)},
    }
    preguntas = parsear_quiz(docx_text_lines(d))
    kb = [
        {"filename": "U1-LF-01 Documento de Lectura Unidad 1", "content": html_a_texto_kb(paginas["U1-LF-01"]["html"], "U1-LF-01")},
        {"filename": "U1-CA-01 Caso de Análisis Unidad 1", "content": html_a_texto_kb(paginas["U1-CA-01"]["html"], "U1-CA-01")},
        {"filename": "U1-ER-01 Ejemplo de Resolución Comentado Unidad 1", "content": html_a_texto_kb(paginas["U1-ER-01"]["html"], "U1-ER-01")},
        {"filename": "U1-QF-01 Quiz Formativo Unidad 1", "content": quiz_kb_saneado(preguntas, None)},
    ]

    # Fuentes autorizadas de referencia externa (RU-TUT-005: Fuentes 7, 8 y 9).
    edecomp_file = next(U1.glob("*EDEComp*.pdf"), None)
    if edecomp_file:
        kb.append({"filename": "Manual EDECOMP - Fundamentos y Estrategias por Competencias",
                   "content": extraer_edecomp(edecomp_file)})

    guia_file = next(U1.glob("*Planes_Formativos*.pdf"), None)
    if guia_file:
        kb.append({"filename": "Guía SENCE-ChileValora - Elaboración de Planes Formativos",
                   "content": extraer_guia_planes(guia_file)})

    mctp_file = next(U1.glob("*Marco-de-Cualificaciones*.pdf"), None)
    if mctp_file:
        kb.append({"filename": "Marco de Cualificaciones TP (MCTP) - Ministerio de Educación",
                   "content": extraer_mctp(mctp_file)})
    dims = parsear_diagnostico(docx_text_lines(diag))
    for code, dim in dims.items():
        dim["criterios"] = CRITERIOS[code]

    paquete = {"paginas": paginas, "kb": kb, "quiz": preguntas, "diagnostico": dims}
    OUT.write_text(json.dumps(paquete, ensure_ascii=False, indent=1), encoding="utf-8")

    print(f"OK -> {OUT}")
    for k, p in paginas.items():
        print(f"  página {k}: {len(p['html'])} car.")
    for doc in kb:
        print(f"  kb {doc['filename']}: {len(doc['content'])} car.")
    print(f"  quiz: {len(preguntas)} preguntas, claves {''.join('ABCD'[p['correcta']] for p in preguntas)}")
    for code, dim in dims.items():
        print(f"  {code}: {len(dim['items'])} declarativos, situacionales {[s['codigo'] for s in dim['sit']]}")


if __name__ == "__main__":
    main()
