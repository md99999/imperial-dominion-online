/*
 * Imperial Dominion Online: a small amount of polish only. The game works with
 * JavaScript switched off, because every order is an ordinary form POST.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.classList || !form.classList.contains('ido-form')) return;

        // Confirm the orders that cannot be taken back.
        var action = form.querySelector('input[name="ido_action"]');
        var value = action ? action.value : '';
        var question = '';
        if (value === 'attack') {
            question = 'Send your army? Losses are permanent and the battle resolves at once.';
        } else if (value === 'raze') {
            question = 'Pull these buildings down? Only a fraction of the cost comes back.';
        } else if (value === 'disband') {
            question = 'Disband these troops? They return to the fields as peasants.';
        }
        if (question && !window.confirm(question)) {
            event.preventDefault();
            return;
        }

        // Stop a double click from sending the same order twice.
        var button = form.querySelector('button[type="submit"]');
        if (button) {
            if (button.dataset.idoSent === '1') {
                event.preventDefault();
                return;
            }
            button.dataset.idoSent = '1';
            window.setTimeout(function () {
                button.disabled = true;
            }, 0);
        }
    });
}());
