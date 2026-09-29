<?php
use CRM_Membershipanalytics_ExtensionUtil as E;

/**
 * Append-only log of meaningful membership changes, derived from
 * civicrm_membership_log (backfill) and captured live going forward.
 * This is the source of truth; the snapshot (period read-model) is built from it.
 */
return [
  'name' => 'MembershipEvent',
  'table' => 'civicrm_membership_event',
  'class' => 'CRM_Membershipanalytics_DAO_MembershipEvent',
  'getInfo' => fn() => [
    'title' => E::ts('Membership Event'),
    'title_plural' => E::ts('Membership Events'),
    'description' => E::ts('One row per meaningful membership change (status/type change, join, drop, archive, reinstate, reactivate).'),
    'log' => TRUE,
  ],
  'getFields' => fn() => [
    'id' => [
      'title' => E::ts('ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Number',
      'required' => TRUE,
      'description' => E::ts('Unique MembershipEvent ID'),
      'primary_key' => TRUE,
      'auto_increment' => TRUE,
    ],
    'membership_id' => [
      'title' => E::ts('Membership ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('FK to Membership; nullable so events survive membership deletion'),
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
      'description' => E::ts('FK to Contact (denormalized); nullable so events survive contact deletion'),
      'entity_reference' => [
        'entity' => 'Contact',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'event_type' => [
      'title' => E::ts('Event Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Classified change: joined / status_change / type_change / renewed / continued / reactivated / reinstated / dropped / archived'),
      'pseudoconstant' => [
        'option_group_name' => 'membership_event_type',
      ],
    ],
    'from_status_id' => [
      'title' => E::ts('From Status'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Membership status before the change'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_status',
        'key_column' => 'id',
        'label_column' => 'label',
      ],
    ],
    'to_status_id' => [
      'title' => E::ts('To Status'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Membership status after the change'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_status',
        'key_column' => 'id',
        'label_column' => 'label',
      ],
    ],
    'from_type_id' => [
      'title' => E::ts('From Membership Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Membership type before a transfer'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'to_type_id' => [
      'title' => E::ts('To Membership Type'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('Membership type after a transfer'),
      'pseudoconstant' => [
        'table' => 'civicrm_membership_type',
        'key_column' => 'id',
        'label_column' => 'name',
      ],
    ],
    'effective_date' => [
      'title' => E::ts('Effective Date'),
      'sql_type' => 'datetime',
      'input_type' => 'Select Date',
      'description' => E::ts('When the change occurred (log modified_date, or the activity date for reinstate/reactivate)'),
    ],
    'fiscal_year' => [
      'title' => E::ts('Fiscal Year'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Fiscal year (fiscalYearStart basis) containing effective_date'),
    ],
    'calendar_year' => [
      'title' => E::ts('Calendar Year'),
      'sql_type' => 'int',
      'input_type' => 'Number',
      'description' => E::ts('Calendar year containing effective_date'),
    ],
    'contribution_id' => [
      'title' => E::ts('Contribution ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('Payment for payment-driven events (renewal / reactivate / reinstate)'),
      'entity_reference' => [
        'entity' => 'Contribution',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'activity_id' => [
      'title' => E::ts('Activity ID'),
      'sql_type' => 'int unsigned',
      'input_type' => 'EntityRef',
      'description' => E::ts('Link to the Membership Reinstated / Reactivated activity where applicable'),
      'entity_reference' => [
        'entity' => 'Activity',
        'key' => 'id',
        'on_delete' => 'SET NULL',
      ],
    ],
    'source' => [
      'title' => E::ts('Source'),
      'sql_type' => 'int unsigned',
      'input_type' => 'Select',
      'description' => E::ts('How this row was produced: log_backfill / audit_backfill / hook / order_complete / activity'),
      'pseudoconstant' => [
        'option_group_name' => 'membershipanalytics_source',
      ],
    ],
    'created_date' => [
      'title' => E::ts('Created Date'),
      'sql_type' => 'timestamp',
      'input_type' => 'Select Date',
      'description' => E::ts('When this event row was recorded'),
      'default' => 'CURRENT_TIMESTAMP',
    ],
  ],
  'getIndices' => fn() => [
    'index_membership_effective' => [
      'fields' => ['membership_id' => TRUE, 'effective_date' => TRUE],
      'unique' => FALSE,
    ],
    'index_contact_effective' => [
      'fields' => ['contact_id' => TRUE, 'effective_date' => TRUE],
      'unique' => FALSE,
    ],
    'index_eventtype_effective' => [
      'fields' => ['event_type' => TRUE, 'effective_date' => TRUE],
      'unique' => FALSE,
    ],
    'index_fiscal_year' => [
      'fields' => ['fiscal_year' => TRUE],
      'unique' => FALSE,
    ],
    'index_totype_effective' => [
      'fields' => ['to_type_id' => TRUE, 'effective_date' => TRUE],
      'unique' => FALSE,
    ],
  ],
  'getPaths' => fn() => [],
];
