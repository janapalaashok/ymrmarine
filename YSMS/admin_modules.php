<?php
/**
 * Admin Controls - Master Data Module Registry
 * -----------------------------------------------------------------------
 * Every master-data module managed from admin_controls.php (Ports,
 * Surveyors, Clients, Survey Types, ...) is described here as plain
 * config. Both admin_controls.php (UI) and ajax/admin_master.php
 * (add / edit / delete / list) read from this single file.
 *
 * To add a future module (Companies, Agents, Suppliers, Survey
 * Categories, Locations, Status Types, etc.) you only need to add a new
 * entry to the array below - no other code changes are required for the
 * basic Add / Edit / Delete / Search / list behaviour.
 *
 * Module keys:
 *   label        Plural display label shown as the tab / section title
 *   singular     Singular label used in messages ("Port added")
 *   icon         FontAwesome icon class for the tab
 *   table        Physical DB table
 *   id_field     Primary key column
 *   name_field   Main column used for search + duplicate checking
 *   where        Optional extra SQL WHERE condition (without "WHERE")
 *   order_by     Optional ORDER BY column (defaults to name_field)
 *   fields       Ordered list of editable fields shown in the Add/Edit form
 *                  name      column name
 *                  label     field label
 *                  type      text | password | select
 *                  required  bool
 *                  options   for type=select
 *   insert_extra Extra fixed columns forced on INSERT (e.g. role_id => 2)
 *   fk_checks    List of ['table' => ..., 'column' => ...] checked before
 *                delete is allowed, so a record in use can't be removed.
 */

return [
    'ports' => [
        'label'      => 'Ports',
        'singular'   => 'Port',
        'icon'       => 'fa-anchor',
        'table'      => 'ports',
        'id_field'   => 'id',
        'name_field' => 'port_name',
        'where'      => '',
        'order_by'   => 'port_name',
        'fields'     => [
            ['name' => 'port_name', 'label' => 'Port Name', 'type' => 'text', 'required' => true],
        ],
        'fk_checks'  => [
            ['table' => 'surveys', 'column' => 'port_id'],
        ],
    ],

    'clients' => [
        'label'      => 'Clients',
        'singular'   => 'Client',
        'icon'       => 'fa-building',
        'table'      => 'clients',
        'id_field'   => 'id',
        'name_field' => 'company_name',
        'where'      => '',
        'order_by'   => 'company_name',
        'fields'     => [
            ['name' => 'company_name',   'label' => 'Company Name',                         'type' => 'text', 'required' => true],
            ['name' => 'contact_person', 'label' => 'Contact Person',                       'type' => 'text', 'required' => false],
            ['name' => 'address_line1',  'label' => 'Client Address Line 1 (Door no, street)', 'type' => 'text', 'required' => false],
            ['name' => 'address_line2',  'label' => 'Client Address Line 2 (City, country)',   'type' => 'text', 'required' => false],
        ],
        'fk_checks'  => [
            ['table' => 'surveys', 'column' => 'client_id'],
        ],
    ],

    'survey_types' => [
        'label'      => 'Survey Types',
        'singular'   => 'Survey Type',
        'icon'       => 'fa-clipboard-list',
        'table'      => 'survey_types',
        'id_field'   => 'id',
        'name_field' => 'type_name',
        'where'      => '',
        'order_by'   => 'type_name',
        'fields'     => [
            ['name' => 'type_name', 'label' => 'Survey Type Name', 'type' => 'text', 'required' => true],
        ],
        'fk_checks'  => [
            ['table' => 'surveys', 'column' => 'survey_type_id'],
            ['table' => 'survey_survey_types', 'column' => 'survey_type_id'],
        ],
    ],

    'surveyors' => [
        'label'      => 'Surveyors',
        'singular'   => 'Surveyor',
        'icon'       => 'fa-user-gear',
        'table'      => 'users',
        'id_field'   => 'id',
        'name_field' => 'full_name',
        'where'      => 'role_id = 2',
        'order_by'   => 'full_name',
        'fields'     => [
            ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text',     'required' => true],
            ['name' => 'username',  'label' => 'Username',  'type' => 'text',     'required' => true],
            ['name' => 'password',  'label' => 'Password',  'type' => 'password', 'required' => false, 'hint' => 'Leave blank to keep current password (when editing).'],
            ['name' => 'status',    'label' => 'Status',    'type' => 'select',   'required' => true, 'options' => ['Active', 'Inactive']],
        ],
        'insert_extra' => ['role_id' => 2],
        'fk_checks'    => [
            ['table' => 'surveys', 'column' => 'surveyor_id'],
        ],
    ],

    // Future-ready examples (left commented out, uncomment/add as needed):
    // 'companies' => [
    //     'label' => 'Companies', 'singular' => 'Company', 'icon' => 'fa-industry',
    //     'table' => 'companies', 'id_field' => 'id', 'name_field' => 'company_name',
    //     'where' => '', 'order_by' => 'company_name',
    //     'fields' => [['name' => 'company_name', 'label' => 'Company Name', 'type' => 'text', 'required' => true]],
    //     'fk_checks' => [],
    // ],
];
