/**
 * Aviso de espera para las acciones que llaman al modelo.
 *
 * Preparar la matriz, pedir metas al Coach, proponer un apoyo, revisar una
 * evidencia o evaluar el proyecto tardan varios segundos. Sin aviso, la pagina
 * parece colgada y la persona vuelve a pulsar, lo que dispara la accion dos
 * veces.
 *
 * Cualquier boton o enlace con data-espera="texto" queda cubierto: al pulsarlo
 * se deshabilita, muestra el texto con un indicador y, si tarda, agrega una
 * linea que explica que sigue trabajando.
 *
 * @module local_chatbot/espera
 */
(function() {
    'use strict';

    var PACIENCIA = 8000;

    /**
     * Marca el elemento como en curso y evita el doble envio.
     *
     * @param {HTMLElement} elemento Boton o enlace pulsado.
     * @returns {void}
     */
    function ocupar(elemento) {
        if (elemento.dataset.esperando === '1') {
            return;
        }
        elemento.dataset.esperando = '1';

        var mensaje = elemento.getAttribute('data-espera') || '';
        var paciencia = elemento.getAttribute('data-espera-larga') || '';

        elemento.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"'
            + ' aria-hidden="true"></span>' + mensaje;
        elemento.classList.add('disabled');
        elemento.setAttribute('aria-busy', 'true');
        if (elemento.tagName === 'BUTTON') {
            // El boton se deshabilita despues de enviar, para no perder su valor.
            window.setTimeout(function() {
                elemento.disabled = true;
            }, 0);
        }

        // El aviso va en la pagina, no dentro del boton: cabe sin apretarlo.
        if (paciencia) {
            window.setTimeout(function() {
                if (document.getElementById('local-chatbot-paciencia')) {
                    return;
                }
                var nota = document.createElement('p');
                nota.id = 'local-chatbot-paciencia';
                nota.className = 'small text-muted mt-2 mb-0';
                nota.setAttribute('role', 'status');
                nota.textContent = paciencia;
                elemento.parentNode.insertBefore(nota, elemento.nextSibling);
            }, PACIENCIA);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-espera]').forEach(function(elemento) {
            var formulario = elemento.closest('form');
            if (formulario && elemento.tagName === 'BUTTON') {
                formulario.addEventListener('submit', function() {
                    ocupar(elemento);
                });
                return;
            }
            elemento.addEventListener('click', function() {
                ocupar(elemento);
            });
        });
    });
})();
