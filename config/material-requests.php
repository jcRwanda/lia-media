<?php

return [
    'allowed_origins' => array_filter(explode(',', (string) env('MATERIAL_REQUEST_ALLOWED_ORIGINS', ''))),
    'honey_pot_name' => env('MATERIAL_REQUEST_HONEYPOT', 'website_url'),
    'bilingual_terms' => [
        'kinyarwanda' => "Ndemera ko ibikoresho mumpaye bizakoreshwa gusa mu nyungu z'ubuhanzi no guteza imbere impano cyangwa umushinga. Niyemeje kubifata neza no kubikoresha mu buryo buboneye, kandi mu gihe hagize ikibazo kigaragara, nzakimenyesha ako kanya kandi nkagikemura nta mananiza.",
        'english' => 'I agree that the equipment entrusted to me will be used only for artistic purposes and to develop my talent or project. I commit to taking good care of it and using it properly, and if any issue arises, I will report it immediately and resolve it without delay.',
    ],
    'fields' => [
        'common_required' => [
            'full_name',
            'phone',
            'email',
            'emergency_contact_name',
            'emergency_contact_phone',
            'purpose_project_description',
            'needed_from',
            'return_by',
            'terms_accepted',
            'items',
        ],
        'address' => [
            'province_res',
            'district_res',
            'sector_res',
            'cell_res',
            'residential_address',
        ],
        'project_location' => [
            'project_province',
            'project_district',
            'project_sector',
            'project_cell',
            'project_location',
        ],
        'extra_for_trainee_alumni' => [
            'program_cohort',
            'graduation_completion_year',
            'student_alumni_id',
            'project_title',
            'project_type',
            'expected_outcome',
            'portfolio_social_link',
        ],
    ],
    'requester_types' => ['client', 'trainee', 'alumni'],
];
