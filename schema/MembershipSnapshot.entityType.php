<?php
use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Period read-model: one row per membership per period, with category and status
 * captured as of the period. Derived by replaying MembershipEvent (or read from the
 * membership log during backfill); demographics resolved via MembershipContactDimension.
 */
return [
  'name' => 'MembershipSnapshot',
  'table' => 'civicrm_membership_snapshot',
  'class' => 'CRM_Membershipanalytics_DAO_MembershipSnapshot',
  'getInfo' => fn() => [
    'title' => E::ts('Membership Snapshot'),
    'title_plural' => E::ts('Membership Snapshots'),
    'description' => E::ts('Per-membership, per-period state (category/status as of the period) for analytics.'),
    'log' => TRUE,
  ],
  'getFields' => function () {
    $fields = [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique MembershipSnapshot ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'membership_id' => [
      'title' => E::ts('Membership ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Membership; nullable so history survives membership deletion'),
      'entity_reference' => [
        'entity' => 'Membership',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Contact (denormalized); nullable so history survives contact deletion'),
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'membership_type_id' => [
      'title' => E::ts('Membership Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Category as of the period'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'status_id' => [
      'title' => E::ts('Status'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Status as of the period'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_status',
        'key_column' => 'id',
        'label_column' => 'label',
      ],
    ],
    'status_bucket' => [
      'title' => E::ts('Status Bucket'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Classified lifecycle bucket (new/current/grace/pending/dropped/archived/cancelled/deceased/lapsed)'),
      'pseudoconstant' => [
        'option_group_name' => 'membership_status_bucket',
      ],
    ],
    'counts_as_member' => [
      'title' => E::ts('Counts As Member'),
      'sql_type' => 'tinyint',
      'input_type' => 'CheckBox',
      'description' => E::ts('Whether this row counts toward membership per the counting setting'),
    ],
    'period' => [
      'title' => E::ts('Period'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Period unit (month/week)'),
      'pseudoconstant' => [
        'option_group_name' => 'membershipanalytics_period',
      ],
    ],
    'period_start' => [
      'title' => E::ts('Period Start'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('First day of the period'),
    ],
    'period_end' => [
      'title' => E::ts('Period End'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('Last day of the period; the as-of instant'),
    ],
    'fiscal_year' => [
      'title' => E::ts('Fiscal Year'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Fiscal year containing period_end'),
    ],
    'calendar_year' => [
      'title' => E::ts('Calendar Year'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Calendar year containing period_end'),
    ],
    'join_date' => [
      'title' => E::ts('Join Date'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('Membership join date (for joined-in-year / new detection)'),
    ],
    'start_date' => [
      'title' => E::ts('Start Date'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('Membership term start as of the period'),
    ],
    'end_date' => [
      'title' => E::ts('End Date'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('Membership term end as of the period'),
    ],
    'movement' => [
      'title' => E::ts('Movement'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Movement vs. prior period (new/continued/renewed/reactivated/reinstated/transferred_in/transferred_out/dropped/archived)'),
      'pseudoconstant' => [
        'option_group_name' => 'membership_movement',
      ],
    ],
    'movement_from_type_id' => [
      'title' => E::ts('Movement From Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('For transfers: category moved from'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'movement_to_type_id' => [
      'title' => E::ts('Movement To Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('For transfers: category moved to'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    // Demographic dimensions stamped per period (Option B: config-driven columns, driven by the
    // membershipanalytics_historized_dimensions setting). These core columns cover the default
    // dimensions; a site adds more via the dimension manager. Gender is stable, so a current-value
    // stamp is accurate; geography is the contact's primary address (as-of-from-audit-logs is a
    // later refinement). Stamped by MembershipSnapshot.stampDimensions.
    'gender_id' => [
      'title' => E::ts('Gender'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Contact gender as of the period'),
      'pseudoconstant' => [
        'option_group_name' => 'gender',
      ],
    ],
    'country_id' => [
      'title' => E::ts('Country'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Primary address country as of the period'),
      'pseudoconstant' => [
        'table' => 'civicrm_country',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'state_province_id' => [
      'title' => E::ts('State/Province'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Primary address state/province as of the period'),
      'pseudoconstant' => [
        'table' => 'civicrm_state_province',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'city' => [
      'title' => E::ts('City'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => E::ts('Primary address city as of the period'),
    ],
    ];
    // Expose any dimension columns added at runtime (is_fellow and anything configured through the
    // UI dimension manager) so SearchKit and APIv4 can select, group and filter on them too. These
    // are not in the static list above because they are added by MembershipSnapshot.syncColumns.
    foreach (CRM_Membershipanalytics_Config::snapshotDimensionFields(array_keys($fields)) as $name => $def) {
      $fields[$name] = $def;
    }
    return $fields;
  },
  'getIndices' => fn() => [
    'index_period_end' => [
      'fields' => ['period_end' => TRUE],
      'unique' => FALSE,
    ],
    'index_type_period' => [
      'fields' => ['membership_type_id' => TRUE, 'period_end' => TRUE],
      'unique' => FALSE,
    ],
    'index_bucket_period' => [
      'fields' => ['status_bucket' => TRUE, 'period_end' => TRUE],
      'unique' => FALSE,
    ],
    'index_contact_period' => [
      'fields' => ['contact_id' => TRUE, 'period_end' => TRUE],
      'unique' => FALSE,
    ],
    'index_membership_period' => [
      'fields' => ['membership_id' => TRUE, 'period_end' => TRUE],
      'unique' => FALSE,
    ],
    'index_fiscal_year' => [
      'fields' => ['fiscal_year' => TRUE],
      'unique' => FALSE,
    ],
    'index_calendar_year' => [
      'fields' => ['calendar_year' => TRUE],
      'unique' => FALSE,
    ],
    'index_gender_fiscal_year' => [
      'fields' => ['gender_id' => TRUE, 'fiscal_year' => TRUE],
      'unique' => FALSE,
    ],
  ],
  'getPaths' => fn() => [],
];
