<?php

return [
    'allowed_origins' => array_filter(explode(',', (string) env('MATERIAL_REQUEST_ALLOWED_ORIGINS', ''))),
    'honey_pot_name' => env('MATERIAL_REQUEST_HONEYPOT', 'website_url'),

    // Signed prefill links and returning-requester OTP challenges.
    'prefill_ttl' => (int) env('MATERIAL_REQUEST_PREFILL_TTL', 900),
    'otp_ttl' => (int) env('MATERIAL_REQUEST_OTP_TTL', 600),
    'otp_max_attempts' => 5,
    'id_document_max_bytes' => (int) env('MATERIAL_REQUEST_ID_MAX_BYTES', 10485760),
    'signature_max_bytes' => 2097152,

    'requester_types' => ['client', 'trainee', 'alumni'],

    'bilingual_terms' => [
        'kinyarwanda' => "Ndemera ko ibikoresho mumpaye bizakoreshwa gusa mu nyungu z'ubuhanzi no guteza imbere impano cyangwa umushinga. Niyemeje kubifata neza no kubikoresha mu buryo buboneye, kandi mu gihe hagize ikibazo kigaragara, nzakimenyesha ako kanya kandi nkagikemura nta mananiza. Niyemeje kubahiriza amabwiriza yose y'ikoreshwa ry'ibikoresho no kwerekana ko Love In Arts yagize uruhare mu mushinga wanjye (credit).",
        'english' => 'I agree that the equipment entrusted to me will be used only for artistic purposes and to develop my talent or project. I commit to taking good care of it and using it properly, and if any issue arises, I will report it immediately and resolve it without delay. I will follow all equipment-use rules and credit Love In Arts for its support of my project.',
    ],

    'consent_declaration' => [
        'kinyarwanda' => "Ndemeza ko amakuru natanze ari ukuri kandi nemeye ko LIA ishobora kubika no gusuzuma ibyangombwa byanjye mu buryo bw'ibanga ku rwego rw'abakozi bayo gusa.",
        'english' => 'I declare that the information I provided is true and I consent to LIA storing and reviewing my documents privately, accessible to LIA staff only.',
    ],

    // The exact question list, in order. Special widgets are identified by type.
    'fields' => [
        'common_required' => [
            'full_name',
            'phone',
            'email',
            'project_name',
            'emergency_contact_name',
            'emergency_contact_phone',
            'relationship',
            'project_summary',
            'needed_from',
            'return_by',
            'terms_accepted',
            'consent_declaration',
            'signature',
        ],
        'address' => [
            'province_res',
            'district_res',
            'sector_res',
            'cell_res',
            'village_res',
        ],
        'project_location' => [
            'project_province',
            'project_district',
            'project_sector',
            'project_cell',
        ],
        'extra_for_trainee_alumni' => [
            'cohort_id',
        ],
        'uploads' => [
            'id_document',
        ],
    ],

    'labels' => [
        'requester_type' => ['kn' => "Ubwoko bw'usaba", 'en' => 'Requester Type'],
        'source_detail' => ['kn' => 'Aho ubusabe bukomoka', 'en' => 'Source Detail'],
        'full_name' => ['kn' => 'Amazina yose', 'en' => 'Full Names'],
        'phone' => ['kn' => 'Numero ya telefoni', 'en' => 'Phone Number'],
        'email' => ['kn' => 'Imeli', 'en' => 'Email'],
        'project_name' => ['kn' => "Izina ry'umushinga", 'en' => 'Project Name'],
        'residential_address' => ['kn' => 'Aderesi yo guturamo', 'en' => 'Residential Address'],
        'province_res' => ['kn' => 'Intara', 'en' => 'Province'],
        'district_res' => ['kn' => 'Akarere', 'en' => 'District'],
        'sector_res' => ['kn' => 'Umurenge', 'en' => 'Sector'],
        'cell_res' => ['kn' => 'Akagari', 'en' => 'Cell'],
        'village_res' => ['kn' => 'Umudugudu', 'en' => 'Village'],
        'emergency_contact_name' => ['kn' => "Izina ry'umuntu wo guhamagara mu gihe gikomeye", 'en' => 'Emergency Contact Name'],
        'emergency_contact_phone' => ['kn' => "Telefoni y'umuntu wo guhamagara mu gihe gikomeye", 'en' => 'Emergency Contact Phone'],
        'relationship' => ['kn' => 'Isano', 'en' => 'Relationship'],
        'project_summary' => ['kn' => "Incamake y'umushinga", 'en' => 'Project Summary'],
        'items' => ['kn' => 'Ibikoresho usaba', 'en' => 'Equipment Requested'],
        'needed_from' => ['kn' => 'Itariki yo gutangira (gufata ibikoresho)', 'en' => 'Start Date (Pick-up)'],
        'return_by' => ['kn' => 'Itariki yo kurangiza (gusubiza ibikoresho)', 'en' => 'End Date (Return)'],
        'project_location' => ['kn' => 'Aho umushinga uzabera', 'en' => 'Project Location'],
        'project_same_as_residential' => ['kn' => "Bimwe n'aderesi yo guturamo", 'en' => 'Same as residential'],
        'project_province' => ['kn' => 'Intara', 'en' => 'Province'],
        'project_district' => ['kn' => 'Akarere', 'en' => 'District'],
        'project_sector' => ['kn' => 'Umurenge', 'en' => 'Sector'],
        'project_cell' => ['kn' => 'Akagari', 'en' => 'Cell'],
        'cohort_id' => ['kn' => 'Hitamo icyiciro', 'en' => 'Select Cohort'],
        'terms_accepted' => ['kn' => "Amasezerano y'ikoreshwa ry'ibikoresho no kwerekana uruhare rwa Love In Arts", 'en' => 'Terms & Credit agreement'],
        'consent_declaration' => ['kn' => 'Kwemera no kwitangaza', 'en' => 'Consent & Declaration'],
        'id_document' => ['kn' => 'Ohereza indangamuntu yawe cyangwa pasiporo', 'en' => 'Upload Your ID'],
        'signature' => ['kn' => "Umukono n'itariki", 'en' => 'Signature & Date'],
    ],
];
