-- 001_unique_username.sql
-- Enforce one account per username.
--
-- login.php authenticates with `WHERE email = ? OR username = ? LIMIT 1`.
-- If two rows share a username, that LIMIT picks an arbitrary row and the
-- legitimate owner can be locked out. Only `email` is UNIQUE today.
--
-- Run this pre-check first; the ALTER aborts if any duplicates remain:
--
--   SELECT username, COUNT(*) AS n
--   FROM user
--   GROUP BY username
--   HAVING n > 1;
--
-- Note: the column collation is utf8mb4_general_ci, so this index is
-- case-insensitive and ignores trailing spaces (Arief == arief == "arief ").

ALTER TABLE `user`
    ADD UNIQUE KEY `uq_username` (`username`);
