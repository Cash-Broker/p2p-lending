<?php

/*
 * App-level patch over Filament's bg translations (merged on top of the
 * package file by Laravel's namespaced-translation loader). The shipped bg
 * table.php does not translate these lines, so they rendered as raw keys
 * while the fallback locale is bg too:
 * - loading / result_count: the two screen-reader live regions Filament 5.10
 *   added to every table (smoke test 2026-10-08);
 * - column_manager actions: the VISIBLE «Apply columns» / «Reset» buttons of
 *   the column picker; columns.actions: the record-actions header's
 *   aria-label; columns.icon.boolean: the text alternative of boolean icon
 *   columns (admin crawl, Filament 5.10.1, 2026-10-08).
 * Remove a key once upstream Filament ships it.
 */
return [

    'column_manager' => [

        'actions' => [

            'apply' => [
                'label' => 'Приложи колоните',
            ],

            'reset' => [
                'label' => 'Нулирай',
            ],

        ],

    ],

    'columns' => [

        'actions' => [
            'label' => 'Действие|Действия',
        ],

        'icon' => [

            'boolean' => [
                'true' => 'Да',
                'false' => 'Не',
            ],

        ],

    ],

    'loading' => 'Зареждане...',

    'result_count' => '{0} Няма резултати|{1} :count резултат|[2,*] :count резултата',

];
