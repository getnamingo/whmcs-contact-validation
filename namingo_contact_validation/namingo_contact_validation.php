<?php
/**
 * WHMCS Contact Validation (https://www.whmcs.com/)
 *
 * Displays ICANN / NIS2-style registrant contact validation tracking
 * Written in 2026 by Taras Kondratyuk (https://namingo.org)
 *
 * @license MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

/**
 * WHMCS addon module configuration.
 */
function namingo_contact_validation_config()
{
    return [
        'name' => 'Namingo Contact Validation',
        'description' => 'Admin interface for registrant contact validation status, manual validation, token generation, and audit notes.',
        'version' => '1.0.1',
        'author' => 'Namingo',
        'fields' => [
            'records_per_page' => [
                'FriendlyName' => 'Records per page',
                'Type' => 'text',
                'Size' => '5',
                'Default' => '25',
                'Description' => 'Default: 25',
            ],
            'default_validation_method' => [
                'FriendlyName' => 'Default manual validation method',
                'Type' => 'text',
                'Size' => '30',
                'Default' => 'admin_manual',
                'Description' => 'Stored in namingo_contact_validation.validation_method when an admin validates a contact.',
            ],
            'enable_quick_actions' => [
                'FriendlyName' => 'Enable quick actions',
                'Type' => 'yesno',
                'Description' => 'Show quick Validate / Unvalidate buttons directly in list tables.',
            ],
        ],
    ];
}

/**
 * Activation creates the validation table when it does not already exist.
 *
 */
function namingo_contact_validation_activate()
{
    try {
        $sql = "CREATE TABLE IF NOT EXISTS `namingo_contact_validation` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `client_id` int(10) NOT NULL,
            `contact_id` int(10) unsigned NOT NULL DEFAULT 0,
            `is_validated` tinyint(1) unsigned NOT NULL DEFAULT 0,
            `validation_checked_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `validation_method` varchar(100) DEFAULT NULL,
            `validation_token` varchar(255) DEFAULT NULL,
            `validation_log` text DEFAULT NULL,
            `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            `updated_at` datetime(3) DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (`id`),
            UNIQUE KEY `client_contact` (`client_id`, `contact_id`),
            KEY `is_validated` (`is_validated`),
            KEY `validation_checked_at` (`validation_checked_at`),
            KEY `validation_token` (`validation_token`),
            CONSTRAINT `contact_validation_client_fk`
                FOREIGN KEY (`client_id`) REFERENCES `tblclients`(`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        Capsule::connection()->statement($sql);
        ncv_upgrade_identity_schema();

        return [
            'status' => 'success',
            'description' => 'Namingo Contact Validation is active. Use Addons > Namingo Contact Validation to review and validate client registrant contacts.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to create namingo_contact_validation table: ' . $e->getMessage(),
        ];
    }
}

/**
 * Preserve validation records on deactivate for audit purposes.
 */
function namingo_contact_validation_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Module deactivated. The namingo_contact_validation table was preserved for audit/compliance history.',
    ];
}

/**
 * Admin output entry point.
 */
function namingo_contact_validation_output($vars)
{
    $moduleLink = $vars['modulelink'];
    $activeTab = ncv_get_string('tab', 'unvalidated');
    $activeTab = in_array($activeTab, ['unvalidated', 'validated', 'details'], true) ? $activeTab : 'unvalidated';

    $defaultMethod = trim((string)($vars['default_validation_method'] ?? 'admin_manual')) ?: 'admin_manual';
    $perPage = (int)($vars['records_per_page'] ?? 25);
    $perPage = $perPage > 0 && $perPage <= 200 ? $perPage : 25;
    $quickActions = !empty($vars['enable_quick_actions']);

    echo ncv_render_styles();
    echo '<div class="ncv-wrap">';
    echo '<p class="text-muted">Review registrant contact validation status, open a client record, and manually mark validation decisions with audit notes.</p>';

    try {
        ncv_ensure_table_exists();
        $message = null;
        $error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            ncv_verify_csrf();
            [$message, $error] = ncv_handle_post($defaultMethod);
            $activeTab = ncv_post_string('redirect_tab', $activeTab);
            $activeTab = in_array($activeTab, ['unvalidated', 'validated', 'details'], true) ? $activeTab : 'unvalidated';
        }

        if ($message) {
            echo '<div class="alert alert-success">' . ncv_e($message) . '</div>';
        }
        if ($error) {
            echo '<div class="alert alert-danger">' . ncv_e($error) . '</div>';
        }

        echo ncv_render_stats();
        echo ncv_render_tabs($moduleLink, $activeTab);

        if ($activeTab === 'details') {
            $clientId = ncv_get_int('client_id');
            $contactId = ncv_get_int('contact_id');
            echo ncv_render_details($moduleLink, $clientId, $contactId, $defaultMethod);
        } elseif ($activeTab === 'validated') {
            echo ncv_render_list($moduleLink, true, $perPage, $quickActions);
        } else {
            echo ncv_render_list($moduleLink, false, $perPage, $quickActions);
        }
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger"><strong>Module error:</strong> ' . ncv_e($e->getMessage()) . '</div>';
    }

    echo '</div>';
}

function namingo_contact_validation_upgrade($vars)
{
    ncv_upgrade_identity_schema();
}

function ncv_upgrade_identity_schema()
{
    if (!Capsule::schema()->hasTable('namingo_contact_validation')) {
        return;
    }

    if (!Capsule::schema()->hasColumn('namingo_contact_validation', 'contact_id')) {
        Capsule::connection()->statement(
            "ALTER TABLE `namingo_contact_validation`
             ADD `contact_id` int(10) unsigned NOT NULL DEFAULT 0
             AFTER `client_id`"
        );
    }

    $pairIndex = Capsule::select(
        "SHOW INDEX FROM `namingo_contact_validation`
         WHERE Key_name = 'client_contact'"
    );

    if (!$pairIndex) {
        Capsule::connection()->statement(
            "ALTER TABLE `namingo_contact_validation`
             ADD UNIQUE KEY `client_contact` (`client_id`, `contact_id`)"
        );
    }

    // Add the composite index before dropping the old one so the
    // client_id foreign key always remains indexed.
    $legacyIndex = Capsule::select(
        "SHOW INDEX FROM `namingo_contact_validation`
         WHERE Key_name = 'client_id'"
    );

    if ($legacyIndex) {
        Capsule::connection()->statement(
            "ALTER TABLE `namingo_contact_validation`
             DROP INDEX `client_id`"
        );
    }
}

function ncv_ensure_table_exists()
{
    if (!Capsule::schema()->hasTable('namingo_contact_validation')) {
        throw new RuntimeException('The table namingo_contact_validation does not exist. Activate the addon module first or create the table manually.');
    }

    ncv_upgrade_identity_schema();
}

function ncv_handle_post($defaultMethod)
{
    $action = ncv_post_string('action');
    $clientId = ncv_post_int('client_id');
    $contactId = ncv_post_int('contact_id');
    $note = trim(ncv_post_string('note'));
    $method = trim(ncv_post_string('validation_method')) ?: $defaultMethod;

    if ($clientId <= 0) {
        return [null, 'Missing or invalid client ID.'];
    }

    $contact = ncv_get_identity($clientId, $contactId);
    if (!$contact) {
        return [null, 'Contact not found.'];
    }

    switch ($action) {
        case 'validate':
            $logMessage = ncv_admin_log_line('Validated manually', $note, $method);
            ncv_upsert_validation($clientId, $contactId, [
                'is_validated' => 1,
                'validation_method' => $method,
                'validation_token' => null,
                'validation_checked_at' => ncv_now_ms(),
                'validation_log' => ncv_append_validation_log($clientId, $contactId, $logMessage),
            ]);
            ncv_activity('Validated ' . ncv_identity_label($clientId, $contactId));
            return ['Client #' . $clientId . ' has been marked as validated.', null];

        case 'unvalidate':
            $logMessage = ncv_admin_log_line('Marked as unvalidated', $note, $method);
            ncv_upsert_validation($clientId, $contactId, [
                'is_validated' => 0,
                'validation_method' => $method,
                'validation_token' => null,
                'validation_checked_at' => ncv_now_ms(),
                'validation_log' => ncv_append_validation_log($clientId, $contactId, $logMessage),
            ]);
            ncv_activity('Marked contact as unvalidated for client #' . $clientId . ' (' . trim($contact->firstname . ' ' . $contact->lastname) . ')');
            return ['Client #' . $clientId . ' has been marked as unvalidated.', null];

        case 'reset_token':
            $token = ncv_generate_token();
            $logMessage = ncv_admin_log_line('Generated validation token', $note, 'token_generated');
            ncv_upsert_validation($clientId, $contactId, [
                'is_validated' => 0,
                'validation_method' => 'token_generated',
                'validation_token' => $token,
                'validation_checked_at' => ncv_now_ms(),
                'validation_log' => ncv_append_validation_log($clientId, $contactId, $logMessage),
            ]);
            ncv_activity('Generated contact validation token for client #' . $clientId . ' (' . trim($contact->firstname . ' ' . $contact->lastname) . ')');
            return ['A new validation token has been generated and the client is now pending validation.', null];

        case 'save_note':
            if ($note === '') {
                return [null, 'Cannot save an empty note.'];
            }
            $existing = ncv_get_validation($clientId, $contactId);
            $logMessage = ncv_admin_log_line('Added admin note', $note, $existing ? (string)$existing->validation_method : $method);
            ncv_upsert_validation($clientId, $contactId, [
                'is_validated' => $existing ? (int)$existing->is_validated : 0,
                'validation_method' => $existing ? $existing->validation_method : $method,
                'validation_checked_at' => $existing ? $existing->validation_checked_at : ncv_now_ms(),
                'validation_log' => ncv_append_validation_log($clientId, $contactId, $logMessage),
            ]);
            ncv_activity('Added contact validation note for client #' . $clientId . ' (' . trim($contact->firstname . ' ' . $contact->lastname) . ')');
            return ['Note saved for client #' . $clientId . '.', null];
    }

    return [null, 'Unknown action.'];
}

function ncv_render_stats()
{
    $validated = ncv_contact_base_query(true, '')->count();
    $unvalidated = ncv_contact_base_query(false, '')->count();
    $totalContacts = ncv_identity_base_query()->count();

    $tokens = Capsule::table('namingo_contact_validation')
        ->where('is_validated', 0)
        ->whereNotNull('validation_token')
        ->where('validation_token', '<>', '')
        ->count();

    return '<div class="row ncv-stats">'
        . ncv_stat_card('Total Contacts', $totalContacts, 'Default and additional WHMCS contacts')
        . ncv_stat_card('Validated', $validated, 'Validation passed')
        . ncv_stat_card('Unvalidated', $unvalidated, 'Missing or pending validation')
        . ncv_stat_card('Tokens Open', $tokens, 'Token generated, not validated')
        . '</div>';
}

function ncv_stat_card($label, $value, $hint)
{
    return '<div class="col-sm-3"><div class="panel panel-default ncv-stat">'
        . '<div class="panel-body">'
        . '<div class="ncv-stat-value">' . ncv_e((string)$value) . '</div>'
        . '<div class="ncv-stat-label">' . ncv_e($label) . '</div>'
        . '<div class="text-muted small">' . ncv_e($hint) . '</div>'
        . '</div></div></div>';
}

function ncv_render_tabs($moduleLink, $activeTab)
{
    $tabs = [
        'unvalidated' => 'Unvalidated / Pending',
        'validated' => 'Validated',
    ];

    $html = '<ul class="nav nav-tabs ncv-tabs">';
    foreach ($tabs as $tab => $label) {
        $class = $activeTab === $tab ? ' class="active"' : '';
        $html .= '<li' . $class . '><a href="' . ncv_e(ncv_url($moduleLink, ['tab' => $tab])) . '">' . ncv_e($label) . '</a></li>';
    }
    if ($activeTab === 'details') {
        $html .= '<li class="active"><a href="#">Client Details</a></li>';
    }
    $html .= '</ul>';

    return $html;
}

function ncv_render_list($moduleLink, $validated, $perPage, $quickActions)
{
    $tab = $validated ? 'validated' : 'unvalidated';
    $search = trim(ncv_get_string('q'));
    $page = max(1, ncv_get_int('page', 1));

    [$rows, $total] = ncv_get_contacts($validated, $search, $page, $perPage);
    $totalPages = max(1, (int)ceil($total / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        [$rows, $total] = ncv_get_contacts($validated, $search, $page, $perPage);
    }

    $html = '<div class="panel panel-default ncv-list-panel">';
    $html .= '<div class="panel-heading"><strong>' . ($validated ? 'Validated Contacts' : 'Unvalidated / Pending Contacts') . '</strong></div>';
    $html .= '<div class="panel-body">';
    $html .= '<form method="get" action="addonmodules.php" class="form-inline ncv-search">'
        . '<input type="hidden" name="module" value="namingo_contact_validation">'
        . '<input type="hidden" name="tab" value="' . ncv_e($tab) . '">'
        . '<div class="form-group">'
        . '<input type="text" name="q" value="' . ncv_e($search) . '" class="form-control" placeholder="Search ID, name, company, email, phone..." style="min-width:320px">'
        . '</div> '
        . '<button type="submit" class="btn btn-primary">Search</button> '
        . '<a class="btn btn-default" href="' . ncv_e(ncv_url($moduleLink, ['tab' => $tab])) . '">Reset</a>'
        . '</form>';

    if (!$rows) {
        $html .= '<div class="alert alert-info">No contacts found.</div>';
    } else {
        $html .= '<div class="table-responsive"><table class="table table-striped table-hover ncv-table">';
        $html .= '<thead><tr>'
            . '<th>ID</th>'
            . '<th>Client</th>'
            . '<th>Email</th>'
            . '<th>Phone</th>'
            . '<th>Country</th>'
            . '<th>Status</th>'
            . '<th>Method</th>'
            . '<th>Checked</th>'
            . '<th class="text-right">Actions</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $clientName = trim($row->firstname . ' ' . $row->lastname);
            $clientLabel = ncv_e($clientName ?: ('Client #' . (int)$row->client_id));
            if ($row->companyname) {
                $clientLabel .= ' <span class="text-muted">(' . ncv_e($row->companyname) . ')</span>';
            }
            $status = ((int)$row->is_validated === 1)
                ? '<span class="label label-success">Validated</span>'
                : '<span class="label label-warning">Unvalidated</span>';

            $identityId = '#' . (int)$row->client_id;
            if ((int)$row->contact_id > 0) {
                $identityId .= ' / C#' . (int)$row->contact_id;
            }

            $html .= '<tr>'
                . '<td>' . $identityId . '</td>'
                . '<td><a href="clientssummary.php?userid=' . (int)$row->client_id . '">' . $clientLabel . '</a></td>'
                . '<td><a href="mailto:' . ncv_e($row->email) . '">' . ncv_e($row->email) . '</a></td>'
                . '<td>' . ncv_e($row->phonenumber ?: '-') . '</td>'
                . '<td>' . ncv_e($row->country ?: '-') . '</td>'
                . '<td>' . $status . '</td>'
                . '<td>' . ncv_e($row->validation_method ?: '-') . '</td>'
                . '<td>' . ncv_e(ncv_format_datetime($row->validation_checked_at)) . '</td>'
                . '<td class="text-right">'
                . '<a class="btn btn-xs btn-default" href="' . ncv_e(ncv_url($moduleLink, [
                    'tab' => 'details',
                    'client_id' => (int)$row->client_id,
                    'contact_id' => (int)$row->contact_id,
                ])) . '">Open</a> ';

            if ($quickActions) {
                if ((int)$row->is_validated === 1) {
                    $html .= ncv_inline_action_form('unvalidate', $row->client_id, $row->contact_id, 'Unvalidate', 'btn-warning', $tab);
                } else {
                    $html .= ncv_inline_action_form('validate', $row->client_id, $row->contact_id, 'Validate', 'btn-success', $tab);
                }
            }

            $html .= '</td></tr>';
        }

        $html .= '</tbody></table></div>';
        $html .= ncv_render_pagination($moduleLink, $tab, $search, $page, $totalPages, $total);
    }

    $html .= '</div></div>';
    return $html;
}

function ncv_get_contacts($validated, $search, $page, $perPage)
{
    $query = ncv_contact_base_query($validated, $search);
    $total = (int)$query->count();

    $rows = ncv_contact_base_query($validated, $search)
        ->select(
            'c.client_id',
            'c.contact_id',
            'c.firstname',
            'c.lastname',
            'c.companyname',
            'c.email',
            'c.phonenumber',
            'c.country',
            'v.validation_method',
            'v.validation_checked_at',
            'v.validation_token',
            Capsule::raw('COALESCE(v.is_validated, 0) as is_validated')
        )
        ->orderBy($validated ? 'v.validation_checked_at' : 'c.client_id', 'desc')
        ->offset(($page - 1) * $perPage)
        ->limit($perPage)
        ->get();

    return [$rows ? $rows->all() : [], $total];
}

function ncv_identity_base_query()
{
    return Capsule::table(Capsule::raw("
        (
            SELECT
                c.id AS client_id,
                0 AS contact_id,
                c.firstname,
                c.lastname,
                c.companyname,
                c.email,
                c.phonenumber,
                c.address1,
                c.address2,
                c.city,
                c.state,
                c.postcode,
                c.country
            FROM tblclients c

            UNION ALL

            SELECT
                ct.userid AS client_id,
                ct.id AS contact_id,
                ct.firstname,
                ct.lastname,
                ct.companyname,
                ct.email,
                ct.phonenumber,
                ct.address1,
                ct.address2,
                ct.city,
                ct.state,
                ct.postcode,
                ct.country
            FROM tblcontacts ct
        ) AS c
    "));
}

function ncv_contact_base_query($validated, $search)
{
    $query = ncv_identity_base_query()
        ->leftJoin('namingo_contact_validation as v', function ($join) {
            $join->on('v.client_id', '=', 'c.client_id')
                ->on('v.contact_id', '=', 'c.contact_id');
        });

    if ($validated) {
        $query->where('v.is_validated', 1);
    } else {
        $query->where(function ($where) {
            $where->whereNull('v.id')
                ->orWhere('v.is_validated', 0);
        });
    }

    if ($search !== '') {
        $like = '%' . $search . '%';

        $query->where(function ($where) use ($search, $like) {
            if (ctype_digit($search)) {
                $where->orWhere('c.client_id', (int)$search)
                    ->orWhere('c.contact_id', (int)$search);
            }

            $where->orWhere('c.firstname', 'like', $like)
                ->orWhere('c.lastname', 'like', $like)
                ->orWhere('c.companyname', 'like', $like)
                ->orWhere('c.email', 'like', $like)
                ->orWhere('c.phonenumber', 'like', $like)
                ->orWhere('c.address1', 'like', $like)
                ->orWhere('c.city', 'like', $like)
                ->orWhere('c.state', 'like', $like)
                ->orWhere('c.country', 'like', $like);
        });
    }

    return $query;
}

function ncv_render_details($moduleLink, $clientId, $contactId, $defaultMethod)
{
    if ($clientId <= 0) {
        return '<div class="alert alert-danger">Missing client ID.</div>';
    }

    $client = ncv_get_identity($clientId, $contactId);
    if (!$client) {
        return '<div class="alert alert-danger">Contact not found.</div>';
    }

    $validation = ncv_get_validation($clientId, $contactId);
    $isValidated = $validation && (int)$validation->is_validated === 1;
    $status = $isValidated
        ? '<span class="label label-success">Validated</span>'
        : '<span class="label label-warning">Unvalidated</span>';

    $name = trim($client->firstname . ' ' . $client->lastname);
    $address = trim(implode(', ', array_filter([
        $client->address1,
        $client->address2,
        $client->city,
        $client->state,
        $client->postcode,
        $client->country,
    ])));

    $html = '<div class="panel panel-default ncv-details">';
    $identityLabel = $contactId > 0
        ? 'Client #' . $clientId . ' / Contact #' . $contactId
        : 'Client #' . $clientId . ' / Default Contact';

    $html .= '<div class="panel-heading"><strong>' . ncv_e($identityLabel) . ': ' . ncv_e($name) . '</strong> ' . $status . '</div>';
    $html .= '<div class="panel-body">';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-6">';
    $html .= '<h4>Registrant Contact</h4>';
    $html .= '<table class="table table-condensed">'
        . ncv_detail_row('Client', '<a href="clientssummary.php?userid=' . (int)$clientId . '">' . ncv_e($name) . '</a>')
        . ncv_detail_row('Company', ncv_e($client->companyname ?: '-'))
        . ncv_detail_row('Email', '<a href="mailto:' . ncv_e($client->email) . '">' . ncv_e($client->email) . '</a>')
        . ncv_detail_row('Phone', ncv_e($client->phonenumber ?: '-'))
        . ncv_detail_row('Address', ncv_e($address ?: '-'))
        . '</table>';
    $html .= '</div>';

    $html .= '<div class="col-md-6">';
    $html .= '<h4>Validation Record</h4>';
    $html .= '<table class="table table-condensed">'
        . ncv_detail_row('Status', $status)
        . ncv_detail_row('Method', ncv_e($validation->validation_method ?? '-'))
        . ncv_detail_row('Checked At', ncv_e(ncv_format_datetime($validation->validation_checked_at ?? null)))
        . ncv_detail_row('Created At', ncv_e(ncv_format_datetime($validation->created_at ?? null)))
        . ncv_detail_row('Updated At', ncv_e(ncv_format_datetime($validation->updated_at ?? null)))
        . ncv_detail_row('Token', $validation && $validation->validation_token ? '<code>' . ncv_e($validation->validation_token) . '</code>' : '-')
        . '</table>';
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<hr>';
    $html .= '<div class="row">';
    $html .= '<div class="col-md-7">';
    $html .= '<h4>Audit Log</h4>';
    $html .= '<pre class="ncv-log">' . ncv_e($validation->validation_log ?? 'No validation log yet.') . '</pre>';
    $html .= '</div>';

    $html .= '<div class="col-md-5">';
    $html .= '<h4>Admin Actions</h4>';
    $html .= ncv_action_form($clientId, $contactId, 'validate', 'Mark as Validated', 'btn-success', $defaultMethod, 'Document what you checked: email confirmation, phone call, KYC, registry response, etc.');
    $html .= ncv_action_form($clientId, $contactId, 'unvalidate', 'Mark as Unvalidated', 'btn-warning', $defaultMethod, 'Reason: failed checks, stale data, abuse case, bounced email, etc.');
    $html .= ncv_action_form($clientId, $contactId, 'reset_token', 'Generate / Reset Token', 'btn-info', 'token_generated', 'Use when you want to start or restart a token-based validation flow later.');
    $html .= ncv_action_form($clientId, $contactId, 'save_note', 'Add Audit Note', 'btn-default', $defaultMethod, 'Free-form internal note without changing status.');
    $html .= '</div>';
    $html .= '</div>';

    $html .= '<p><a class="btn btn-default" href="' . ncv_e(ncv_url($moduleLink, ['tab' => $isValidated ? 'validated' : 'unvalidated'])) . '">&larr; Back to list</a></p>';
    $html .= '</div></div>';

    return $html;
}

function ncv_action_form($clientId, $contactId, $action, $button, $buttonClass, $method, $placeholder)
{
    $methodInput = '';
    if ($action !== 'reset_token') {
        $methodInput = '<input type="text" name="validation_method" value="' . ncv_e($method) . '" class="form-control input-sm" placeholder="Validation method" style="margin-bottom:6px">';
    } else {
        $methodInput = '<input type="hidden" name="validation_method" value="' . ncv_e($method) . '">';
    }

    return '<form method="post" class="ncv-action-form">'
        . ncv_csrf_field()
        . '<input type="hidden" name="action" value="' . ncv_e($action) . '">'
        . '<input type="hidden" name="client_id" value="' . (int)$clientId . '">'
        . '<input type="hidden" name="contact_id" value="' . (int)$contactId . '">'
        . '<input type="hidden" name="redirect_tab" value="details">'
        . '<input type="hidden" name="tab" value="details">'
        . $methodInput
        . '<textarea name="note" class="form-control" rows="2" placeholder="' . ncv_e($placeholder) . '"></textarea>'
        . '<button type="submit" class="btn ' . ncv_e($buttonClass) . ' btn-block" style="margin-top:6px">' . ncv_e($button) . '</button>'
        . '</form>';
}

function ncv_inline_action_form($action, $clientId, $contactId, $button, $buttonClass, $redirectTab)
{
    return '<form method="post" style="display:inline">'
        . ncv_csrf_field()
        . '<input type="hidden" name="action" value="' . ncv_e($action) . '">'
        . '<input type="hidden" name="client_id" value="' . (int)$clientId . '">'
        . '<input type="hidden" name="contact_id" value="' . (int)$contactId . '">'
        . '<input type="hidden" name="redirect_tab" value="' . ncv_e($redirectTab) . '">'
        . '<input type="hidden" name="note" value="Quick action from contact validation list">'
        . '<button type="submit" class="btn btn-xs ' . ncv_e($buttonClass) . '">' . ncv_e($button) . '</button>'
        . '</form>';
}

function ncv_render_pagination($moduleLink, $tab, $search, $page, $totalPages, $total)
{
    if ($totalPages <= 1) {
        return '<p class="text-muted">Showing ' . (int)$total . ' record(s).</p>';
    }

    $html = '<div class="ncv-pagination"><span class="text-muted">Showing page ' . (int)$page . ' of ' . (int)$totalPages . ' — ' . (int)$total . ' record(s)</span>';
    $html .= '<ul class="pagination pagination-sm pull-right">';

    $start = max(1, $page - 3);
    $end = min($totalPages, $page + 3);

    $prevDisabled = $page <= 1 ? ' class="disabled"' : '';
    $html .= '<li' . $prevDisabled . '><a href="' . ncv_e(ncv_url($moduleLink, ['tab' => $tab, 'q' => $search, 'page' => max(1, $page - 1)])) . '">&laquo;</a></li>';

    for ($i = $start; $i <= $end; $i++) {
        $class = $i === $page ? ' class="active"' : '';
        $html .= '<li' . $class . '><a href="' . ncv_e(ncv_url($moduleLink, ['tab' => $tab, 'q' => $search, 'page' => $i])) . '">' . $i . '</a></li>';
    }

    $nextDisabled = $page >= $totalPages ? ' class="disabled"' : '';
    $html .= '<li' . $nextDisabled . '><a href="' . ncv_e(ncv_url($moduleLink, ['tab' => $tab, 'q' => $search, 'page' => min($totalPages, $page + 1)])) . '">&raquo;</a></li>';
    $html .= '</ul><div class="clearfix"></div></div>';

    return $html;
}

function ncv_detail_row($label, $valueHtml)
{
    return '<tr><th style="width:140px">' . ncv_e($label) . '</th><td>' . $valueHtml . '</td></tr>';
}

function ncv_get_identity($clientId, $contactId = 0)
{
    $clientId = (int)$clientId;
    $contactId = (int)$contactId;

    if ($contactId > 0) {
        return Capsule::table('tblcontacts')
            ->where('id', $contactId)
            ->where('userid', $clientId)
            ->selectRaw('userid AS client_id, id AS contact_id, firstname, lastname, companyname, email, phonenumber, address1, address2, city, state, postcode, country')
            ->first();
    }

    return Capsule::table('tblclients')
        ->where('id', $clientId)
        ->selectRaw('id AS client_id, 0 AS contact_id, firstname, lastname, companyname, email, phonenumber, address1, address2, city, state, postcode, country')
        ->first();
}

function ncv_get_validation($clientId, $contactId = 0)
{
    return Capsule::table('namingo_contact_validation')
        ->where('client_id', (int)$clientId)
        ->where('contact_id', (int)$contactId)
        ->first();
}

function ncv_upsert_validation($clientId, $contactId, array $data)
{
    $clientId = (int)$clientId;
    $contactId = (int)$contactId;

    $query = Capsule::table('namingo_contact_validation')
        ->where('client_id', $clientId)
        ->where('contact_id', $contactId);

    $exists = $query->exists();

    $data['updated_at'] = ncv_now_ms();

    if ($exists) {
        $query->update($data);
        return;
    }

    $data['client_id'] = $clientId;
    $data['contact_id'] = $contactId;
    $data['created_at'] = ncv_now_ms();
    Capsule::table('namingo_contact_validation')->insert($data);
}

function ncv_append_validation_log($clientId, $contactId, $line)
{
    $existing = ncv_get_validation((int)$clientId, (int)$contactId);
    $old = $existing && $existing->validation_log ? rtrim((string)$existing->validation_log) : '';
    return $old === '' ? $line : $old . "\n" . $line;
}

function ncv_identity_label($clientId, $contactId)
{
    return (int)$contactId > 0
        ? 'contact #' . (int)$contactId . ' for client #' . (int)$clientId
        : 'default contact for client #' . (int)$clientId;
}

function ncv_admin_log_line($action, $note, $method)
{
    $admin = ncv_current_admin_label();
    $note = trim((string)$note);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $action . ' by ' . $admin . ' using method: ' . $method;
    if ($note !== '') {
        $line .= ' | Note: ' . preg_replace('/\s+/', ' ', $note);
    }
    return $line;
}

function ncv_current_admin_label()
{
    $adminId = isset($_SESSION['adminid']) ? (int)$_SESSION['adminid'] : 0;
    if ($adminId <= 0) {
        return 'admin';
    }

    try {
        $admin = Capsule::table('tbladmins')
            ->select('id', 'username', 'firstname', 'lastname')
            ->where('id', $adminId)
            ->first();
        if ($admin) {
            $name = trim($admin->firstname . ' ' . $admin->lastname);
            return ($name ?: $admin->username ?: 'admin') . ' (#' . $adminId . ')';
        }
    } catch (\Throwable $e) {
        // Fall through to generic label.
    }

    return 'admin #' . $adminId;
}

function ncv_activity($message)
{
    if (function_exists('logActivity')) {
        logActivity('Namingo Contact Validation: ' . $message);
    }
}

function ncv_generate_token()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(32));
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }
    return sha1(uniqid('', true) . mt_rand());
}

function ncv_csrf_field()
{
    if (function_exists('generate_token')) {
        return '<input type="hidden" name="token" value="' . ncv_e(generate_token('plain')) . '">';
    }

    if (empty($_SESSION['ncv_contact_validation_token'])) {
        $_SESSION['ncv_contact_validation_token'] = ncv_generate_token();
    }

    return '<input type="hidden" name="ncv_token" value="' . ncv_e($_SESSION['ncv_contact_validation_token']) . '">';
}

function ncv_verify_csrf()
{
    if (function_exists('check_token')) {
        check_token('WHMCS.admin.default');
        return;
    }

    $expected = $_SESSION['ncv_contact_validation_token'] ?? '';
    $actual = $_POST['ncv_token'] ?? '';
    if (!$expected || !$actual || !hash_equals($expected, $actual)) {
        throw new RuntimeException('Invalid CSRF token. Please reload the page and try again.');
    }
}

function ncv_render_styles()
{
    return '<style>
        .ncv-wrap { max-width: 1400px; }
        .ncv-stats { margin-top: 15px; }
        .ncv-stat .panel-body { min-height: 92px; }
        .ncv-stat-value { font-size: 28px; font-weight: 700; line-height: 1; }
        .ncv-stat-label { font-size: 13px; font-weight: 600; margin-top: 6px; text-transform: uppercase; }
        .ncv-tabs { margin-top: 15px; margin-bottom: 15px; }
        .ncv-search { margin-bottom: 15px; }
        .ncv-table td { vertical-align: middle !important; }
        .ncv-log { max-height: 360px; overflow: auto; white-space: pre-wrap; background: #fbfbfb; }
        .ncv-action-form { border: 1px solid #eee; border-radius: 4px; padding: 10px; margin-bottom: 10px; background: #fafafa; }
        .ncv-pagination { margin-top: 10px; }
    </style>';
}

function ncv_now_ms()
{
    // WHMCS stores in the database server timezone; date() follows the PHP/WHMCS configured timezone.
    return date('Y-m-d H:i:s') . '.000';
}

function ncv_format_datetime($value)
{
    if (!$value) {
        return '-';
    }
    return preg_replace('/\.\d+$/', '', (string)$value);
}

function ncv_url($moduleLink, array $params = [])
{
    $separator = strpos($moduleLink, '?') === false ? '?' : '&';
    return $moduleLink . ($params ? $separator . http_build_query($params) : '');
}

function ncv_get_string($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function ncv_get_int($key, $default = 0)
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function ncv_post_string($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function ncv_post_int($key, $default = 0)
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

function ncv_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
