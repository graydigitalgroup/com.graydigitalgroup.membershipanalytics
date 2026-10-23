<?php
use CRM_Membershipanalytics_ExtensionUtil as E;

// The set of contact dimensions stamped onto each membership snapshot is UI-managed through this
// option group: one active option value per dimension, where the value is the field key (a contact
// column, or a custom field as "custom_<id>" or "<CustomGroupName>.<FieldName>"). An admin adds or
// removes a dimension here (no code); Config::activeDimensions() resolves each key's column/source
// from field metadata, then MembershipSnapshot.syncColumns + .stampDimensions add and populate it.
//
// Seeded with the core demographic dimensions to match the built-in defaults. update => 'unmodified'
// so the live list (activations, additions, removals) is preserved and never overwritten.
return [
  [
    'name' => 'OptionGroup_membershipanalytics_dimensions',
    'entity' => 'OptionGroup',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'membershipanalytics_dimensions',
        'title' => E::ts('Membership Snapshot Dimensions'),
        'description' => E::ts('Contact dimensions stamped onto each membership snapshot period. Value = the field key.'),
        'data_type' => 'String',
        'is_active' => TRUE,
        'is_reserved' => FALSE,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name' => 'OptionValue_dimension_gender',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => ['option_group_id.name' => 'membershipanalytics_dimensions', 'name' => 'gender', 'label' => E::ts('Gender'), 'value' => 'gender', 'weight' => 1, 'is_active' => TRUE],
      'match' => ['option_group_id', 'name'],
    ],
  ],
  [
    'name' => 'OptionValue_dimension_country',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => ['option_group_id.name' => 'membershipanalytics_dimensions', 'name' => 'country', 'label' => E::ts('Country'), 'value' => 'country', 'weight' => 2, 'is_active' => TRUE],
      'match' => ['option_group_id', 'name'],
    ],
  ],
  [
    'name' => 'OptionValue_dimension_state',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => ['option_group_id.name' => 'membershipanalytics_dimensions', 'name' => 'state', 'label' => E::ts('State/Province'), 'value' => 'state', 'weight' => 3, 'is_active' => TRUE],
      'match' => ['option_group_id', 'name'],
    ],
  ],
  [
    'name' => 'OptionValue_dimension_city',
    'entity' => 'OptionValue',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => ['option_group_id.name' => 'membershipanalytics_dimensions', 'name' => 'city', 'label' => E::ts('City'), 'value' => 'city', 'weight' => 4, 'is_active' => TRUE],
      'match' => ['option_group_id', 'name'],
    ],
  ],
];
