"""Re-prueba focalizada de M1-v1.4.0: feedback de cinco campos (C-01), datos de terceros sin
modelo (B-04) y los 12 estados de meta en el PDP (F-21). Guarda reprueba_v140.json."""
import json
import re
import sys
from html import unescape

sys.argv = [sys.argv[0], "--solo", "ninguno"]
import ejecutar_pruebas as E                        # noqa: E402

E.SSH = E._ssh()
res = []


def r(cid, desc, ok, detalle):
    res.append({"id": cid, "desc": desc, "auto": "✅" if ok else "🔴", "detalle": detalle})
    print(("✅" if ok else "🔴"), cid, desc)


V5 = ["Propuesta", "Activa", "En desarrollo", "Evidencia presentada", "Evidencia revisada por IA",
      "Requiere profundización", "Avance registrado", "En revisión", "Postergada", "Propuesta de cierre",
      "Cerrada", "Rechazada"]
ANTIGUOS = ["Aceptada", "Aceptado", "Modificada", "Modificado", "Completada", "Completado"]

# --- B-04: evidencia con RUT y teléfono ---
b = E.Persona("prueba.b")
est = E.sonda("estado")["personas"]["prueba.b"]
adapt = est["adapts"][-1]
n0 = len(adapt["reviews"])
b.post(f"/local/chatbot/pdp.php?courseid={E.CID}", {"courseid": E.CID, "adaptaction": "submit", "adapt": adapt["id"],
       "evidence": "Hice la simulación con el cliente Juan Pérez, RUT 12.345.678-5, teléfono +56 9 8765 4321, y le fue bien."})
est = E.sonda("estado")["personas"]["prueba.b"]
a = [x for x in est["adapts"] if x["id"] == adapt["id"]][0]
rev = a["reviews"][-1] if len(a["reviews"]) > n0 else None
r("B-04", "Evidencia con RUT y teléfono: rechazada por datos de terceros, sin guardar contenido",
  bool(rev) and rev["rejectreason"] == "datos_de_terceros" and rev["evidencesynthesis"] == "",
  {"revision": rev})

# --- C-01: evidencia completa con feedback v5 ---
c = E.Persona("prueba.c")
est = E.sonda("estado")["personas"]["prueba.c"]
adapt = est["adapts"][-1]
evid = ("Actividad ajustada: 'Movilización segura con incidencia'. Aprendizaje esperado: ejecutar, en simulación "
        "supervisada, la movilización de una persona con movilidad reducida aplicando el protocolo y comunicando "
        "incidencias. Condiciones: grupo de 12, 90 minutos, 2 camas clínicas, maniquí; riesgo de lesión lumbar. "
        "Secuencia: demostración comentada; práctica en parejas con apoyo; estación con incidencia; práctica final "
        "con mayor autonomía. Evidencia: ejecución observada en la estación con incidencia y registro breve de la "
        "decisión tomada. Instrumento: lista de cotejo con 5 criterios.")
html = c.post(f"/local/chatbot/pdp.php?courseid={E.CID}", {"courseid": E.CID, "adaptaction": "submit",
              "adapt": adapt["id"], "evidence": evid}).text
est = E.sonda("estado")["personas"]["prueba.c"]
a = [x for x in est["adapts"] if x["id"] == adapt["id"]][0]
rev = a["reviews"][-1] if a["reviews"] else None
campos = {k: (rev or {}).get(k) for k in ("strength", "aspecttodeepen", "whyitmatters", "updateproposal", "nextaction")}
r("C-01", "Feedback de cinco campos guardado en columnas propias",
  bool(rev) and all(campos[k] for k in ("strength", "aspecttodeepen", "whyitmatters", "updateproposal", "nextaction")),
  {"estado_apoyo": a["estado"], "campos": campos, "propuesta": (rev or {}).get("proposal")})
texto = unescape(re.sub(r"<[^>]+>", " ", html))
bloques = ["Fortaleza observada", "Aspecto a profundizar", "Por qué importa", "Siguiente acción sugerida",
           "Propuesta de actualización de tu meta"]
r("C-01b", "La respuesta mostrada a la persona trae los cinco campos v5",
  all(bq in texto for bq in bloques), {"presentes": [bq for bq in bloques if bq in texto]})

# --- F-21: estados v5 en el PDP ---
for persona in (b, c):
    txt = unescape(re.sub(r"<[^>]+>", " ", persona.get("/local/chatbot/pdp.php", courseid=E.CID).text))
    badges = re.findall(r'badge text-bg-secondary">\s*([^<]+?)\s*<',
                        persona.get("/local/chatbot/pdp.php", courseid=E.CID).text)
    r("F-21", f"{persona.u}: estados de meta con el vocabulario v5",
      bool(badges) and all(unescape(x) in V5 for x in badges), {"insignias": [unescape(x) for x in badges]})

for persona in (b, c):
    for page in ["pdp.php", "proyecto.php", "cierre.php", "index.php", "diagnostico.php"]:
        resp = persona.get(f"/local/chatbot/{page}", courseid=E.CID)
        malos = [x for x in E.ERRORES_PHP if x in resp.text]
        r("S", f"{persona.u} GET {page}", resp.status_code == 200 and not malos, {"status": resp.status_code, "avisos": malos})

json.dump(res, open(E.AQUI / "reprueba_v140.json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
E.SSH.close()
