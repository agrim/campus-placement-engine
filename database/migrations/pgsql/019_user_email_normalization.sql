-- Refuse ambiguous existing identities instead of choosing or merging an account.
-- Resolve any users_email_normalized collision before retrying the upgrade.
CREATE UNIQUE INDEX users_email_normalized ON users (lower(trim(email)));

UPDATE users SET email = lower(trim(email));
