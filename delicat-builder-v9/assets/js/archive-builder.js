(function () { 'use strict';
var input = document.getElementById('delicat-archive-search');
var picker = document.getElementById('delicat-archive-picker');
if (!input || !picker) return;
var cards = picker.querySelectorAll('[data-delicat-archive-card]');
input.addEventListener('input', function () {
 var query = input.value.toLocaleLowerCase().trim();
 for (var i = 0; i < cards.length; i++) cards[i].hidden = query !== '' && (cards[i].getAttribute('data-search') || '').toLocaleLowerCase().indexOf(query) < 0;
});
}());
