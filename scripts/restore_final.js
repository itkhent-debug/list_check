const mysql = require('mysql2/promise');
const fs = require('fs');

async function main() {
  const sql = fs.readFileSync('database/crm_checklist (1).sql', 'utf8');
  const conn = await mysql.createConnection({
    host: 'yamanote.proxy.rlwy.net', user: 'root',
    password: 'ahFYtSstCghtlLjIzkwRJJaTHXJifVdY',
    database: 'railway', port: 58498
  });
  console.log('Connected');

  await conn.query('SET FOREIGN_KEY_CHECKS = 0');
  await conn.query('DELETE FROM batch_tags');
  await conn.query('DELETE FROM items');
  await conn.query('DELETE FROM tags');
  await conn.query('DELETE FROM batches');
  await conn.query('DELETE FROM users');
  await conn.query('DELETE FROM visitors');
  await conn.query('DELETE FROM workflow_gaps');
  console.log('Cleared');

  // Add compat columns for schema differences
  try { await conn.query('ALTER TABLE batches ADD COLUMN tag_id INT DEFAULT NULL'); } catch(e) {}
  try { await conn.query('ALTER TABLE batch_tags ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST'); } catch(e) {}
  console.log('Schema aligned');

  // Extract all INSERT statements with proper parsing
  let idx = 0;
  let restored = {};
  let failed = [];

  while (true) {
    const start = sql.indexOf('INSERT INTO `', idx);
    if (start === -1) break;

    const backtickEnd = sql.indexOf('`', start + 13);
    const tableName = sql.substring(start + 13, backtickEnd);
    const valuesStart = sql.indexOf('VALUES', backtickEnd);
    if (valuesStart === -1) { idx = backtickEnd + 1; continue; }

    // Find terminating semicolon with balanced parentheses
    let depth = 0;
    let inString = false;
    let stringChar = '';
    let stmtEnd = -1;
    for (let i = valuesStart; i < sql.length; i++) {
      const c = sql[i];
      if (inString) {
        if (c === stringChar && sql[i-1] !== '\\') {
          inString = false;
        }
      } else if (c === "'" || c === '"') {
        inString = true;
        stringChar = c;
      } else if (c === '(') {
        depth++;
      } else if (c === ')') {
        depth--;
      } else if (c === ';' && depth === 0) {
        stmtEnd = i;
        break;
      }
    }

    if (stmtEnd === -1) break;

    const stmt = sql.substring(start, stmtEnd + 1);
    idx = stmtEnd + 1;

    if (tableName === 'workflow_gaps') continue;

    try {
      await conn.query(stmt);
      restored[tableName] = (restored[tableName] || 0) + 1;
    } catch(e) {
      failed.push(tableName + ': ' + e.message.substring(0, 100));
    }
  }

  // Cleanup compat columns
  try { await conn.query('ALTER TABLE batches DROP COLUMN tag_id'); } catch(e) {}
  try {
    await conn.query('ALTER TABLE batch_tags DROP PRIMARY KEY');
    await conn.query('ALTER TABLE batch_tags MODIFY id INT');
    await conn.query('ALTER TABLE batch_tags DROP COLUMN id');
    await conn.query('ALTER TABLE batch_tags ADD PRIMARY KEY (batch_id, tag_id)');
  } catch(e) { console.log('batch_tags cleanup:', e.message); }

  await conn.query('SET FOREIGN_KEY_CHECKS = 1');

  if (failed.length > 0) console.log('Failed:', failed);
  for (const t of ['batches', 'items', 'tags', 'batch_tags', 'users', 'visitors']) {
    const [r] = await conn.query('SELECT COUNT(*) as c FROM `' + t + '`');
    console.log(t + ': ' + r[0].c + ' rows');
  }
  await conn.end();
  console.log('Restore complete!');
}

main().catch(err => { console.error('Failed:', err.message); process.exit(1); });
