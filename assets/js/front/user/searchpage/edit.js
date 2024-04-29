require('../../form/trumbowyg.js');

$(function() {
    /**
     * Supprimer collection item
     */

    $(document).on({
        click: function (e) {
            e.preventDefault();
            $(this).parents('.collection-item-wrapper-generic:first').remove();
        }
    }, '.btn-delete-collection-generic');

    /**
     * Rajouter collection item
     */
    jQuery('.add-another-collection-widget').click(function (e) {
        e.preventDefault();
        var list = jQuery(jQuery(this).attr('data-list-selector'));
        // Try to find the counter of the list or use the length of the list
        var counter = list.data('widget-counter') || list.children().length;

        // grab the prototype template
        var newWidget = list.data('prototype');
        // replace the "__name__" used in the id and name of the prototype
        // with a number that's unique to your emails
        // end name attribute looks like name="contact[emails][2]"
        newWidget = newWidget.replace(/__name__/g, counter);
        // Increase the counter
        counter++;
        // And store it, the length cannot be used if deleting widgets is allowed
        list.data('widget-counter', counter);

        var range = document.createRange();
        var fragment = range.createContextualFragment('');
        var tempDiv = document.createElement('div');
        tempDiv.innerHTML = newWidget;

        while (tempDiv.firstChild) {
            fragment.appendChild(tempDiv.firstChild);
        }


        var wrapper = document.createElement('div');
        wrapper.className = 'collection-item-wrapper-generic fr-mb-2w';
        wrapper.appendChild(fragment);

        list.append(wrapper);
        

        launchTrumbowyg($(wrapper).find('textarea'));
    });

});