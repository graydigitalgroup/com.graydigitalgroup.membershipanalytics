<?php
use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Slowly-changing-dimension table: one row per contact per historized field per value
 * interval. Built once via interval-fill from the audit logs, appended live by a hook.
 * Snapshots/events resolve as-of demographics by joining on date.
 */
return [
  'name' => 'MembershipContactDimension',
  'table' => 'civicrm_membership_contact_dimension',
  'class' => 'CRM_Membershipanalytics_DAO_MembershipContactDimension',
  'getInfo' => fn() => [
    'title' => E::ts('Membership Contact Dimension'),
    'title_plural' => E::ts('Membership Contact Dimensions'),
    'description' => E::ts('As-of history of curated contact reporting dimensions (gender, geography, and site custom fields) as value intervals.'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique MembershipContactDimension ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'contact_id' => [
      'title' => E::ts('Contact ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'required' => TRUE,
      'description' => E::ts('FK to Contact'),
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'CASCADE',
      ],
    ],
    'dimension' => [
      'title' => E::ts('Dimension'),
      'sql_type' => 'varchar(64)',
      'input_type' => 'Text',
      'description' => E::ts('Field key being historized (e.g. gender, country, state, city, or a site custom field)'),
    ],
    'value_int' => [
      'title' => E::ts('Value (int)'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Value for this interval when the dimension is numeric/option (e.g. gender_id, or a boolean custom flag)'),
    ],
    'value_string' => [
      'title' => E::ts('Value (string)'),
      'sql_type' => 'varchar(255)',
      'input_type' => 'Text',
      'description' => E::ts('Value for this interval when the dimension is textual (e.g. country/state/city)'),
    ],
    'valid_from' => [
      'title' => E::ts('Valid From'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('Start of the interval this value was in effect'),
    ],
    'valid_to' => [
      'title' => E::ts('Valid To'),
      'sql_type' => 'date',
      'input_type' => 'Select Date',
      'description' => E::ts('End of the interval; NULL means current'),
    ],
    'source' => [
      'title' => E::ts('Source'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('How this interval was produced: audit_backfill / hook'),
      'pseudoconstant' => [
        'option_group_name' => 'membershipanalytics_source',
      ],
    ],
  ],
  'getIndices' => fn() => [
    'index_contact_dimension_from' => [
      'fields' => ['contact_id' => TRUE, 'dimension' => TRUE, 'valid_from' => TRUE],
      'unique' => FALSE,
    ],
    'index_dimension_value' => [
      'fields' => ['dimension' => TRUE, 'value_int' => TRUE],
      'unique' => FALSE,
    ],
  ],
  'getPaths' => fn() => [],
];
