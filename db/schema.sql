-- =====================================================================
-- DocRenew — Database Schema
-- Employee Document Renewal Management System
-- Target: MySQL 8.0+ (DigitalOcean Managed Database), consumed via Laravel 11/PHP 8.3 migrations
-- Source: DocRenew Functional Specification Document v1.0, Section 6
-- Prepared by: Anil B, Angular Developer — Zamorins Solutions
-- =====================================================================
-- Notes
--   * This file is the reference schema in plain SQL for review and
--     onboarding. The actual codebase should express every table below
--     as a Laravel migration (php artisan make:migration) so schema
--     changes are version-controlled and reversible; this file is the
--     source of truth those migrations are generated from.
--   * Multi-tenant via a tenant_id discriminator column on every
--     tenant-scoped table (FSD 2.4). Application layer must always
--     filter by tenant_id; this schema adds it to every relevant
--     index and foreign key to make that cheap and safe.
--   * Primary keys use CHAR(36) UUIDs (via MySQL's UUID() default, or
--     Laravel's HasUuids trait on the model) rather than AUTO_INCREMENT
--     integers, so IDs are safe to expose and never leak row counts.
--   * documents.status is NOT stored — it is computed at read time from
--     expiry_date and the tenant's timezone (FSD 4.1). This is done as
--     a Laravel Eloquent accessor (Document::getStatusAttribute()) on
--     the model, not as a MySQL stored function/view, to keep the
--     status logic in one place (PHP) instead of split across the app
--     and the database.
--   * document_number is encrypted at the application layer using
--     Laravel's `encrypted` Eloquent cast (AES-256-CBC via APP_KEY),
--     not a database-level encryption function. Never select it back
--     except on explicit reveal (with an audit_logs entry).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- updated_at handling
-- ---------------------------------------------------------------------
-- No trigger needed: every table below declares
--   updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
-- which MySQL maintains natively. Laravel's Eloquent timestamps() does
-- the same thing when these tables are expressed as migrations.

-- =====================================================================
-- tenants — one row per customer organization
-- =====================================================================
CREATE TABLE tenants (
  id              CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  name            VARCHAR(255) NOT NULL,
  timezone        VARCHAR(64)  NOT NULL DEFAULT 'UTC',   -- IANA tz id; drives all reminder-offset math
  settings        JSON         NOT NULL,                 -- branding, date format, default scan interval/window
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- users — sign-in identities
-- =====================================================================
CREATE TABLE users (
  id                   CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id            CHAR(36)     NOT NULL,
  email                VARCHAR(255) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,            -- bcrypt, cost factor 12 (FSD 3.4) — Laravel's Hash::make()
  role                 ENUM('ADMIN','HR','MANAGER','EMPLOYEE') NOT NULL,
  employee_profile_id  CHAR(36)     NULL,                 -- FK added after employee_profiles exists
  status               ENUM('INVITED','ACTIVE','SUSPENDED') NOT NULL DEFAULT 'INVITED',
  last_login_at        TIMESTAMP    NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT uq_users_tenant_email UNIQUE (tenant_id, email),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_users_tenant ON users(tenant_id);

-- =====================================================================
-- employee_profiles — workforce members tracked by the system
-- =====================================================================
CREATE TABLE employee_profiles (
  id             CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id      CHAR(36)     NOT NULL,
  name           VARCHAR(255) NOT NULL,
  employee_code  VARCHAR(64)  NULL,                       -- org-assigned employee ID
  department     VARCHAR(128) NULL,
  job_title      VARCHAR(128) NULL,
  manager_id     CHAR(36)     NULL,                        -- self-reference (FSD 3.3)
  contact_email  VARCHAR(255) NULL,
  contact_phone  VARCHAR(32)  NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,           -- false (0) on deactivation; history retained
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT uq_employee_profiles_tenant_code UNIQUE (tenant_id, employee_code),
  CONSTRAINT fk_employee_profiles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_employee_profiles_manager FOREIGN KEY (manager_id) REFERENCES employee_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_employee_profiles_tenant   ON employee_profiles(tenant_id);
CREATE INDEX idx_employee_profiles_manager  ON employee_profiles(manager_id);

-- Now that employee_profiles exists, wire up the users FK (Section "users")
ALTER TABLE users
  ADD CONSTRAINT fk_users_employee_profile
  FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id) ON DELETE SET NULL;

CREATE INDEX idx_users_employee_profile ON users(employee_profile_id);

-- =====================================================================
-- document_types — tenant-defined document and extended-item categories
-- =====================================================================
CREATE TABLE document_types (
  id          CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  name        VARCHAR(128) NOT NULL,                       -- e.g. Passport, Visa, Work Permit, Warranty, Vehicle Maintenance, Bill, AMC, Other Notification
  category    ENUM('EMPLOYEE_DOCUMENT','EXTENDED_ITEM') NOT NULL DEFAULT 'EMPLOYEE_DOCUMENT',
  -- EMPLOYEE_DOCUMENT: passport, visa, work permit, license, certification — always tied to an employee_profile.
  -- EXTENDED_ITEM: warranty, vehicle maintenance, bill, AMC, or a tenant-defined "Other Notification" item —
  -- may or may not be tied to an employee_profile (e.g. a company vehicle or office AMC has none).
  sort_order  INT          NOT NULL DEFAULT 0,
  retired     TINYINT(1)   NOT NULL DEFAULT 0,              -- hides from new-doc forms, keeps history
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT uq_document_types_tenant_name UNIQUE (tenant_id, name),
  CONSTRAINT fk_document_types_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_document_types_tenant ON document_types(tenant_id);

-- =====================================================================
-- documents — the core compliance record (FSD 4.1, 6.2)
-- Covers both employee documents and extended items (warranty, vehicle
-- maintenance, bills, AMC, other).
-- =====================================================================
CREATE TABLE documents (
  id                  CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id           CHAR(36)     NOT NULL,
  -- Nullable: an EMPLOYEE_DOCUMENT always has one (enforced below); an
  -- EXTENDED_ITEM (warranty, vehicle maintenance, bill, AMC, other) may
  -- belong to the organization itself rather than to a specific employee.
  employee_profile_id CHAR(36)     NULL,
  document_type_id    CHAR(36)     NOT NULL,
  category            ENUM('EMPLOYEE_DOCUMENT','EXTENDED_ITEM') NOT NULL DEFAULT 'EMPLOYEE_DOCUMENT',

  -- Free-text label identifying the item when there's no employee to
  -- name it by — e.g. "Company Car KA01AB1234", "Office AC Unit — AMC",
  -- "Zoho One — Annual Subscription". Optional for EMPLOYEE_DOCUMENT.
  item_label          VARCHAR(255) NULL,

  -- Encrypted identifying number. Stored as application-encrypted text
  -- via Laravel's `encrypted` Eloquent cast (AES-256-CBC, keyed by
  -- APP_KEY); never select it back except on explicit reveal (with an
  -- audit_logs entry). Sized generously since ciphertext + the cast's
  -- base64/JSON envelope runs longer than the plaintext.
  document_number_enc TEXT         NULL,

  issue_date          DATE         NULL,
  expiry_date         DATE         NOT NULL,
  attachment_key      VARCHAR(512) NULL,                   -- object-store key (DigitalOcean Spaces), never the file itself
  last_renewed_at     TIMESTAMP    NULL,
  created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_documents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_documents_employee FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id) ON DELETE CASCADE,
  CONSTRAINT fk_documents_type FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE RESTRICT,

  CONSTRAINT chk_documents_employee_required
    CHECK (category <> 'EMPLOYEE_DOCUMENT' OR employee_profile_id IS NOT NULL),
  CONSTRAINT chk_documents_item_label_required
    CHECK (category <> 'EXTENDED_ITEM' OR employee_profile_id IS NOT NULL OR item_label IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_documents_tenant          ON documents(tenant_id);
CREATE INDEX idx_documents_employee        ON documents(employee_profile_id);
CREATE INDEX idx_documents_type            ON documents(document_type_id);
CREATE INDEX idx_documents_expiry          ON documents(tenant_id, expiry_date);
CREATE INDEX idx_documents_category        ON documents(tenant_id, category);

-- ---------------------------------------------------------------------
-- Computed status (FSD 4.1: "recomputed on every read, never stored")
-- Thresholds mirror the default reminder stages: 90d / 30d / 5d / 0d.
--
-- Implemented in PHP, not SQL — add to app/Models/Document.php:
--
--   protected function status(): Attribute
--   {
--       return Attribute::make(get: function () {
--           if ($this->last_renewed_at && $this->last_renewed_at->gt(now()->subDays(30))) {
--               return 'RENEWED';
--           }
--           $days = now($this->tenant->timezone)->startOfDay()
--               ->diffInDays($this->expiry_date, false);
--           return match (true) {
--               $days < 0  => 'EXPIRED',
--               $days <= 5  => 'EXPIRING_URGENT',
--               $days <= 90 => 'EXPIRING_SOON',
--               default     => 'VALID',
--           };
--       });
--   }
--
-- Keeping this in the model (rather than a MySQL stored function/view)
-- keeps status logic in one language and easy to unit-test with Pest.
-- ---------------------------------------------------------------------

-- =====================================================================
-- reminder_schedules — per-tenant (and optionally per-document-type)
-- stage offsets (FSD 4.5, 6.2)
-- =====================================================================
CREATE TABLE reminder_schedules (
  id                CHAR(36)  NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id         CHAR(36)  NOT NULL,
  document_type_id  CHAR(36)  NULL,                       -- NULL = tenant-wide default
  -- stages: [{ "stage": "EARLY", "offsetDays": 90, "channels": ["EMAIL"],
  --            "recipients": ["EMPLOYEE","HR"] }, ...]
  stages            JSON      NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT uq_reminder_schedules_scope UNIQUE (tenant_id, document_type_id),
  CONSTRAINT fk_reminder_schedules_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_reminder_schedules_type FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_reminder_schedules_tenant ON reminder_schedules(tenant_id);

-- =====================================================================
-- notification_templates — rendered message content per stage/channel
-- =====================================================================
CREATE TABLE notification_templates (
  id          CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id   CHAR(36)     NOT NULL,
  stage       ENUM('EARLY','FOLLOW_UP','URGENT','EXPIRY','ESCALATION') NOT NULL,
  channel     ENUM('EMAIL','SMS','PUSH') NOT NULL,
  subject     VARCHAR(255) NULL,                           -- used for EMAIL only
  body        TEXT         NOT NULL,                       -- merge fields: {{employeeName}}, {{documentType}}, {{expiryDate}}, {{daysRemaining}}
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT uq_notification_templates_scope UNIQUE (tenant_id, stage, channel),
  CONSTRAINT fk_notification_templates_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_notification_templates_tenant ON notification_templates(tenant_id);

-- =====================================================================
-- notifications — delivery log, one row per attempted send (FSD 5, 6.2)
-- =====================================================================
CREATE TABLE notifications (
  id                   CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id            CHAR(36)     NOT NULL,
  document_id          CHAR(36)     NOT NULL,
  stage                ENUM('EARLY','FOLLOW_UP','URGENT','EXPIRY','ESCALATION') NOT NULL,
  channel              ENUM('EMAIL','SMS','PUSH') NOT NULL,
  recipient_user_id    CHAR(36)     NULL,
  recipient_address    VARCHAR(255) NULL,                  -- email/phone snapshot at send time
  status               ENUM('QUEUED','SENT','DELIVERED','FAILED','BOUNCED') NOT NULL DEFAULT 'QUEUED',
  provider_message_id  VARCHAR(255) NULL,
  attempt_count        INT          NOT NULL DEFAULT 0,
  last_error           TEXT         NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- At most one notification per document, per stage, per channel
  -- (FSD 4.2 "avoid duplicate sends"). Escalation repeats on a cadence
  -- via the "recurring" flag in reminder_schedules.stages, so the
  -- backend job must branch on stage before relying on this unique
  -- key — MySQL unique indexes can't be made DEFERRABLE the way
  -- Postgres's can, so the escalation job upserts/skips explicitly
  -- in PHP rather than catching a constraint violation.
  CONSTRAINT uq_notifications_dedupe UNIQUE (document_id, stage, channel),

  CONSTRAINT fk_notifications_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_document FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_notifications_tenant    ON notifications(tenant_id);
CREATE INDEX idx_notifications_document  ON notifications(document_id);
CREATE INDEX idx_notifications_status    ON notifications(tenant_id, status);

-- =====================================================================
-- audit_logs — record of sensitive actions for compliance review
-- =====================================================================
CREATE TABLE audit_logs (
  id               CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  tenant_id        CHAR(36)     NOT NULL,
  acting_user_id   CHAR(36)     NULL,
  action           VARCHAR(128) NOT NULL,   -- e.g. document.viewNumber, user.suspend, config.update
  entity_type      VARCHAR(64)  NULL,
  entity_id        CHAR(36)     NULL,
  metadata         JSON         NOT NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT fk_audit_logs_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  CONSTRAINT fk_audit_logs_user FOREIGN KEY (acting_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_audit_logs_tenant   ON audit_logs(tenant_id);
CREATE INDEX idx_audit_logs_entity   ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_created  ON audit_logs(tenant_id, created_at);

-- =====================================================================
-- Seed: default document types and reminder schedule for a new tenant
-- (illustrative — run with a real tenant id at provisioning time, e.g.
-- from a Laravel DocumentTypeSeeder keyed off the new tenant's UUID)
-- =====================================================================
-- INSERT INTO document_types (tenant_id, name, category, sort_order) VALUES
--   (:tenant_id, 'Passport', 'EMPLOYEE_DOCUMENT', 1),
--   (:tenant_id, 'Visa', 'EMPLOYEE_DOCUMENT', 2),
--   (:tenant_id, 'Work Permit', 'EMPLOYEE_DOCUMENT', 3),
--   (:tenant_id, 'Driving License', 'EMPLOYEE_DOCUMENT', 4),
--   (:tenant_id, 'Certification', 'EMPLOYEE_DOCUMENT', 5),
--   (:tenant_id, 'Warranty', 'EXTENDED_ITEM', 6),
--   (:tenant_id, 'Vehicle Maintenance', 'EXTENDED_ITEM', 7),
--   (:tenant_id, 'Bill', 'EXTENDED_ITEM', 8),
--   (:tenant_id, 'AMC', 'EXTENDED_ITEM', 9),
--   (:tenant_id, 'Other Notification', 'EXTENDED_ITEM', 10);
--
-- INSERT INTO reminder_schedules (tenant_id, document_type_id, stages) VALUES
--   (:tenant_id, NULL, JSON_ARRAY(
--     JSON_OBJECT('stage','EARLY','offsetDays',90,'channels',JSON_ARRAY('EMAIL'),'recipients',JSON_ARRAY('EMPLOYEE','HR')),
--     JSON_OBJECT('stage','FOLLOW_UP','offsetDays',30,'channels',JSON_ARRAY('EMAIL'),'recipients',JSON_ARRAY('EMPLOYEE','HR')),
--     JSON_OBJECT('stage','URGENT','offsetDays',5,'channels',JSON_ARRAY('EMAIL','SMS'),'recipients',JSON_ARRAY('EMPLOYEE','HR')),
--     JSON_OBJECT('stage','EXPIRY','offsetDays',0,'channels',JSON_ARRAY('EMAIL','SMS','PUSH'),'recipients',JSON_ARRAY('EMPLOYEE','HR')),
--     JSON_OBJECT('stage','ESCALATION','offsetDays',-7,'channels',JSON_ARRAY('EMAIL','PUSH'),'recipients',JSON_ARRAY('MANAGER'),'recurring',true)
--   ));
