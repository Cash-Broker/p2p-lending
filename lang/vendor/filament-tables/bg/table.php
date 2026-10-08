<?php

/*
 * App-level patch over Filament's bg translations (merged on top of the
 * package file by Laravel's namespaced-translation loader). Filament 5.10
 * added two screen-reader live regions to every table that the shipped bg
 * table.php does not translate, so assistive tech read out the raw keys
 * (smoke test 2026-10-08). Remove a key once upstream Filament ships it.
 */
return [

    'loading' => 'Зареждане...',

    'result_count' => '{0} Няма резултати|{1} :count резултат|[2,*] :count резултата',

];
