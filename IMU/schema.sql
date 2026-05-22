CREATE TABLE IF NOT EXISTS contacts (
  phone_number      TEXT PRIMARY KEY,
  name              TEXT,
  ghl_contact_id    TEXT,
  ghl_convo_id      TEXT,
  contacts_done     BOOLEAN DEFAULT FALSE,
  contacts_done_at  TIMESTAMPTZ,
  messages_done     BOOLEAN DEFAULT FALSE,
  messages_done_at  TIMESTAMPTZ,
  has_messages      BOOLEAN,
  error             TEXT,
  created_at        TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_ghl_contact_id   ON contacts (ghl_contact_id);
CREATE INDEX IF NOT EXISTS idx_contacts_pending ON contacts (contacts_done) WHERE contacts_done = FALSE;
CREATE INDEX IF NOT EXISTS idx_messages_pending ON contacts (messages_done) WHERE contacts_done = TRUE AND messages_done = FALSE;
CREATE INDEX IF NOT EXISTS idx_errors           ON contacts (error) WHERE error IS NOT NULL;
