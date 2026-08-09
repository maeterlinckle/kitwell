-- ---------------------------------------------------------------------------
-- 008 Built-in roles and permissions
--
-- This is system data, not demo data: the application needs it to function.
-- Written to be re-runnable, and to leave any hand-edited role assignments
-- alone once they exist.
-- ---------------------------------------------------------------------------

INSERT INTO roles (slug, name, description, is_superuser, is_system, sort_order) VALUES
    ('admin',    'Administrator',  'Full access, including users, roles and settings.',                 1, 1, 10),
    ('manager',  'Manager / Staff','Day-to-day asset management: assets, maintenance, PAT and loans.',  0, 1, 20),
    ('viewer',   'Read-only',      'Can view the register and reports but cannot change anything.',     0, 1, 30),
    ('borrower', 'Borrower',       'External or occasional user who can see items and their own loans.',0, 1, 40)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_superuser = VALUES(is_superuser),
    is_system = VALUES(is_system),
    sort_order = VALUES(sort_order);

INSERT INTO permissions (slug, name, group_name, description, sort_order) VALUES
    -- Assets
    ('assets.view',           'View assets',              'Assets',        'See the asset register and asset detail pages.',      10),
    ('assets.create',         'Add assets',               'Assets',        'Register new assets and sub-assets.',                 20),
    ('assets.edit',           'Edit assets',              'Assets',        'Change asset details, condition and status.',         30),
    ('assets.delete',         'Delete assets',            'Assets',        'Permanently remove or retire assets.',                40),
    ('assets.export',         'Export assets',            'Assets',        'Download the register as CSV/PDF.',                   50),

    -- Photos and documents
    ('media.photo.upload',    'Upload photos',            'Photos & files','Add condition photos to an asset.',                   10),
    ('media.photo.delete',    'Delete photos',            'Photos & files','Remove photos from an asset.',                        20),
    ('media.manual.upload',   'Upload manuals',           'Photos & files','Attach PDF manuals and datasheets.',                  30),
    ('media.manual.delete',   'Delete manuals',           'Photos & files','Remove attached PDFs.',                               40),

    -- Maintenance
    ('maintenance.view',      'View maintenance',         'Maintenance',   'See schedules and maintenance history.',              10),
    ('maintenance.manage',    'Manage maintenance',       'Maintenance',   'Create and edit maintenance schedules.',              20),
    ('maintenance.complete',  'Record maintenance',       'Maintenance',   'Log completed maintenance work.',                     30),

    -- PAT testing
    ('pat.view',              'View PAT records',         'PAT testing',   'See PAT test history and due dates.',                 10),
    ('pat.manage',            'Record PAT tests',         'PAT testing',   'Add and edit PAT test results.',                      20),
    ('pat.delete',            'Delete PAT records',       'PAT testing',   'Remove a PAT record entered in error.',               30),

    -- Loans and hire
    ('loans.view',            'View loans',               'Loans & hire',  'See all loans and hire history.',                     10),
    ('loans.view_own',        'View own loans',           'Loans & hire',  'See only loans issued to this person.',               15),
    ('loans.create',          'Check items out',          'Loans & hire',  'Issue an asset to a borrower.',                       20),
    ('loans.return',          'Check items in',           'Loans & hire',  'Record the return of a loaned asset.',                30),
    ('loans.manage',          'Manage loans',             'Loans & hire',  'Edit or cancel loan records.',                        40),

    -- Borrowers
    ('borrowers.view',        'View borrowers',           'Borrowers',     'See the list of people and companies.',               10),
    ('borrowers.manage',      'Manage borrowers',         'Borrowers',     'Add and edit borrower records.',                      20),

    -- Administration
    ('users.view',            'View users',               'Administration','See the user list.',                                  10),
    ('users.manage',          'Manage users',             'Administration','Create users, reset passwords, assign roles.',        20),
    ('roles.manage',          'Manage roles',             'Administration','Create roles and change their permissions.',          30),
    ('categories.manage',     'Manage categories',        'Administration','Maintain the category list.',                         40),
    ('locations.manage',      'Manage locations',         'Administration','Maintain the location list.',                         50),
    ('settings.manage',       'Manage settings',          'Administration','Change application-wide settings.',                   60),
    ('audit.view',            'View activity log',        'Administration','Read the audit trail.',                               70),

    -- Reporting
    ('reports.view',          'View reports',             'Reports',       'Open the reporting and dashboard views.',             10)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    group_name = VALUES(group_name),
    description = VALUES(description),
    sort_order = VALUES(sort_order);

-- Administrator: every permission (also flagged is_superuser, but keeping the
-- rows makes the admin UI honest about what the role holds).
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.slug = 'admin';

-- Manager / Staff: everything operational, nothing that manages accounts.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.slug IN (
        'assets.view','assets.create','assets.edit','assets.delete','assets.export',
        'media.photo.upload','media.photo.delete','media.manual.upload','media.manual.delete',
        'maintenance.view','maintenance.manage','maintenance.complete',
        'pat.view','pat.manage',
        'loans.view','loans.create','loans.return','loans.manage',
        'borrowers.view','borrowers.manage',
        'categories.manage','locations.manage',
        'audit.view','reports.view'
    )
 WHERE r.slug = 'manager';

-- Read-only: look, do not touch.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.slug IN (
        'assets.view','assets.export',
        'maintenance.view','pat.view',
        'loans.view','borrowers.view','reports.view'
    )
 WHERE r.slug = 'viewer';

-- Borrower: can find an item and see what they personally have out.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.slug IN ('assets.view','loans.view_own')
 WHERE r.slug = 'borrower';
