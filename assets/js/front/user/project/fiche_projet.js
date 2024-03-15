require('trumbowyg/dist/trumbowyg.min.js');
require('trumbowyg/dist/langs/fr.min.js');
require('trumbowyg/dist/plugins/cleanpaste/trumbowyg.cleanpaste.min.js');

$(function() {
    
    // wysiwyg
    $('.trumbowyg').trumbowyg({
        svgPath: '/build/trumbowyg/icons.svg',
        lang: 'fr',
        // Redefine the button pane
        btns: [
            ['formatting'],
            ['strong', 'em'],
            ['link'],
            ['unorderedList', 'orderedList'],
            ['removeformat'],            
            ['fullscreen']
        ],
        plugins: {
            // nettoyage texte word
            cleanpaste: true
        }
    });

    // Gestionnaire d'événement 'submit'
    $(document).on('submit', 'form[name="form"]', function(e) {
        e.preventDefault();
        $("#btn_modal_waiting").attr("data-fr-opened", "true");
        setTimeout(function() {
            $(e.target).off('submit');
            e.target.submit();
        }, 250);
    }); 
    
}); 
