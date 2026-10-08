<?php

/*
 * App-level patch over Filament's bg translations (merged on top of the
 * package file by Laravel's namespaced-translation loader). The shipped bg
 * database.php lacks the screen-reader marker each unread item carries in the
 * bell's slide-over. Remove a key once upstream Filament ships it.
 */
return [

    'modal' => [

        'unread_label' => 'Непрочетено известие',

    ],

];
