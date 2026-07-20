-- Seed a single OAuth2 client for the Nextcloud integration.
--
-- SuiteCRM 8 stores OAuth2 clients in the `oauth2clients` table. This client
-- is configured to accept both the authorization-code flow (Nextcloud's
-- preferred connect path) and the password grant (the fallback still exposed
-- in the personal-settings "Advanced" section).
--
-- Substitution variables are provided by the entrypoint via envsubst:
--   $OAUTH_CLIENT_ID
--   $SEED_HASHED_SECRET   (bcrypt hash of $OAUTH_CLIENT_SECRET)
--   $OAUTH_REDIRECT_URI
--
-- @Code Changes by: Kim Haverblad, 2026

DELETE FROM oauth2clients WHERE id = '$OAUTH_CLIENT_ID';

INSERT INTO oauth2clients
    (id, name, date_entered, date_modified, modified_user_id, created_by,
     description, deleted, secret, is_confidential, redirect_url,
     allowed_grant_types, duration_value, duration_amount, duration_unit)
VALUES
    ('$OAUTH_CLIENT_ID',
     'Nextcloud integration (dev)',
     NOW(), NOW(),
     '1', '1',
     'Auto-seeded by the docker dev stack. Safe to delete.',
     0,
     '$SEED_HASHED_SECRET',
     1,
     '$OAUTH_REDIRECT_URI',
     'password,client_credentials,authorization_code,refresh_token',
     60, 60, 'minute');
