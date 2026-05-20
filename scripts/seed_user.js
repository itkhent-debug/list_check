/**
 * One-shot script: ensure a user exists in the production DB with the
 * given email and password, regardless of whether the API seed ran.
 *
 * Usage: node scripts/seed_user.js
 *
 * Reads DB connection from the same env vars / defaults used by api/config.php.
 */
const mysql = require('mysql2/promise');
const crypto = require('crypto');

// --- Target user ---
const TARGET = {
  email: 'jbdelrosario@ga.co',
  name: 'JB Del Rosario',
  password: 'FPAI26',
};

// --- DB config (mirrors api/config.php) ---
const dbConfig = {
  host:     process.env.MYSQLHOST     || 'yamanote.proxy.rlwy.net',
  user:     process.env.MYSQLUSER     || 'root',
  password: process.env.MYSQLPASSWORD || 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY',
  database: process.env.MYSQLDATABASE || 'railway',
  port:     Number(process.env.MYSQLPORT || 58498),
  connectTimeout: 15000,
};

/**
 * Build a PHP-compatible bcrypt hash. PHP's password_hash with PASSWORD_DEFAULT
 * produces a $2y$ bcrypt hash; password_verify accepts both $2y$ and $2b$.
 * Node's bcrypt libs aren't installed here, so we generate a $2b$ hash via a
 * tiny pure-Node bcrypt implementation embedded below.
 *
 * To keep this script dependency-free, we instead call out to mysql2 only and
 * use a precomputed-at-runtime hash through Node's crypto using a simple but
 * standard bcrypt routine via the `bcryptjs` package if available, falling
 * back to spawning OpenSSL... Actually, simplest path: install bcryptjs on
 * the fly via npm if not present.
 */
async function bcryptHash(plain) {
  let bcrypt;
  try {
    bcrypt = require('bcryptjs');
  } catch (_) {
    console.log('[seed] bcryptjs not installed, installing...');
    const { execSync } = require('child_process');
    execSync('npm install bcryptjs --no-save --silent', { stdio: 'inherit' });
    bcrypt = require('bcryptjs');
  }
  // cost 10 matches PHP PASSWORD_DEFAULT default cost.
  return bcrypt.hashSync(plain, 10);
}

(async () => {
  console.log(`[seed] connecting to ${dbConfig.host}:${dbConfig.port}/${dbConfig.database}`);
  const conn = await mysql.createConnection(dbConfig);

  // Make sure the users table & needed columns exist (idempotent).
  await conn.query(`
    CREATE TABLE IF NOT EXISTS users (
      id INT AUTO_INCREMENT PRIMARY KEY,
      email VARCHAR(255) NOT NULL UNIQUE,
      name VARCHAR(100) NOT NULL,
      password_hash VARCHAR(255) DEFAULT NULL,
      picture VARCHAR(500) DEFAULT NULL,
      auth_provider VARCHAR(50) DEFAULT 'local',
      is_active TINYINT(1) DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      last_login DATETIME DEFAULT NULL
    )
  `);

  const hash = await bcryptHash(TARGET.password);

  // INSERT ... ON DUPLICATE KEY UPDATE: create or repair in one call.
  const [result] = await conn.execute(
    `INSERT INTO users (email, name, password_hash, is_active, auth_provider)
     VALUES (?, ?, ?, 1, 'local')
     ON DUPLICATE KEY UPDATE
       password_hash = VALUES(password_hash),
       name          = VALUES(name),
       is_active     = 1,
       auth_provider = 'local'`,
    [TARGET.email, TARGET.name, hash]
  );

  // Verify
  const [rows] = await conn.execute(
    `SELECT id, email, name, is_active, auth_provider,
            CHAR_LENGTH(password_hash) AS hash_len
     FROM users WHERE email = ?`,
    [TARGET.email]
  );

  console.log('[seed] result:', { affectedRows: result.affectedRows, insertId: result.insertId });
  console.log('[seed] user row:', rows[0]);
  console.log(`[seed] OK -> login with ${TARGET.email} / ${TARGET.password}`);

  await conn.end();
})().catch((err) => {
  console.error('[seed] FAILED:', err && err.message ? err.message : err);
  process.exit(1);
});
