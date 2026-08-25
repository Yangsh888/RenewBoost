(function () {
    'use strict';

    if (!(window.TypechoTabs && typeof window.TypechoTabs.init === 'function')) {
        return;
    }

    var tabFields = document.querySelectorAll('[data-boost-tab-field]');
    window.TypechoTabs.init({
        labelGroupSelector: '.boost-list-item, .boost-block-item, .boost-field',
        labelTextSelector: '.boost-list-item-title, .boost-field > span',
        onChange: function (target) {
            for (var i = 0; i < tabFields.length; i++) {
                tabFields[i].value = target;
            }
        }
    });
})();
