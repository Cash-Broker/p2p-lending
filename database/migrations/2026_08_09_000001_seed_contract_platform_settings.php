<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the platform_settings rows that fill the ЗАЕМАТЕЛ (borrower-company)
 * side of the generated investment contract («Договор за целеви паричен
 * заем»). The lawyer's template leaves these as placeholders — company
 * name, ЕИК, seat address, manager name — plus the place of conclusion.
 *
 * Values are snapshotted into `investment_contracts.terms_snapshot` at
 * invest time, so editing a setting affects FUTURE contracts only —
 * already-concluded contracts keep the wording they were accepted with.
 *
 * Company requisites follow the Commercial Register entry for ЕИК
 * 201035515 (provided by the client 2026-08-09); editable later via
 * Filament → Система → Настройки. An empty value prints as a dotted
 * placeholder in the PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insert([
            [
                'key' => 'contract_company_name',
                'value' => 'ВАМА АСЕТ ЕООД',
                'type' => 'string',
                'description' => 'Пълно наименование на дружеството-ЗАЕМАТЕЛ в договора за заем (вкл. правната форма, напр. „ВАМА АСЕТ ЕООД“).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'contract_company_eik',
                'value' => '201035515',
                'type' => 'string',
                'description' => 'ЕИК на дружеството-ЗАЕМАТЕЛ в договора за заем.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'contract_company_address',
                'value' => 'гр. Пловдив, п.к. 4000, р-н Централен, ул. „Капитан Райчо“ № 59, ет. 2, ап. 12',
                'type' => 'string',
                'description' => 'Седалище и адрес на управление на дружеството-ЗАЕМАТЕЛ в договора за заем (по Търговския регистър).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'contract_company_manager',
                'value' => 'Иванка Тодорова Маринова',
                'type' => 'string',
                'description' => 'Име на управителя, представляващ дружеството-ЗАЕМАТЕЛ в договора за заем (по Търговския регистър).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'contract_city',
                'value' => 'Пловдив',
                'type' => 'string',
                'description' => 'Град на сключване на договора за заем („Днес, …, в гр. …“). По подразбиране градът по седалище (компетентният съд по чл. 5, ал. 4 е гр. Пловдив).',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', [
            'contract_company_name',
            'contract_company_eik',
            'contract_company_address',
            'contract_company_manager',
            'contract_city',
        ])->delete();
    }
};
