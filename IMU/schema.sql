CREATE TABLE IF NOT EXISTS contacts (
  phone_number        TEXT PRIMARY KEY,
  name                TEXT,
  account_id          TEXT,
  ghl_contact_id      TEXT,
  ghl_convo_id        TEXT,
  has_messages        BOOLEAN,
  phase1_pushed       BOOLEAN DEFAULT FALSE,
  phase1_pushed_at    TIMESTAMPTZ,
  phase2_pushed       BOOLEAN DEFAULT FALSE,
  phase2_pushed_at    TIMESTAMPTZ,
  error               TEXT,
  created_at          TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ghl_contact_id ON contacts (ghl_contact_id);
CREATE INDEX IF NOT EXISTS idx_phase1_pending ON contacts (phase1_pushed) WHERE phase1_pushed = FALSE;
CREATE INDEX IF NOT EXISTS idx_phase2_pending ON contacts (phase2_pushed) WHERE phase1_pushed = TRUE AND phase2_pushed = FALSE;
CREATE INDEX IF NOT EXISTS idx_errors         ON contacts (error) WHERE error IS NOT NULL;
