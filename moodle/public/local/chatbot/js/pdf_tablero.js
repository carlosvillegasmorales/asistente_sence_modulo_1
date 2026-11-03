/**
 * Descarga del tablero del Modulo 2 como PDF, sin dialogo de impresion (boton #m2d-pdf, interruptor pdfm2).
 *
 * Dibuja el tablero con html2canvas y arma un PDF A4 vertical con jsPDF; el archivo se descarga solo. Las paginas se
 * cortan entre tarjetas, no por la mitad de una. Si algo falla (libreria que no carga, navegador antiguo), abre la
 * impresion del navegador, con el mismo estilo @media print de styles.css.
 */
(function() {
    'use strict';

    var boton = document.getElementById('m2d-pdf');
    if (!boton) {
        return;
    }
    var textoOriginal = boton.textContent;

    function cargar(url) {
        return new Promise(function(resolve, reject) {
            var s = document.createElement('script');
            s.src = url;
            s.onload = resolve;
            s.onerror = function() {
                reject(new Error('No cargo ' + url));
            };
            document.head.appendChild(s);
        });
    }

    /**
     * Alturas (en px del tablero) donde se puede cortar una pagina: justo antes de cada tarjeta, KPI o titulo de seccion.
     */
    function cortes(raiz) {
        var base = raiz.getBoundingClientRect().top;
        var lista = [];
        raiz.querySelectorAll('.m1-dash > *, .m1-kpis > *, .m1-grid > *, .m1-card').forEach(function(el) {
            lista.push(Math.round(el.getBoundingClientRect().top - base));
        });
        return lista;
    }

    /** Siguiente altura de corte: la ultima posible antes de `limite`, pero nunca antes de `minimo`. */
    function elegirCorte(candidatos, inicio, limite, minimo) {
        var mejor = limite;
        var encontrado = false;
        candidatos.forEach(function(c) {
            if (c > inicio + minimo && c <= limite && (!encontrado || c > mejor)) {
                mejor = c;
                encontrado = true;
            }
        });
        return mejor;
    }

    async function descargar() {
        var raiz = document.querySelector('.m1-dash');
        if (!raiz) {
            throw new Error('Sin tablero');
        }
        // Moodle trae RequireJS: sin quitar `define` un instante, las librerias se registran como modulo AMD anonimo
        // en vez de quedar en window (html2canvas, jspdf).
        var defineMoodle = window.define;
        try {
            window.define = undefined;
            if (typeof window.html2canvas !== 'function') {
                await cargar(boton.getAttribute('data-html2canvas'));
            }
            if (!window.jspdf || !window.jspdf.jsPDF) {
                await cargar(boton.getAttribute('data-jspdf'));
            }
        } finally {
            window.define = defineMoodle;
        }
        document.body.classList.add('m2d-capture');
        var lienzo;
        var puntos;
        try {
            // Un cuadro de pintura del navegador para que el estilo de captura (sin botones ni pestañas) ya se aplique.
            await new Promise(function(r) {
                requestAnimationFrame(function() {
                    requestAnimationFrame(r);
                });
            });
            var escala = 2;
            // Los puntos de corte se miden antes de dibujar, con el mismo estilo y el mismo ancho que el dibujo.
            puntos = cortes(raiz).map(function(y) {
                return y * escala;
            });
            lienzo = await window.html2canvas(raiz, {scale: escala, backgroundColor: '#ffffff', useCORS: true, logging: false});
        } finally {
            document.body.classList.remove('m2d-capture');
        }

        var jsPDF = window.jspdf.jsPDF;
        var pdf = new jsPDF({orientation: 'portrait', unit: 'mm', format: 'a4'});
        var margen = 8;
        var anchoPag = pdf.internal.pageSize.getWidth() - 2 * margen;
        var altoPag = pdf.internal.pageSize.getHeight() - 2 * margen;
        // Alto de una pagina medido en pixeles del lienzo.
        var altoPx = Math.floor(lienzo.width * altoPag / anchoPag);
        var inicio = 0;
        var primera = true;
        while (inicio < lienzo.height - 2) {
            var fin = Math.min(inicio + altoPx, lienzo.height);
            if (fin < lienzo.height) {
                fin = elegirCorte(puntos, inicio, fin, altoPx * 0.2);
            }
            var alto = fin - inicio;
            var trozo = document.createElement('canvas');
            trozo.width = lienzo.width;
            trozo.height = alto;
            trozo.getContext('2d').drawImage(lienzo, 0, inicio, lienzo.width, alto, 0, 0, lienzo.width, alto);
            if (!primera) {
                pdf.addPage();
            }
            pdf.addImage(trozo.toDataURL('image/jpeg', 0.92), 'JPEG', margen, margen, anchoPag, alto * anchoPag / lienzo.width);
            primera = false;
            inicio = fin;
        }
        pdf.save((boton.getAttribute('data-name') || 'Tablero_Modulo2') + '.pdf');
    }

    boton.addEventListener('click', function() {
        if (boton.disabled) {
            return;
        }
        boton.disabled = true;
        boton.textContent = boton.getAttribute('data-wait') || textoOriginal;
        descargar().catch(function(e) {
            if (window.console) {
                window.console.warn('PDF del tablero: ' + e.message + '. Se abre la impresion del navegador.');
            }
            var titulo = document.title;
            document.title = boton.getAttribute('data-name') || titulo;
            window.print();
            document.title = titulo;
        }).then(function() {
            boton.disabled = false;
            boton.textContent = textoOriginal;
        });
    });
})();
