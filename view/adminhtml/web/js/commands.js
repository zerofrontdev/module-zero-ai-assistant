/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * The commands list under the message field: shown while the message is a
 * "/" and a command name being typed, narrowed as it is typed; a click (or
 * Enter on a button) puts "/name " in the field. The server spells the
 * command out (Model\Commands). The form is swapped in afresh after every
 * message, so the listeners hang on the document.
 */
(function () {
    'use strict';

    function list() {
        return document.querySelector('[data-zero-ai-commands]');
    }

    function update(field) {
        var menu = list();
        if (!menu) {
            return;
        }
        var match = /^\/([a-z-]*)$/i.exec(field.value);
        var shown = 0;
        menu.querySelectorAll('[data-command]').forEach(function (button) {
            var visible = !!match && button.getAttribute('data-command').indexOf(match[1].toLowerCase()) === 0;
            button.parentElement.hidden = !visible;
            shown += visible ? 1 : 0;
        });
        menu.hidden = shown === 0;
    }

    document.addEventListener('input', function (event) {
        if (event.target && event.target.id === 'zero-ai-message') {
            update(event.target);
        }
    });

    document.addEventListener('keydown', function (event) {
        var menu = list();
        if (event.key === 'Escape' && menu && !menu.hidden) {
            menu.hidden = true;
        }
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('[data-zero-ai-commands] [data-command]');
        if (!button) {
            return;
        }
        var field = document.getElementById('zero-ai-message');
        field.value = '/' + button.getAttribute('data-command') + ' ';
        list().hidden = true;
        field.focus();
    });
})();
