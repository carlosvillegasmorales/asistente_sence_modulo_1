/**
 * Widget del asistente del Modulo 1.
 *
 * Un solo asistente con tres modos funcionales explicitos. Dos decisiones de
 * este archivo vienen directamente de las reglas de gobernanza del modulo:
 *
 * - La persona siempre debe saber en que modo esta y que informacion usa el
 *   asistente, asi que cada modo muestra un banner propio y cada respuesta
 *   lista debajo las fuentes de contexto que se emplearon.
 * - Toda recomendacion relevante debe poder aceptarse, modificarse o
 *   rechazarse, asi que las respuestas de los modos coach y evaluador traen
 *   sus tres botones de decision y esa decision se registra.
 *
 * Cambiar de modo abre el hilo de ese modo: no se mezcla una sesion de
 * tutoria con una de evaluacion.
 */
(function() {
    var initChatbot = function() {
        const toggleBtn = document.getElementById('chatbot-toggle-btn');
        const closeBtn = document.getElementById('chatbot-close-btn');
        const fullscreenBtn = document.getElementById('chatbot-fullscreen-btn');
        const clearBtn = document.getElementById('chatbot-clear-history-btn');
        const windowEl = document.getElementById('chatbot-window');
        const overlayEl = document.getElementById('chatbot-overlay');
        const inputField = document.getElementById('chatbot-input-field');
        const sendBtn = document.getElementById('chatbot-send-btn');
        const messagesContainer = document.getElementById('chatbot-messages');
        const modeSelector = document.getElementById('chatbot-mode-selector');
        const modeBanner = document.getElementById('chatbot-mode-banner');
        const fileInput = document.getElementById('chatbot-file-upload');
        const filePreview = document.getElementById('chatbot-attachment-preview');
        const fileName = document.getElementById('chatbot-attachment-name');
        const fileRemove = document.getElementById('chatbot-attachment-remove');

        // Elementos del selector y drawer de perfiles/roles de acompanamiento.
        const roleBtn = document.getElementById('chatbot-role-btn');
        const roleBadge = document.getElementById('chatbot-role-btn-badge');
        const roleName = document.getElementById('chatbot-role-btn-name');
        const roleDrawer = document.getElementById('chatbot-role-drawer');
        const roleDrawerClose = document.getElementById('chatbot-role-drawer-close');
        const roleCards = document.querySelectorAll('.chatbot-role-card');

        // Elementos de la ruta del modulo y pildoras de accion rapida.
        const journeyTracker = document.getElementById('chatbot-journey-tracker');
        const journeyToggle = document.getElementById('chatbot-journey-toggle');
        const quickChips = document.querySelectorAll('.chatbot-chip');

        if (!toggleBtn || !windowEl || !window.chatbotConfig) {
            return;
        }

        const cfg = window.chatbotConfig;
        const str = cfg.strings || {};
        const profiles = cfg.profiles || {};

        function openChat() {
            windowEl.classList.add('is-open');
            toggleBtn.classList.add('is-active');
            if (inputField && !inputField.disabled) {
                inputField.focus();
            }
        }

        // RU-TRZ-003: al cerrar el widget o salir de la pagina se avisa al servidor para que
        // registre el cierre del Tutor. sendBeacon sobrevive a la descarga de la pagina; el
        // servidor decide si hubo consultas que cerrar, asi que avisar de mas no duplica.
        function notifyClose() {
            if (!navigator.sendBeacon || !window.chatbotConfig || currentMode() !== 'tutor') {
                return;
            }
            var data = new FormData();
            data.append('sesskey', window.chatbotConfig.sesskey);
            data.append('courseid', window.chatbotConfig.courseid || 0);
            data.append('cmid', window.chatbotConfig.cmid || 0);
            try {
                navigator.sendBeacon(window.chatbotConfig.wwwroot + '/local/chatbot/ajax_close.php', data);
            } catch (e) {
                // La trazabilidad nunca debe molestar a la persona.
            }
        }

        function closeChat() {
            var wasOpen = windowEl.classList.contains('is-open');
            if (wasOpen) {
                notifyClose();
            }
            windowEl.classList.remove('is-open');
            windowEl.classList.remove('chatbot-window-fullscreen');
            toggleBtn.classList.remove('is-active');
            if (overlayEl) {
                overlayEl.classList.remove('active');
            }
        }

        var lastToggleTime = 0;
        function toggleChat() {
            var now = Date.now();
            if (now - lastToggleTime < 300) {
                return;
            }
            lastToggleTime = now;
            if (!windowEl) return;
            if (windowEl.classList.contains('is-open')) {
                closeChat();
            } else {
                openChat();
            }
        }

        window.addEventListener('pagehide', function() {
            if (windowEl.classList.contains('is-open')) {
                notifyClose();
            }
        });

        window.chatbotToggle = toggleChat;
        window.chatbotOpen = openChat;
        window.chatbotClose = closeChat;

        // Hilo de una meta abierto ({id, label}) o null para el chat general.
        var activeGoal = null;
        // Ultima carga de historial en curso: los envios esperan a que termine para que la
        // carga no borre el mensaje recien enviado.
        var historyLoad = Promise.resolve(0);

        var activeMode = (cfg.initialMode && profiles[cfg.initialMode])
            ? cfg.initialMode
            : (modeSelector ? modeSelector.value : 'tutor');

        function currentMode() {
            return activeMode;
        }

        function updateModeClass() {
            if (!windowEl) return;
            windowEl.classList.remove('chatbot-mode-tutor', 'chatbot-mode-coach', 'chatbot-mode-evaluador');
            windowEl.classList.add('chatbot-mode-' + activeMode);
        }

        function renderBanner() {
            if (!modeBanner) {
                return;
            }
            const mode = currentMode();
            if (profiles[mode] && profiles[mode].banner) {
                modeBanner.textContent = profiles[mode].banner;
            } else {
                modeBanner.textContent = str['banner_' + mode] || '';
            }
        }

        function setMode(newMode) {
            if (!profiles[newMode]) {
                return;
            }
            // Chat solo Tutor (ajustes del cliente del 08-10-2026): ningun boton cambia de modo.
            if (cfg.ux && cfg.ux.tutorOnly && newMode !== 'tutor') {
                return;
            }
            if (activeMode === newMode) {
                if (roleDrawer) {
                    roleDrawer.classList.remove('is-open');
                }
                return;
            }
            activeMode = newMode;
            // El hilo de una meta es una conversacion con el Coach: otro modo vuelve al chat general.
            if (activeGoal && newMode !== 'coach') {
                activeGoal = null;
                renderThreadBar();
            }
            if (modeSelector) {
                modeSelector.value = newMode;
            }

            const p = profiles[newMode];
            if (roleBadge) {
                roleBadge.textContent = p.letter || 'A';
            }
            if (roleName) {
                // En la cabecera va el nombre corto: el completo no entra a 440px
                // sin recortarse, y ya esta a la vista en el banner de abajo y en
                // las tarjetas del desplegable.
                roleName.textContent = p.short || p.name || '';
            }

            // Actualizar clase activa en las tarjetas del drawer.
            roleCards.forEach(function(card) {
                if (card.getAttribute('data-mode') === newMode) {
                    card.classList.add('is-active');
                } else {
                    card.classList.remove('is-active');
                }
            });

            if (roleDrawer) {
                roleDrawer.classList.remove('is-open');
            }

            updateModeClass();
            renderBanner();
            loadHistory();
            if (inputField) {
                inputField.focus();
            }
        }

        function clearTranscript() {
            messagesContainer.innerHTML = '';
        }

        function getWelcomeMessage(mode) {
            let welcome = str['welcome_' + mode] || '';
            // El servidor arma la nota segun la pantalla abierta: dice donde esta la persona
            // y, aparte, lo que sigue.
            if (cfg.journey && cfg.journey.welcome) {
                welcome += cfg.journey.welcome;
            }
            // Si el widget eligio el modo por la pantalla, lo dice: cambiar de modo es cambiar
            // de conversacion, y sin aviso la persona no entiende por que no ve lo anterior.
            if (cfg.modeAuto && mode === cfg.initialMode && str['mode_auto_' + mode]) {
                welcome += '\n\n' + str['mode_auto_' + mode];
            }
            return welcome;
        }

        /**
         * Franja que indica que la conversacion es la de una meta, con la salida al chat general.
         */
        function renderThreadBar() {
            let bar = document.getElementById('chatbot-thread-bar');
            if (!activeGoal) {
                if (bar) {
                    bar.remove();
                }
                return;
            }
            if (!bar) {
                bar = document.createElement('div');
                bar.id = 'chatbot-thread-bar';
                bar.className = 'chatbot-thread-bar';
                messagesContainer.parentNode.insertBefore(bar, messagesContainer);
            }
            bar.innerHTML = '';
            const etiqueta = document.createElement('span');
            etiqueta.textContent = '🎯 ' + (str.thread_label || '').replace('{meta}', activeGoal.label);
            const volver = document.createElement('button');
            volver.type = 'button';
            volver.className = 'chatbot-thread-back';
            volver.textContent = str.thread_back || '';
            volver.addEventListener('click', function() {
                activeGoal = null;
                renderThreadBar();
                loadHistory();
            });
            bar.appendChild(etiqueta);
            bar.appendChild(volver);
        }

        /**
         * Carga el historial del modo (y del hilo de meta, si hay uno abierto). Devuelve una
         * promesa con la cantidad de mensajes, para saber si el hilo es nuevo.
         */
        function loadHistory() {
            if (!cfg.hasConsent) {
                return Promise.resolve(0);
            }
            const mode = currentMode();
            const formData = new FormData();
            formData.append('sesskey', cfg.sesskey);
            formData.append('courseid', cfg.courseid);
            formData.append('mode', mode);
            formData.append('goalid', activeGoal ? activeGoal.id : 0);

            historyLoad = fetch(cfg.wwwroot + '/local/chatbot/ajax_history.php', {method: 'POST', body: formData})
                .then(r => r.json())
                .then(data => {
                    clearTranscript();
                    if (data.history && data.history.length > 0) {
                        data.history.forEach(m => appendMessage(m.message, m.is_bot == 1 ? 'bot' : 'user'));
                        return data.history.length;
                    }
                    appendMessage(activeGoal ? (str.thread_welcome || '').replace('{meta}', activeGoal.label)
                        : getWelcomeMessage(mode), 'bot');
                    return 0;
                })
                .catch(() => {
                    clearTranscript();
                    appendMessage(getWelcomeMessage(mode), 'bot');
                    return 0;
                });
            return historyLoad;
        }

        /**
         * Inserta un mensaje en la conversacion.
         *
         * El HTML se sanitiza siempre antes de insertarlo: el texto puede venir
         * del modelo, que a su vez repite contenido de documentos subidos y del
         * propio usuario, y marked no filtra HTML crudo incrustado en markdown.
         */
        /**
         * Valoracion 👍/👎 de una respuesta. Con 👎 pide un motivo de una lista cerrada; solo
         * viaja el voto y el motivo codificado.
         */
        function buildFeedbackBar(feedbackid) {
            const bar = document.createElement('div');
            bar.className = 'chatbot-feedback';
            const send = function(vote, reason) {
                const fd = new FormData();
                fd.append('sesskey', cfg.sesskey);
                fd.append('interactionid', feedbackid);
                fd.append('vote', vote);
                fd.append('reason', reason || '');
                return fetch(cfg.feedbackUrl, {method: 'POST', body: fd}).catch(function() {});
            };
            const thanks = function() {
                bar.innerHTML = '';
                const t = document.createElement('span');
                t.className = 'chatbot-feedback-thanks';
                t.textContent = str.fb_thanks || '';
                bar.appendChild(t);
            };
            const up = document.createElement('button');
            up.type = 'button';
            up.className = 'chatbot-feedback-btn';
            up.title = str.fb_up || '';
            up.setAttribute('aria-label', str.fb_up || '');
            up.textContent = '👍';
            up.addEventListener('click', function() {
                send(1, '');
                thanks();
            });
            const down = document.createElement('button');
            down.type = 'button';
            down.className = 'chatbot-feedback-btn';
            down.title = str.fb_down || '';
            down.setAttribute('aria-label', str.fb_down || '');
            down.textContent = '👎';
            down.addEventListener('click', function() {
                send(-1, '');
                bar.innerHTML = '';
                const why = document.createElement('span');
                why.className = 'chatbot-feedback-why';
                why.textContent = (str.fb_why || '') + ' ';
                bar.appendChild(why);
                ['no_responde', 'incorrecta', 'larga', 'no_entendi'].forEach(function(reason) {
                    const r = document.createElement('button');
                    r.type = 'button';
                    r.className = 'chatbot-feedback-reason';
                    r.textContent = str['fb_' + reason] || reason;
                    r.addEventListener('click', function() {
                        send(-1, reason);
                        thanks();
                    });
                    bar.appendChild(r);
                });
            });
            bar.appendChild(up);
            bar.appendChild(down);
            return bar;
        }

        /**
         * Rellena la burbuja del asistente con la respuesta ya formateada, sus fuentes, la
         * decision sobre la recomendacion, la valoracion y, si fallo algo pasajero, el reintento.
         */
        function fillBotMessage(msgDiv, data, retrytext) {
            const text = data.reply || '';
            if (typeof marked !== 'undefined') {
                const html = marked.parse(text);
                msgDiv.innerHTML = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(html) : html;
            } else {
                msgDiv.textContent = text;
            }
            if (data.resources && data.resources.length > 0) {
                const sources = document.createElement('div');
                sources.className = 'chatbot-sources';
                sources.textContent = (str.sources || 'Fuentes') + ': ' + data.resources.join(' · ');
                msgDiv.appendChild(sources);
            }
            if (data.interactionid && data.interactionid > 0) {
                msgDiv.appendChild(buildDecisionBar(data.interactionid));
            }
            if (cfg.ux && cfg.ux.feedback && data.feedbackid && data.feedbackid > 0) {
                msgDiv.appendChild(buildFeedbackBar(data.feedbackid));
            }
            if (cfg.ux && cfg.ux.retry && data.retryable && retrytext) {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'chatbot-retry-btn';
                retry.textContent = '↻ ' + (str.retry_btn || 'Reintentar');
                retry.addEventListener('click', function() {
                    retry.disabled = true;
                    sendMessage(retrytext);
                });
                msgDiv.appendChild(retry);
            }
        }

        function appendMessage(text, type, resources, interactionid) {
            const wrapper = document.createElement('div');
            wrapper.className = 'chat-msg-wrapper ' + type + '-wrapper';

            const avatar = document.createElement('div');
            avatar.className = 'chat-avatar ' + type + '-avatar';
            if (type === 'user') {
                avatar.textContent = 'Tú';
            } else {
                const img = document.createElement('img');
                img.src = cfg.wwwroot + '/local/chatbot/pix/interfases_logo.png';
                img.alt = '';
                img.style.cssText = 'width:100%;height:100%;object-fit:contain;background:#fff;padding:4px;box-sizing:border-box;border-radius:50%;';
                avatar.appendChild(img);
            }

            const msgDiv = document.createElement('div');
            msgDiv.className = 'chat-msg ' + type + '-msg';

            if (type === 'bot' && typeof marked !== 'undefined') {
                const html = marked.parse(text || '');
                msgDiv.innerHTML = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(html) : html;
            } else {
                msgDiv.textContent = text || '';
            }

            // Declaracion de fuentes: que informacion uso el asistente.
            if (resources && resources.length > 0) {
                const sources = document.createElement('div');
                sources.className = 'chatbot-sources';
                sources.textContent = (str.sources || 'Fuentes') + ': ' + resources.join(' · ');
                msgDiv.appendChild(sources);
            }

            // Decision sobre la recomendacion, en los modos que la producen.
            if (interactionid && interactionid > 0) {
                msgDiv.appendChild(buildDecisionBar(interactionid));
            }

            wrapper.appendChild(avatar);
            wrapper.appendChild(msgDiv);
            messagesContainer.appendChild(wrapper);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function buildDecisionBar(interactionid) {
            const bar = document.createElement('div');
            bar.className = 'chatbot-decision';

            const label = document.createElement('span');
            label.className = 'chatbot-decision-label';
            label.textContent = str.decisionprompt || '';
            bar.appendChild(label);

            // Las cuatro decisiones que la persona puede tomar sobre cualquier
            // recomendacion del asistente: aceptar, editar, postergar, rechazar.
            const options = [
                {value: 1, text: str.accept || 'Aceptar'},
                {value: 2, text: str.modify || 'Modificar'},
                {value: 3, text: str.postpone || 'Postergar'},
                {value: 0, text: str.reject || 'Descartar'}
            ];

            options.forEach(function(option) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'chatbot-decision-btn';
                btn.textContent = option.text;
                btn.addEventListener('click', function() {
                    sendDecision(interactionid, option.value, bar);
                });
                bar.appendChild(btn);
            });

            return bar;
        }

        function sendDecision(interactionid, decision, bar) {
            const formData = new FormData();
            formData.append('sesskey', cfg.sesskey);
            formData.append('interactionid', interactionid);
            formData.append('decision', decision);

            fetch(cfg.wwwroot + '/local/chatbot/ajax_decision.php', {method: 'POST', body: formData})
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        bar.textContent = str.decisionsaved || '';
                        bar.classList.add('is-done');
                    }
                })
                .catch(() => {});
        }

        function appendTyping(id) {
            const wrapper = document.createElement('div');
            wrapper.className = 'chat-msg-wrapper bot-wrapper';
            wrapper.id = id;

            const indicator = document.createElement('div');
            indicator.className = 'chat-msg bot-msg typing-indicator';
            for (let i = 0; i < 3; i++) {
                const dot = document.createElement('div');
                dot.className = 'typing-dot';
                indicator.appendChild(dot);
            }

            wrapper.appendChild(indicator);
            messagesContainer.appendChild(wrapper);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function removeTyping(typingId) {
            const typingEl = document.getElementById(typingId);
            if (typingEl) {
                typingEl.remove();
            }
        }

        // Burbuja nueva del asistente, rellenada con la respuesta completa.
        function appendBot(data, retrytext) {
            appendMessage('', 'bot');
            const msgDiv = messagesContainer.lastElementChild.querySelector('.chat-msg');
            fillBotMessage(msgDiv, data, retrytext);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        /**
         * Envia el turno. Con retrytext reenvia esa misma pregunta (boton Reintentar) sin volver
         * a pintarla. Con las respuestas en vivo encendidas, el texto aparece por frases a medida
         * que el servidor las libera; al final se reemplaza por la respuesta revisada completa.
         */
        function sendMessage(retrytext) {
            const isRetry = typeof retrytext === 'string';
            const text = isRetry ? retrytext : inputField.value.trim();
            const file = (!isRetry && fileInput && fileInput.files.length > 0) ? fileInput.files[0] : null;

            if (text === '' && !file) {
                return;
            }

            if (!isRetry) {
                appendMessage(text || '(documento adjunto)', 'user');
            }
            inputField.value = '';
            if (fileInput) {
                fileInput.value = '';
            }
            if (filePreview) {
                filePreview.classList.remove('active');
            }

            const typingId = 'typing-' + Date.now();
            appendTyping(typingId);

            const formData = new FormData();
            formData.append('message', text);
            formData.append('mode', currentMode());
            formData.append('sesskey', cfg.sesskey);
            formData.append('courseid', cfg.courseid);
            // Recurso desde el que se abrio el asistente (Anexo E §11, source_resource).
            formData.append('cmid', cfg.cmid || 0);
            // Hilo de la meta abierta (0 = chat general); el servidor comprueba que la meta sea de la persona.
            formData.append('goalid', activeGoal ? activeGoal.id : 0);
            // Pantalla del recorrido que tiene abierta la persona (RU de ruta): el servidor la
            // vuelve a validar y la agrega al contexto para que el asistente no la ubique en otro paso.
            formData.append('screen', (cfg.journey && cfg.journey.screenStep) || 0);
            // Etapa del instrumento que se esta respondiendo, cuando el widget
            // se pinto sobre el diagnostico. La resolvio el servidor al armar
            // esta configuracion, y el servidor vuelve a comprobar si esa etapa
            // ya fue enviada antes de hacerle caso: esto puede endurecer las
            // reglas del asistente, nunca relajarlas.
            formData.append('diagstage', cfg.diagStage || '');
            if (file) {
                formData.append('chat_attachment', file);
            }
            const useStream = !!(cfg.ux && cfg.ux.streaming && window.ReadableStream && window.TextDecoder && !file);
            if (useStream) {
                formData.append('stream', '1');
            }

            fetch(cfg.ajaxurl, {method: 'POST', body: formData})
                .then(function(r) {
                    const ct = r.headers.get('content-type') || '';
                    if (!useStream || ct.indexOf('text/event-stream') === -1 || !r.body) {
                        return r.json().then(function(data) {
                            removeTyping(typingId);
                            appendBot(data, text);
                        });
                    }
                    const reader = r.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    let shown = '';
                    let msgDiv = null;
                    let finished = false;
                    const ensure = function() {
                        if (!msgDiv) {
                            removeTyping(typingId);
                            appendMessage('', 'bot');
                            msgDiv = messagesContainer.lastElementChild.querySelector('.chat-msg');
                        }
                    };
                    const render = function(t) {
                        ensure();
                        if (typeof marked !== 'undefined') {
                            const html = marked.parse(t);
                            msgDiv.innerHTML = (typeof DOMPurify !== 'undefined') ? DOMPurify.sanitize(html) : html;
                        } else {
                            msgDiv.textContent = t;
                        }
                        messagesContainer.scrollTop = messagesContainer.scrollHeight;
                    };
                    const handle = function(evento, data) {
                        if (evento === 'delta') {
                            shown += data.text || '';
                            render(shown);
                        } else if (evento === 'replace') {
                            shown = data.text || '';
                            render(shown);
                        } else if (evento === 'final') {
                            finished = true;
                            ensure();
                            fillBotMessage(msgDiv, data, text);
                            messagesContainer.scrollTop = messagesContainer.scrollHeight;
                        }
                    };
                    const pump = function() {
                        return reader.read().then(function(res) {
                            if (res.done) {
                                if (!finished) {
                                    ensure();
                                    fillBotMessage(msgDiv, {reply: shown || str.connerror || '', retryable: !shown}, text);
                                }
                                return;
                            }
                            buffer += decoder.decode(res.value, {stream: true}).replace(/\r\n/g, '\n');
                            let pos;
                            while ((pos = buffer.indexOf('\n\n')) !== -1) {
                                const bloque = buffer.slice(0, pos);
                                buffer = buffer.slice(pos + 2);
                                let evento = 'message';
                                let datos = '';
                                bloque.split('\n').forEach(function(linea) {
                                    if (linea.indexOf('event:') === 0) {
                                        evento = linea.slice(6).trim();
                                    } else if (linea.indexOf('data:') === 0) {
                                        datos += linea.slice(5).trim();
                                    }
                                });
                                if (datos) {
                                    try {
                                        handle(evento, JSON.parse(datos));
                                    } catch (e) {
                                        // Un evento ilegible no corta la respuesta.
                                    }
                                }
                            }
                            return pump();
                        });
                    };
                    return pump();
                })
                .catch(function() {
                    removeTyping(typingId);
                    appendBot({reply: str.connerror || '', retryable: true}, text);
                });
        }

        // --- Eventos ---
        toggleBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleChat();
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeChat();
            });
        }

        if (overlayEl) {
            overlayEl.addEventListener('click', function() {
                closeChat();
            });
        }

        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', function() {
                windowEl.classList.toggle('chatbot-window-fullscreen');
                if (overlayEl) {
                    overlayEl.classList.toggle('active');
                }
            });
        }

        // Control del drawer de perfiles/roles.
        if (roleBtn && roleDrawer) {
            roleBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                roleDrawer.classList.toggle('is-open');
            });
        }

        if (roleDrawerClose && roleDrawer) {
            roleDrawerClose.addEventListener('click', function(e) {
                e.stopPropagation();
                roleDrawer.classList.remove('is-open');
            });
        }

        roleCards.forEach(function(card) {
            card.addEventListener('click', function(e) {
                e.stopPropagation();
                const m = this.getAttribute('data-mode');
                if (m) {
                    setMode(m);
                }
            });
        });

        if (windowEl && roleDrawer) {
            windowEl.addEventListener('click', function(e) {
                if (roleDrawer.classList.contains('is-open') &&
                    !roleDrawer.contains(e.target) &&
                    (!roleBtn || !roleBtn.contains(e.target))) {
                    roleDrawer.classList.remove('is-open');
                }
            });
        }

        if (modeSelector) {
            modeSelector.addEventListener('change', function() {
                setMode(this.value);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                if (!confirm(str.confirmclear || '')) {
                    return;
                }
                const formData = new FormData();
                formData.append('sesskey', cfg.sesskey);
                formData.append('courseid', cfg.courseid);
                formData.append('mode', currentMode());
                formData.append('goalid', activeGoal ? activeGoal.id : 0);

                fetch(cfg.wwwroot + '/local/chatbot/ajax_clear_history.php', {method: 'POST', body: formData})
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            loadHistory();
                        } else {
                            alert(str.clearfailed || '');
                        }
                    })
                    .catch(function() {
                        alert(str.clearfailed || '');
                    });
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', function() {
                if (fileInput.files.length > 0 && filePreview && fileName) {
                    fileName.textContent = fileInput.files[0].name;
                    filePreview.classList.add('active');
                }
            });
        }

        if (fileRemove) {
            fileRemove.addEventListener('click', function() {
                fileInput.value = '';
                filePreview.classList.remove('active');
            });
        }

        if (sendBtn && inputField) {
            sendBtn.addEventListener('click', sendMessage);
            inputField.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !inputField.disabled) {
                    sendMessage();
                }
            });
        }

        // Control del tracker de la ruta (colapsar / desplegar)
        if (journeyToggle && journeyTracker) {
            journeyToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                journeyTracker.classList.toggle('is-collapsed');
            });
        }

        // Píldoras de acción rápida (Quick Action Chips)
        if (quickChips && quickChips.length > 0) {
            quickChips.forEach(function(chip) {
                chip.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Pildoras del recurso abierto: llevan su pregunta y su modo.
                    const pregunta = this.getAttribute('data-ask');
                    if (pregunta) {
                        if (typeof window.chatbotAsk === 'function') {
                            window.chatbotAsk(pregunta, this.getAttribute('data-mode') || undefined);
                        }
                        return;
                    }
                    const action = this.getAttribute('data-chip');
                    if (action === 'whereami') {
                        if (typeof window.chatbotAsk === 'function') {
                            window.chatbotAsk('¿En qué etapa de la ruta del Módulo 1 me encuentro y cuál es mi siguiente paso?');
                        }
                    } else if (action === 'whatmode') {
                        const m = currentMode();
                        const p = profiles[m];
                        const roleName = p ? p.name : m;
                        if (typeof window.chatbotAsk === 'function') {
                            window.chatbotAsk('¿Cuál es tu rol como ' + roleName + ' y de qué manera me apoyas en este momento?');
                        }
                    } else if (action === 'nextstep') {
                        // nextUrl viene vacio cuando no queda nada pendiente distinto de
                        // la pantalla abierta: ahi se pide orientacion en vez de navegar.
                        if (cfg.journey && cfg.journey.nextUrl) {
                            window.location.href = cfg.journey.nextUrl;
                        } else if (typeof window.chatbotAsk === 'function') {
                            window.chatbotAsk('Ya estoy en este hito de la ruta. ¿Qué orientaciones y recomendaciones me das para avanzar y completarlo con éxito?');
                        }
                    }
                });
            });
        }

        // =========================================================
        // CONTROLADOR EXCLUSIVO PARA ADMINISTRADOR (API KEYS Y MODELOS)
        // =========================================================
        if (cfg.isAdmin && cfg.adminAjaxUrl) {
            const adminConfigBtn = document.getElementById('chatbot-admin-config-btn');
            const adminDrawerCard = document.getElementById('chatbot-role-admin-card');
            const adminModalOverlay = document.getElementById('chatbot-admin-modal-overlay');
            const adminModalClose = document.getElementById('chatbot-admin-modal-close');
            const adminCancelBtn = document.getElementById('chatbot-admin-cancel-btn');
            const adminSaveBtn = document.getElementById('chatbot-admin-save-btn');
            const adminTabs = document.querySelectorAll('.chatbot-admin-tab');
            const adminTabContents = document.querySelectorAll('.chatbot-admin-tab-content');
            const toggleEyeBtns = document.querySelectorAll('.chatbot-admin-toggle-eye');
            const testBtns = document.querySelectorAll('.chatbot-admin-test-btn');

            const providersList = ['gemini', 'anthropic', 'openai'];

            function openAdminModal() {
                if (!adminModalOverlay) return;
                adminModalOverlay.style.display = 'flex';
                // Cerrar drawer de roles si estaba abierto.
                if (roleDrawer) {
                    roleDrawer.classList.remove('is-open');
                }
                loadAdminConfig();
            }

            function closeAdminModal() {
                if (!adminModalOverlay) return;
                adminModalOverlay.style.display = 'none';
            }

            if (adminConfigBtn) {
                adminConfigBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openAdminModal();
                });
            }

            if (adminDrawerCard) {
                adminDrawerCard.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openAdminModal();
                });
            }

            if (adminModalClose) {
                adminModalClose.addEventListener('click', closeAdminModal);
            }

            if (adminCancelBtn) {
                adminCancelBtn.addEventListener('click', closeAdminModal);
            }

            if (adminModalOverlay) {
                adminModalOverlay.addEventListener('click', function(e) {
                    if (e.target === adminModalOverlay) {
                        closeAdminModal();
                    }
                });
            }

            // Pestañas entre proveedores.
            adminTabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');
                    adminTabs.forEach(t => t.classList.remove('active'));
                    adminTabContents.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    const targetEl = document.getElementById(targetTab);
                    if (targetEl) {
                        targetEl.classList.add('active');
                    }
                });
            });

            // Mostrar / Ocultar contraseñas/claves de API.
            toggleEyeBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const inputEl = document.getElementById(targetId);
                    if (!inputEl) return;
                    
                    if (inputEl.classList.contains('chatbot-admin-input-secret')) {
                        const isRevealed = inputEl.classList.toggle('is-revealed');
                        this.style.opacity = isRevealed ? '1' : '0.6';
                        this.style.color = isRevealed ? '#4F46E5' : '';
                    } else {
                        if (inputEl.type === 'password') {
                            inputEl.type = 'text';
                            this.style.opacity = '1';
                            this.style.color = '#4F46E5';
                        } else {
                            inputEl.type = 'password';
                            this.style.opacity = '0.6';
                            this.style.color = '';
                        }
                    }
                });
            });

            // Sincronización entre select de modelos sugeridos y campo personalizado.
            providersList.forEach(function(p) {
                const presetSelect = document.getElementById('admin-' + p + '-model-preset');
                const customWrap = document.getElementById('admin-' + p + '-model-custom-wrap');
                const customInput = document.getElementById('admin-' + p + '-model');

                if (presetSelect && customWrap && customInput) {
                    presetSelect.addEventListener('change', function() {
                        if (this.value === 'custom') {
                            customWrap.style.display = 'block';
                            customInput.focus();
                        } else {
                            customWrap.style.display = 'none';
                            customInput.value = this.value;
                        }
                    });
                }
            });

            // Cargar configuración actual desde el servidor.
            function loadAdminConfig() {
                fetch(cfg.adminAjaxUrl + '?action=get&sesskey=' + encodeURIComponent(cfg.sesskey))
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) return;

                        // Marcar proveedor activo.
                        const activeRadio = document.querySelector('input[name="chatbot_active_provider"][value="' + data.active_provider + '"]');
                        if (activeRadio) {
                            activeRadio.checked = true;
                        }

                        // Actualizar cada proveedor.
                        providersList.forEach(function(p) {
                            const pdata = data.providers[p];
                            if (!pdata) return;

                            const keyStatus = document.getElementById(p + '-key-status');
                            const tagStatus = document.getElementById('tag-status-' + p);
                            const keyInput = document.getElementById('admin-' + p + '-key');
                            const presetSelect = document.getElementById('admin-' + p + '-model-preset');
                            const customWrap = document.getElementById('admin-' + p + '-model-custom-wrap');
                            const customInput = document.getElementById('admin-' + p + '-model');

                            if (keyStatus) {
                                if (pdata.has_key) {
                                    keyStatus.textContent = 'Configurada ✓';
                                    keyStatus.className = 'chatbot-admin-key-badge is-configured';
                                } else {
                                    keyStatus.textContent = 'No configurada';
                                    keyStatus.className = 'chatbot-admin-key-badge is-missing';
                                }
                            }

                            if (tagStatus) {
                                if (pdata.has_key) {
                                    tagStatus.textContent = 'Lista ✓';
                                    tagStatus.style.background = '#ECFDF5';
                                    tagStatus.style.color = '#047857';
                                } else {
                                    tagStatus.textContent = 'Sin clave';
                                    tagStatus.style.background = '#FEF2F2';
                                    tagStatus.style.color = '#B91C1C';
                                }
                            }

                            if (keyInput) {
                                keyInput.value = '';
                                if (pdata.has_key && pdata.key_masked) {
                                    keyInput.placeholder = pdata.key_masked + ' (Dejar en blanco para mantener)';
                                }
                            }

                            // Sincronizar modelo actual.
                            const currentModel = pdata.model || pdata.default_model || '';
                            if (presetSelect && customInput) {
                                customInput.value = currentModel;
                                let foundOption = false;
                                for (let i = 0; i < presetSelect.options.length; i++) {
                                    if (presetSelect.options[i].value === currentModel) {
                                        presetSelect.selectedIndex = i;
                                        foundOption = true;
                                        break;
                                    }
                                }
                                if (!foundOption) {
                                    presetSelect.value = 'custom';
                                    if (customWrap) customWrap.style.display = 'block';
                                } else {
                                    if (customWrap) customWrap.style.display = 'none';
                                }
                            }
                        });
                    })
                    .catch(err => console.error('Error al cargar configuración de IA:', err));
            }

            // Probar conexión de un proveedor.
            testBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const provider = this.getAttribute('data-provider');
                    const resultEl = document.getElementById(provider + '-test-result');
                    const keyInput = document.getElementById('admin-' + provider + '-key');
                    const customInput = document.getElementById('admin-' + provider + '-model');
                    const presetSelect = document.getElementById('admin-' + provider + '-model-preset');

                    const apiKey = keyInput ? keyInput.value.trim() : '';
                    let model = '';
                    if (presetSelect && presetSelect.value !== 'custom') {
                        model = presetSelect.value;
                    } else if (customInput) {
                        model = customInput.value.trim();
                    }

                    if (resultEl) {
                        resultEl.innerHTML = '<span class="chatbot-admin-loading-spinner"></span> ' + (str.admin_testing || 'Verificando con el proveedor...');
                        resultEl.className = 'chatbot-admin-test-result is-loading';
                    }
                    btn.disabled = true;

                    const fd = new FormData();
                    fd.append('action', 'test');
                    fd.append('sesskey', cfg.sesskey);
                    fd.append('provider', provider);
                    fd.append('api_key', apiKey);
                    fd.append('model', model);

                    fetch(cfg.adminAjaxUrl, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            btn.disabled = false;
                            if (!resultEl) return;
                            if (res.success) {
                                resultEl.className = 'chatbot-admin-test-result is-success';
                                resultEl.innerHTML = '<strong>✓ Conexión exitosa</strong> · Latencia: ' + res.latency + 'ms · Modelo: ' + (res.model || model);
                            } else {
                                resultEl.className = 'chatbot-admin-test-result is-error';
                                resultEl.innerHTML = '<strong>✗ Error:</strong> ' + (res.error || 'No se pudo conectar.');
                            }
                        })
                        .catch(err => {
                            btn.disabled = false;
                            if (resultEl) {
                                resultEl.className = 'chatbot-admin-test-result is-error';
                                resultEl.innerHTML = '<strong>✗ Error de red:</strong> ' + err.message;
                            }
                        });
                });
            });

            // Guardar cambios.
            if (adminSaveBtn) {
                adminSaveBtn.addEventListener('click', function() {
                    const activeRadio = document.querySelector('input[name="chatbot_active_provider"]:checked');
                    const activeProvider = activeRadio ? activeRadio.value : '';

                    const fd = new FormData();
                    fd.append('action', 'save');
                    fd.append('sesskey', cfg.sesskey);
                    fd.append('active_provider', activeProvider);

                    providersList.forEach(function(p) {
                        const keyInput = document.getElementById('admin-' + p + '-key');
                        const customInput = document.getElementById('admin-' + p + '-model');
                        const presetSelect = document.getElementById('admin-' + p + '-model-preset');

                        if (keyInput && keyInput.value.trim() !== '') {
                            fd.append(p + '_api_key', keyInput.value.trim());
                        }

                        let model = '';
                        if (presetSelect && presetSelect.value !== 'custom') {
                            model = presetSelect.value;
                        } else if (customInput) {
                            model = customInput.value.trim();
                        }
                        fd.append(p + '_model', model);
                    });

                    adminSaveBtn.disabled = true;
                    const saveText = adminSaveBtn.querySelector('.chatbot-admin-save-text');
                    const oldText = saveText ? saveText.textContent : 'Guardar';
                    if (saveText) saveText.textContent = str.admin_saving || 'Guardando...';

                    fetch(cfg.adminAjaxUrl, { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            adminSaveBtn.disabled = false;
                            if (saveText) saveText.textContent = oldText;

                            if (res.success) {
                                alert(str.admin_save_success || 'Configuración guardada exitosamente.');
                                loadAdminConfig();
                                setTimeout(closeAdminModal, 800);
                            } else {
                                alert(res.error || (str.admin_save_error || 'Error al guardar.'));
                            }
                        })
                        .catch(err => {
                            adminSaveBtn.disabled = false;
                            if (saveText) saveText.textContent = oldText;
                            alert('Error de conexión al guardar: ' + err.message);
                        });
                });
            }
        }


        updateModeClass();
        renderBanner();
        if (cfg.hasConsent) {
            loadHistory();
        }

        window.chatbotSetMode = setMode;

        /**
         * Abre (o retoma) la conversacion de una meta con el Coach. Si el hilo es nuevo, envia la
         * primera consulta; si ya tiene mensajes, solo los muestra para seguir donde quedo.
         */
        if (cfg.ux && cfg.ux.goalthreads) {
            window.chatbotGoalThread = function(goalid, label, firstQuestion) {
                if (!goalid) {
                    window.chatbotAsk(firstQuestion, 'coach');
                    return;
                }
                activeGoal = {id: goalid, label: label || ''};
                openChat();
                renderThreadBar();
                if (activeMode !== 'coach' && profiles.coach) {
                    activeMode = 'coach';
                    if (modeSelector) {
                        modeSelector.value = 'coach';
                    }
                    if (roleBadge) {
                        roleBadge.textContent = profiles.coach.letter || 'B';
                    }
                    if (roleName) {
                        roleName.textContent = profiles.coach.short || profiles.coach.name || '';
                    }
                    roleCards.forEach(function(card) {
                        card.classList.toggle('is-active', card.getAttribute('data-mode') === 'coach');
                    });
                    updateModeClass();
                    renderBanner();
                }
                loadHistory().then(function(cantidad) {
                    if (cantidad === 0 && firstQuestion) {
                        inputField.value = firstQuestion;
                        setTimeout(function() {
                            sendMessage();
                        }, 150);
                    }
                });
            };
        }

        /**
         * Abre el asistente con una consulta ya escrita y la envia.
         *
         * Lo usan los botones de las paginas del recorrido: "Consultar concepto"
         * en el diagnostico y "Conversar esta meta con el Coach" en el plan.
         * Antes solo se invocaba: la funcion no existia, asi que esos botones no
         * hacian nada.
         *
         * @param {string} texto Consulta a enviar.
         * @param {string} [modo] tutor, coach o evaluador. Si se omite, deja el activo.
         */
        window.chatbotAsk = function(texto, modo) {
            if (cfg.ux && cfg.ux.tutorOnly) {
                modo = 'tutor';
            }
            if (!texto) {
                openChat();
                return;
            }
            // Estas consultas son del chat general: si habia un hilo de meta abierto, se sale de el
            // y se recarga el historial general antes de mandar.
            const salirDelHilo = !!activeGoal;
            if (salirDelHilo) {
                activeGoal = null;
                renderThreadBar();
            }
            const cambiaModo = modo && profiles[modo] && modo !== activeMode;
            if (cambiaModo) {
                setMode(modo);
            } else if (salirDelHilo) {
                loadHistory();
            }
            openChat();
            // Se manda cuando el historial termino de cargar: si no, la carga borra el mensaje.
            historyLoad.then(function() {
                inputField.value = texto;
                sendMessage();
            });
        };
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initChatbot);
    } else {
        initChatbot();
    }
})();
