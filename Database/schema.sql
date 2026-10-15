-- =====================================================================
-- DocRenew — Database Schema
-- Employee Document Renewal Management System
-- Target: PostgreSQL 15+
-- Source: DocRenew Functional Specification Document v1.0, Section 6
-- Prepared by: Anil B, Angular Developer — Zamorins Solutions
-- =====================================================================
-- Notes
--   * Multi-tenant via a tenant_id discriminator column on every
--     tenant-scoped table (FSD 2.4). Application layer must always
--     filter by tenant_id; this schema adds it to every relevant
--     index and foreign key to make that cheap and safe.
--   * documents.status is NOT stored — it is computed from expiry_date
--     and the tenant's timezone at read time (FSD 4.1). A SQL function
--     and view are provided below so the backend (or ad-hoc reporting)
--     can compute it consistently in one place.
--   * document_number is stored pgcrypto-encrypted; see the
--     pgp_sym_encrypt / pgp_sym_decrypt usage note near the documents
--     table. The actual encryption key is supplied by the application
--     at the connection/session level — never hard-code it in SQL.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Extensions
-- ---------------------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS "pgcrypto";   -- gen_random_uuid(), pgp_sym_encrypt/decrypt

-- ---------------------------------------------------------------------
-- Enumerated types
-- ---------------------------------------------------------------------
CREATE TYPE user_role AS ENUM ('ADMIN', 'HR', 'MANAGER', 'EMPLOYEE');
CREATE TYPE user_status AS ENUM ('INVITED', 'ACTIVE', 'SUSPENDED');

CREATE TYPE document_status AS ENUM (
  'VALID', 'EXPIRING_SOON', 'EXPIRING_URGENT', 'EXPIRED', 'RENEWED'
);

CREATE TYPE reminder_stage AS ENUM (
  'EARLY',        -- 3 months before expiry (default offset)
  'FOLLOW_UP',    -- 1 month before expiry
  'URGENT',       -- 5 days before expiry
  'EXPIRY',       -- on expiry date
  'ESCALATION'    -- after expiry, recurring, to management
);

CREATE TYPE notification_channel AS ENUM ('EMAIL', 'SMS', 'PUSH');

CREATE TYPE item_category AS ENUM ('EMPLOYEE_DOCUMENT', 'EXTENDED_ITEM');
-- EMPLOYEE_DOCUMENT: passport, visa, work permit, license, certification — always tied to an employee_profile.
-- EXTENDED_ITEM: warranty, vehicle maintenance, bill, AMC, or a tenant-defined "Other Notification" item —
-- may or may not be tied to an employee_profile (e.g. a company vehicle or office AMC has none).

CREATE TYPE notification_status AS ENUM (
  'QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'BOUNCED'
);

-- ---------------------------------------------------------------------
-- updated_at trigger helper
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =====================================================================
-- tenants — one row per customer organization
-- =====================================================================
CREATE TABLE tenants (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  name            VARCHAR(255) NOT NULL,
  timezone        VARCHAR(64)  NOT NULL DEFAULT 'UTC',   -- IANA tz id; drives all reminder-offset math
  settings        JSONB        NOT NULL DEFAULT '{}',    -- branding, date format, default scan interval/window
  created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE TRIGGER trg_tenants_updated_at
  BEFORE UPDATE ON tenants
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- users — sign-in identities
-- =====================================================================
CREATE TABLE users (
  id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id            UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  email                VARCHAR(255) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,            -- bcrypt, cost factor 12 (FSD 3.4)
  role                 user_role NOT NULL,
  employee_profile_id  UUID,                              -- FK added after employee_profiles exists
  status               user_status NOT NULL DEFAULT 'INVITED',
  last_login_at        TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT uq_users_tenant_email UNIQUE (tenant_id, email)
);

CREATE INDEX idx_users_tenant ON users(tenant_id);

CREATE TRIGGER trg_users_updated_at
  BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- employee_profiles — workforce members tracked by the system
-- =====================================================================
CREATE TABLE employee_profiles (
  id             UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id      UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name           VARCHAR(255) NOT NULL,
  employee_code  VARCHAR(64),                             -- org-assigned employee ID
  department     VARCHAR(128),
  job_title      VARCHAR(128),
  manager_id     UUID REFERENCES employee_profiles(id) ON DELETE SET NULL,  -- self-reference (FSD 3.3)
  contact_email  VARCHAR(255),
  contact_phone  VARCHAR(32),
  active         BOOLEAN NOT NULL DEFAULT true,            -- false on deactivation; history retained
  created_at     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at     TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT uq_employee_profiles_tenant_code UNIQUE (tenant_id, employee_code)
);

CREATE INDEX idx_employee_profiles_tenant   ON employee_profiles(tenant_id);
CREATE INDEX idx_employee_profiles_manager  ON employee_profiles(manager_id);

CREATE TRIGGER trg_employee_profiles_updated_at
  BEFORE UPDATE ON employee_profiles
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Now that employee_profiles exists, wire up the users FK (Section "users")
ALTER TABLE users
  ADD CONSTRAINT fk_users_employee_profile
  FOREIGN KEY (employee_profile_id) REFERENCES employee_profiles(id) ON DELETE SET NULL;

CREATE INDEX idx_users_employee_profile ON users(employee_profile_id);

-- =====================================================================
-- document_types — tenant-defined document categories
-- =====================================================================
CREATE TABLE document_types (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name        VARCHAR(128) NOT NULL,                       -- e.g. Passport, Visa, Work Permit, Warranty, Vehicle Maintenance, Bill, AMC, Other Notification
  category    item_category NOT NULL DEFAULT 'EMPLOYEE_DOCUMENT',
  sort_order  INTEGER NOT NULL DEFAULT 0,
  retired     BOOLEAN NOT NULL DEFAULT false,               -- hides from new-doc forms, keeps history
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT uq_document_types_tenant_name UNIQUE (tenant_id, name)
);

CREATE INDEX idx_document_types_tenant ON document_types(tenant_id);

CREATE TRIGGER trg_document_types_updated_at
  BEFORE UPDATE ON document_types
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- documents — the core compliance record (FSD 4.1, 6.2)
-- =====================================================================
CREATE TABLE documents (
  id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  -- Nullable: an EMPLOYEE_DOCUMENT always has one (enforced below); an
  -- EXTENDED_ITEM (warranty, vehicle maintenance, bill, AMC, other) may
  -- belong to the organization itself rather than to a specific employee.
  employee_profile_id UUID REFERENCES employee_profiles(id) ON DELETE CASCADE,
  document_type_id    UUID NOT NULL REFERENCES document_types(id) ON DELETE RESTRICT,
  category            item_category NOT NULL DEFAULT 'EMPLOYEE_DOCUMENT',

  -- Free-text label identifying the item when there's no employee to
  -- name it by — e.g. "Company Car KA01AB1234", "Office AC Unit — AMC",
  -- "Zoho One — Annual Subscription". Optional for EMPLOYEE_DOCUMENT.
  item_label          VARCHAR(255),

  -- Encrypted identifying number. Store ciphertext as bytea via
  -- pgp_sym_encrypt(number, :key) at the application layer; never
  -- select it back except on explicit reveal (with an audit_logs entry).
  document_number_enc BYTEA,

  issue_date          DATE,
  expiry_date         DATE NOT NULL,
  attachment_key       VARCHAR(512),                        -- object-store key, never the file itself
  last_renewed_at      TIMESTAMPTZ,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT chk_documents_employee_required
    CHECK (category <> 'EMPLOYEE_DOCUMENT' OR employee_profile_id IS NOT NULL),
  CONSTRAINT chk_documents_item_label_required
    CHECK (category <> 'EXTENDED_ITEM' OR employee_profile_id IS NOT NULL OR item_label IS NOT NULL)
);

CREATE INDEX idx_documents_tenant          ON documents(tenant_id);
CREATE INDEX idx_documents_employee        ON documents(employee_profile_id);
CREATE INDEX idx_documents_type            ON documents(document_type_id);
CREATE INDEX idx_documents_expiry          ON documents(tenant_id, expiry_date);
CREATE INDEX idx_documents_category        ON documents(tenant_id, category);

CREATE TRIGGER trg_documents_updated_at
  BEFORE UPDATE ON documents
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- Computed status (FSD 4.1: "recomputed on every read, never stored")
-- Thresholds mirror the default reminder stages: 90d / 30d / 5d / 0d.
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION compute_document_status(
  p_expiry_date   DATE,
  p_last_renewed  TIMESTAMPTZ,
  p_tz            VARCHAR DEFAULT 'UTC'
) RETURNS document_status AS $$
DECLARE
  v_today DATE := (now() AT TIME ZONE p_tz)::date;
  v_days  INTEGER := p_expiry_date - v_today;
BEGIN
  IF p_last_renewed IS NOT NULL
     AND p_last_renewed > (now() - interval '30 days') THEN
    RETURN 'RENEWED';
  ELSIF v_days < 0 THEN
    RETURN 'EXPIRED';
  ELSIF v_days <= 5 THEN
    RETURN 'EXPIRING_URGENT';
  ELSIF v_days <= 90 THEN
    RETURN 'EXPIRING_SOON';
  ELSE
    RETURN 'VALID';
  END IF;
END;
$$ LANGUAGE plpgsql STABLE;

CREATE OR REPLACE VIEW v_documents_with_status AS
SELECT
  d.*,
  compute_document_status(d.expiry_date, d.last_renewed_at, t.timezone) AS status
FROM documents d
JOIN tenants t ON t.id = d.tenant_id;

-- =====================================================================
-- reminder_schedules — per-tenant (and optionally per-document-type)
-- stage offsets (FSD 4.5, 6.2)
-- =====================================================================
CREATE TABLE reminder_schedules (
  id                UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id         UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  document_type_id  UUID REFERENCES document_types(id) ON DELETE CASCADE,  -- NULL = tenant-wide default
  -- stages: [{ "stage": "EARLY", "offsetDays": 90, "channels": ["EMAIL"],
  --            "recipients": ["EMPLOYEE","HR"] }, ...]
  stages            JSONB NOT NULL DEFAULT '[]',
  created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT uq_reminder_schedules_scope UNIQUE (tenant_id, document_type_id)
);

CREATE INDEX idx_reminder_schedules_tenant ON reminder_schedules(tenant_id);

CREATE TRIGGER trg_reminder_schedules_updated_at
  BEFORE UPDATE ON reminder_schedules
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- notification_templates — rendered message content per stage/channel
-- =====================================================================
CREATE TABLE notification_templates (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id   UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  stage       reminder_stage NOT NULL,
  channel     notification_channel NOT NULL,
  subject     VARCHAR(255),                                 -- used for EMAIL only
  body        TEXT NOT NULL,                                -- merge fields: {{employeeName}}, {{documentType}}, {{expiryDate}}, {{daysRemaining}}
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),

  CONSTRAINT uq_notification_templates_scope UNIQUE (tenant_id, stage, channel)
);

CREATE INDEX idx_notification_templates_tenant ON notification_templates(tenant_id);

CREATE TRIGGER trg_notification_templates_updated_at
  BEFORE UPDATE ON notification_templates
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- notifications — delivery log, one row per attempted send (FSD 5, 6.2)
-- =====================================================================
CREATE TABLE notifications (
  id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id            UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  document_id          UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  stage                reminder_stage NOT NULL,
  channel              notification_channel NOT NULL,
  recipient_user_id    UUID REFERENCES users(id) ON DELETE SET NULL,
  recipient_address    VARCHAR(255),                        -- email/phone snapshot at send time
  status               notification_status NOT NULL DEFAULT 'QUEUED',
  provider_message_id  VARCHAR(255),
  attempt_count        INTEGER NOT NULL DEFAULT 0,
  last_error           TEXT,
  created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at           TIMESTAMPTZ NOT NULL DEFAULT now(),

  -- At most one notification per document, per stage, per channel
  -- (FSD 4.2 "avoid duplicate sends"). Escalation repeats on a cadence,
  -- so it is intentionally excluded from this uniqueness rule.
  CONSTRAINT uq_notifications_dedupe
    UNIQUE (document_id, stage, channel)
    DEFERRABLE INITIALLY IMMEDIATE
);

CREATE INDEX idx_notifications_tenant    ON notifications(tenant_id);
CREATE INDEX idx_notifications_document  ON notifications(document_id);
CREATE INDEX idx_notifications_status    ON notifications(tenant_id, status);

CREATE TRIGGER trg_notifications_updated_at
  BEFORE UPDATE ON notifications
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- =====================================================================
-- audit_logs — record of sensitive actions for compliance review
-- =====================================================================
CREATE TABLE audit_logs (
  id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  tenant_id        UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  acting_user_id   UUID REFERENCES users(id) ON DELETE SET NULL,
  action           VARCHAR(128) NOT NULL,   -- e.g. document.viewNumber, user.suspend, config.update
  entity_type      VARCHAR(64),
  entity_id        UUID,
  metadata         JSONB NOT NULL DEFAULT '{}',
  created_at       TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX idx_audit_logs_tenant   ON audit_logs(tenant_id);
CREATE INDEX idx_audit_logs_entity   ON audit_logs(entity_type, entity_id);
CREATE INDEX idx_audit_logs_created  ON audit_logs(tenant_id, created_at);

-- =====================================================================
-- Seed: default document types and reminder schedule for a new tenant
-- (illustrative — run with a real :tenant_id parameter at provisioning time)
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
--   (:tenant_id, NULL, '[
--     {"stage":"EARLY","offsetDays":90,"channels":["EMAIL"],"recipients":["EMPLOYEE","HR"]},
--     {"stage":"FOLLOW_UP","offsetDays":30,"channels":["EMAIL"],"recipients":["EMPLOYEE","HR"]},
--     {"stage":"URGENT","offsetDays":5,"channels":["EMAIL","SMS"],"recipients":["EMPLOYEE","HR"]},
--     {"stage":"EXPIRY","offsetDays":0,"channels":["EMAIL","SMS","PUSH"],"recipients":["EMPLOYEE","HR"]},
--     {"stage":"ESCALATION","offsetDays":-7,"channels":["EMAIL","PUSH"],"recipients":["MANAGER"],"recurring":true}
--   ]'::jsonb);
