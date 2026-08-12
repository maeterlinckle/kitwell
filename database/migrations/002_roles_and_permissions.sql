-- ---------------------------------------------------------------------------
-- 002 Built-in roles and permissions
--
-- System data, not demo data: the application needs these rows to function.
-- Permissions are stored as data, so a site can add roles and change what each
-- role may do without a schema change.
--
-- Re-runnable. Role and permission definitions are refreshed; the grants in
-- role_permissions are only inserted where missing, so a site's own edits to a
-- built-in role are left alone.
-- ---------------------------------------------------------------------------

INSERT INTO roles (slug, name, description, is_superuser, is_system, sort_order) VALUES
    ('admin',   'Administrator',   'Full access, including users, roles and settings.',                 1, 1, 10),
    ('manager', 'Manager / Staff', 'Day-to-day asset management: assets, maintenance, PAT and hires.',  0, 1, 20),
    ('viewer',  'Read-only',       'Can view the register and reports but cannot change anything.',     0, 1, 30),
    ('hirer',   'Hirer',           'Signs in to see only the equipment they currently hold',            0, 1, 40)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_superuser = VALUES(is_superuser),
    is_system = VALUES(is_system),
    sort_order = VALUES(sort_order);

INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    -- Assets
    ('assets.view',        'View assets',           'Assets',         'See the asset register and asset detail pages.',       10),
    ('assets.create',      'Add assets',            'Assets',         'Register new assets and sub-assets.',                  20),
    ('assets.edit',        'Edit assets',           'Assets',         'Change asset details, condition and status.',          30),
    ('assets.delete',      'Delete assets',         'Assets',         'Permanently remove or retire assets.',                 40),
    ('assets.export',      'Export assets',         'Assets',         'Download the register as CSV/PDF.',                    50),
    ('faults.report',      'Report faults',         'Assets',         'Mark an asset as faulty and record what is wrong with it.', 35),

    -- Photos and documents
    ('media.photo.upload', 'Upload photos',         'Photos & files', 'Add condition photos to an asset.',                    10),
    ('media.photo.delete', 'Delete photos',         'Photos & files', 'Remove photos from an asset.',                         20),
    ('media.manual.upload','Upload manuals',        'Photos & files', 'Attach PDF manuals and datasheets.',                   30),
    ('media.manual.delete','Delete manuals',        'Photos & files', 'Remove attached PDFs.',                                40),

    -- Maintenance
    ('maintenance.view',   'View maintenance',      'Maintenance',    'See schedules and maintenance history.',               10),
    ('maintenance.manage', 'Manage maintenance',    'Maintenance',    'Create and edit maintenance schedules.',               20),
    ('maintenance.complete','Record maintenance',   'Maintenance',    'Log completed maintenance work.',                      30),

    -- PAT testing
    ('pat.view',           'View PAT records',      'PAT testing',    'See PAT test history and due dates.',                  10),
    ('pat.manage',         'Record PAT tests',      'PAT testing',    'Add and edit PAT test results.',                       20),
    ('pat.delete',         'Delete PAT records',    'PAT testing',    'Remove a PAT record entered in error.',                30),

    -- Hires
    ('hires.view',         'View hires',            'Hires',          'See all hires and hire history.',                      10),
    ('hires.view_own',     'View own hires',        'Hires',          'See only hires issued to this person.',                15),
    ('hires.create',       'Check items out',       'Hires',          'Issue an asset to a hirer.',                           20),
    ('hires.return',       'Check items in',        'Hires',          'Record the return of a hired asset.',                  30),
    ('hires.manage',       'Manage hires',          'Hires',          'Edit or cancel hire records.',                         40),

    -- Hirers
    ('hirers.view',        'View hirers',           'Hirers',         'See the list of people and companies.',                10),
    ('hirers.manage',      'Manage hirers',         'Hirers',         'Add and edit hirer records.',                          20),

    -- Reports
    ('reports.view',       'View reports',          'Reports',        'Open the reporting and dashboard views.',              10),
    ('reports.manage',     'Manage custom reports', 'Reports',        'Create, edit and delete saved report definitions.',     20),

    -- Email
    ('email.manage',       'Manage email',          'Email',          'Configure SMTP, edit templates and reminders, and read the send log.', 10),
    ('email.send',         'Send email',            'Email',          'Use the “Email this” actions, such as sending a hirer their hire list.', 20),

    -- Administration
    ('users.view',         'View users',            'Administration', 'See the user list.',                                   10),
    ('users.manage',       'Manage users',          'Administration', 'Create users, reset passwords, assign roles.',          20),
    ('roles.manage',       'Manage roles',          'Administration', 'Create roles and change their permissions.',            30),
    ('categories.manage',  'Manage categories',     'Administration', 'Maintain the category list.',                           40),
    ('teams.manage',       'Manage teams',          'Administration', 'Create teams, archive them, and add or remove their members.', 45),
    ('locations.manage',   'Manage locations',      'Administration', 'Maintain the location list.',                           50),
    ('settings.manage',    'Manage settings',       'Administration', 'Change application-wide settings.',                     60),
    ('api.manage',         'Manage API keys',       'Administration', 'Issue and revoke API keys, and see when each was last used.', 65),
    ('audit.view',         'View activity log',     'Administration', 'Read the audit trail.',                                 70)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

-- Administrator holds everything. The superuser flag already grants it, but the
-- rows are written so the permission matrix reads correctly in the admin UI.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'manager' AND p.slug IN (
    'assets.view', 'assets.create', 'assets.edit', 'assets.delete', 'assets.export',
    'faults.report',
    'media.photo.upload', 'media.photo.delete', 'media.manual.upload', 'media.manual.delete',
    'maintenance.view', 'maintenance.manage', 'maintenance.complete',
    'pat.view', 'pat.manage',
    'hires.view', 'hires.create', 'hires.return', 'hires.manage',
    'hirers.view', 'hirers.manage',
    'categories.manage', 'locations.manage',
    'reports.view', 'reports.manage',
    'email.send',
    'audit.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'viewer' AND p.slug IN (
    'assets.view', 'assets.export',
    'maintenance.view',
    'pat.view',
    'hires.view',
    'hirers.view',
    'reports.view'
);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.slug = 'hirer' AND p.slug IN (
    'hires.view_own'
);
