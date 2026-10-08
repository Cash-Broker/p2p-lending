<?php

/*
 * App-level patch over Filament's bg translations (merged on top of the
 * package file by Laravel's namespaced-translation loader). The shipped bg
 * layout.php lacks the panel shell's landmark labels, the skip link, the
 * avatar alt text and the theme switcher's group label, so every admin page
 * carried raw «filament-panels::layout.*» keys in its aria-labels while the
 * fallback locale is bg too (admin crawl, Filament 5.10.1, 2026-10-08). The
 * bell's unread-count label (its aria-label AND hover tooltip whenever the
 * admin has unread notifications) was missing as well.
 * Remove a key once upstream Filament ships it.
 */
return [

    'skip_to_content' => [
        'label' => 'Към съдържанието',
    ],

    'actions' => [

        'open_database_notifications' => [
            'label_with_unread_count' => '{1} Известия, :count непрочетено известие|[2,*] Известия, :count непрочетени известия',
        ],

        'theme_switcher' => [
            'label' => 'Тема',
        ],

    ],

    'navigation' => [
        'label' => 'Странична навигация',
    ],

    'topbar' => [
        'label' => 'Горна лента',
    ],

    'avatar' => [
        'alt' => 'Аватар на :name',
    ],

];
