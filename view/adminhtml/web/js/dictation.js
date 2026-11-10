/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero AI Assistant: dictation into the message field, with the browser's
 * own speech recognition (Web Speech API: Chrome, Edge, Safari). The button
 * ([data-zero-ai-mic], its language in data-lang) stays hidden where the
 * browser has none. JavaScript because nothing else reaches the microphone.
 * The message form is swapped in afresh after every message, so the button
 * is found on each click.
 */
(function () {
    'use strict';

    var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    var recognition = null;

    function reveal() {
        if (!Recognition) {
            return;
        }
        document.querySelectorAll('[data-zero-ai-mic]').forEach(function (button) {
            button.hidden = false;
        });
    }

    function listening(button, on) {
        button.classList.toggle('is-listening', on);
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.title = on ? button.getAttribute('data-stop') : button.getAttribute('data-start');
    }

    function start(button) {
        var field = document.getElementById('zero-ai-message');
        if (!field) {
            return;
        }
        var before = field.value.replace(/\s+$/, '');
        var heard = '';
        recognition = new Recognition();
        recognition.lang = button.getAttribute('data-lang') || document.documentElement.lang || 'en-US';
        recognition.continuous = true;
        recognition.interimResults = true;
        recognition.onresult = function (event) {
            var interim = '';
            for (var i = event.resultIndex; i < event.results.length; i++) {
                if (event.results[i].isFinal) {
                    heard += event.results[i][0].transcript;
                } else {
                    interim += event.results[i][0].transcript;
                }
            }
            field.value = (before ? before + ' ' : '') + (heard + interim).trim();
        };
        recognition.onerror = function (event) {
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                button.title = button.getAttribute('data-denied');
            }
        };
        recognition.onend = function () {
            recognition = null;
            var current = document.querySelector('[data-zero-ai-mic]');
            if (current) {
                listening(current, false);
            }
            field.focus();
        };
        listening(button, true);
        recognition.start();
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-zero-ai-mic]') : null;
        if (!button || !Recognition) {
            return;
        }
        event.preventDefault();
        if (recognition) {
            recognition.stop();
        } else {
            start(button);
        }
    });
    // Sending stops listening: the answer is on its way.
    document.addEventListener('submit', function (event) {
        if (recognition && event.target.id === 'zero-ai-composer') {
            recognition.stop();
        }
    }, true);
    document.addEventListener('htmx:afterSettle', reveal);
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reveal);
    } else {
        reveal();
    }
})();
