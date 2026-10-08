<?php

/*
 * App-level patch over Filament's bg translations. The shipped bg locale has
 * no notification.php at all, so the close button of EVERY toast carried the
 * raw key as its hover tooltip and aria-label.
 * Remove a key once upstream Filament ships it.
 */
return [

    'actions' => [

        'close' => [
            'label' => 'Затвори известието',
        ],

    ],

];
