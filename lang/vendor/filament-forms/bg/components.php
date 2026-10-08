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
 *   test, 2026-10-08).
 * Remove a key once upstream Filament ships it.
 */
return [

    'select' => [

        'no_options_message' => 'Няма налични опции.',

        'max_items_message' => 'Могат да бъдат избрани само :count.',

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
