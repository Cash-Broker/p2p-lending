<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Bulgarian default messages for the framework's validation rules. They are
    | UI copy, not an API contract: the Filament admin shows them under each
    | field, and the investor SPA renders `errors.<field>[0]` from a 422 as-is.
    | The attribute is always quoted after «Полето», so the sentence agrees
    | with the neuter noun whatever the field's own gender.
    |
    */

    'accepted' => 'Полето „:attribute“ трябва да бъде прието.',
    'accepted_if' => 'Полето „:attribute“ трябва да бъде прието, когато „:other“ е :value.',
    'active_url' => 'Полето „:attribute“ трябва да бъде валиден URL адрес.',
    'after' => 'Полето „:attribute“ трябва да бъде дата след :date.',
    'after_or_equal' => 'Полето „:attribute“ трябва да бъде дата не по-рано от :date.',
    'alpha' => 'Полето „:attribute“ може да съдържа само букви.',
    'alpha_dash' => 'Полето „:attribute“ може да съдържа само букви, цифри, тирета и долни черти.',
    'alpha_num' => 'Полето „:attribute“ може да съдържа само букви и цифри.',
    'any_of' => 'Полето „:attribute“ е невалидно.',
    'array' => 'Полето „:attribute“ трябва да бъде масив.',
    'array_keys' => 'Полето „:attribute“ може да съдържа само следните ключове: :values.',
    'ascii' => 'Полето „:attribute“ може да съдържа само латински букви, цифри и символи.',
    'base64' => 'Полето „:attribute“ трябва да бъде валиден Base64 низ.',
    'before' => 'Полето „:attribute“ трябва да бъде дата преди :date.',
    'before_or_equal' => 'Полето „:attribute“ трябва да бъде дата не по-късно от :date.',
    'between' => [
        'array' => 'Полето „:attribute“ трябва да съдържа между :min и :max елемента.',
        'file' => 'Файлът в полето „:attribute“ трябва да бъде между :min и :max килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде между :min и :max.',
        'string' => 'Полето „:attribute“ трябва да съдържа между :min и :max символа.',
    ],
    'boolean' => 'Полето „:attribute“ трябва да бъде „да“ или „не“.',
    'can' => 'Полето „:attribute“ съдържа неразрешена стойност.',
    'confirmed' => 'Потвърждението на полето „:attribute“ не съвпада.',
    'contains' => 'В полето „:attribute“ липсва задължителна стойност.',
    'current_password' => 'Паролата е грешна.',
    'date' => 'Полето „:attribute“ трябва да бъде валидна дата.',
    'date_equals' => 'Полето „:attribute“ трябва да бъде дата, равна на :date.',
    'date_format' => 'Полето „:attribute“ трябва да е във формат :format.',
    'decimal' => 'Полето „:attribute“ трябва да има :decimal знака след десетичната запетая.',
    'declined' => 'Полето „:attribute“ трябва да бъде отказано.',
    'declined_if' => 'Полето „:attribute“ трябва да бъде отказано, когато „:other“ е :value.',
    'different' => 'Полетата „:attribute“ и „:other“ трябва да са различни.',
    'digits' => 'Полето „:attribute“ трябва да съдържа точно :digits цифри.',
    'digits_between' => 'Полето „:attribute“ трябва да съдържа между :min и :max цифри.',
    'dimensions' => 'Изображението в полето „:attribute“ е с невалидни размери.',
    'distinct' => 'Полето „:attribute“ съдържа повтаряща се стойност.',
    'doesnt_contain' => 'Полето „:attribute“ не трябва да съдържа нито едно от следните: :values.',
    'doesnt_end_with' => 'Полето „:attribute“ не трябва да завършва с нито едно от следните: :values.',
    'doesnt_start_with' => 'Полето „:attribute“ не трябва да започва с нито едно от следните: :values.',
    'email' => 'Полето „:attribute“ трябва да бъде валиден имейл адрес.',
    'encoding' => 'Полето „:attribute“ трябва да е в кодировка :encoding.',
    'ends_with' => 'Полето „:attribute“ трябва да завършва с едно от следните: :values.',
    'enum' => 'Избраната стойност за „:attribute“ е невалидна.',
    'exists' => 'Избраната стойност за „:attribute“ е невалидна.',
    'extensions' => 'Полето „:attribute“ трябва да е файл с едно от следните разширения: :values.',
    'file' => 'Полето „:attribute“ трябва да бъде файл.',
    'filled' => 'Полето „:attribute“ трябва да има стойност.',
    'gt' => [
        'array' => 'Полето „:attribute“ трябва да съдържа повече от :value елемента.',
        'file' => 'Файлът в полето „:attribute“ трябва да бъде по-голям от :value килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде по-голямо от :value.',
        'string' => 'Полето „:attribute“ трябва да съдържа повече от :value символа.',
    ],
    'gte' => [
        'array' => 'Полето „:attribute“ трябва да съдържа :value или повече елемента.',
        'file' => 'Файлът в полето „:attribute“ трябва да бъде поне :value килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде по-голямо или равно на :value.',
        'string' => 'Полето „:attribute“ трябва да съдържа поне :value символа.',
    ],
    'hex_color' => 'Полето „:attribute“ трябва да бъде валиден шестнадесетичен цвят.',
    'image' => 'Полето „:attribute“ трябва да бъде изображение.',
    'in' => 'Избраната стойност за „:attribute“ е невалидна.',
    'in_array' => 'Полето „:attribute“ трябва да съществува в „:other“.',
    'in_array_keys' => 'Полето „:attribute“ трябва да съдържа поне един от следните ключове: :values.',
    'integer' => 'Полето „:attribute“ трябва да бъде цяло число.',
    'ip' => 'Полето „:attribute“ трябва да бъде валиден IP адрес.',
    'ipv4' => 'Полето „:attribute“ трябва да бъде валиден IPv4 адрес.',
    'ipv6' => 'Полето „:attribute“ трябва да бъде валиден IPv6 адрес.',
    'json' => 'Полето „:attribute“ трябва да бъде валиден JSON низ.',
    'list' => 'Полето „:attribute“ трябва да бъде списък.',
    'lowercase' => 'Полето „:attribute“ трябва да е с малки букви.',
    'lt' => [
        'array' => 'Полето „:attribute“ трябва да съдържа по-малко от :value елемента.',
        'file' => 'Файлът в полето „:attribute“ трябва да бъде по-малък от :value килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде по-малко от :value.',
        'string' => 'Полето „:attribute“ трябва да съдържа по-малко от :value символа.',
    ],
    'lte' => [
        'array' => 'Полето „:attribute“ не може да съдържа повече от :value елемента.',
        'file' => 'Файлът в полето „:attribute“ не може да бъде по-голям от :value килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде по-малко или равно на :value.',
        'string' => 'Полето „:attribute“ не може да съдържа повече от :value символа.',
    ],
    'mac_address' => 'Полето „:attribute“ трябва да бъде валиден MAC адрес.',
    'max' => [
        'array' => 'Полето „:attribute“ не може да съдържа повече от :max елемента.',
        'file' => 'Файлът в полето „:attribute“ не може да бъде по-голям от :max килобайта.',
        'numeric' => 'Полето „:attribute“ не може да бъде по-голямо от :max.',
        'string' => 'Полето „:attribute“ не може да съдържа повече от :max символа.',
    ],
    'max_digits' => 'Полето „:attribute“ не може да съдържа повече от :max цифри.',
    'mimes' => 'Полето „:attribute“ трябва да бъде файл от тип: :values.',
    'mimetypes' => 'Полето „:attribute“ трябва да бъде файл от тип: :values.',
    'min' => [
        'array' => 'Полето „:attribute“ трябва да съдържа поне :min елемента.',
        'file' => 'Файлът в полето „:attribute“ трябва да бъде поне :min килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде поне :min.',
        'string' => 'Полето „:attribute“ трябва да съдържа поне :min символа.',
    ],
    'min_digits' => 'Полето „:attribute“ трябва да съдържа поне :min цифри.',
    'missing' => 'Полето „:attribute“ не трябва да присъства.',
    'missing_if' => 'Полето „:attribute“ не трябва да присъства, когато „:other“ е :value.',
    'missing_unless' => 'Полето „:attribute“ не трябва да присъства, освен ако „:other“ е :value.',
    'missing_with' => 'Полето „:attribute“ не трябва да присъства, когато присъства :values.',
    'missing_with_all' => 'Полето „:attribute“ не трябва да присъства, когато присъстват :values.',
    'multiple_of' => 'Полето „:attribute“ трябва да бъде кратно на :value.',
    'not_in' => 'Избраната стойност за „:attribute“ е невалидна.',
    'not_regex' => 'Форматът на полето „:attribute“ е невалиден.',
    'numeric' => 'Полето „:attribute“ трябва да бъде число.',
    'password' => [
        'letters' => 'Полето „:attribute“ трябва да съдържа поне една буква.',
        'mixed' => 'Полето „:attribute“ трябва да съдържа поне една главна и една малка буква.',
        'numbers' => 'Полето „:attribute“ трябва да съдържа поне една цифра.',
        'symbols' => 'Полето „:attribute“ трябва да съдържа поне един символ.',
        'uncompromised' => 'Стойността на полето „:attribute“ е открита в изтекли при пробив данни. Моля, изберете друга.',
    ],
    'present' => 'Полето „:attribute“ трябва да присъства.',
    'present_if' => 'Полето „:attribute“ трябва да присъства, когато „:other“ е :value.',
    'present_unless' => 'Полето „:attribute“ трябва да присъства, освен ако „:other“ е :value.',
    'present_with' => 'Полето „:attribute“ трябва да присъства, когато присъства :values.',
    'present_with_all' => 'Полето „:attribute“ трябва да присъства, когато присъстват :values.',
    'prohibited' => 'Полето „:attribute“ не е позволено.',
    'prohibited_if' => 'Полето „:attribute“ не е позволено, когато „:other“ е :value.',
    'prohibited_if_accepted' => 'Полето „:attribute“ не е позволено, когато „:other“ е прието.',
    'prohibited_if_declined' => 'Полето „:attribute“ не е позволено, когато „:other“ е отказано.',
    'prohibited_unless' => 'Полето „:attribute“ не е позволено, освен ако „:other“ е сред :values.',
    'prohibits' => 'Полето „:attribute“ не позволява „:other“ да присъства.',
    'regex' => 'Форматът на полето „:attribute“ е невалиден.',
    'required' => 'Полето „:attribute“ е задължително.',
    'required_array_keys' => 'Полето „:attribute“ трябва да съдържа стойности за: :values.',
    'required_if' => 'Полето „:attribute“ е задължително, когато „:other“ е :value.',
    'required_if_accepted' => 'Полето „:attribute“ е задължително, когато „:other“ е прието.',
    'required_if_declined' => 'Полето „:attribute“ е задължително, когато „:other“ е отказано.',
    'required_unless' => 'Полето „:attribute“ е задължително, освен ако „:other“ е сред :values.',
    'required_with' => 'Полето „:attribute“ е задължително, когато е попълнено :values.',
    'required_with_all' => 'Полето „:attribute“ е задължително, когато са попълнени :values.',
    'required_without' => 'Полето „:attribute“ е задължително, когато не е попълнено :values.',
    'required_without_all' => 'Полето „:attribute“ е задължително, когато не е попълнено нито едно от :values.',
    'same' => 'Полето „:attribute“ трябва да съвпада с „:other“.',
    'size' => [
        'array' => 'Полето „:attribute“ трябва да съдържа :size елемента.',
        'file' => 'Файлът в полето „:attribute“ трябва да бъде :size килобайта.',
        'numeric' => 'Полето „:attribute“ трябва да бъде :size.',
        'string' => 'Полето „:attribute“ трябва да съдържа точно :size символа.',
    ],
    'starts_with' => 'Полето „:attribute“ трябва да започва с едно от следните: :values.',
    'string' => 'Полето „:attribute“ трябва да бъде текст.',
    'timezone' => 'Полето „:attribute“ трябва да бъде валидна часова зона.',
    'unique' => 'Стойността на полето „:attribute“ вече се използва.',
    'uploaded' => 'Качването на файла в полето „:attribute“ не бе успешно.',
    'uppercase' => 'Полето „:attribute“ трябва да е с главни букви.',
    'url' => 'Полето „:attribute“ трябва да бъде валиден URL адрес.',
    'ulid' => 'Полето „:attribute“ трябва да бъде валиден ULID.',
    'uuid' => 'Полето „:attribute“ трябва да бъде валиден UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | Display names for the API field keys the investor SPA renders errors for
    | — without them a message would read «Полето „first name“ …». Filament
    | never reaches this list: it passes each field's own label as the
    | attribute, and an inline attribute wins over these translations.
    | Lower-case first letter, because the name sits mid-sentence (the same
    | thing Filament does with its labels).
    |
    */

    'attributes' => [
        'email' => 'имейл',
        'password' => 'парола',
        'current_password' => 'текуща парола',
        'name' => 'име',
        'first_name' => 'име на контактното лице',
        'last_name' => 'фамилия на контактното лице',
        'phone' => 'телефон',
        'legal_name' => 'име на фирмата',
        'eik' => 'ЕИК',
        'vat_number' => 'ДДС номер',
        'legal_form' => 'правна форма',
        'company_email' => 'имейл на фирмата',
        'company_phone' => 'телефон на фирмата',
        'address_street' => 'адрес (улица, №)',
        'address_city' => 'град',
        'address_postcode' => 'пощенски код',
        'address_country' => 'държава',
        'terms_accepted' => 'условия за ползване',
        'amount' => 'сума',
        'iban' => 'IBAN',
        'saved_iban_id' => 'IBAN',
        'loan_offer_id' => 'оферта',
        'expected_interest_rate' => 'лихвен процент',
        'document_front' => 'лицева страна на документа',
        'document_back' => 'гръб на документа',
        'selfie' => 'селфи',
        'biometric_consent' => 'съгласие за биометрични данни',
    ],

];
