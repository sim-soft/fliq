-- ============================================================
-- sample_db — SQLite test schema for simsoft/fliq ORM
-- Equivalent to resources/sample_db.sql (MySQL version)
-- ============================================================

PRAGMA foreign_keys = ON;

-- ============================================================
-- TABLES
-- ============================================================

CREATE TABLE department (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code INTEGER NOT NULL DEFAULT 1,
    name        TEXT NOT NULL,
    budget      REAL NOT NULL DEFAULT 0.00
);

CREATE TABLE "user" (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    created       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP DEFAULT NULL,
    status_code   INTEGER NOT NULL DEFAULT 1,
    department_id INTEGER NOT NULL DEFAULT 0,
    username      TEXT NOT NULL UNIQUE,
    email         TEXT NOT NULL UNIQUE,
    password      TEXT NOT NULL DEFAULT '',
    role          TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('admin','editor','member')),
    score         INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE user_profile (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    created       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id       INTEGER NOT NULL UNIQUE REFERENCES "user"(id) ON DELETE CASCADE,
    first_name    TEXT NOT NULL,
    last_name     TEXT NOT NULL,
    phone         TEXT DEFAULT NULL,
    date_of_birth TEXT DEFAULT NULL,
    bio           TEXT DEFAULT NULL
);

CREATE TABLE category (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code INTEGER NOT NULL DEFAULT 1,
    parent_id   INTEGER NOT NULL DEFAULT 0,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL
);

CREATE TABLE post (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    created      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at   TIMESTAMP DEFAULT NULL,
    status_code  INTEGER NOT NULL DEFAULT 1,
    user_id      INTEGER NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
    category_id  INTEGER NOT NULL DEFAULT 0,
    title        TEXT NOT NULL,
    slug         TEXT NOT NULL,
    body         TEXT NOT NULL,
    view_count   INTEGER NOT NULL DEFAULT 0,
    published_at TIMESTAMP DEFAULT NULL
);

CREATE TABLE comment (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code INTEGER NOT NULL DEFAULT 1,
    post_id     INTEGER NOT NULL REFERENCES post(id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
    body        TEXT NOT NULL
);

CREATE TABLE tag (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    name    TEXT NOT NULL,
    slug    TEXT NOT NULL UNIQUE
);

CREATE TABLE post_tag (
    post_id INTEGER NOT NULL REFERENCES post(id) ON DELETE CASCADE,
    tag_id  INTEGER NOT NULL REFERENCES tag(id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, tag_id)
);

CREATE TABLE "order" (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code INTEGER NOT NULL DEFAULT 1,
    user_id     INTEGER NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
    total       REAL NOT NULL DEFAULT 0.00,
    discount    REAL NOT NULL DEFAULT 0.00,
    note        TEXT DEFAULT NULL,
    ordered_at  TIMESTAMP DEFAULT NULL
);

CREATE TABLE order_item (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id     INTEGER NOT NULL REFERENCES "order"(id) ON DELETE CASCADE,
    product_name TEXT NOT NULL,
    quantity     INTEGER NOT NULL DEFAULT 1,
    unit_price   REAL NOT NULL
);

CREATE TABLE setting (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    created  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    "group"  TEXT NOT NULL DEFAULT 'general',
    key      TEXT NOT NULL,
    value    TEXT DEFAULT NULL,
    metadata TEXT DEFAULT NULL,
    UNIQUE ("group", key)
);

CREATE TABLE task (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at  TIMESTAMP DEFAULT NULL,
    updated_at  TIMESTAMP DEFAULT NULL,
    deleted_at  TIMESTAMP DEFAULT NULL,
    user_id     INTEGER NOT NULL,
    title       TEXT NOT NULL,
    description TEXT DEFAULT NULL,
    priority    TEXT NOT NULL DEFAULT 'medium' CHECK (priority IN ('low','medium','high')),
    status      TEXT NOT NULL DEFAULT 'todo' CHECK (status IN ('todo','in_progress','done'))
);

-- Indexes
CREATE INDEX idx_user_department ON "user"(department_id);
CREATE INDEX idx_user_status ON "user"(status_code);
CREATE INDEX idx_user_role ON "user"(role);
CREATE INDEX idx_user_deleted ON "user"(deleted_at);
CREATE INDEX idx_post_user ON post(user_id);
CREATE INDEX idx_post_category ON post(category_id);
CREATE INDEX idx_post_status ON post(status_code);
CREATE INDEX idx_post_deleted ON post(deleted_at);
CREATE INDEX idx_comment_post ON comment(post_id);
CREATE INDEX idx_comment_user ON comment(user_id);
CREATE INDEX idx_order_user ON "order"(user_id);
CREATE INDEX idx_order_status ON "order"(status_code);
CREATE INDEX idx_order_item ON order_item(order_id);
CREATE INDEX idx_task_user ON task(user_id);
CREATE INDEX idx_task_deleted ON task(deleted_at);

-- ============================================================
-- TEST DATA
-- ============================================================

INSERT INTO department (id, name, budget, status_code) VALUES
(1, 'Engineering', 500000.00, 1),
(2, 'Marketing', 200000.00, 1),
(3, 'Sales', 300000.00, 1),
(4, 'Support', 150000.00, 1),
(5, 'HR', 100000.00, 0);

INSERT INTO "user" (id, department_id, username, email, password, role, score, status_code, deleted_at) VALUES
(1,  1, 'alice',   'alice@example.com',   '$2y$10$hash1', 'admin',  95, 1, NULL),
(2,  1, 'bob',     'bob@example.com',     '$2y$10$hash2', 'editor', 82, 1, NULL),
(3,  2, 'charlie', 'charlie@example.com', '$2y$10$hash3', 'member', 70, 1, NULL),
(4,  2, 'diana',   'diana@example.com',   '$2y$10$hash4', 'member', 55, 1, NULL),
(5,  3, 'eve',     'eve@example.com',     '$2y$10$hash5', 'editor', 88, 1, NULL),
(6,  3, 'frank',   'frank@example.com',   '$2y$10$hash6', 'member', 40, 1, NULL),
(7,  4, 'grace',   'grace@example.com',   '$2y$10$hash7', 'member', 60, 1, NULL),
(8,  4, 'henry',   'henry@example.com',   '$2y$10$hash8', 'member', 33, 0, NULL),
(9,  1, 'ivan',    'ivan@example.com',    '$2y$10$hash9', 'member', 77, 1, NULL),
(10, 5, 'judy',    'judy@example.com',    '$2y$10$hash10','admin',  91, 999, '2024-06-01 00:00:00');

INSERT INTO user_profile (id, user_id, first_name, last_name, phone, date_of_birth, bio) VALUES
(1,  1,  'Alice',   'Smith',    '+60121111111', '1990-03-15', 'Lead engineer and system architect.'),
(2,  2,  'Bob',     'Johnson',  '+60122222222', '1988-07-22', 'Full-stack developer.'),
(3,  3,  'Charlie', 'Brown',    '+60123333333', '1992-11-05', 'Marketing specialist.'),
(4,  4,  'Diana',   'Prince',   '+60124444444', '1995-01-30', 'Content creator.'),
(5,  5,  'Eve',     'Williams', '+60125555555', '1991-09-12', 'Sales lead and editor.'),
(6,  6,  'Frank',   'Miller',   '+60126666666', '1993-04-18', 'Junior sales rep.'),
(7,  7,  'Grace',   'Lee',      '+60127777777', '1994-08-25', 'Support engineer.'),
(8,  8,  'Henry',   'Taylor',   NULL,           '1989-12-01', NULL),
(9,  9,  'Ivan',    'Chen',     '+60129999999', '1996-02-14', 'Backend developer.'),
(10, 10, 'Judy',    'Garcia',   '+60120000000', '1987-06-20', 'HR manager (deleted).');

INSERT INTO category (id, parent_id, name, slug, status_code) VALUES
(1, 0, 'Technology',  'technology',  1),
(2, 0, 'Lifestyle',   'lifestyle',   1),
(3, 0, 'Business',    'business',    1),
(4, 1, 'Programming', 'programming', 1),
(5, 1, 'DevOps',      'devops',      1),
(6, 2, 'Travel',      'travel',      1),
(7, 2, 'Food',        'food',        1),
(8, 3, 'Startups',    'startups',    1),
(9, 3, 'Finance',     'finance',     0);

INSERT INTO tag (id, name, slug) VALUES
(1, 'PHP',        'php'),
(2, 'MySQL',      'mysql'),
(3, 'Laravel',    'laravel'),
(4, 'JavaScript', 'javascript'),
(5, 'Docker',     'docker'),
(6, 'Tutorial',   'tutorial'),
(7, 'Review',     'review'),
(8, 'News',       'news');

INSERT INTO post (id, user_id, category_id, title, slug, body, view_count, status_code, published_at, deleted_at) VALUES
(1,  1, 4, 'Getting Started with PHP 8.1',       'getting-started-php-81',       'PHP 8.1 introduces fibers, enums, and more.',                120, 2, '2024-01-10 08:00:00', NULL),
(2,  1, 5, 'Docker for PHP Developers',          'docker-php-developers',        'Learn how to containerize your PHP apps.',                    85, 2, '2024-01-15 10:00:00', NULL),
(3,  2, 4, 'Understanding PDO Prepared Stmts',   'understanding-pdo-prepared',   'Prepared statements prevent SQL injection.',                  200, 2, '2024-02-01 09:00:00', NULL),
(4,  2, 4, 'PHP Design Patterns',                'php-design-patterns',          'Common patterns: Factory, Strategy, Observer.',               150, 2, '2024-02-10 11:00:00', NULL),
(5,  3, 6, 'Top 10 Travel Destinations 2024',    'top-10-travel-2024',           'Explore the best places to visit this year.',                 300, 2, '2024-03-01 07:00:00', NULL),
(6,  3, 7, 'Best Street Food in Asia',           'best-street-food-asia',        'A culinary journey through Asian street food.',               180, 2, '2024-03-05 12:00:00', NULL),
(7,  5, 8, 'How to Pitch Your Startup',          'how-to-pitch-startup',         'Tips for a compelling investor pitch.',                        95, 2, '2024-03-10 14:00:00', NULL),
(8,  5, 9, 'Personal Finance for Developers',    'personal-finance-developers',  'Managing money as a software engineer.',                      60, 1, NULL, NULL),
(9,  9, 4, 'Building a Query Builder in PHP',    'building-query-builder-php',   'Step by step guide to building a fluent query builder.',      250, 2, '2024-04-01 08:30:00', NULL),
(10, 9, 5, 'CI/CD with GitHub Actions',          'cicd-github-actions',          'Automate your PHP testing and deployment.',                    110, 2, '2024-04-05 09:00:00', NULL),
(11, 4, 2, 'Content Marketing Strategy',         'content-marketing-strategy',   'How to build an audience through content.',                    45, 1, NULL, NULL),
(12, 6, 8, 'Sales Automation Tools',             'sales-automation-tools',       'Review of top sales automation platforms.',                    30, 1, NULL, NULL),
(13, 7, 4, 'Debugging PHP Applications',         'debugging-php-applications',   'Tools and techniques for effective debugging.',                75, 2, '2024-05-01 10:00:00', NULL),
(14, 1, 4, 'Advanced MySQL Indexing',            'advanced-mysql-indexing',      'Optimize your queries with proper indexing strategies.',      180, 2, '2024-05-10 08:00:00', NULL),
(15, 10, 3, 'HR Best Practices',                 'hr-best-practices',            'Modern HR management techniques.',                             20, 999, '2024-04-20 00:00:00', '2024-06-01 00:00:00');

INSERT INTO post_tag (post_id, tag_id) VALUES
(1, 1), (1, 6),
(2, 1), (2, 5), (2, 6),
(3, 1), (3, 2),
(4, 1), (4, 6),
(5, 7),
(6, 7),
(7, 8),
(9, 1), (9, 2), (9, 6),
(10, 5), (10, 6),
(13, 1), (13, 6),
(14, 1), (14, 2);

INSERT INTO comment (id, post_id, user_id, body, status_code) VALUES
(1,  1, 2, 'Great introduction to PHP 8.1 features!', 1),
(2,  1, 3, 'Enums are my favorite addition.', 1),
(3,  1, 9, 'Fibers are underrated.', 1),
(4,  2, 5, 'Docker changed my workflow completely.', 1),
(5,  3, 1, 'Security first, always use prepared statements.', 1),
(6,  3, 7, 'Clear explanation, thanks!', 1),
(7,  4, 9, 'Strategy pattern is so useful in PHP.', 1),
(8,  5, 4, 'Adding Bali to my list!', 1),
(9,  5, 6, 'Japan should be #1.', 1),
(10, 5, 7, 'Great recommendations.', 1),
(11, 6, 4, 'Now I am hungry.', 1),
(12, 7, 3, 'Solid advice for first-time founders.', 1),
(13, 9, 1, 'This is exactly how I built mine.', 1),
(14, 9, 2, 'Fluent interfaces are elegant.', 1),
(15, 9, 5, 'Would love a part 2 on joins.', 1),
(16, 10, 2, 'GitHub Actions is so convenient.', 1),
(17, 13, 1, 'Xdebug + PHPStorm is the best combo.', 1),
(18, 13, 9, 'Try Ray by Spatie too.', 1),
(19, 14, 2, 'Composite indexes are often overlooked.', 1),
(20, 14, 9, 'EXPLAIN is your best friend.', 1);

INSERT INTO "order" (id, user_id, total, discount, status_code, note, ordered_at) VALUES
(1,  1, 150.00,  0.00, 4, NULL,                    '2024-01-05 10:00:00'),
(2,  1, 89.90,   10.00, 4, 'Birthday discount',    '2024-02-14 12:00:00'),
(3,  2, 250.00,  25.00, 4, NULL,                    '2024-01-20 09:30:00'),
(4,  2, 45.00,   0.00, 4, NULL,                     '2024-03-01 14:00:00'),
(5,  3, 320.50,  0.00, 4, 'Bulk order',            '2024-02-10 08:00:00'),
(6,  3, 75.00,   5.00, 3, NULL,                     '2024-04-15 11:00:00'),
(7,  4, 199.99,  20.00, 2, NULL,                    '2024-05-01 16:00:00'),
(8,  5, 500.00,  50.00, 4, 'VIP customer',         '2024-01-30 10:00:00'),
(9,  5, 120.00,  0.00, 4, NULL,                     '2024-03-20 09:00:00'),
(10, 5, 85.50,   0.00, 3, NULL,                     '2024-05-10 13:00:00'),
(11, 6, 60.00,   0.00, 1, NULL,                     '2024-05-20 10:00:00'),
(12, 7, 175.00,  15.00, 4, NULL,                    '2024-02-28 08:30:00'),
(13, 7, 95.00,   0.00, 4, NULL,                     '2024-04-10 11:00:00'),
(14, 9, 430.00,  30.00, 4, 'Team purchase',        '2024-03-15 09:00:00'),
(15, 9, 55.00,   0.00, 2, NULL,                     '2024-05-25 14:00:00'),
(16, 1, 210.00,  0.00, 3, NULL,                     '2024-04-20 10:00:00'),
(17, 2, 180.00,  0.00, 5, 'Cancelled by customer', '2024-04-25 09:00:00'),
(18, 3, 99.99,   10.00, 1, NULL,                    '2024-05-28 15:00:00'),
(19, 4, 340.00,  0.00, 2, NULL,                     '2024-05-30 08:00:00'),
(20, 8, 65.00,   0.00, 5, 'User inactive',         '2024-03-05 10:00:00');

INSERT INTO order_item (id, order_id, product_name, quantity, unit_price) VALUES
(1,  1,  'PHP Book',            1, 50.00),
(2,  1,  'MySQL Handbook',      1, 45.00),
(3,  1,  'USB-C Cable',         2, 27.50),
(4,  2,  'Mechanical Keyboard', 1, 89.90),
(5,  3,  'Monitor 27"',         1, 250.00),
(6,  4,  'Mouse Pad XL',        1, 25.00),
(7,  4,  'Webcam HD',           1, 20.00),
(8,  5,  'Standing Desk',       1, 280.50),
(9,  5,  'Desk Lamp',           1, 40.00),
(10, 6,  'Notebook',            3, 15.00),
(11, 6,  'Pen Set',             2, 15.00),
(12, 7,  'Headphones',          1, 199.99),
(13, 8,  'Ergonomic Chair',     1, 450.00),
(14, 8,  'Footrest',            1, 50.00),
(15, 9,  'USB Hub',             2, 30.00),
(16, 9,  'HDMI Cable',          2, 30.00),
(17, 10, 'Phone Stand',         1, 35.50),
(18, 10, 'Screen Protector',    2, 25.00),
(19, 11, 'Notebook',            4, 15.00),
(20, 12, 'Laptop Sleeve',       1, 45.00),
(21, 12, 'Wireless Mouse',      1, 65.00),
(22, 12, 'Mouse Pad',           1, 15.00),
(23, 12, 'USB Drive 64GB',      2, 25.00),
(24, 13, 'Webcam HD',           1, 55.00),
(25, 13, 'Ring Light',          1, 40.00),
(26, 14, 'Monitor 32"',         1, 350.00),
(27, 14, 'Monitor Arm',         1, 80.00),
(28, 15, 'Cable Organizer',     1, 25.00),
(29, 15, 'Desk Mat',            1, 30.00),
(30, 16, 'Keyboard Wrist Rest', 1, 35.00),
(31, 16, 'Monitor Light Bar',   1, 75.00),
(32, 16, 'Desk Shelf',          1, 100.00),
(33, 17, 'SSD 1TB',             1, 180.00),
(34, 18, 'Wireless Charger',    1, 49.99),
(35, 18, 'Phone Case',          2, 25.00),
(36, 19, 'Tablet',              1, 340.00),
(37, 20, 'USB-C Adapter',       1, 35.00),
(38, 20, 'Earbuds',             1, 30.00);

INSERT INTO setting (id, "group", key, value, metadata) VALUES
(1, 'general', 'site_name',    'SimSoft Demo',         '{"priority": 1, "editable": true, "tags": ["core", "branding"]}'),
(2, 'general', 'timezone',     'Asia/Kuala_Lumpur',    '{"priority": 2, "editable": true, "tags": ["locale"]}'),
(3, 'general', 'locale',       'en',                   '{"priority": 3, "editable": true, "tags": ["locale", "i18n"]}'),
(4, 'mail',    'driver',       'smtp',                 '{"priority": 1, "editable": false, "tags": ["mail", "core"]}'),
(5, 'mail',    'host',         'smtp.mailtrap.io',     '{"priority": 2, "editable": true, "tags": ["mail"]}'),
(6, 'mail',    'port',         '587',                  '{"priority": 3, "editable": true, "tags": ["mail"]}'),
(7, 'mail',    'from_name',    'SimSoft',              '{"priority": 4, "editable": true, "tags": ["mail", "branding"]}'),
(8, 'mail',    'from_address', 'noreply@simsoft.test', '{"priority": 5, "editable": true, "tags": ["mail"]}'),
(9, 'cache',   'driver',       'file',                 '{"priority": 1, "editable": false, "tags": ["cache", "core"]}'),
(10,'cache',   'ttl',          '3600',                 '{"priority": 2, "editable": true, "tags": ["cache"]}');

INSERT INTO task (id, user_id, title, description, priority, status, created_at, updated_at, deleted_at) VALUES
(1, 1, 'Setup CI pipeline',       'Configure GitHub Actions for the project.',  'high',   'done',        '2024-01-10 09:00:00', '2024-01-15 14:00:00', NULL),
(2, 1, 'Write unit tests',        'Cover all query builder methods.',           'high',   'in_progress', '2024-01-12 10:00:00', '2024-02-01 11:00:00', NULL),
(3, 2, 'Fix login bug',           'Users cannot login with special chars.',     'high',   'done',        '2024-01-20 08:00:00', '2024-01-22 16:00:00', NULL),
(4, 2, 'Update documentation',    'Add examples for new features.',             'medium', 'todo',        '2024-02-01 09:00:00', '2024-02-01 09:00:00', NULL),
(5, 3, 'Design landing page',     'Create mockups for the new landing page.',   'medium', 'in_progress', '2024-02-10 10:00:00', '2024-03-01 15:00:00', NULL),
(6, 3, 'Old task to remove',      'This task was deleted.',                     'low',    'todo',        '2024-01-05 08:00:00', '2024-01-10 09:00:00', '2024-02-01 00:00:00'),
(7, 5, 'Prepare sales report',    'Q1 sales summary for management.',           'medium', 'done',        '2024-03-01 08:00:00', '2024-03-15 17:00:00', NULL),
(8, 7, 'Respond to tickets',      'Clear the support backlog.',                 'high',   'in_progress', '2024-03-10 09:00:00', '2024-03-12 11:00:00', NULL),
(9, 9, 'Refactor query builder',  'Simplify the condition handling.',           'medium', 'todo',        '2024-04-01 10:00:00', '2024-04-01 10:00:00', NULL),
(10,1, 'Cancelled feature',       'This feature was scrapped.',                 'low',    'todo',        '2024-02-20 08:00:00', '2024-03-01 09:00:00', '2024-03-01 09:00:00');
