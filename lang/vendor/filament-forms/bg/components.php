<?php

/*
 * App-level patch over Filament's bg translations (merged on top of the
 * package file by Laravel's namespaced-translation loader). The shipped
 * bg components.php is missing exactly these keys, so they rendered as raw
 * translation keys in the admin wherever the fallback locale is bg too:
 * - select: an empty dropdown showed
 *   «filament-forms::components.select.no_options_message» (spotted on the
 *   bonus user picker, 2026-08-10);
 * - text_input: the password reveal and copy buttons (Filament 5.10.1 smoke
 *   test, 2026-10-08);
 * - select: the search box, clear and remove-option labels of every
 *   searchable select, plus the inline «create option» button (loan form);
 * - file_upload: the download / open-in-new-tab button labels. Filament embeds
 *   them in every upload field's Alpine config, so the raw keys sat in the
 *   originator form's HTML; the buttons themselves appear only once a field
 *   is downloadable()/openable() (the logo field is neither today) (admin
 *   crawl, Filament 5.10.1, 2026-10-08).
 * Remove a key once upstream Filament ships it.
 */
return [

    'file_upload' => [

        'actions' => [

            'download' => [
                'label' => 'Изтегли',
            ],

            'open' => [
                'label' => 'Отвори в нов раздел',
            ],

        ],

    ],

    'select' => [

        'actions' => [

            'clear' => [
                'label' => 'Изчисти избора',
            ],

            'create_option' => [
                'label' => 'Създай',
            ],

            // «:label» is substituted by Filament's select.js with the
            // option's label, so the placeholder must stay verbatim.
            'remove_option' => [
                'label' => 'Премахни :label',
            ],

        ],

        'no_options_message' => 'Няма налични опции.',

        'max_items_message' => 'Могат да бъдат избрани само :count.',

        'search_label' => 'Търсене',

    ],

    'text_input' => [

        'actions' => [

            'copy' => [
                'label' => 'Копирай',
                'message' => 'Копирано',
            ],

            'hide_password' => [
                'label' => 'Скрий паролата',
            ],

            'show_password' => [
                'label' => 'Покажи паролата',
            ],

        ],

    ],

];
