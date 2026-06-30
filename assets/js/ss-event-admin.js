/**
 * SS Event Admin — Tabs, media, ticket table.
 */
(function ($) {
    'use strict';

    $(function () {

        // ── Tab switching ─────────────────────────────────────────────

        $('.ss-admin-tabs__btn').on('click', function () {
            var tab = $(this).data('tab');
            $('.ss-admin-tabs__btn').removeClass('ss-active');
            $(this).addClass('ss-active');
            $('.ss-admin-tabs__panel').removeClass('ss-active');
            $('.ss-admin-tabs__panel[data-tab="' + tab + '"]').addClass('ss-active');
        });

        // ── Hero image ────────────────────────────────────────────────

        var heroFrame = null;

        $('#ss-hero-select').on('click', function (e) {
            e.preventDefault();
            if (heroFrame) { heroFrame.open(); return; }

            heroFrame = wp.media({
                title: 'Seleccionar imagen principal',
                button: { text: 'Usar esta imagen' },
                multiple: false,
                library: { type: 'image' }
            });

            heroFrame.on('select', function () {
                var att = heroFrame.state().get('selection').first().toJSON();
                var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
                $('#ss-hero-input').val(att.id);
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

        // ── Gallery ───────────────────────────────────────────────────

        var galleryFrame = null;
        var $gallery = $('#ss-gallery-container');

        $('#ss-gallery-add').on('click', function (e) {
            e.preventDefault();
            if (galleryFrame) { galleryFrame.open(); return; }

            galleryFrame = wp.media({
                title: 'Agregar imágenes a la galería',
                button: { text: 'Agregar a galería' },
                multiple: true,
                library: { type: 'image' }
            });

            galleryFrame.on('select', function () {
                var attachments = galleryFrame.state().get('selection').toJSON();
                attachments.forEach(function (att) {
                    var thumb = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
                    if ($gallery.find('[data-id="' + att.id + '"]').length) return;
                    $gallery.append(
                        '<div class="ss-admin-gallery__item" data-id="' + att.id + '">' +
                            '<img src="' + thumb + '" alt="">' +
                            '<button type="button" class="ss-admin-gallery__remove" title="Quitar">&times;</button>' +
                        '</div>'
                    );
                });
                syncGalleryInput();
            });

            galleryFrame.open();
        });

        $gallery.on('click', '.ss-admin-gallery__remove', function (e) {
            e.preventDefault();
            $(this).closest('.ss-admin-gallery__item').remove();
            syncGalleryInput();
        });

        // Drag & drop reorder
        $gallery.on('dragstart', '.ss-admin-gallery__item', function (e) {
            $(this).addClass('ss-dragging');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', $(this).index());
        });

        $gallery.on('dragover', '.ss-admin-gallery__item', function (e) {
            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';
            $(this).addClass('ss-drag-over');
        });

        $gallery.on('dragleave', '.ss-admin-gallery__item', function () {
            $(this).removeClass('ss-drag-over');
        });

        $gallery.on('drop', '.ss-admin-gallery__item', function (e) {
            e.preventDefault();
            $(this).removeClass('ss-drag-over');
            var from = parseInt(e.originalEvent.dataTransfer.getData('text/plain'), 10);
            var $items = $gallery.children();
            var $dragged = $items.eq(from);
            var to = $(this).index();
            if (from !== to) {
                if (from < to) { $(this).after($dragged); }
                else { $(this).before($dragged); }
                syncGalleryInput();
            }
        });

        $gallery.on('dragend', '.ss-admin-gallery__item', function () {
            $(this).removeClass('ss-dragging');
            $gallery.children().removeClass('ss-drag-over');
        });

        $gallery.on('mouseenter', '.ss-admin-gallery__item', function () {
            $(this).attr('draggable', 'true');
        });

        function syncGalleryInput() {
            var ids = [];
            $gallery.find('.ss-admin-gallery__item').each(function () {
                ids.push($(this).data('id'));
            });
            $('#ss-gallery-input').val(ids.join(','));
        }

        // ── Ticket table ──────────────────────────────────────────────

        var ticketIdx = $('#ss-ticket-tbody tr').length;

        $('#ss-ticket-add').on('click', function () {
            var i = ticketIdx++;
            var row =
                '<tr class="ss-ticket-row">' +
                    '<td><input type="text" name="ss_tt[' + i + '][zone]" value="" placeholder="Ej: VIP" class="widefat"></td>' +
                    '<td><input type="number" name="ss_tt[' + i + '][price]" value="0" min="0" step="100" class="widefat"></td>' +
                    '<td><input type="number" name="ss_tt[' + i + '][capacity]" value="0" min="0" class="widefat"></td>' +
                    '<td><button type="button" class="button ss-ticket-remove" title="Eliminar">&times;</button></td>' +
                '</tr>';
            $('#ss-ticket-tbody').append(row);
        });

        $('#ss-ticket-tbody').on('click', '.ss-ticket-remove', function () {
            $(this).closest('tr').remove();
        });

        // ── Dropdowns de ubicación y organizador ──────────────────────

        var registros = (typeof ssRegistros !== 'undefined') ? ssRegistros : { locations: [], organizers: [] };

        function populateSelect(selectId, items, labelKey) {
            var $sel = $('#' + selectId);
            if (!$sel.length || !items || !items.length) { return; }
            items.forEach(function(item) {
                $sel.append('<option value="' + item.id + '">' + $('<span>').text(item[labelKey]).html() + '</option>');
            });
        }

        populateSelect('ss-loc-preset', registros.locations, 'name');
        populateSelect('ss-org-preset', registros.organizers, 'name');

        $('#ss-loc-preset').on('change', function() {
            var id = $(this).val();
            if (!id) { return; }
            var loc = registros.locations.find(function(l) { return String(l.id) === String(id); });
            if (!loc) { return; }
            $('#ss-loc-venue').val(loc.name   || '');
            $('#ss-loc-street').val(loc.street || '');
            $('#ss-loc-city').val(loc.city     || '');
        });

        $('#ss-org-preset').on('change', function() {
            var id = $(this).val();
            if (!id) { return; }
            var org = registros.organizers.find(function(o) { return String(o.id) === String(id); });
            if (!org) { return; }
            $('#ss-org-name').val(org.name   || '');
            $('#ss-org-email').val(org.email || '');
            $('#ss-org-phone').val(org.phone || '');
        });

    });
})(jQuery);
