<?php

/*
 * App-level patch over Filament's bg translations (merged on top of the
 * package file by Laravel's namespaced-translation loader). The shipped
 * bg components.php is missing exactly these select keys, so an empty
 * select dropdown rendered the raw translation key
 * «filament-forms::components.select.no_options_message» in the admin
 * (spotted on the bonus user picker, 2026-08-10). Remove once upstream
 * Filament ships them.
 */
return [

    'select' => [

        'no_options_message' => 'Няма налични опции.',

        'max_items_message' => 'Могат да бъдат избрани само :count.',

    ],

];
