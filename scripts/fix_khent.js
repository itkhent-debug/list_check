const mysql = require('mysql2/promise');
const bcrypt = require('bcryptjs');

(async () => {
  const c = await mysql.createConnection({
    host: 'yamanote.proxy.rlwy.net',
    user: 'root',
    password: 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY',
    database: 'railway',
    port: 58498,
  });

  const hash = bcrypt.hashSync('FPAI26', 10);
  await c.execute(
    'UPDATE users SET password_hash = ?, is_active = 1 WHERE email = ?',
    [hash, 'khentagustin@ga.co']
  );

  const [r] = await c.execute(
    'SELECT id, email, is_active, CHAR_LENGTH(password_hash) AS hl FROM users WHERE email = ?',
    ['khentagustin@ga.co']
  );

  console.log('khentagustin@ga.co repaired:', JSON.stringify(r[0], null, 2));
  await c.end();
})();
