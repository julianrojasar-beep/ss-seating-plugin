/**
 * SS Event Admin Media — WP Media Library para hero + galería.
 */
(function ($) {
    'use strict';

    $(function () {

        // ── Hero image ──────────────────────────────────────────────────

        var heroFrame = null;

        $('#ss-hero-select').on('click', function (e) {
            e.preventDefault();

            if (heroFrame) {
                heroFrame.open();
                return;
            }

            heroFrame = wp.media({
                title: 'Seleccionar imagen principal',
                button: { text: 'Usar esta imagen' },
                multiple: false,
                library: { type: 'image' }
            });

            heroFrame.on('select', function () {
                var attachment = heroFrame.state().get('selection').first().toJSON();
                var url = attachment.sizes && attachment.sizes.medium
                        ? attachment.sizes.medium.url
                        : attachment.url;

                $('#ss-hero-input').val(attachment.id);
                $('#ss-hero-preview').html('<img src="' + url + '" alt="">');
                $('#ss-hero-remove').removeClass('hidden');
            });

            heroFrame.open();
        });

        $('#ss-hero-remove').on('click', function (e) {
            e.preventDefault();
            $('#ss-hero-input').val('');
            $('#ss-hero-preview').html('');
            $(this).addClass('hidden');
        });

        // ── Gallery ─────────────────────────────────────────────────────

        var galleryFrame = null;

        $('#ss-gallery-add').on('click', function (e) {
            e.preventDefault();

            if (galleryFrame) {
                galleryFrame.open();
                return;
            }

            galleryFrame = wp.media({
                title: 'Agregar imágenes a la galería',
                button: { text: 'Agregar a galería' },
                multiple: true,
                library: { type: 'image' }
            });

            galleryFrame.on('select', function () {
                var attachments = galleryFrame.state().get('selection').toJSON();
                var $container = $('#ss-gallery-container');

                attachments.forEach(function (att) {
                    var thumb = att.sizes && att.sizes.thumbnail
                              ? att.sizes.thumbnail.url
                              : att.url;

                    // Skip si ya existe
                    if ($container.find('[data-id="' + att.id + '"]').length) {
                        return;
                    }

                    $container.append(
                        '<div class="ss-media-metabox__gallery-item" data-id="' + att.id + '">' +
                            '<img src="' + thumb + '" alt="">' +
                            '<button type="button" class="ss-gallery-remove" title="Quitar">&times;</button>' +
                        '</div>'
                    );
                });

                syncGalleryInput();
            });

            galleryFrame.open();
        });

        // Remove gallery item
        $('#ss-gallery-container').on('click', '.ss-gallery-remove', function (e) {
            e.preventDefault();
            $(this).closest('.ss-media-metabox__gallery-item').remove();
            syncGalleryInput();
        });

        // Drag & drop reorder (simple swap via HTML5 drag)
        var $container = $('#ss-gallery-container');

        $container.on('dragstart', '.ss-media-metabox__gallery-item', function (e) {
            $(this).addClass('ss-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', $(this).index());
        });

        $container.on('dragover', '.ss-media-metabox__gallery-item', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $(this).addClass('ss-drag-over');
        });

        $container.on('dragleave', '.ss-media-metabox__gallery-item', function () {
            $(this).removeClass('ss-drag-over');
        });

        $container.on('drop', '.ss-media-metabox__gallery-item', function (e) {
            e.preventDefault();
            $(this).removeClass('ss-drag-over');
            var fromIndex = parseInt(e.originalEvent.dataTransfer.getData('text/plain'), 10);
            var $items = $container.children();
            var $dragged = $items.eq(fromIndex);
            var toIndex = $(this).index();

            if (fromIndex !== toIndex) {
                if (fromIndex < toIndex) {
                    $(this).after($dragged);
                } else {
                    $(this).before($dragged);
                }
                syncGalleryInput();
            }
        });

        $container.on('dragend', '.ss-media-metabox__gallery-item', function () {
            $(this).removeClass('ss-dragging');
            $container.children().removeClass('ss-drag-over');
        });

        // Make items draggable
        $container.on('mouseenter', '.ss-media-metabox__gallery-item', function () {
            $(this).attr('draggable', 'true');
        });

        function syncGalleryInput() {
            var ids = [];
            $('#ss-gallery-container .ss-media-metabox__gallery-item').each(function () {
                ids.push($(this).data('id'));
            });
            $('#ss-gallery-input').val(ids.join(','));
        }

    });
})(jQuery);
