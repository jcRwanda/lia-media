<?php

namespace App\Services;

use App\Models\Cohort;

class MaterialRequestFormSchemaService
{
    private const REQUESTER_TYPE_LABELS = [
        'client' => ['en' => 'Client', 'kn' => 'Umukiriya'],
        'trainee' => ['en' => 'Trainee', 'kn' => 'Umunyeshuri'],
        'alumni' => ['en' => 'Alumni', 'kn' => 'Uwahize amasomo'],
    ];

    private const SECTION_LABELS = [
        'personal' => ['en' => 'Personal details', 'kn' => 'Amakuru bwite'],
        'residential_address' => ['en' => 'Residential address', 'kn' => 'Aderesi yo guturamo'],
        'emergency_contact' => ['en' => 'Emergency contact', 'kn' => 'Umuntu wo guhamagara mu gihe gikomeye'],
        'request' => ['en' => 'Request', 'kn' => 'Ubusabe'],
        'project_location' => ['en' => 'Project location', 'kn' => 'Aho umushinga uzabera'],
        'programme' => ['en' => 'Programme', 'kn' => 'Porogaramu'],
        'declarations' => ['en' => 'Declarations', 'kn' => 'Ibyemezo'],
        'documents' => ['en' => 'Documents', 'kn' => 'Ibyangombwa'],
        'metadata' => ['en' => 'Source metadata', 'kn' => 'Amakuru y\'inkomoko'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function build(string $type): array
    {
        $labels = config('material-requests.labels', []);
        $cohortRequired = in_array($type, ['trainee', 'alumni'], true);
        $idRequired = in_array($type, ['client', 'alumni'], true);
        $cohorts = Cohort::query()
            ->orderByDesc('year')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'year'])
            ->map(fn (Cohort $cohort) => [
                'value' => $cohort->id,
                'label' => $cohort->name.($cohort->code ? ' ('.$cohort->code.')' : ''),
                'name' => $cohort->name,
                'code' => $cohort->code,
                'year' => $cohort->year,
            ])
            ->values()
            ->all();

        $fields = [
            $this->field('requester_type', 'select', true, 'personal', $labels, [
                'options' => $this->requesterTypeOptions(),
            ]),
            $this->field('full_name', 'text', true, 'personal', $labels, ['max_length' => 255]),
            $this->field('phone', 'tel', true, 'personal', $labels, ['max_length' => 50]),
            $this->field('email', 'email', true, 'personal', $labels, ['max_length' => 255]),
            $this->field('project_name', 'text', true, 'personal', $labels, ['max_length' => 255]),
            $this->field('national_id', 'text', false, 'personal', $labels, ['max_length' => 100]),

            $this->field('province_res', 'select', false, 'residential_address', $labels, $this->divisionRule('province')),
            $this->field('district_res', 'select', false, 'residential_address', $labels, $this->divisionRule('district', 'province_res')),
            $this->field('sector_res', 'select', false, 'residential_address', $labels, $this->divisionRule('sector', 'district_res')),
            $this->field('cell_res', 'select', false, 'residential_address', $labels, $this->divisionRule('cell', 'sector_res')),
            $this->field('village_res', 'text', false, 'residential_address', $labels, ['max_length' => 255]),
            $this->field('residential_address', 'text', false, 'residential_address', $labels, ['max_length' => 500]),

            $this->field('emergency_contact_name', 'text', true, 'emergency_contact', $labels, ['max_length' => 255]),
            $this->field('emergency_contact_phone', 'tel', true, 'emergency_contact', $labels, ['max_length' => 50]),
            $this->field('relationship', 'text', true, 'emergency_contact', $labels, ['max_length' => 255]),

            $this->field('purpose_project_description', 'textarea', true, 'request', $labels, [
                'aliases' => ['project_summary'],
            ]),
            $this->field('items', 'items', true, 'request', $labels, [
                'min_items' => 1,
                'catalog_url' => url('/api/v1/material-requests/catalog'),
            ]),
            $this->field('needed_from', 'date', true, 'request', $labels, ['aliases' => ['start_date']]),
            $this->field('return_by', 'date', true, 'request', $labels, ['aliases' => ['end_date']]),

            $this->field('project_same_as_residential', 'checkbox', false, 'project_location', $labels),
            $this->field('project_province', 'select', false, 'project_location', $labels, $this->divisionRule('province')),
            $this->field('project_district', 'select', false, 'project_location', $labels, $this->divisionRule('district', 'project_province')),
            $this->field('project_sector', 'select', false, 'project_location', $labels, $this->divisionRule('sector', 'project_district')),
            $this->field('project_cell', 'select', false, 'project_location', $labels, $this->divisionRule('cell', 'project_sector')),
            $this->field('project_location', 'text', false, 'project_location', $labels, ['max_length' => 500]),

            $this->field('cohort_id', 'select', $cohortRequired, 'programme', $labels, [
                'options' => $cohorts,
                'aliases' => ['cohort'],
            ]),
            $this->field('program_cohort', 'text', false, 'programme', $labels, ['max_length' => 255]),
            $this->field('graduation_completion_year', 'text', false, 'programme', $labels, ['max_length' => 10]),
            $this->field('student_alumni_id', 'text', false, 'programme', $labels, [
                'max_length' => 255,
                'aliases' => ['student_id'],
            ]),
            $this->field('project_title', 'text', false, 'programme', $labels, ['max_length' => 255]),
            $this->field('project_type', 'text', false, 'programme', $labels, ['max_length' => 255]),
            $this->field('expected_outcome', 'textarea', false, 'programme', $labels),
            $this->field('portfolio_social_link', 'url', false, 'programme', $labels, ['max_length' => 500]),

            $this->field('terms_accepted', 'checkbox', true, 'declarations', $labels),
            $this->field('consent_declaration', 'checkbox', true, 'declarations', $labels),
            $this->field('signature', 'signature', true, 'documents', $labels),
            $this->field('id_document', 'file', $idRequired, 'documents', $labels),

            $this->field('source_detail', 'text', false, 'metadata', $labels, ['max_length' => 255]),
        ];

        if (! in_array($type, ['trainee', 'alumni'], true)) {
            $fields = array_values(array_filter(
                $fields,
                fn (array $field) => ! in_array($field['key'], [
                    'graduation_completion_year',
                    'student_alumni_id',
                    'project_title',
                    'project_type',
                    'expected_outcome',
                    'portfolio_social_link',
                ], true),
            ));
        }

        return [
            'type' => $type,
            'requester_types' => $this->requesterTypeOptions(),
            'sections' => collect(self::SECTION_LABELS)
                ->map(fn (array $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'fields' => $fields,
            'labels' => $labels,
            'options' => [
                'cohorts' => $cohorts,
                'requester_types' => $this->requesterTypeOptions(),
                'rwanda_divisions_url' => asset('data/rwanda-divisions.json'),
            ],
            'terms' => config('material-requests.bilingual_terms'),
            'consent_declaration' => config('material-requests.consent_declaration'),
            'uploads' => [
                'id_document' => [
                    'required' => $idRequired,
                    'max_bytes' => (int) config('material-requests.id_document_max_bytes'),
                    'accept' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'application/pdf'],
                ],
                'signature' => [
                    'required' => true,
                    'max_bytes' => (int) config('material-requests.signature_max_bytes'),
                    'accept' => ['image/png'],
                ],
            ],
            'links' => [
                'catalog' => url('/api/v1/material-requests/catalog'),
                'availability' => url('/api/v1/material-requests/availability'),
                'submit' => url('/api/v1/material-requests/submit'),
                'openapi' => url('/api/v1/material-requests/openapi.json'),
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: array{en: string, kn: string}}>
     */
    private function requesterTypeOptions(): array
    {
        return collect(config('material-requests.requester_types', []))
            ->map(fn (string $type) => [
                'value' => $type,
                'label' => self::REQUESTER_TYPE_LABELS[$type] ?? ['en' => ucfirst($type), 'kn' => ucfirst($type)],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{en: string, kn: string}>  $labels
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function field(string $key, string $type, bool $required, string $section, array $labels, array $extra = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'required' => $required,
            'section' => $section,
            'label' => $labels[$key] ?? ['en' => $key, 'kn' => $key],
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function divisionRule(string $level, ?string $dependsOn = null): array
    {
        return [
            'options_source' => 'rwanda_divisions',
            'level' => $level,
            'depends_on' => $dependsOn,
        ];
    }
}
